<?php
/**
 * Politique de mises a jour automatiques.
 *
 * Le board pilote les mises a jour de la flotte : les auto-updates
 * WordPress sont donc coupees, a deux exceptions pres :
 * - les minor core (security releases x.y.z) restent en auto ;
 * - le connector lui-meme s'auto-update via GitHub Releases.
 *
 * Les emails de notification de mise a jour sont coupes en permanence :
 * ils partent vers l'admin_email (souvent le client) alors que le suivi
 * des versions se fait cote board.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applique la politique de mises a jour automatiques du parc.
 */
class WBoard_Connector_Update_Policy {

	/**
	 * Priorite haute pour passer apres les reglages du site
	 * (toggles d'auto-update actives dans l'admin, constantes, etc.).
	 */
	const FILTER_PRIORITY = 100;

	/**
	 * Enregistre les filtres de la politique.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Emails de mise a jour : coupes en permanence.
		add_filter( 'auto_core_update_send_email', '__return_false', self::FILTER_PRIORITY );
		add_filter( 'auto_plugin_update_send_email', '__return_false', self::FILTER_PRIORITY );
		add_filter( 'auto_theme_update_send_email', '__return_false', self::FILTER_PRIORITY );
		add_filter( 'send_core_update_notification_email', '__return_false', self::FILTER_PRIORITY );

		// Auto-updates plugins/themes : coupees (sauf le connector).
		add_filter( 'auto_update_plugin', array( $this, 'filter_plugin_auto_update' ), self::FILTER_PRIORITY, 2 );
		add_filter( 'auto_update_theme', '__return_false', self::FILTER_PRIORITY );

		// Core : uniquement les minor (security releases), jamais les majors.
		add_filter( 'allow_minor_auto_core_updates', '__return_true', self::FILTER_PRIORITY );
		add_filter( 'allow_major_auto_core_updates', '__return_false', self::FILTER_PRIORITY );
		add_filter( 'allow_dev_auto_core_updates', '__return_false', self::FILTER_PRIORITY );
	}

	/**
	 * Autorise l'auto-update du connector uniquement.
	 *
	 * @param bool|null $update Autorisation courante de l'auto-update.
	 * @param object    $item   Element de mise a jour (contient ->plugin).
	 *
	 * @return bool
	 */
	public function filter_plugin_auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && WBOARD_CONNECTOR_BASENAME === $item->plugin ) {
			return true;
		}

		return false;
	}
}
