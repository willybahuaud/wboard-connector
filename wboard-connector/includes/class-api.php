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
		add_filter( 'rest_authentication_errors', array( $this, 'bypass_core_auth_errors' ), 999 );
	}

	/**
	 * Neutralise les erreurs d'authentification core pour les requêtes WBoard.
	 *
	 * Sur les sites protégés par htpasswd, Apache peut transmettre le header
	 * "Authorization: Basic" à PHP. WordPress l'interprète alors comme une
	 * tentative d'Application Password et rejette la requête en 401
	 * (invalid_username) avant même d'atteindre nos routes. Les requêtes du
	 * board étant authentifiées par signature HMAC (permission_callback),
	 * on écarte ici l'erreur d'auth core pour ne pas les bloquer.
	 *
	 * @param WP_Error|true|null $result Résultat d'authentification courant.
	 *
	 * @return WP_Error|true|null
	 */
	public function bypass_core_auth_errors( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $this->is_wboard_request() ) {
			return $result;
		}

		// Uniquement si la requête porte une signature HMAC : la vérification
		// réelle reste assurée par check_permission() sur chaque route.
		if ( empty( $_SERVER['HTTP_X_WBOARD_SIGNATURE'] ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Détermine si la requête courante cible l'API WBoard.
	 *
	 * @return bool
	 */
	private function is_wboard_request() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$rest_route  = isset( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : '';

		return false !== strpos( $request_uri, self::API_NAMESPACE )
			|| false !== strpos( $rest_route, self::API_NAMESPACE );
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

		// GET /wboard/v1/backup-credentials - Récupère les credentials de backup distant.
		register_rest_route(
			self::API_NAMESPACE,
			'/backup-credentials',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_backup_credentials' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// POST /wboard/v1/update-component - Met à jour un plugin, thème ou le core.
		register_rest_route(
			self::API_NAMESPACE,
			'/update-component',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_component' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// POST /wboard/v1/test-session - Crée une session de test courte durée.
		register_rest_route(
			self::API_NAMESPACE,
			'/test-session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_test_session' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// POST /wboard/v1/destroy-sessions - Détruit les sessions d'utilisateurs.
		register_rest_route(
			self::API_NAMESPACE,
			'/destroy-sessions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'destroy_sessions' ),
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
			'debug'          => $collector->get_debug_status(),
			'multisite'      => WBoard_Connector_Multisite::get_multisite_info(),
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
	 * Récupère la configuration de backup distant (sans secrets).
	 *
	 * Retourne le type de stockage, le bucket et le path.
	 * Les clés API ne sont jamais exposées.
	 *
	 * @param WP_REST_Request $request La requête REST.
	 *
	 * @return WP_REST_Response|WP_Error La config ou une erreur.
	 */
	public function get_backup_credentials( WP_REST_Request $request ) {
		$collector = new WBoard_Connector_Collector();
		$config    = $collector->get_backup_remote_config();

		if ( null === $config ) {
			return new WP_Error(
				'wboard_no_remote_config',
				__( 'Aucun stockage distant configuré.', 'wboard-connector' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $config, 200 );
	}

	/**
	 * Met à jour un plugin, un thème ou le core à distance.
	 *
	 * Reçoit le type (plugin/theme/core) et le slug du composant,
	 * puis délègue à WBoard_Connector_Remote_Updater.
	 *
	 * @param WP_REST_Request $request La requête REST avec type et slug.
	 *
	 * @return WP_REST_Response Résultat de la mise à jour.
	 */
	public function update_component( WP_REST_Request $request ) {
		$body = json_decode( $request->get_body(), true );
		$type = isset( $body['type'] ) ? sanitize_text_field( $body['type'] ) : '';
		$slug = isset( $body['slug'] ) ? sanitize_text_field( $body['slug'] ) : '';

		// Validation des paramètres.
		if ( empty( $type ) || empty( $slug ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'code'    => 'missing_params',
					'message' => 'Les paramètres "type" et "slug" sont requis.',
				),
				400
			);
		}

		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'code'    => 'invalid_type',
					'message' => 'Le type doit être "plugin", "theme" ou "core".',
				),
				400
			);
		}

		// Validation stricte du slug : alphanum, tirets, pas de path traversal.
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'code'    => 'invalid_slug',
					'message' => 'Le slug contient des caractères invalides.',
				),
				400
			);
		}

		$updater = new WBoard_Connector_Remote_Updater();

		if ( 'core' === $type ) {
			$result = $updater->update_core();
		} elseif ( 'plugin' === $type ) {
			$result = $updater->update_plugin( $slug );
		} else {
			$result = $updater->update_theme( $slug );
		}

		$http_status = 'success' === $result['status'] ? 200 : 500;

		return new WP_REST_Response( $result, $http_status );
	}

	/**
	 * Crée une session de test courte durée.
	 *
	 * Génère un token temporaire pour un utilisateur (any role).
	 * Utilisé par BackstopJS pour naviguer en mode authentifié.
	 *
	 * @param WP_REST_Request $request La requête REST contenant l'ID utilisateur.
	 *
	 * @return WP_REST_Response|WP_Error L'URL de connexion ou une erreur.
	 */
	public function create_test_session( WP_REST_Request $request ) {
		$test_session = new WBoard_Connector_Test_Session();

		$body    = json_decode( $request->get_body(), true );
		$user_id = isset( $body['user_id'] ) ? (int) $body['user_id'] : 0;

		if ( empty( $user_id ) ) {
			return new WP_Error(
				'wboard_missing_user_id',
				__( 'ID utilisateur requis.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		$result = $test_session->generate_token( $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Détruit les sessions de plusieurs utilisateurs.
	 *
	 * Appelé après un test visuel pour nettoyer les sessions
	 * de test courte durée.
	 *
	 * @param WP_REST_Request $request La requête REST avec les user_ids.
	 *
	 * @return WP_REST_Response Résultat de la destruction.
	 */
	public function destroy_sessions( WP_REST_Request $request ) {
		$body     = json_decode( $request->get_body(), true );
		$user_ids = isset( $body['user_ids'] ) ? array_map( 'intval', (array) $body['user_ids'] ) : array();

		if ( empty( $user_ids ) ) {
			return new WP_Error(
				'wboard_missing_user_ids',
				__( 'Liste d\'IDs utilisateurs requise.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		$test_session = new WBoard_Connector_Test_Session();
		$destroyed    = 0;

		foreach ( $user_ids as $user_id ) {
			$user = get_user_by( 'ID', $user_id );
			if ( $user ) {
				$test_session->destroy_user_sessions( $user_id );
				$destroyed++;
			}
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'destroyed' => $destroyed,
			),
			200
		);
	}
}
