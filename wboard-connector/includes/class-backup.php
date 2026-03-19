<?php
/**
 * Module de backup pour le connector WBoard.
 *
 * Enregistre les endpoints REST de backup et gere
 * l'authentification specifique (IP whitelist + HMAC + board).
 * Ce module est isolé : si le backup n'est pas active,
 * aucun endpoint n'est enregistre (retourne 404 naturellement).
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Backup
 *
 * Point d'entree du module backup du connector.
 */
class WBoard_Connector_Backup {

	/**
	 * Namespace de l'API REST.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'wboard/v1';

	/**
	 * Nom de l'option pour la config backup.
	 *
	 * @var string
	 */
	const OPTION_BACKUP_CONFIG = 'wboard_backup_config';

	/**
	 * Nom du transient pour le cache de verification board.
	 *
	 * @var string
	 */
	const TRANSIENT_BOARD_VERIFY = 'wboard_backup_board_verify_';

	/**
	 * Duree du cache de verification board (1 heure).
	 *
	 * @var int
	 */
	const BOARD_VERIFY_CACHE_TTL = 3600;

	/**
	 * Rate limit specifique aux endpoints backup (requetes/minute).
	 *
	 * Plus genereux que le rate limit global car le backup-manager
	 * enchaine les requetes (scan pagine, fichiers par batch, tables).
	 *
	 * @var int
	 */
	const BACKUP_RATE_LIMIT = 120;

	/**
	 * Instance de la classe Security.
	 *
	 * @var WBoard_Connector_Security
	 */
	private $security;

	/**
	 * Config backup chargee depuis wp_options.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Constructeur.
	 *
	 * @param WBoard_Connector_Security $security Instance de securite.
	 */
	public function __construct( WBoard_Connector_Security $security ) {
		$this->security = $security;
		$this->config   = $this->load_config();
	}

	/**
	 * Enregistre les hooks WordPress.
	 *
	 * Les routes ne sont enregistrees que si le backup est active.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes REST pour le backup.
	 *
	 * @return void
	 */
	public function register_routes() {
		// POST /wboard/v1/backup/scan — Scan fichiers, retourne manifeste.
		register_rest_route(
			self::API_NAMESPACE,
			'/backup/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_scan' ),
				'permission_callback' => array( $this, 'check_backup_permission' ),
			)
		);

		// POST /wboard/v1/backup/files — Cree un ZIP et l'uploade.
		register_rest_route(
			self::API_NAMESPACE,
			'/backup/files',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_files' ),
				'permission_callback' => array( $this, 'check_backup_permission' ),
			)
		);

		// POST /wboard/v1/backup/db/tables — Liste les tables + empreintes.
		register_rest_route(
			self::API_NAMESPACE,
			'/backup/db/tables',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_db_tables' ),
				'permission_callback' => array( $this, 'check_backup_permission' ),
			)
		);

		// POST /wboard/v1/backup/db/export — Exporte une table (cursor-based).
		register_rest_route(
			self::API_NAMESPACE,
			'/backup/db/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_db_export' ),
				'permission_callback' => array( $this, 'check_backup_permission' ),
			)
		);

		// GET /wboard/v1/backup/status — Statut du module backup.
		register_rest_route(
			self::API_NAMESPACE,
			'/backup/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'check_backup_permission' ),
			)
		);
	}

	/**
	 * Verifie les permissions pour les endpoints backup.
	 *
	 * Authentification 3 niveaux :
	 * 1. IP whitelist (si configuree)
	 * 2. HMAC-SHA256 (via classe Security existante)
	 * 3. Verification board en temps reel (cachee 1h)
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return bool|WP_Error True si autorise, WP_Error sinon.
	 */
	public function check_backup_permission( WP_REST_Request $request ) {
		// Niveau 1 : verification IP (gratuit, bloque vite).
		$ip_check = $this->check_ip_whitelist();
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		// Niveau 2 : verification HMAC (reutilise la classe Security existante).
		$hmac_check = $this->security->verify_request( $request );
		if ( is_wp_error( $hmac_check ) ) {
			return $hmac_check;
		}

		// Niveau 3 : verification board (cachee 1h, non-bloquant si board down).
		$board_check = $this->verify_with_board( $request );
		if ( is_wp_error( $board_check ) ) {
			return $board_check;
		}

		return true;
	}

	/**
	 * Delegue le scan au Scanner.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_scan( WP_REST_Request $request ) {
		$scanner = new WBoard_Connector_Backup_Scanner();

		return $scanner->handle( $request, $this->config );
	}

	/**
	 * Delegue la creation de ZIP au module Files.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_files( WP_REST_Request $request ) {
		$files = new WBoard_Connector_Backup_Files();

		return $files->handle( $request, $this->config );
	}

	/**
	 * Delegue le listing des tables au module DB.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_db_tables( WP_REST_Request $request ) {
		$db = new WBoard_Connector_Backup_Db();

		return $db->handle_tables( $request, $this->config );
	}

	/**
	 * Delegue l'export SQL au module DB.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_db_export( WP_REST_Request $request ) {
		$db = new WBoard_Connector_Backup_Db();

		return $db->handle_export( $request, $this->config );
	}

	/**
	 * Retourne le statut du module backup.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_status( WP_REST_Request $request ) {
		$disk_free = function_exists( 'disk_free_space' )
			? @disk_free_space( WP_CONTENT_DIR )
			: null;

		$compression = $this->detect_compression_method();

		return new WP_REST_Response(
			array(
				'enabled'            => true,
				'version'            => WBOARD_CONNECTOR_VERSION,
				'php_version'        => PHP_VERSION,
				'wp_content_dir'     => WP_CONTENT_DIR,
				'compression_method' => $compression,
				'disk_free_bytes'    => $disk_free,
				'max_execution_time' => $this->get_max_execution_time(),
				'memory_limit'       => $this->get_memory_limit_bytes(),
				'curl_available'     => function_exists( 'curl_init' ),
				'is_multisite'       => is_multisite(),
			),
			200
		);
	}

	/**
	 * Verifie si le module backup est active.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return ! empty( $this->config['enabled'] );
	}

	/**
	 * Charge la configuration backup depuis wp_options.
	 *
	 * Structure attendue :
	 * {
	 *   'enabled'     => true/false,
	 *   'allowed_ips' => ['212.x.x.x', '85.x.x.x'],
	 *   'manager_url' => 'https://backup-1.wabeo.work'
	 * }
	 *
	 * @return array La configuration backup.
	 */
	private function load_config() {
		$config = get_site_option(
			self::OPTION_BACKUP_CONFIG,
			array(
				'enabled'     => false,
				'allowed_ips' => array(),
				'manager_url' => '',
			)
		);

		return wp_parse_args(
			$config,
			array(
				'enabled'     => false,
				'allowed_ips' => array(),
				'manager_url' => '',
			)
		);
	}

	/**
	 * Verifie que l'IP de la requete est dans la whitelist.
	 *
	 * Si aucune IP n'est configuree, le check est ignore
	 * (permet le fonctionnement avant la premiere sync).
	 *
	 * @return bool|WP_Error True si OK.
	 */
	private function check_ip_whitelist() {
		$allowed_ips = $this->config['allowed_ips'];

		// Pas d'IP configurees = pas de filtrage IP.
		if ( empty( $allowed_ips ) ) {
			return true;
		}

		$client_ip = $this->get_client_ip();

		if ( ! in_array( $client_ip, $allowed_ips, true ) ) {
			return new WP_Error(
				'wboard_backup_ip_denied',
				__( 'IP non autorisee pour le backup.', 'wboard-connector' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Verifie aupres du board que le backup-manager est autorise.
	 *
	 * Le resultat est cache 1h. Si le board est injoignable,
	 * on laisse passer (les niveaux 1+2 suffisent).
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return bool|WP_Error True si OK.
	 */
	private function verify_with_board( WP_REST_Request $request ) {
		// La verification board est optionnelle au MVP.
		// Quand le board implementera l'endpoint /api/backup/verify,
		// on activera cette verification ici.
		// Pour l'instant, les niveaux 1 (IP) + 2 (HMAC) suffisent.
		return true;
	}

	/**
	 * Recupere l'adresse IP du client.
	 *
	 * Priorise REMOTE_ADDR car les headers HTTP sont spoofables.
	 * Supporte les reverse proxy courants (Cloudflare, etc.).
	 *
	 * @return string L'adresse IP.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'REMOTE_ADDR',
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
		);

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}

				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Detecte la meilleure methode de compression disponible.
	 *
	 * Ordre de preference :
	 * 1. ZipArchive (extension PHP native)
	 * 2. PclZip (bundle WordPress)
	 * 3. tar_gz (PHP pur, dernier recours)
	 *
	 * @return string Le nom de la methode.
	 */
	public function detect_compression_method() {
		if ( class_exists( 'ZipArchive' ) ) {
			return 'ziparchive';
		}

		// PclZip est toujours present dans WordPress.
		$pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
		if ( file_exists( $pclzip_path ) ) {
			return 'pclzip';
		}

		return 'tar_gz';
	}

	/**
	 * Retourne le max_execution_time effectif.
	 *
	 * @return int Secondes (0 = illimite).
	 */
	public function get_max_execution_time() {
		$max = (int) ini_get( 'max_execution_time' );

		return ( 0 === $max ) ? 0 : $max;
	}

	/**
	 * Retourne la memory_limit en bytes.
	 *
	 * @return int Bytes.
	 */
	public function get_memory_limit_bytes() {
		$limit = ini_get( 'memory_limit' );

		if ( '-1' === $limit ) {
			return -1;
		}

		return wp_convert_hr_to_bytes( $limit );
	}
}
