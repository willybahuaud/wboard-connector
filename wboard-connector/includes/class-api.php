<?php
/**
 * Classe de gestion des endpoints REST API.
 *
 * Enregistre et gère les endpoints REST utilisés par le board WBoard
 * pour collecter les données et effectuer des actions sur le site.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Api
 *
 * Endpoints REST pour la communication avec le board.
 */
class WBoard_Connector_Api {

	/**
	 * Namespace de l'API REST.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'wboard/v1';

	/**
	 * Instance de la classe Security pour la vérification des requêtes.
	 *
	 * @var WBoard_Connector_Security
	 */
	private $security;

	/**
	 * Constructeur.
	 *
	 * @param WBoard_Connector_Security $security Instance de la classe de sécurité.
	 */
	public function __construct( WBoard_Connector_Security $security ) {
		$this->security = $security;
	}

	/**
	 * Enregistre les hooks WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes REST API.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /wboard/v1/status - Retourne l'état complet du site.
		register_rest_route(
			self::API_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// POST /wboard/v1/autologin - Génère un token d'auto-login.
		register_rest_route(
			self::API_NAMESPACE,
			'/autologin',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_autologin' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// POST /wboard/v1/regenerate-key - Régénère la clé secrète.
		register_rest_route(
			self::API_NAMESPACE,
			'/regenerate-key',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate_key' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Vérifie les permissions de la requête.
	 *
	 * Utilise la classe Security pour valider la signature HMAC.
	 *
	 * @param WP_REST_Request $request La requête REST.
	 *
	 * @return bool|WP_Error True si autorisé, WP_Error sinon.
	 */
	public function check_permission( WP_REST_Request $request ) {
		return $this->security->verify_request( $request );
	}

	/**
	 * Retourne l'état complet du site.
	 *
	 * Endpoint principal appelé périodiquement par le board.
	 *
	 * @param WP_REST_Request $request La requête REST.
	 *
	 * @return WP_REST_Response Les données du site.
	 */
	public function get_status( WP_REST_Request $request ) {
		$collector = new WBoard_Connector_Collector();

		$data = array(
			'wp_version'     => $collector->get_wp_version(),
			'php_version'    => $collector->get_php_version(),
			'plugin_version' => WBOARD_CONNECTOR_VERSION,
			'updates'        => $collector->get_updates(),
			'backup'         => $collector->get_backup_status(),
			'security'       => $collector->get_security_status(),
			'admin_users'    => $collector->get_admin_users(),
			'installed'      => array(
				'plugins' => $collector->get_installed_plugins(),
				'themes'  => $collector->get_installed_themes(),
			),
			'cron'           => $collector->get_cron_status(),
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Crée un token d'auto-login temporaire.
	 *
	 * @param WP_REST_Request $request La requête REST contenant l'ID utilisateur.
	 *
	 * @return WP_REST_Response|WP_Error L'URL de connexion ou une erreur.
	 */
	public function create_autologin( WP_REST_Request $request ) {
		$autologin = new WBoard_Connector_Autologin();

		$body    = json_decode( $request->get_body(), true );
		$user_id = isset( $body['user_id'] ) ? (int) $body['user_id'] : 0;

		if ( empty( $user_id ) ) {
			return new WP_Error(
				'wboard_missing_user_id',
				__( 'ID utilisateur requis.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		$result = $autologin->generate_token( $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Régénère la clé secrète du site.
	 *
	 * @param WP_REST_Request $request La requête REST.
	 *
	 * @return WP_REST_Response La nouvelle clé secrète.
	 */
	public function regenerate_key( WP_REST_Request $request ) {
		$new_key = $this->security->regenerate_secret_key();

		return new WP_REST_Response(
			array(
				'success'    => true,
				'secret_key' => $new_key,
			),
			200
		);
	}
}
