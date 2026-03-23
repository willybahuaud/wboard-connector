<?php
/**
 * Scanner de fichiers pour le module backup.
 *
 * Parcourt wp-content/ et genere un manifeste (chemin\tmtime\ttaille)
 * avec pagination pour respecter les limites de temps d'execution.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Backup_Scanner
 *
 * Scanne le systeme de fichiers et genere le manifeste.
 */
class WBoard_Connector_Backup_Scanner {

	/**
	 * Marge de securite avant le timeout (secondes).
	 *
	 * On s'arrete 6 secondes avant le max_execution_time
	 * pour avoir le temps de retourner la reponse.
	 *
	 * @var int
	 */
	const TIMEOUT_MARGIN = 6;

	/**
	 * Profondeur maximale de recursion.
	 *
	 * @var int
	 */
	const MAX_DEPTH = 40;

	/**
	 * Seuil de detection des boucles symlink.
	 *
	 * Si un segment de chemin apparait plus de N fois,
	 * on considere que c'est une boucle et on skip.
	 *
	 * @var int
	 */
	const SYMLINK_LOOP_THRESHOLD = 5;

	/**
	 * Extensions exclues par defaut (fichiers temporaires, logs, caches).
	 *
	 * @var array
	 */
	const DEFAULT_EXCLUDED_EXTENSIONS = array(
		'log',
		'tmp',
		'bak',
		'swp',
		'DS_Store',
	);

	/**
	 * Repertoires exclus par defaut dans wp-content/.
	 *
	 * @var array
	 */
	const DEFAULT_EXCLUDED_DIRS = array(
		'cache',
		'upgrade',
		'backup',
		'backups',
		'backup-db',
		'updraft',
		'ai1wm-backups',
		'wboard-tmp',
	);

	/**
	 * Gere la requete de scan.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 * @param array           $config  La config backup.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request, array $config ) {
		$body = json_decode( $request->get_body(), true );

		$offset     = isset( $body['offset'] ) ? (int) $body['offset'] : 0;
		$exclusions = isset( $body['exclusions'] ) ? (array) $body['exclusions'] : array();

		$start_time    = time();
		$safe_limit    = $this->get_safe_execution_time();
		$scan_dir      = $this->get_scan_directory();
		$base_path_len = strlen( $scan_dir ) + 1; // +1 pour le separateur.

		// Fusion des exclusions : defauts + config board + requete.
		$excluded_dirs       = $this->merge_excluded_dirs( $exclusions, $config );
		$excluded_extensions = $this->merge_excluded_extensions( $exclusions );

		$manifest_lines = array();
		$current_index  = 0;
		$files_scanned  = 0;
		$is_complete    = true;

		try {
			$iterator = $this->create_iterator( $scan_dir );

			foreach ( $iterator as $file ) {
				// Pagination : on skip jusqu'a l'offset.
				if ( $current_index < $offset ) {
					$current_index++;
					continue;
				}

				// Timeout : on s'arrete proprement.
				if ( $safe_limit > 0 && ( time() - $start_time ) >= $safe_limit ) {
					$is_complete = false;
					break;
				}

				$pathname = $file->getPathname();

				// Detecte les boucles symlink.
				if ( $this->is_symlink_loop( $pathname ) ) {
					$current_index++;
					continue;
				}

				// On ne veut que les fichiers.
				if ( ! $file->isFile() ) {
					$current_index++;
					continue;
				}

				// Normalise le separateur de chemin AVANT les checks d'exclusion
				// (sur Windows les chemins contiennent des \ qui casseraient le matching).
				$relative_path = str_replace( '\\', '/', substr( $pathname, $base_path_len ) );

				// Verifie les exclusions de repertoires.
				if ( $this->is_dir_excluded( $relative_path, $excluded_dirs ) ) {
					$current_index++;
					continue;
				}

				// Verifie les exclusions d'extensions.
				if ( $this->is_extension_excluded( $relative_path, $excluded_extensions ) ) {
					$current_index++;
					continue;
				}

				$mtime = $file->getMTime();
				$size  = $file->getSize();

				// Format manifeste : chemin\tmtime\ttaille.
				$manifest_lines[] = $relative_path . "\t" . $mtime . "\t" . $size;

				$files_scanned++;
				$current_index++;
			}
		} catch ( \Exception $exception ) {
			return new WP_Error(
				'wboard_backup_scan_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Erreur pendant le scan : %s', 'wboard-connector' ),
					$exception->getMessage()
				),
				array( 'status' => 500 )
			);
		}

		$manifest_content = implode( "\n", $manifest_lines );

		return new WP_REST_Response(
			array(
				'manifest' => $manifest_content,
				'cursor'   => $current_index,
				'complete' => $is_complete,
				'stats'    => array(
					'files_scanned' => $files_scanned,
					'duration'      => time() - $start_time,
					'scan_dir'      => $scan_dir,
				),
			),
			200
		);
	}

	/**
	 * Determine le repertoire a scanner.
	 *
	 * Supporte Bedrock (WP_CONTENT_DIR peut etre custom).
	 *
	 * @return string Le chemin absolu du repertoire.
	 */
	private function get_scan_directory() {
		return WP_CONTENT_DIR;
	}

	/**
	 * Cree l'iterateur de fichiers recursif.
	 *
	 * @param string $directory Le repertoire racine.
	 *
	 * @return RecursiveIteratorIterator
	 */
	private function create_iterator( $directory ) {
		$flags = RecursiveDirectoryIterator::SKIP_DOTS;

		// FOLLOW_SYMLINKS si la fonction est dispo (PHP 5.3+).
		if ( defined( 'RecursiveDirectoryIterator::FOLLOW_SYMLINKS' ) ) {
			$flags |= RecursiveDirectoryIterator::FOLLOW_SYMLINKS;
		}

		$dir_iterator = new RecursiveDirectoryIterator( $directory, $flags );

		return new RecursiveIteratorIterator(
			$dir_iterator,
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);
	}

	/**
	 * Detecte les boucles de symlinks.
	 *
	 * Si un segment de chemin apparait plus de SYMLINK_LOOP_THRESHOLD fois,
	 * c'est probablement une boucle symlink.
	 *
	 * @param string $path Le chemin complet.
	 *
	 * @return bool True si boucle detectee.
	 */
	private function is_symlink_loop( $path ) {
		$segments = explode( DIRECTORY_SEPARATOR, $path );
		$counts   = array_count_values( $segments );

		foreach ( $counts as $count ) {
			if ( $count > self::SYMLINK_LOOP_THRESHOLD ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verifie si un chemin relatif est dans un repertoire exclu.
	 *
	 * @param string $relative_path Le chemin relatif depuis wp-content/.
	 * @param array  $excluded_dirs Les repertoires exclus.
	 *
	 * @return bool True si exclu.
	 */
	private function is_dir_excluded( $relative_path, array $excluded_dirs ) {
		foreach ( $excluded_dirs as $excluded ) {
			// Match exact du premier segment ou du chemin complet.
			if ( strpos( $relative_path, $excluded . '/' ) === 0 ) {
				return true;
			}
			if ( strpos( $relative_path, '/' . $excluded . '/' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verifie si un fichier a une extension exclue.
	 *
	 * @param string $relative_path Le chemin relatif.
	 * @param array  $excluded_extensions Les extensions exclues (sans le point).
	 *
	 * @return bool True si exclu.
	 */
	private function is_extension_excluded( $relative_path, array $excluded_extensions ) {
		$extension = strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) );

		return in_array( $extension, $excluded_extensions, true );
	}

	/**
	 * Fusionne les repertoires exclus : defauts + config + requete.
	 *
	 * @param array $request_exclusions Les exclusions de la requete.
	 * @param array $config             La config backup.
	 *
	 * @return array Les repertoires exclus finaux.
	 */
	private function merge_excluded_dirs( array $request_exclusions, array $config ) {
		$dirs = self::DEFAULT_EXCLUDED_DIRS;

		// Exclusions de la config board (transmises lors de la sync).
		if ( ! empty( $config['excluded_dirs'] ) ) {
			$dirs = array_merge( $dirs, (array) $config['excluded_dirs'] );
		}

		// Exclusions envoyees par le backup-manager dans la requete.
		if ( ! empty( $request_exclusions['dirs'] ) ) {
			$dirs = array_merge( $dirs, (array) $request_exclusions['dirs'] );
		}

		return array_unique( array_filter( $dirs ) );
	}

	/**
	 * Fusionne les extensions exclues : defauts + requete.
	 *
	 * @param array $request_exclusions Les exclusions de la requete.
	 *
	 * @return array Les extensions exclues finales (sans le point).
	 */
	private function merge_excluded_extensions( array $request_exclusions ) {
		$extensions = self::DEFAULT_EXCLUDED_EXTENSIONS;

		if ( ! empty( $request_exclusions['extensions'] ) ) {
			$extensions = array_merge( $extensions, (array) $request_exclusions['extensions'] );
		}

		return array_unique( array_map( 'strtolower', $extensions ) );
	}

	/**
	 * Retourne le temps d'execution securise (avec marge).
	 *
	 * @return int Secondes disponibles (0 = illimite).
	 */
	private function get_safe_execution_time() {
		$max = (int) ini_get( 'max_execution_time' );

		if ( 0 === $max ) {
			return 0;
		}

		$safe = $max - self::TIMEOUT_MARGIN;

		return max( $safe, 5 );
	}
}
