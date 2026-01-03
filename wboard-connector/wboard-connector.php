<?php
/**
 * Plugin Name: WBoard Connector
 * Plugin URI: https://github.com/wboard/connector
 * Description: Connecteur pour WBoard - Permet la supervision centralisée du site WordPress.
 * Version: 1.0.3
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Willy bahuaud
 * Author URI: https://wabeo.fr
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wboard-connector
 * Domain Path: /languages
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Constantes du plugin.
 */
define( 'WBOARD_CONNECTOR_VERSION', '1.0.3' );
define( 'WBOARD_CONNECTOR_FILE', __FILE__ );
define( 'WBOARD_CONNECTOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WBOARD_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'WBOARD_CONNECTOR_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader des classes du plugin.
 *
 * @param string $class_name Nom de la classe à charger.
 *
 * @return void
 */
function wboard_connector_autoloader( $class_name ) {
	// Vérifie que la classe appartient au namespace du plugin.
	if ( strpos( $class_name, 'WBoard_Connector_' ) !== 0 ) {
		return;
	}

	// Convertit le nom de classe en nom de fichier.
	$class_file = str_replace( 'WBoard_Connector_', '', $class_name );
	$class_file = strtolower( str_replace( '_', '-', $class_file ) );
	$file_path  = WBOARD_CONNECTOR_PATH . 'includes/class-' . $class_file . '.php';

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}
spl_autoload_register( 'wboard_connector_autoloader' );

/**
 * Initialise le plugin.
 *
 * Hook sur plugins_loaded pour s'assurer que WordPress est complètement chargé.
 *
 * @return void
 */
function wboard_connector_init() {
	// Charge les classes principales.
	$security  = new WBoard_Connector_Security();
	$api       = new WBoard_Connector_Api( $security );
	$settings  = new WBoard_Connector_Settings();
	$autologin = new WBoard_Connector_Autologin();
	$updater   = new WBoard_Connector_Updater();

	// Enregistre les hooks.
	$api->register_hooks();
	$settings->register_hooks();
	$autologin->register_hooks();
	$updater->register_hooks();
}
add_action( 'plugins_loaded', 'wboard_connector_init' );

/**
 * Actions à l'activation du plugin.
 *
 * Génère une clé secrète si elle n'existe pas.
 *
 * @return void
 */
function wboard_connector_activate() {
	// Génère la clé secrète si elle n'existe pas.
	if ( ! get_option( 'wboard_connector_secret_key' ) ) {
		$secret_key = wp_generate_password( 64, true, true );
		update_option( 'wboard_connector_secret_key', $secret_key );
	}

	// Stocke la date d'installation.
	if ( ! get_option( 'wboard_connector_installed_at' ) ) {
		update_option( 'wboard_connector_installed_at', current_time( 'mysql' ) );
	}
}
register_activation_hook( __FILE__, 'wboard_connector_activate' );

/**
 * Actions à la désactivation du plugin.
 *
 * Nettoie les transients mais conserve les options pour une éventuelle réactivation.
 *
 * @return void
 */
function wboard_connector_deactivate() {
	// Supprime les transients liés au plugin.
	delete_transient( 'wboard_connector_last_request' );
}
register_deactivation_hook( __FILE__, 'wboard_connector_deactivate' );
