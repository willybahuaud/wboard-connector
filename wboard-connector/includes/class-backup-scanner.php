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
	 * Fichiers racine a inclure dans le backup.
	 *
	 * Prefixes @root/ dans le manifeste pour les distinguer de wp-content.
	 * wp-config.php est volontairement exclu (contient des secrets).
	 *
	 * @var array
	 */
	const ROOT_FILES = array(
		'.htaccess',
		'.user.ini',
		'php.ini',
		'robots.txt',
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
		$max_files  = isset( $body['max_files'] ) ? (int) $body['max_files'] : 0;

		$start_time    = time();
		$safe_limit    = $this->get_safe_execution_time();
		$scan_dir      = $this->get_scan_directory();
		$base_path_len = strlen( $scan_dir ) + 1; // +1 pour le separateur.

		// Fusion des exclusions : defauts + config board + requete.
		$excluded_dirs       = $this->merge_excluded_dirs( $exclusions, $config );
		$excluded_files      = $this->merge_excluded_files( $exclusions, $config );
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

				// Limites : timeout PHP ou max_files atteint.
				if ( $safe_limit > 0 && ( time() - $start_time ) >= $safe_limit ) {
					$is_complete = false;
					break;
				}
				if ( $max_files > 0 && $files_scanned >= $max_files ) {
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

				// Verifie les exclusions de fichiers (patterns glob sur le nom).
				if ( $this->is_file_excluded( $relative_path, $excluded_files ) ) {
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

		// Fichiers racine (uniquement sur la derniere page).
		if ( $is_complete ) {
			$root_lines = $this->scan_root_files();
			$manifest_lines = array_merge( $manifest_lines, $root_lines );
			$files_scanned += count( $root_lines );
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
				'meta'     => array(
					'wp_version'  => get_bloginfo( 'version' ),
					'php_version' => phpversion(),
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
	 * Scanne les fichiers de configuration racine (ABSPATH).
	 *
	 * Seuls les fichiers de la liste ROOT_FILES sont inclus.
	 * Ils sont prefixes @root/ dans le manifeste pour les distinguer
	 * des fichiers wp-content. wp-config.php n'est jamais inclus.
	 *
	 * @return array Lignes de manifeste au format "chemin\tmtime\ttaille".
	 */
	private function scan_root_files() {
		$lines = array();

		foreach ( self::ROOT_FILES as $filename ) {
			$filepath = ABSPATH . $filename;

			if ( ! file_exists( $filepath ) || ! is_file( $filepath ) ) {
				continue;
			}

			$mtime = filemtime( $filepath );
			$size  = filesize( $filepath );

			if ( false === $mtime || false === $size ) {
				continue;
			}

			$lines[] = '@root/' . $filename . "\t" . $mtime . "\t" . $size;
		}

		return $lines;
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
	 * Le match se fait toujours sur le premier segment du chemin (enfant direct
	 * de wp-content). Un nom comme "upgrade" exclut wp-content/upgrade/ mais
	 * pas wp-content/plugins/elementor/core/upgrade/ — cf. l'intention de
	 * DEFAULT_EXCLUDED_DIRS ("Repertoires exclus par defaut dans wp-content/").
	 *
	 * Modes supportes :
	 * - Nom exact  : "updraft" matche "updraft/..."
	 * - Glob avec *: "*cache*" matche tout premier segment contenant "cache"
	 *
	 * @param string $relative_path Le chemin relatif depuis wp-content/.
	 * @param array  $excluded_dirs Les repertoires exclus.
	 *
	 * @return bool True si exclu.
	 */
	private function is_dir_excluded( $relative_path, array $excluded_dirs ) {
		foreach ( $excluded_dirs as $excluded ) {
			if ( strpos( $excluded, '*' ) !== false ) {
				// Mode glob : on teste le premier segment du chemin.
				if ( $this->matches_glob_segment( $relative_path, $excluded ) ) {
					return true;
				}
			} else {
				// Mode exact : match du premier segment uniquement.
				if ( strpos( $relative_path, $excluded . '/' ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Teste si le premier segment du chemin matche un pattern glob.
	 *
	 * Seul le dossier de premier niveau (enfant direct de wp-content)
	 * est teste pour eviter les faux positifs. Ex: "*cache*" matchera
	 * le dossier "object-cache/" mais pas "plugins/wp-super-cache/".
	 *
	 * @param string $relative_path Le chemin relatif.
	 * @param string $pattern       Le pattern glob (ex: "*cache*").
	 *
	 * @return bool True si le premier segment matche.
	 */
	private function matches_glob_segment( $relative_path, $pattern ) {
		$first_slash = strpos( $relative_path, '/' );

		if ( false === $first_slash ) {
			return false;
		}

		$first_segment = substr( $relative_path, 0, $first_slash );

		return fnmatch( $pattern, $first_segment );
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
	 * Fusionne les fichiers exclus : config + requete.
	 *
	 * @param array $request_exclusions Les exclusions de la requete.
	 * @param array $config             La config backup.
	 *
	 * @return array Les patterns de fichiers exclus (globs sur le nom, ex: "temp-*.txt").
	 */
	private function merge_excluded_files( array $request_exclusions, array $config ) {
		$files = array();

		// Exclusions de la config board.
		if ( ! empty( $config['excluded_files'] ) ) {
			$files = array_merge( $files, (array) $config['excluded_files'] );
		}

		// Exclusions envoyees par le backup-manager dans la requete.
		if ( ! empty( $request_exclusions['files'] ) ) {
			$files = array_merge( $files, (array) $request_exclusions['files'] );
		}

		return array_unique( array_filter( $files ) );
	}

	/**
	 * Verifie si un fichier matche un pattern d'exclusion.
	 *
	 * Deux modes selon le pattern :
	 * - Sans "/" : matche uniquement les fichiers a la racine de wp-content/.
	 *   Ex: "litespeed.php" exclut wp-content/litespeed.php
	 *   mais PAS wp-content/plugins/litespeed-cache/litespeed.php.
	 * - Avec "/" : matche sur le chemin relatif complet (glob).
	 *   Ex: "uploads/truc/pattern-*" exclut wp-content/uploads/truc/pattern-foo.log.
	 *
	 * @param string $relative_path  Le chemin relatif depuis wp-content/.
	 * @param array  $excluded_files Les patterns glob a tester.
	 *
	 * @return bool True si le fichier est exclu.
	 */
	private function is_file_excluded( $relative_path, array $excluded_files ) {
		if ( empty( $excluded_files ) ) {
			return false;
		}

		$is_root_file = ( strpos( $relative_path, '/' ) === false );
		$filename     = basename( $relative_path );

		foreach ( $excluded_files as $pattern ) {
			if ( strpos( $pattern, '**/' ) === 0 ) {
				// Pattern "**/" : matche le nom de fichier partout dans l'arbo.
				$sub_pattern = substr( $pattern, 3 );
				if ( fnmatch( $sub_pattern, $filename ) ) {
					return true;
				}
			} elseif ( strpos( $pattern, '/' ) !== false ) {
				// Pattern avec "/" : matche sur le chemin relatif complet.
				if ( fnmatch( $pattern, $relative_path ) ) {
					return true;
				}
			} elseif ( $is_root_file ) {
				// Pattern sans "/" : matche uniquement a la racine.
				if ( fnmatch( $pattern, $relative_path ) ) {
					return true;
				}
			}
		}

		return false;
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
