<?php
/**
 * Nettoyage des fichiers temporaires de backup.
 *
 * Supprime les fichiers dans wp-content/wboard-tmp/ qui datent
 * de plus de 1 heure (fichiers orphelins apres crash ou timeout).
 * Enregistre un cron WP biquotidien pour le nettoyage automatique.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Backup_Cleanup
 *
 * Gere le nettoyage des fichiers temporaires de backup.
 */
class WBoard_Connector_Backup_Cleanup {

	/**
	 * Hook du cron de nettoyage.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wboard_backup_cleanup';

	/**
	 * Age maximum d'un fichier temporaire (secondes).
	 *
	 * @var int
	 */
	const MAX_FILE_AGE = 3600;

	/**
	 * Repertoire temporaire.
	 *
	 * @var string
	 */
	const TEMP_DIR = 'wboard-tmp';

	/**
	 * Enregistre le cron de nettoyage.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Desinscrit le cron de nettoyage.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Fichiers de protection a ne pas supprimer.
	 *
	 * @var array
	 */
	private static $protected_files = array( 'index.php', '.htaccess', 'nginx.conf' );

	/**
	 * Execute le nettoyage.
	 *
	 * Supprime les fichiers de plus de MAX_FILE_AGE secondes
	 * dans les deux emplacements possibles (sys_temp + wp-content fallback).
	 *
	 * @return array{deleted: int, errors: int} Compteurs.
	 */
	public static function run() {
		$deleted = 0;
		$errors  = 0;

		// Nettoie les deux emplacements (l'ancien wp-content + le nouveau sys_temp).
		$dirs = array(
			WP_CONTENT_DIR . '/' . self::TEMP_DIR,
			sys_get_temp_dir() . '/wboard-backup',
		);

		foreach ( $dirs as $temp_dir ) {
			if ( ! is_dir( $temp_dir ) ) {
				continue;
			}

			$result  = self::clean_directory( $temp_dir );
			$deleted += $result['deleted'];
			$errors  += $result['errors'];
		}

		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/**
	 * Nettoie un repertoire de ses fichiers temporaires ages.
	 *
	 * @param string $dir Chemin du repertoire.
	 *
	 * @return array{deleted: int, errors: int} Compteurs.
	 */
	private static function clean_directory( $dir ) {
		$deleted  = 0;
		$errors   = 0;
		$now      = time();
		$iterator = new DirectoryIterator( $dir );

		foreach ( $iterator as $file ) {
			if ( $file->isDot() ) {
				continue;
			}

			$filename = $file->getFilename();

			// Preserve les fichiers de protection.
			if ( in_array( $filename, self::$protected_files, true ) ) {
				continue;
			}

			// Verifie l'age du fichier.
			$age = $now - $file->getMTime();
			if ( $age < self::MAX_FILE_AGE ) {
				continue;
			}

			// Supprime.
			if ( $file->isFile() ) {
				wp_delete_file( $file->getPathname() );
				if ( file_exists( $file->getPathname() ) ) {
					$errors++;
				} else {
					$deleted++;
				}
			}
		}

		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}
}
