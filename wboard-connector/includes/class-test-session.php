<?php
/**
 * Classe de gestion des sessions de test courte duree.
 *
 * Genere des tokens temporaires pour permettre des sessions
 * authentifiees lors des tests visuels BackstopJS.
 * Contrairement a l'auto-login, pas de restriction de role admin.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Test_Session
 *
 * Gere la creation, validation et destruction des sessions de test.
 */
class WBoard_Connector_Test_Session {

	/**
	 * Duree de validite du token en secondes.
	 *
	 * @var int
	 */
	const TOKEN_EXPIRATION = 30;

	/**
	 * Duree du cookie d'authentification en secondes (15 minutes).
	 *
	 * @var int
	 */
	const COOKIE_EXPIRATION = 900;

	/**
	 * Nom du parametre URL pour le token.
	 *
	 * @var string
	 */
	const TOKEN_PARAM = 'wboard_test_token';

	/**
	 * Prefixe pour le transient stockant le token.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wboard_test_session_';

	/**
	 * Genere un token de session de test pour un utilisateur.
	 *
	 * Pas de verification de role : tout utilisateur WordPress
	 * valide peut etre utilise pour les sessions de test.
	 *
	 * @param int $user_id ID de l'utilisateur WordPress.
	 *
	 * @return array|WP_Error Donnees de connexion ou erreur.
	 */
	public function generate_token( $user_id ) {
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new WP_Error(
				'wboard_user_not_found',
				__( 'Utilisateur non trouvé.', 'wboard-connector' ),
				array( 'status' => 404 )
			);
		}

		// Genere un token unique.
		$token = wp_generate_password( 32, false );

		// Stocke le token avec l'ID utilisateur.
		$transient_key = self::TRANSIENT_PREFIX . $token;
		set_transient( $transient_key, $user_id, self::TOKEN_EXPIRATION );

		// Construit l'URL de connexion.
		$login_url = add_query_arg( self::TOKEN_PARAM, $token, home_url( '/' ) );

		// Calcule l'expiration.
		$expires_at = gmdate( 'c', time() + self::TOKEN_EXPIRATION );

		return array(
			'success'    => true,
			'login_url'  => $login_url,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Verifie et consomme un token de session de test.
	 *
	 * @param string $token Le token a verifier.
	 *
	 * @return int|false L'ID utilisateur si valide, false sinon.
	 */
	public function verify_token( $token ) {
		$transient_key = self::TRANSIENT_PREFIX . $token;
		$user_id       = get_transient( $transient_key );

		if ( $user_id ) {
			// Supprime le token immediatement apres utilisation.
			delete_transient( $transient_key );

			return (int) $user_id;
		}

		return false;
	}

	/**
	 * Detruit toutes les sessions d'un utilisateur.
	 *
	 * @param int $user_id ID de l'utilisateur.
	 *
	 * @return void
	 */
	public function destroy_user_sessions( $user_id ) {
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();
	}

	/**
	 * Enregistre les hooks WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'handle_test_session_request' ) );
	}

	/**
	 * Intercepte et traite les requetes de session de test.
	 *
	 * Pose un cookie courte duree (15 min) au lieu de la duree standard.
	 *
	 * @return void
	 */
	public function handle_test_session_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token securise par transient.
		if ( ! isset( $_GET[ self::TOKEN_PARAM ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token   = sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_PARAM ] ) );
		$user_id = $this->verify_token( $token );

		if ( ! $user_id ) {
			wp_die(
				esc_html__( 'Token invalide ou expiré.', 'wboard-connector' ),
				esc_html__( 'Erreur d\'authentification', 'wboard-connector' ),
				array( 'response' => 401 )
			);
		}

		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			wp_die(
				esc_html__( 'Impossible de connecter l\'utilisateur.', 'wboard-connector' ),
				esc_html__( 'Erreur d\'authentification', 'wboard-connector' ),
				array( 'response' => 500 )
			);
		}

		// Pose le filtre pour un cookie courte duree AVANT wp_set_auth_cookie().
		add_filter( 'auth_cookie_expiration', array( $this, 'short_cookie_expiration' ) );

		// Nettoie la session actuelle.
		wp_clear_auth_cookie();

		// Connecte l'utilisateur.
		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id, false );

		// Retire le filtre apres usage.
		remove_filter( 'auth_cookie_expiration', array( $this, 'short_cookie_expiration' ) );

		// Declenche l'action de connexion.
		do_action( 'wp_login', $user->user_login, $user );

		// Redirige vers la page d'accueil.
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Callback pour le filtre auth_cookie_expiration.
	 *
	 * Retourne une duree courte (15 minutes) pour les sessions de test.
	 *
	 * @return int Duree en secondes.
	 */
	public function short_cookie_expiration() {
		return self::COOKIE_EXPIRATION;
	}
}
