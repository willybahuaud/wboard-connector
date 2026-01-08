<?php
/**
 * Classe de gestion de la page de réglages.
 *
 * Crée et gère la page d'administration du plugin.
 * En multisite, la page est dans le Network Admin.
 * En mono-site, la page est dans Réglages.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Settings
 *
 * Page de réglages du plugin dans l'admin WordPress.
 */
class WBoard_Connector_Settings {

	/**
	 * Slug de la page de réglages.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wboard-connector';

	/**
	 * Groupe d'options pour l'API Settings.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'wboard_connector_options';

	/**
	 * Enregistre les hooks WordPress.
	 *
	 * En multisite, la page est ajoutée au Network Admin.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( WBoard_Connector_Multisite::is_multisite() ) {
			// Multisite : page dans le Network Admin uniquement.
			add_action( 'network_admin_menu', array( $this, 'add_network_settings_page' ) );
		} else {
			// Mono-site : page dans Réglages.
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		}

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_wboard_regenerate_key', array( $this, 'ajax_regenerate_key' ) );
	}

	/**
	 * Ajoute la page de réglages dans le menu (mono-site).
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'WBoard Connector', 'wboard-connector' ),
			__( 'WBoard', 'wboard-connector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Ajoute la page de réglages dans le Network Admin (multisite).
	 *
	 * @return void
	 */
	public function add_network_settings_page() {
		add_submenu_page(
			'settings.php',
			__( 'WBoard Connector', 'wboard-connector' ),
			__( 'WBoard', 'wboard-connector' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enregistre les paramètres du plugin.
	 *
	 * @return void
	 */
	public function register_settings() {
		// Pas de paramètres à enregistrer pour l'instant.
		// Les mises à jour passent par GitHub Releases.
	}

	/**
	 * Charge les assets CSS/JS de la page admin.
	 *
	 * Gère les deux contextes : mono-site et network admin.
	 *
	 * @param string $hook Hook de la page actuelle.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Le hook diffère selon le contexte.
		// Mono-site : 'settings_page_wboard-connector'
		// Network admin : 'settings_page_wboard-connector' (même pattern).
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wboard-connector-admin',
			WBOARD_CONNECTOR_URL . 'admin/css/admin.css',
			array(),
			WBOARD_CONNECTOR_VERSION
		);

		wp_enqueue_script(
			'wboard-connector-admin',
			WBOARD_CONNECTOR_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			WBOARD_CONNECTOR_VERSION,
			true
		);

		// En multisite, l'AJAX doit passer par le network admin.
		$ajax_url = WBoard_Connector_Multisite::is_multisite()
			? network_admin_url( 'admin-ajax.php' )
			: admin_url( 'admin-ajax.php' );

		wp_localize_script(
			'wboard-connector-admin',
			'wboardConnector',
			array(
				'ajaxUrl' => $ajax_url,
				'nonce'   => wp_create_nonce( 'wboard_connector_admin' ),
				'strings' => array(
					'confirmRegenerate' => __( 'Êtes-vous sûr de vouloir régénérer la clé secrète ? L\'ancienne clé ne sera plus valide.', 'wboard-connector' ),
					'copied'            => __( 'Copié !', 'wboard-connector' ),
					'error'             => __( 'Une erreur est survenue.', 'wboard-connector' ),
				),
			)
		);
	}

	/**
	 * Affiche la page de réglages.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! $this->current_user_can_manage() ) {
			return;
		}

		require_once WBOARD_CONNECTOR_PATH . 'admin/settings-page.php';
	}

	/**
	 * Gère la requête AJAX de régénération de clé.
	 *
	 * @return void
	 */
	public function ajax_regenerate_key() {
		check_ajax_referer( 'wboard_connector_admin', 'nonce' );

		if ( ! $this->current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'wboard-connector' ) ) );
		}

		$security = new WBoard_Connector_Security();
		$new_key  = $security->regenerate_secret_key();

		wp_send_json_success(
			array(
				'key'     => $new_key,
				'message' => __( 'Clé secrète régénérée avec succès.', 'wboard-connector' ),
			)
		);
	}

	/**
	 * Vérifie si l'utilisateur courant peut gérer le plugin.
	 *
	 * En multisite : doit être super admin.
	 * En mono-site : doit avoir la capacité manage_options.
	 *
	 * @return bool True si l'utilisateur a les droits.
	 */
	private function current_user_can_manage() {
		if ( WBoard_Connector_Multisite::is_multisite() ) {
			return is_super_admin();
		}

		return current_user_can( 'manage_options' );
	}
}
