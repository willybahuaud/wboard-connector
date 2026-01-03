<?php
/**
 * Classe de collecte des données du site.
 *
 * Récupère les informations sur les versions, mises à jour,
 * sauvegardes et sécurité du site WordPress.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Collector
 *
 * Collecte toutes les données nécessaires pour le board.
 */
class WBoard_Connector_Collector {

	/**
	 * Retourne la version de WordPress.
	 *
	 * @return string Version de WordPress.
	 */
	public function get_wp_version() {
		return get_bloginfo( 'version' );
	}

	/**
	 * Retourne la version de PHP.
	 *
	 * @return string Version de PHP.
	 */
	public function get_php_version() {
		return PHP_VERSION;
	}

	/**
	 * Retourne les mises à jour disponibles.
	 *
	 * @return array Données sur les mises à jour core, plugins et thèmes.
	 */
	public function get_updates() {
		// Force la vérification des mises à jour si les données sont périmées.
		wp_update_plugins();
		wp_update_themes();

		$core_updates   = $this->get_core_updates();
		$plugin_updates = $this->get_plugin_updates();
		$theme_updates  = $this->get_theme_updates();

		return array(
			'core'    => count( $core_updates ),
			'plugins' => count( $plugin_updates ),
			'themes'  => count( $theme_updates ),
			'details' => array_merge(
				$core_updates,
				$plugin_updates,
				$theme_updates
			),
		);
	}

	/**
	 * Récupère les mises à jour du core WordPress disponibles.
	 *
	 * WordPress peut proposer plusieurs versions (branche actuelle + majeure).
	 * On ne retourne que la version la plus récente pour éviter les doublons.
	 *
	 * @return array Liste des mises à jour core (max 1 élément).
	 */
	private function get_core_updates() {
		$core_updates = get_site_transient( 'update_core' );

		if ( empty( $core_updates->updates ) || ! is_array( $core_updates->updates ) ) {
			return array();
		}

		$latest_version = null;

		foreach ( $core_updates->updates as $update ) {
			// Ignore la version actuelle et les versions de développement.
			if ( 'upgrade' !== $update->response ) {
				continue;
			}

			// Garde la version la plus récente.
			if ( null === $latest_version || version_compare( $update->current, $latest_version, '>' ) ) {
				$latest_version = $update->current;
			}
		}

		if ( null === $latest_version ) {
			return array();
		}

		return array(
			array(
				'type'            => 'core',
				'slug'            => 'wordpress',
				'name'            => 'WordPress',
				'current_version' => get_bloginfo( 'version' ),
				'new_version'     => $latest_version,
			),
		);
	}

	/**
	 * Récupère les mises à jour de plugins disponibles.
	 *
	 * @return array Liste des mises à jour plugins.
	 */
	private function get_plugin_updates() {
		$updates        = array();
		$plugin_updates = get_site_transient( 'update_plugins' );

		if ( ! empty( $plugin_updates->response ) && is_array( $plugin_updates->response ) ) {
			// Récupère les données de tous les plugins pour avoir les noms.
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all_plugins = get_plugins();

			foreach ( $plugin_updates->response as $plugin_file => $plugin_data ) {
				$plugin_name = isset( $all_plugins[ $plugin_file ]['Name'] )
					? $all_plugins[ $plugin_file ]['Name']
					: $plugin_data->slug;

				$current_version = isset( $all_plugins[ $plugin_file ]['Version'] )
					? $all_plugins[ $plugin_file ]['Version']
					: '';

				$updates[] = array(
					'type'            => 'plugin',
					'slug'            => $plugin_data->slug,
					'name'            => $plugin_name,
					'current_version' => $current_version,
					'new_version'     => $plugin_data->new_version,
				);
			}
		}

		return $updates;
	}

	/**
	 * Récupère les mises à jour de thèmes disponibles.
	 *
	 * @return array Liste des mises à jour thèmes.
	 */
	private function get_theme_updates() {
		$updates       = array();
		$theme_updates = get_site_transient( 'update_themes' );

		if ( ! empty( $theme_updates->response ) && is_array( $theme_updates->response ) ) {
			foreach ( $theme_updates->response as $theme_slug => $theme_data ) {
				$theme = wp_get_theme( $theme_slug );

				$updates[] = array(
					'type'            => 'theme',
					'slug'            => $theme_slug,
					'name'            => $theme->exists() ? $theme->get( 'Name' ) : $theme_slug,
					'current_version' => $theme->exists() ? $theme->get( 'Version' ) : '',
					'new_version'     => $theme_data['new_version'],
				);
			}
		}

		return $updates;
	}

	/**
	 * Retourne le statut des sauvegardes.
	 *
	 * Supporte Vivid Backup Pro et WPVivid Backup.
	 *
	 * @return array Données de sauvegarde.
	 */
	public function get_backup_status() {
		// Essaie d'abord Vivid Backup Pro.
		$vivid_status = $this->get_vivid_backup_status();
		if ( $vivid_status['provider'] !== 'none' ) {
			return $vivid_status;
		}

		// Fallback sur WPVivid.
		$wpvivid_status = $this->get_wpvivid_backup_status();
		if ( $wpvivid_status['provider'] !== 'none' ) {
			return $wpvivid_status;
		}

		// Aucun plugin de backup détecté.
		return array(
			'status'    => 'unknown',
			'last_date' => null,
			's3_url'    => null,
			'provider'  => 'none',
		);
	}

	/**
	 * Récupère le statut de Vivid Backup Pro.
	 *
	 * @return array Données de sauvegarde Vivid.
	 */
	private function get_vivid_backup_status() {
		// TODO: Implémenter après analyse du code source de Vivid Backup Pro.
		// Voir resources/vivid-backup-pro/ pour la structure des données.
		return array(
			'status'    => 'unknown',
			'last_date' => null,
			's3_url'    => null,
			'provider'  => 'none',
		);
	}

	/**
	 * Récupère le statut de WPVivid Backup.
	 *
	 * @return array Données de sauvegarde WPVivid.
	 */
	private function get_wpvivid_backup_status() {
		// TODO: Implémenter après analyse du code source de WPVivid.
		// Voir resources/wpvivid-backuprestore/ pour la structure des données.
		return array(
			'status'    => 'unknown',
			'last_date' => null,
			's3_url'    => null,
			'provider'  => 'none',
		);
	}

	/**
	 * Retourne le statut de sécurité.
	 *
	 * Supporte SecuPress Pro.
	 *
	 * @return array Données de sécurité.
	 */
	public function get_security_status() {
		// TODO: Implémenter après analyse du code source de SecuPress.
		// Voir resources/secupress/ pour la structure des données.
		return array(
			'provider' => 'none',
			'score'    => null,
			'issues'   => array(),
		);
	}

	/**
	 * Retourne la liste des utilisateurs administrateurs.
	 *
	 * @return array Liste des administrateurs.
	 */
	public function get_admin_users() {
		$admin_users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$users = array();
		foreach ( $admin_users as $user ) {
			$users[] = array(
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'display_name' => $user->display_name,
				'role'         => 'administrator',
			);
		}

		return $users;
	}
}
