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
	 * Supporte WPVivid Backup (gratuit) et WPVivid Backup Pro.
	 *
	 * @return array Données de sauvegarde WPVivid.
	 */
	private function get_wpvivid_backup_status() {
		// Vérifie si WPVivid est actif.
		$is_free_active = is_plugin_active( 'wpvivid-backuprestore/wpvivid-backuprestore.php' );
		$is_pro_active  = is_plugin_active( 'wpvivid-backup-pro/wpvivid-backup-pro.php' );

		if ( ! $is_free_active && ! $is_pro_active ) {
			return array(
				'status'    => 'unknown',
				'last_date' => null,
				's3_url'    => null,
				'provider'  => 'none',
			);
		}

		$provider = $is_pro_active ? 'wpvivid_pro' : 'wpvivid';

		// Récupère les infos de planification.
		$schedule_info = $this->get_wpvivid_schedule_info();

		// Récupère le dernier backup.
		$last_backup = $this->get_wpvivid_last_backup();

		// Détermine le statut global.
		$status = $this->determine_wpvivid_status( $schedule_info, $last_backup );

		return array(
			'status'         => $status,
			'last_date'      => $last_backup['date'] ?? null,
			's3_url'         => $last_backup['remote_url'] ?? null,
			'provider'       => $provider,
			'schedule'       => $schedule_info,
			'remote_type'    => $last_backup['remote_type'] ?? null,
		);
	}

	/**
	 * Récupère les informations de planification WPVivid.
	 *
	 * Détecte les schedules classiques et incrémentaux.
	 *
	 * @return array Infos de planification détaillées.
	 */
	private function get_wpvivid_schedule_info() {
		// Schedule classique (version gratuite et pro).
		$classic_enabled    = false;
		$classic_recurrence = null;
		$classic_next_run   = null;

		// Vérifie le cron WordPress.
		$schedule_event = wp_get_schedule( 'wpvivid_main_schedule_event' );
		$next_scheduled = wp_next_scheduled( 'wpvivid_main_schedule_event' );

		if ( false !== $schedule_event ) {
			$classic_enabled    = true;
			$classic_recurrence = $schedule_event;
			$classic_next_run   = $next_scheduled ? gmdate( 'c', $next_scheduled ) : null;
		}

		// Vérifie aussi les réglages enregistrés (version gratuite).
		if ( ! $classic_enabled ) {
			$schedule_setting = get_option( 'wpvivid_schedule_setting' );
			if ( ! empty( $schedule_setting ) && ! empty( $schedule_setting['enable'] ) ) {
				$classic_enabled    = true;
				$classic_recurrence = $schedule_setting['recurrence'] ?? null;
			}
		}

		// Vérifie schedule_setting2 (WPVivid Pro custom schedules).
		if ( ! $classic_enabled ) {
			$schedule_setting2 = get_option( 'wpvivid_schedule_setting2' );
			if ( ! empty( $schedule_setting2 ) && is_array( $schedule_setting2 ) ) {
				foreach ( $schedule_setting2 as $schedule ) {
					if ( ! empty( $schedule['enable'] ) || ! empty( $schedule['status'] ) ) {
						$classic_enabled    = true;
						$classic_recurrence = $schedule['recurrence'] ?? $schedule['frequency'] ?? null;
						break;
					}
				}
			}
		}

		// Schedule incrémental (WPVivid Pro uniquement).
		$incremental_enabled    = false;
		$incremental_recurrence = null;

		$incremental_schedules = get_option( 'wpvivid_incremental_schedules' );
		if ( ! empty( $incremental_schedules ) && is_array( $incremental_schedules ) ) {
			foreach ( $incremental_schedules as $schedule ) {
				if ( ! empty( $schedule['enable'] ) || ! empty( $schedule['status'] ) ) {
					$incremental_enabled    = true;
					$incremental_recurrence = $schedule['recurrence'] ?? $schedule['frequency'] ?? null;
					break;
				}
			}
		}

		// Vérifie aussi schedule_addon_setting pour incremental.
		if ( ! $incremental_enabled ) {
			$addon_setting = get_option( 'wpvivid_schedule_addon_setting' );
			if ( ! empty( $addon_setting ) && is_array( $addon_setting ) ) {
				if ( ! empty( $addon_setting['incremental_enable'] ) ) {
					$incremental_enabled    = true;
					$incremental_recurrence = $addon_setting['incremental_recurrence'] ?? null;
				}
			}
		}

		return array(
			'enabled'                 => $classic_enabled || $incremental_enabled,
			'recurrence'              => $classic_recurrence ?? $incremental_recurrence,
			'next_run'                => $classic_next_run,
			'classic_enabled'         => $classic_enabled,
			'classic_recurrence'      => $classic_recurrence,
			'incremental_enabled'     => $incremental_enabled,
			'incremental_recurrence'  => $incremental_recurrence,
		);
	}

	/**
	 * Récupère le dernier backup WPVivid.
	 *
	 * Supporte à la fois WPVivid gratuit (wpvivid_backup_list)
	 * et WPVivid Pro (wpvivid_backup_reports).
	 *
	 * @return array|null Infos du dernier backup ou null.
	 */
	private function get_wpvivid_last_backup() {
		// Essaie d'abord wpvivid_backup_reports (WPVivid Pro).
		$backup_reports = get_option( 'wpvivid_backup_reports' );

		if ( ! empty( $backup_reports ) && is_array( $backup_reports ) ) {
			return $this->parse_wpvivid_pro_backup( $backup_reports );
		}

		// Fallback sur wpvivid_backup_list (version gratuite).
		$backup_list = get_option( 'wpvivid_backup_list' );

		if ( ! empty( $backup_list ) && is_array( $backup_list ) ) {
			return $this->parse_wpvivid_free_backup( $backup_list );
		}

		return null;
	}

	/**
	 * Parse les backups WPVivid Pro depuis wpvivid_backup_reports.
	 *
	 * @param array $backup_reports Les rapports de backup.
	 *
	 * @return array|null Infos du dernier backup ou null.
	 */
	private function parse_wpvivid_pro_backup( $backup_reports ) {
		// Convertit en array et trie par backup_time (plus récent en premier).
		$reports = array_values( $backup_reports );

		usort(
			$reports,
			function ( $a, $b ) {
				return ( $b['backup_time'] ?? 0 ) <=> ( $a['backup_time'] ?? 0 );
			}
		);

		$latest = reset( $reports );

		if ( empty( $latest ) || empty( $latest['backup_time'] ) ) {
			return null;
		}

		// Vérifie si le backup a réussi.
		$status = $latest['status'] ?? '';
		if ( 'Succeeded' !== $status && 'completed' !== strtolower( $status ) ) {
			return array(
				'date'        => gmdate( 'c', $latest['backup_time'] ),
				'timestamp'   => $latest['backup_time'],
				'type'        => 'unknown',
				'has_local'   => false,
				'has_remote'  => false,
				'remote_url'  => null,
				'remote_type' => null,
				'failed'      => true,
			);
		}

		// Récupère les infos de stockage distant depuis upload_setting.
		$remote_info = $this->get_wpvivid_pro_remote_info();

		return array(
			'date'        => gmdate( 'c', $latest['backup_time'] ),
			'timestamp'   => $latest['backup_time'],
			'type'        => 'manual',
			'has_local'   => true,
			'has_remote'  => ! empty( $remote_info['type'] ),
			'remote_url'  => $remote_info['url'] ?? null,
			'remote_type' => $remote_info['type'] ?? null,
		);
	}

	/**
	 * Récupère les infos de stockage distant pour WPVivid Pro.
	 *
	 * @return array Infos remote (type, url) ou array vide.
	 */
	private function get_wpvivid_pro_remote_info() {
		$upload_setting = get_option( 'wpvivid_upload_setting' );
		$user_history   = get_option( 'wpvivid_user_history' );

		if ( empty( $upload_setting ) || ! is_array( $upload_setting ) ) {
			return array();
		}

		// Récupère l'ID du remote sélectionné.
		$selected_remote_id = null;

		if ( ! empty( $user_history['remote_selected'] ) && is_array( $user_history['remote_selected'] ) ) {
			$selected_remote_id = reset( $user_history['remote_selected'] );
		}

		// Si pas de sélection dans user_history, cherche dans upload_setting.
		if ( empty( $selected_remote_id ) && ! empty( $upload_setting['remote_selected'] ) ) {
			$selected_remote_id = is_array( $upload_setting['remote_selected'] )
				? reset( $upload_setting['remote_selected'] )
				: $upload_setting['remote_selected'];
		}

		// Récupère la config du remote.
		if ( empty( $selected_remote_id ) || empty( $upload_setting[ $selected_remote_id ] ) ) {
			// Fallback : prend le premier remote disponible.
			foreach ( $upload_setting as $key => $value ) {
				if ( is_array( $value ) && ! empty( $value['type'] ) ) {
					$selected_remote_id = $key;
					break;
				}
			}
		}

		if ( empty( $selected_remote_id ) || empty( $upload_setting[ $selected_remote_id ] ) ) {
			return array();
		}

		$remote_config = $upload_setting[ $selected_remote_id ];

		return array(
			'type' => $remote_config['type'] ?? null,
			'url'  => $this->build_wpvivid_remote_url( $remote_config ),
		);
	}

	/**
	 * Parse les backups WPVivid gratuit depuis wpvivid_backup_list.
	 *
	 * @param array $backup_list La liste des backups.
	 *
	 * @return array|null Infos du dernier backup ou null.
	 */
	private function parse_wpvivid_free_backup( $backup_list ) {
		// Convertit en array indexé si c'est un array associatif.
		$backups = array_values( $backup_list );

		// Trie par date de création (plus récent en premier).
		usort(
			$backups,
			function ( $a, $b ) {
				return ( $b['create_time'] ?? 0 ) <=> ( $a['create_time'] ?? 0 );
			}
		);

		$latest = reset( $backups );

		if ( empty( $latest ) || empty( $latest['create_time'] ) ) {
			return null;
		}

		$result = array(
			'date'        => gmdate( 'c', $latest['create_time'] ),
			'timestamp'   => $latest['create_time'],
			'type'        => $latest['type'] ?? 'unknown',
			'has_local'   => ! empty( $latest['save_local'] ),
			'has_remote'  => ! empty( $latest['remote'] ),
			'remote_url'  => null,
			'remote_type' => null,
		);

		// Extrait les infos de stockage distant.
		if ( ! empty( $latest['remote'] ) && is_array( $latest['remote'] ) ) {
			$remote = reset( $latest['remote'] );
			if ( ! empty( $remote ) ) {
				$result['remote_type'] = $remote['type'] ?? null;
				$result['remote_url']  = $this->build_wpvivid_remote_url( $remote );
			}
		}

		return $result;
	}

	/**
	 * Construit l'URL de stockage distant WPVivid.
	 *
	 * @param array $remote Configuration du stockage distant.
	 *
	 * @return string|null URL ou null.
	 */
	private function build_wpvivid_remote_url( $remote ) {
		$type = $remote['type'] ?? '';

		switch ( $type ) {
			case 'amazons3':
			case 's3':
			case 's3compat':
				if ( ! empty( $remote['bucket'] ) ) {
					$path = trim( $remote['path'] ?? '', '/' );
					return "s3://{$remote['bucket']}" . ( $path ? "/{$path}" : '' );
				}
				break;

			case 'b2':
			case 'backblaze':
				if ( ! empty( $remote['bucket'] ) ) {
					$path = trim( $remote['path'] ?? '', '/' );
					return "b2://{$remote['bucket']}" . ( $path ? "/{$path}" : '' );
				}
				break;

			case 'dropbox':
				return 'dropbox://' . ( $remote['path'] ?? '/' );

			case 'googledrive':
				return 'googledrive://' . ( $remote['folder_id'] ?? 'root' );

			case 'onedrive':
				return 'onedrive://' . ( $remote['folder_id'] ?? 'root' );

			case 'ftp':
			case 'sftp':
				if ( ! empty( $remote['host'] ) ) {
					return "{$type}://{$remote['host']}" . ( $remote['path'] ?? '/' );
				}
				break;
		}

		return $remote['url'] ?? null;
	}

	/**
	 * Détermine le statut global des backups WPVivid.
	 *
	 * @param array      $schedule_info Infos de planification.
	 * @param array|null $last_backup   Dernier backup.
	 *
	 * @return string Statut : ok, warning, error.
	 */
	private function determine_wpvivid_status( $schedule_info, $last_backup ) {
		// Pas de backup du tout.
		if ( null === $last_backup ) {
			return 'error';
		}

		$last_timestamp = $last_backup['timestamp'] ?? 0;
		$now            = time();
		$age_days       = ( $now - $last_timestamp ) / DAY_IN_SECONDS;

		// Backup trop ancien (plus de 7 jours).
		if ( $age_days > 7 ) {
			return 'error';
		}

		// Backup entre 3 et 7 jours.
		if ( $age_days > 3 ) {
			return 'warning';
		}

		// Pas de planification active.
		if ( empty( $schedule_info['enabled'] ) ) {
			return 'warning';
		}

		return 'ok';
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

	/**
	 * Retourne la liste des plugins installés.
	 *
	 * @return array Liste des plugins avec slug, nom, version et statut actif.
	 */
	public function get_installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins        = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$installed      = array();

		foreach ( $plugins as $file => $data ) {
			$slug = dirname( $file );
			if ( '.' === $slug ) {
				$slug = basename( $file, '.php' );
			}

			$installed[] = array(
				'slug'      => $slug,
				'name'      => $data['Name'],
				'version'   => $data['Version'],
				'is_active' => in_array( $file, $active_plugins, true ),
			);
		}

		return $installed;
	}

	/**
	 * Retourne la liste des thèmes installés.
	 *
	 * @return array Liste des thèmes avec slug, nom, version et statut actif.
	 */
	public function get_installed_themes() {
		$themes       = wp_get_themes();
		$active_theme = get_stylesheet();
		$installed    = array();

		foreach ( $themes as $slug => $theme ) {
			$installed[] = array(
				'slug'      => $slug,
				'name'      => $theme->get( 'Name' ),
				'version'   => $theme->get( 'Version' ),
				'is_active' => ( $slug === $active_theme ),
			);
		}

		return $installed;
	}

	/**
	 * Retourne le statut du système WP-Cron.
	 *
	 * Détecte si DISABLE_WP_CRON est activé et compte les tâches en retard.
	 *
	 * @return array Données cron : disabled, overdue_count, last_run.
	 */
	public function get_cron_status() {
		$cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$overdue_count  = 0;
		$last_run       = null;
		$now            = time();
		$overdue_threshold = $now - HOUR_IN_SECONDS; // Tâches de plus d'1 heure.

		// Récupère toutes les tâches planifiées.
		$cron_array = _get_cron_array();

		if ( ! empty( $cron_array ) && is_array( $cron_array ) ) {
			foreach ( $cron_array as $timestamp => $hooks ) {
				// Ignore les timestamps invalides.
				if ( ! is_numeric( $timestamp ) ) {
					continue;
				}

				// Compte les tâches en retard (planifiées il y a plus d'1h).
				if ( $timestamp < $overdue_threshold ) {
					foreach ( $hooks as $hook => $events ) {
						$overdue_count += count( $events );
					}
				}
			}
		}

		// Récupère le timestamp du dernier cron exécuté.
		$last_run_timestamp = get_option( 'wboard_cron_last_run' );
		if ( $last_run_timestamp ) {
			$last_run = gmdate( 'c', (int) $last_run_timestamp );
		}

		return array(
			'disabled'      => $cron_disabled,
			'overdue_count' => $overdue_count,
			'last_run'      => $last_run,
		);
	}

	/**
	 * Enregistre le timestamp du dernier cron exécuté.
	 *
	 * Appelé via le hook 'wp_loaded' pour tracker l'exécution du cron.
	 *
	 * @return void
	 */
	public static function track_cron_execution() {
		// Vérifie si c'est une requête cron.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			update_option( 'wboard_cron_last_run', time(), false );
		}
	}
}
