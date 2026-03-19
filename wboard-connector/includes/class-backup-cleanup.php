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
	 * Execute le nettoyage.
	 *
	 * Supprime les fichiers de plus de MAX_FILE_AGE secondes
	 * dans le repertoire temporaire.
	 *
	 * @return array{deleted: int, errors: int} Compteurs.
	 */
	public static function run() {
		$temp_dir = WP_CONTENT_DIR . '/' . self::TEMP_DIR;

		if ( ! is_dir( $temp_dir ) ) {
			return array(
				'deleted' => 0,
				'errors'  => 0,
			);
		}

		$deleted  = 0;
		$errors   = 0;
		$now      = time();
		$iterator = new DirectoryIterator( $temp_dir );

		foreach ( $iterator as $file ) {
			if ( $file->isDot() ) {
				continue;
			}

			$filename = $file->getFilename();

			// Preserve les fichiers de protection.
			if ( 'index.php' === $filename || '.htaccess' === $filename ) {
				continue;
			}

			// Verifie l'age du fichier.
			$age = $now - $file->getMTime();
			if ( $age < self::MAX_FILE_AGE ) {
				continue;
			}

			// Supprime.
			if ( $file->isFile() ) {
				$result = wp_delete_file( $file->getPathname() );
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
