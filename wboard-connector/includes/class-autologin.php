<?php
/**
 * Classe de gestion de l'auto-login.
 *
 * Génère des tokens temporaires pour permettre une connexion
 * sécurisée depuis le board WBoard.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Autologin
 *
 * Gère la création et validation des tokens d'auto-login.
 */
class WBoard_Connector_Autologin {

	/**
	 * Durée de validité du token en secondes.
	 *
	 * @var int
	 */
	const TOKEN_EXPIRATION = 30;

	/**
	 * Nom du paramètre URL pour le token.
	 *
	 * @var string
	 */
	const TOKEN_PARAM = 'wboard_token';

	/**
	 * Préfixe pour le transient stockant le token.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wboard_autologin_';

	/**
	 * Génère un token d'auto-login pour un utilisateur.
	 *
	 * En multisite, seuls les super admins peuvent utiliser l'auto-login.
	 * En mono-site, les administrateurs peuvent l'utiliser.
	 *
	 * @param int $user_id ID de l'utilisateur WordPress.
	 *
	 * @return array|WP_Error Données de connexion ou erreur.
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

		// Vérifie les permissions selon le contexte.
		if ( ! $this->user_can_autologin( $user_id ) ) {
			$message = WBoard_Connector_Multisite::is_multisite()
				? __( 'Seuls les super admins peuvent utiliser l\'auto-login.', 'wboard-connector' )
				: __( 'Seuls les administrateurs peuvent utiliser l\'auto-login.', 'wboard-connector' );

			return new WP_Error(
				'wboard_not_admin',
				$message,
				array( 'status' => 403 )
			);
		}

		// Génère un token unique.
		$token = wp_generate_password( 32, false );

		// Stocke le token avec l'ID utilisateur.
		$transient_key = self::TRANSIENT_PREFIX . $token;
		set_transient( $transient_key, $user_id, self::TOKEN_EXPIRATION );

		// Construit l'URL de connexion.
		$login_url = add_query_arg( self::TOKEN_PARAM, $token, home_url( '/' ) );

		// Calcule l'expiration.
		$expires_at = gmdate( 'c', time() + self::TOKEN_EXPIRATION );

		// Détermine l'URL de redirection.
		$redirect_url = WBoard_Connector_Multisite::is_multisite()
			? network_admin_url()
			: admin_url();

		return array(
			'success'      => true,
			'login_url'    => $login_url,
			'expires_at'   => $expires_at,
			'redirect_url' => $redirect_url,
		);
	}

	/**
	 * Vérifie si un utilisateur peut utiliser l'auto-login.
	 *
	 * En multisite : doit être super admin.
	 * En mono-site : doit être administrateur.
	 *
	 * @param int $user_id ID de l'utilisateur.
	 *
	 * @return bool True si autorisé.
	 */
	private function user_can_autologin( $user_id ) {
		if ( WBoard_Connector_Multisite::is_multisite() ) {
			return WBoard_Connector_Multisite::is_user_super_admin( $user_id );
		}

		return user_can( $user_id, 'administrator' );
	}

	/**
	 * Vérifie et consomme un token d'auto-login.
	 *
	 * @param string $token Le token à vérifier.
	 *
	 * @return int|false L'ID utilisateur si valide, false sinon.
	 */
	public function verify_token( $token ) {
		$transient_key = self::TRANSIENT_PREFIX . $token;
		$user_id       = get_transient( $transient_key );

		if ( $user_id ) {
			// Supprime le token immédiatement après utilisation.
			delete_transient( $transient_key );

			return (int) $user_id;
		}

		return false;
	}

	/**
	 * Connecte un utilisateur via son ID.
	 *
	 * @param int $user_id ID de l'utilisateur.
	 *
	 * @return bool True si connexion réussie.
	 */
	public function login_user( $user_id ) {
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return false;
		}

		// Nettoie la session actuelle.
		wp_clear_auth_cookie();

		// Connecte l'utilisateur.
		wp_set_current_user( $user_id, $user->user_login );
		wp_set_auth_cookie( $user_id, false );

		// Déclenche l'action de connexion.
		do_action( 'wp_login', $user->user_login, $user );

		return true;
	}

	/**
	 * Enregistre le hook pour intercepter les tokens.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'handle_autologin_request' ) );
	}

	/**
	 * Intercepte et traite les requêtes d'auto-login.
	 *
	 * En multisite, redirige vers le Network Admin.
	 *
	 * @return void
	 */
	public function handle_autologin_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token sécurisé par transient.
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

		// Connecte l'utilisateur.
		if ( ! $this->login_user( $user_id ) ) {
			wp_die(
				esc_html__( 'Impossible de connecter l\'utilisateur.', 'wboard-connector' ),
				esc_html__( 'Erreur d\'authentification', 'wboard-connector' ),
				array( 'response' => 500 )
			);
		}

		// Redirige vers le tableau de bord approprié.
		$redirect_url = WBoard_Connector_Multisite::is_multisite()
			? network_admin_url()
			: admin_url();

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
