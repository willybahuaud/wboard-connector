<?php
/**
 * Classe de mise a jour a distance des plugins et themes.
 *
 * Permet au board de declencher les MAJ de plugins/themes
 * via l'API REST sans connexion au back-office WordPress.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Remote_Updater
 *
 * Gere les mises a jour a distance des plugins et themes.
 */
class WBoard_Connector_Remote_Updater {

	/**
	 * Repertoire temporaire pour les backups pre-update.
	 *
	 * @var string
	 */
	const TEMP_BACKUP_DIR = 'wboard-upgrade-temp-backup';

	/**
	 * Met a jour un plugin depuis son slug.
	 *
	 * @param string $slug Slug du plugin (ex: "woocommerce").
	 *
	 * @return array Resultat de la MAJ avec status, code, versions, message.
	 */
	public function update_plugin( $slug ) {
		$this->load_upgrader_dependencies();

		// Resoudre slug → plugin file.
		$plugin_file = $this->resolve_plugin_file( $slug );
		if ( ! $plugin_file ) {
			return array(
				'status'      => 'error',
				'code'        => 'plugin_not_found',
				'old_version' => null,
				'new_version' => null,
				'message'     => sprintf( 'Plugin "%s" introuvable sur ce site.', $slug ),
			);
		}

		// Capturer la version actuelle et l'etat d'activation.
		$old_version        = $this->get_plugin_version( $plugin_file );
		$was_active         = is_plugin_active( $plugin_file );
		$was_network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );

		// Couper les emails de notification.
		$this->silence_emails();

		// Backup pre-update.
		$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		$this->backup_to_temp( $slug, $plugin_dir, 'plugin' );

		// Executer la MAJ.
		ob_start();
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );
		ob_end_clean();

		// Verifications post-update (4 niveaux).
		$error = $this->check_upgrade_result( $result, $skin );

		if ( $error ) {
			$this->restore_emails();
			$this->disable_maintenance_mode();
			$this->cleanup_temp_backup( $slug, 'plugin' );

			return array(
				'status'      => 'error',
				'code'        => $error['code'],
				'old_version' => $old_version,
				'new_version' => null,
				'message'     => $error['message'],
			);
		}

		// Verifier l'integrite du dossier.
		if ( ! $this->verify_directory_integrity( $plugin_dir ) ) {
			$this->restore_emails();
			$this->disable_maintenance_mode();

			return array(
				'status'      => 'error',
				'code'        => 'directory_integrity_failed',
				'old_version' => $old_version,
				'new_version' => null,
				'message'     => 'Le dossier du plugin semble corrompu apres la MAJ.',
			);
		}

		// Recharger les infos plugins pour avoir la nouvelle version.
		wp_cache_delete( 'plugins', 'plugins' );
		$new_version = $this->get_plugin_version( $plugin_file );

		// Verifier que la version a bien change.
		if ( $new_version && $old_version && version_compare( $new_version, $old_version, '<=' ) ) {
			$this->restore_emails();
			$this->disable_maintenance_mode();
			$this->cleanup_temp_backup( $slug, 'plugin' );

			return array(
				'status'      => 'error',
				'code'        => 'version_unchanged',
				'old_version' => $old_version,
				'new_version' => $new_version,
				'message'     => sprintf( 'La version n\'a pas change (toujours %s).', $old_version ),
			);
		}

		// Reactiver le plugin si il etait actif avant la MAJ.
		// Plugin_Upgrader desactive le plugin pendant l'upgrade et tente
		// de le reactiver, mais ca peut echouer silencieusement.
		if ( $was_active && ! is_plugin_active( $plugin_file ) ) {
			activate_plugin( $plugin_file, '', $was_network_active, true );
		}

		// Succes : cleanup backup temp.
		$this->cleanup_temp_backup( $slug, 'plugin' );
		$this->restore_emails();
		$this->disable_maintenance_mode();

		return array(
			'status'      => 'success',
			'code'        => 'updated',
			'old_version' => $old_version,
			'new_version' => $new_version,
			'message'     => sprintf( 'Plugin mis a jour de %s vers %s.', $old_version, $new_version ),
		);
	}

	/**
	 * Met a jour un theme depuis son slug.
	 *
	 * @param string $slug Slug du theme.
	 *
	 * @return array Resultat de la MAJ avec status, code, versions, message.
	 */
	public function update_theme( $slug ) {
		$this->load_upgrader_dependencies();

		// Verifier que le theme existe.
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return array(
				'status'      => 'error',
				'code'        => 'theme_not_found',
				'old_version' => null,
				'new_version' => null,
				'message'     => sprintf( 'Theme "%s" introuvable sur ce site.', $slug ),
			);
		}

		$old_version = $this->get_theme_version( $slug );

		$this->silence_emails();

		// Backup pre-update.
		$theme_dir = get_theme_root() . '/' . $slug;
		$this->backup_to_temp( $slug, $theme_dir, 'theme' );

		// Executer la MAJ.
		ob_start();
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $slug );
		ob_end_clean();

		$error = $this->check_upgrade_result( $result, $skin );

		if ( $error ) {
			$this->restore_emails();
			$this->disable_maintenance_mode();
			$this->cleanup_temp_backup( $slug, 'theme' );

			return array(
				'status'      => 'error',
				'code'        => $error['code'],
				'old_version' => $old_version,
				'new_version' => null,
				'message'     => $error['message'],
			);
		}

		if ( ! $this->verify_directory_integrity( $theme_dir ) ) {
			$this->restore_emails();
			$this->disable_maintenance_mode();

			return array(
				'status'      => 'error',
				'code'        => 'directory_integrity_failed',
				'old_version' => $old_version,
				'new_version' => null,
				'message'     => 'Le dossier du theme semble corrompu apres la MAJ.',
			);
		}

		// Recharger pour avoir la nouvelle version.
		wp_clean_themes_cache();
		$new_version = $this->get_theme_version( $slug );

		if ( $new_version && $old_version && version_compare( $new_version, $old_version, '<=' ) ) {
			$this->restore_emails();
			$this->disable_maintenance_mode();
			$this->cleanup_temp_backup( $slug, 'theme' );

			return array(
				'status'      => 'error',
				'code'        => 'version_unchanged',
				'old_version' => $old_version,
				'new_version' => $new_version,
				'message'     => sprintf( 'La version n\'a pas change (toujours %s).', $old_version ),
			);
		}

		$this->cleanup_temp_backup( $slug, 'theme' );
		$this->restore_emails();
		$this->disable_maintenance_mode();

		return array(
			'status'      => 'success',
			'code'        => 'updated',
			'old_version' => $old_version,
			'new_version' => $new_version,
			'message'     => sprintf( 'Theme mis a jour de %s vers %s.', $old_version, $new_version ),
		);
	}

	/**
	 * Charge l'environnement admin complet necessaire pour WP Upgrader.
	 *
	 * En contexte REST API, les includes admin ne sont pas charges.
	 * Plugin_Upgrader->upgrade() desactive puis reactive le plugin,
	 * et la reactivation peut echouer si des fonctions admin manquent.
	 *
	 * - admin.php : environnement admin complet (fonctions, hooks, etc.)
	 * - class-wp-upgrader.php : classes Upgrader (pas incluses par admin.php)
	 * - WP_Filesystem() : requis par copy_dir() et les Upgraders
	 *
	 * @return void
	 */
	private function load_upgrader_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Initialiser le filesystem global (requis par copy_dir et les Upgraders).
		WP_Filesystem();
	}

	/**
	 * Resout un slug de plugin vers son fichier principal (plugin file).
	 *
	 * WordPress utilise le "plugin file" (ex: woocommerce/woocommerce.php) comme
	 * identifiant unique, mais le board ne stocke que le slug (ex: woocommerce).
	 *
	 * @param string $slug Slug du plugin.
	 *
	 * @return string|null Le plugin file ou null si introuvable.
	 */
	private function resolve_plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			// Le slug correspond au nom du dossier (premiere partie du plugin file).
			$plugin_slug = dirname( $plugin_file );

			// Plugin dans un dossier : comparer le dossier.
			if ( '.' !== $plugin_slug && $plugin_slug === $slug ) {
				return $plugin_file;
			}

			// Plugin fichier unique (pas de dossier) : comparer sans .php.
			if ( '.' === $plugin_slug && basename( $plugin_file, '.php' ) === $slug ) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * Coupe les filtres d'email WordPress pendant la MAJ.
	 *
	 * Evite les notifications intempestives lors des MAJ declenchees par le board.
	 *
	 * @return void
	 */
	private function silence_emails() {
		add_filter( 'auto_core_update_send_email', '__return_false' );
		add_filter( 'auto_plugin_update_send_email', '__return_false' );
		add_filter( 'auto_theme_update_send_email', '__return_false' );
		add_filter( 'send_core_update_notification_email', '__return_false' );
	}

	/**
	 * Restaure les filtres d'email WordPress.
	 *
	 * @return void
	 */
	private function restore_emails() {
		remove_filter( 'auto_core_update_send_email', '__return_false' );
		remove_filter( 'auto_plugin_update_send_email', '__return_false' );
		remove_filter( 'auto_theme_update_send_email', '__return_false' );
		remove_filter( 'send_core_update_notification_email', '__return_false' );
	}

	/**
	 * Sauvegarde un dossier dans le repertoire temp avant MAJ.
	 *
	 * @param string $slug    Slug du composant.
	 * @param string $src_dir Chemin du dossier source.
	 * @param string $type    Type : "plugin" ou "theme".
	 *
	 * @return bool True si la copie a reussi.
	 */
	private function backup_to_temp( $slug, $src_dir, $type ) {
		if ( ! is_dir( $src_dir ) ) {
			return false;
		}

		$backup_dir = WP_CONTENT_DIR . '/' . self::TEMP_BACKUP_DIR . '/' . $type . '/' . $slug;

		// Creer le repertoire de destination.
		if ( ! wp_mkdir_p( $backup_dir ) ) {
			return false;
		}

		// Copier recursivement.
		return copy_dir( $src_dir, $backup_dir );
	}

	/**
	 * Supprime le backup temporaire apres une MAJ reussie.
	 *
	 * @param string $slug Slug du composant.
	 * @param string $type Type : "plugin" ou "theme".
	 *
	 * @return void
	 */
	private function cleanup_temp_backup( $slug, $type ) {
		global $wp_filesystem;

		$backup_dir = WP_CONTENT_DIR . '/' . self::TEMP_BACKUP_DIR . '/' . $type . '/' . $slug;

		if ( ! is_dir( $backup_dir ) ) {
			return;
		}

		// Initialiser le filesystem si necessaire.
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		$wp_filesystem->delete( $backup_dir, true );

		// Nettoyer les dossiers parents vides.
		$type_dir = WP_CONTENT_DIR . '/' . self::TEMP_BACKUP_DIR . '/' . $type;
		if ( is_dir( $type_dir ) && $this->is_directory_empty( $type_dir ) ) {
			$wp_filesystem->delete( $type_dir, true );
		}

		$base_dir = WP_CONTENT_DIR . '/' . self::TEMP_BACKUP_DIR;
		if ( is_dir( $base_dir ) && $this->is_directory_empty( $base_dir ) ) {
			$wp_filesystem->delete( $base_dir, true );
		}
	}

	/**
	 * Verifie si un repertoire est vide.
	 *
	 * @param string $dir Chemin du repertoire.
	 *
	 * @return bool True si le repertoire est vide.
	 */
	private function is_directory_empty( $dir ) {
		$handle = opendir( $dir );
		if ( ! $handle ) {
			return true;
		}

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				closedir( $handle );
				return false;
			}
		}

		closedir( $handle );
		return true;
	}

	/**
	 * Lit la version d'un plugin depuis son fichier principal.
	 *
	 * @param string $plugin_file Le plugin file (ex: woocommerce/woocommerce.php).
	 *
	 * @return string|null La version ou null.
	 */
	private function get_plugin_version( $plugin_file ) {
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );

		return ! empty( $plugin_data['Version'] ) ? $plugin_data['Version'] : null;
	}

	/**
	 * Lit la version d'un theme depuis style.css.
	 *
	 * @param string $theme_slug Slug du theme.
	 *
	 * @return string|null La version ou null.
	 */
	private function get_theme_version( $theme_slug ) {
		$theme = wp_get_theme( $theme_slug );

		return $theme->exists() ? $theme->get( 'Version' ) : null;
	}

	/**
	 * Verifie qu'un dossier existe et n'est pas vide apres une MAJ.
	 *
	 * @param string $path Chemin du dossier.
	 *
	 * @return bool True si le dossier est valide.
	 */
	private function verify_directory_integrity( $path ) {
		if ( ! is_dir( $path ) ) {
			return false;
		}

		return ! $this->is_directory_empty( $path );
	}

	/**
	 * Filet de securite : supprime le fichier .maintenance.
	 *
	 * WordPress cree ce fichier pendant les MAJ. En cas d'echec,
	 * il peut rester et bloquer l'acces au site.
	 *
	 * @return void
	 */
	private function disable_maintenance_mode() {
		$maintenance_file = ABSPATH . '.maintenance';

		if ( file_exists( $maintenance_file ) ) {
			unlink( $maintenance_file );
		}
	}

	/**
	 * Verifie le resultat d'un upgrade WP sur 4 niveaux.
	 *
	 * @param mixed              $result Le resultat de $upgrader->upgrade().
	 * @param WP_Ajax_Upgrader_Skin $skin   Le skin de l'upgrader.
	 *
	 * @return array|null Null si OK, array avec code/message si erreur.
	 */
	private function check_upgrade_result( $result, $skin ) {
		// Niveau 1 : skin->result contient une WP_Error.
		if ( is_wp_error( $skin->result ) ) {
			return array(
				'code'    => 'wp_error_' . $skin->result->get_error_code(),
				'message' => $skin->result->get_error_message(),
			);
		}

		// Niveau 2 : skin->get_errors() contient des erreurs.
		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return array(
				'code'    => 'skin_error_' . $errors->get_error_code(),
				'message' => $errors->get_error_message(),
			);
		}

		// Niveau 3 : result === false (echec silencieux).
		if ( false === $result ) {
			return array(
				'code'    => 'upgrade_failed',
				'message' => 'La mise a jour a echoue (erreur interne WordPress).',
			);
		}

		// Niveau 4 : result est une WP_Error.
		if ( is_wp_error( $result ) ) {
			return array(
				'code'    => 'result_error_' . $result->get_error_code(),
				'message' => $result->get_error_message(),
			);
		}

		return null;
	}
}
