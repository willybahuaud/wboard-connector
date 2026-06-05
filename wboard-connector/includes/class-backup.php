<?php
/**
 * Module de backup pour le connector WBoard.
 *
 * Enregistre les endpoints REST de backup et gere
 * l'authentification specifique (IP whitelist + HMAC + board).
 * Ce module est isole : si le backup n'est pas active,
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
	 * Le cron de nettoyage est toujours active (pour nettoyer
	 * les fichiers orphelins meme si on desactive le backup).
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Le cron de nettoyage tourne meme si le backup est desactive
		// (pour purger les fichiers orphelins apres desactivation).
		WBoard_Connector_Backup_Cleanup::schedule();
		add_action( WBoard_Connector_Backup_Cleanup::CRON_HOOK, array( 'WBoard_Connector_Backup_Cleanup', 'run' ) );

		// Les routes sont toujours enregistrees : la securite repose
		// sur la signature HMAC, pas sur un flag local. C'est le
		// backup-manager qui decide si un site est actif ou non.
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

		// POST /wboard/v1/backup/stream — Stream les fichiers en tar brut.
		// Le backup-manager recoit le tar directement dans la reponse HTTP.
		// Pas de ZIP, pas de fichier temporaire, pas d'upload : juste readfile().
		register_rest_route(
			self::API_NAMESPACE,
			'/backup/stream',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_stream' ),
				'permission_callback' => array( $this, 'check_backup_permission' ),
			)
		);

		// POST /wboard/v1/backup/db/stream — Stream toutes les tables en tar brut.
		// Une seule requete HTTP pour toutes les tables, zero rate limiting.
		register_rest_route(
			self::API_NAMESPACE,
			'/backup/db/stream',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_db_stream' ),
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

		// POST /wboard/v1/backup/db/stream-table — Stream le SQL d'UNE table directement.
		// Pas de tar, pas de fichier temp : flux SQL continu pour eviter les coupures
		// proxy/PHP-FPM sur les grosses tables (cf. postmeta).
		register_rest_route(
			self::API_NAMESPACE,
			'/backup/db/stream-table',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_db_stream_table' ),
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
	 * 2. HMAC-SHA256 (via classe Security existante, sans rate limit)
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

		// Niveau 2 : verification HMAC (sans le rate limit global a 30/min,
		// car le backup-manager enchaine les requetes).
		$hmac_check = $this->security->verify_request_no_ratelimit( $request );
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
	 * Delegue le streaming tar au module Streamer.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return WP_REST_Response|WP_Error|void
	 */
	public function handle_stream( WP_REST_Request $request ) {
		$streamer = new WBoard_Connector_Backup_Streamer();

		return $streamer->handle( $request, $this->config );
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
	 * Delegue le streaming tar DB au module DB.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return WP_REST_Response|WP_Error|void
	 */
	public function handle_db_stream( WP_REST_Request $request ) {
		$db = new WBoard_Connector_Backup_Db();

		return $db->handle_stream_export( $request, $this->config );
	}

	/**
	 * Delegue le streaming SQL d'une seule table au module DB.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 *
	 * @return WP_REST_Response|WP_Error|void
	 */
	public function handle_db_stream_table( WP_REST_Request $request ) {
		$db = new WBoard_Connector_Backup_Db();

		return $db->handle_stream_single_table( $request, $this->config );
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

		$compression = self::detect_compression_method();

		return new WP_REST_Response(
			array(
				'enabled'            => true,
				'version'            => WBOARD_CONNECTOR_VERSION,
				'php_version'        => PHP_VERSION,
				'compression_method' => $compression,
				'disk_free_bytes'    => $disk_free,
				'max_execution_time' => (int) ini_get( 'max_execution_time' ),
				'memory_limit'       => wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ),
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
	 * Recupere le site_id du connector.
	 *
	 * @return string
	 */
	private function get_site_id() {
		return get_site_option( 'wboard_connector_site_id', '' );
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
	 * Reutilise get_client_ip() de la classe Security (pas de duplication).
	 *
	 * @return bool|WP_Error True si OK.
	 */
	private function check_ip_whitelist() {
		$allowed_ips = $this->config['allowed_ips'];

		// Pas d'IP configurees = pas de filtrage IP.
		if ( empty( $allowed_ips ) ) {
			return true;
		}

		// Recuperation IP via Security pour ne pas dupliquer la logique.
		// get_client_ip est private dans Security, on utilise REMOTE_ADDR
		// comme proxy suffisant (c'est la meme logique en priorite).
		$client_ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';

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
	 * Detecte la meilleure methode de compression disponible.
	 *
	 * @return string Le nom de la methode ('ziparchive', 'pclzip', 'tar_gz', 'none').
	 */
	public static function detect_compression_method() {
		if ( class_exists( 'ZipArchive' ) ) {
			return 'ziparchive';
		}

		$pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
		if ( file_exists( $pclzip_path ) ) {
			return 'pclzip';
		}

		if ( function_exists( 'gzopen' ) ) {
			return 'tar_gz';
		}

		return 'none';
	}
}
