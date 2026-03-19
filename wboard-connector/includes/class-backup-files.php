<?php
/**
 * Module de creation ZIP et upload pour le backup.
 *
 * Recoit une liste de fichiers du backup-manager, cree un ZIP
 * avec la meilleure methode disponible, l'uploade en streaming,
 * puis nettoie les fichiers temporaires.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Backup_Files
 *
 * Cree des archives ZIP de fichiers et les uploade.
 */
class WBoard_Connector_Backup_Files {

	/**
	 * Repertoire temporaire pour les ZIP.
	 *
	 * Sous-repertoire de wp-content/ pour etre sur qu'il est writable.
	 * Ce repertoire est exclu du scan (dans les exclusions par defaut du scanner).
	 *
	 * @var string
	 */
	const TEMP_DIR = 'wboard-tmp';

	/**
	 * Taille max estimee des fichiers avant compression (120 Mo).
	 *
	 * Si les fichiers demandes depassent cette taille,
	 * on retourne une erreur (le backup-manager doit splitter).
	 *
	 * @var int
	 */
	const MAX_BATCH_SIZE = 125829120;

	/**
	 * Gere la requete de creation de ZIP.
	 *
	 * Body attendu :
	 * {
	 *   "files": ["plugins/woocommerce/woocommerce.php", "themes/astra/style.css"],
	 *   "upload_url": "https://backup-1.wabeo.work/ingest/files",
	 *   "upload_token": "tok_abc123"
	 * }
	 *
	 * @param WP_REST_Request $request  La requete REST.
	 * @param array           $config   La config backup.
	 * @param string          $site_id  Identifiant du site.
	 * @param string          $hmac_secret Secret HMAC du site.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request, array $config, $site_id, $hmac_secret ) {
		$body = json_decode( $request->get_body(), true );

		$files        = isset( $body['files'] ) ? (array) $body['files'] : array();
		$upload_url   = isset( $body['upload_url'] ) ? $body['upload_url'] : '';
		$upload_token = isset( $body['upload_token'] ) ? $body['upload_token'] : '';

		// Validation.
		if ( empty( $files ) ) {
			return new WP_Error(
				'wboard_backup_no_files',
				__( 'Aucun fichier specifie.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $upload_url ) || empty( $upload_token ) ) {
			return new WP_Error(
				'wboard_backup_missing_upload_params',
				__( 'upload_url et upload_token requis.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize et validation des chemins.
		$validated_files = $this->validate_file_paths( $files );
		if ( is_wp_error( $validated_files ) ) {
			return $validated_files;
		}

		// Verification de la taille totale estimee.
		$total_size = $this->estimate_total_size( $validated_files );
		if ( $total_size > self::MAX_BATCH_SIZE ) {
			return new WP_Error(
				'wboard_backup_batch_too_large',
				sprintf(
					'Taille totale estimee (%s) depasse la limite (%s). Le backup-manager doit splitter.',
					size_format( $total_size ),
					size_format( self::MAX_BATCH_SIZE )
				),
				array( 'status' => 413 )
			);
		}

		// Creation du repertoire temporaire.
		$temp_dir = self::ensure_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$archive_id = wp_generate_password( 12, false, false );
		$start      = time();

		// Creation de l'archive.
		$archive_result = $this->create_archive( $validated_files, $temp_dir, $archive_id );
		if ( is_wp_error( $archive_result ) ) {
			$this->cleanup_by_id( $temp_dir, $archive_id );
			return $archive_result;
		}

		$archive_path = $archive_result['path'];
		$manager_url  = isset( $config['manager_url'] ) ? $config['manager_url'] : '';

		// Upload vers le backup-manager.
		$uploader      = new WBoard_Connector_Backup_Uploader();
		$upload_result  = $uploader->upload(
			$archive_path,
			$upload_url,
			$upload_token,
			$site_id,
			$hmac_secret,
			$manager_url
		);

		// Nettoyage du fichier temporaire immediatement.
		$archive_size = file_exists( $archive_path ) ? filesize( $archive_path ) : 0;
		$this->cleanup_by_id( $temp_dir, $archive_id );

		if ( ! $upload_result['success'] ) {
			return new WP_Error(
				'wboard_backup_upload_failed',
				$upload_result['error'],
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response(
			array(
				'success'        => true,
				'files_count'    => count( $validated_files ),
				'archive_size'   => $archive_size,
				'duration'       => time() - $start,
				'missing_files'  => $archive_result['missing'],
			),
			200
		);
	}

	/**
	 * Valide les chemins de fichiers.
	 *
	 * Verifie que chaque chemin :
	 * - Ne contient pas de path traversal (../)
	 * - Existe dans wp-content/
	 * - Est un fichier (pas un repertoire)
	 *
	 * @param array $files Les chemins relatifs a wp-content/.
	 *
	 * @return array|WP_Error Les chemins absolus valides.
	 */
	private function validate_file_paths( array $files ) {
		$validated = array();
		$base_dir  = WP_CONTENT_DIR;
		$real_base = realpath( $base_dir );

		if ( false === $real_base ) {
			return new WP_Error(
				'wboard_backup_invalid_base',
				__( 'Repertoire wp-content introuvable.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		foreach ( $files as $relative_path ) {
			$relative_path = ltrim( $relative_path, '/' );

			// Protection path traversal.
			if ( strpos( $relative_path, '..' ) !== false ) {
				continue;
			}

			$absolute_path = $base_dir . '/' . $relative_path;
			$real_path     = realpath( $absolute_path );

			// Le fichier doit exister et etre dans wp-content/.
			if ( false === $real_path || strpos( $real_path, $real_base ) !== 0 ) {
				continue;
			}

			if ( ! is_file( $real_path ) ) {
				continue;
			}

			$validated[ $relative_path ] = $real_path;
		}

		if ( empty( $validated ) ) {
			return new WP_Error(
				'wboard_backup_no_valid_files',
				__( 'Aucun fichier valide dans la liste.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		return $validated;
	}

	/**
	 * Estime la taille totale des fichiers a archiver.
	 *
	 * @param array $files Map chemin_relatif => chemin_absolu.
	 *
	 * @return int Taille totale en bytes.
	 */
	private function estimate_total_size( array $files ) {
		$total = 0;
		foreach ( $files as $absolute_path ) {
			$total += filesize( $absolute_path );
		}
		return $total;
	}

	/**
	 * Cree une archive avec la meilleure methode disponible.
	 *
	 * Retourne le chemin reel de l'archive creee (peut etre .zip ou .tar.gz).
	 *
	 * @param array  $files      Map chemin_relatif => chemin_absolu.
	 * @param string $temp_dir   Repertoire temporaire.
	 * @param string $archive_id Identifiant unique de l'archive.
	 *
	 * @return array{path: string, missing: array}|WP_Error
	 */
	private function create_archive( array $files, $temp_dir, $archive_id ) {
		$method = self::detect_compression_method();

		switch ( $method ) {
			case 'ziparchive':
				$path   = $temp_dir . '/backup-' . $archive_id . '.zip';
				$result = $this->create_zip_ziparchive( $files, $path );
				break;

			case 'pclzip':
				$path   = $temp_dir . '/backup-' . $archive_id . '.zip';
				$result = $this->create_zip_pclzip( $files, $path );
				break;

			case 'tar_gz':
				$path   = $temp_dir . '/backup-' . $archive_id . '.tar.gz';
				$result = $this->create_tar_gz_streaming( $files, $path );
				break;

			default:
				return new WP_Error(
					'wboard_backup_no_compression',
					__( 'Aucune methode de compression disponible.', 'wboard-connector' ),
					array( 'status' => 500 )
				);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'path'    => $path,
			'missing' => $result['missing'],
		);
	}

	/**
	 * Cree un ZIP avec ZipArchive (methode preferee).
	 *
	 * @param array  $files    Map chemin_relatif => chemin_absolu.
	 * @param string $zip_path Chemin de sortie.
	 *
	 * @return array{missing: array}|WP_Error
	 */
	private function create_zip_ziparchive( array $files, $zip_path ) {
		$zip = new ZipArchive();

		$result = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $result ) {
			return new WP_Error(
				'wboard_backup_zip_create_failed',
				sprintf( 'Impossible de creer le ZIP (code %d).', $result ),
				array( 'status' => 500 )
			);
		}

		$missing = array();

		foreach ( $files as $relative_path => $absolute_path ) {
			if ( ! file_exists( $absolute_path ) || ! is_readable( $absolute_path ) ) {
				$missing[] = $relative_path;
				continue;
			}

			$zip->addFile( $absolute_path, $relative_path );
		}

		$zip->close();

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error(
				'wboard_backup_zip_missing',
				__( 'Le ZIP n\'a pas ete cree.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		return array( 'missing' => $missing );
	}

	/**
	 * Cree un ZIP avec PclZip (fallback WordPress).
	 *
	 * @param array  $files    Map chemin_relatif => chemin_absolu.
	 * @param string $zip_path Chemin de sortie.
	 *
	 * @return array{missing: array}|WP_Error
	 */
	private function create_zip_pclzip( array $files, $zip_path ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$archive = new PclZip( $zip_path );
		$missing = array();

		$file_list = array();
		foreach ( $files as $relative_path => $absolute_path ) {
			if ( ! file_exists( $absolute_path ) || ! is_readable( $absolute_path ) ) {
				$missing[] = $relative_path;
				continue;
			}
			$file_list[] = $absolute_path;
		}

		if ( empty( $file_list ) ) {
			return new WP_Error(
				'wboard_backup_no_readable_files',
				__( 'Aucun fichier lisible.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		$result = $archive->create(
			$file_list,
			PCLZIP_OPT_REMOVE_PATH,
			WP_CONTENT_DIR
		);

		if ( 0 === $result ) {
			return new WP_Error(
				'wboard_backup_pclzip_failed',
				sprintf( 'PclZip erreur : %s', $archive->errorInfo( true ) ),
				array( 'status' => 500 )
			);
		}

		return array( 'missing' => $missing );
	}

	/**
	 * Cree une archive tar.gz en streaming (dernier recours).
	 *
	 * Ecrit le tar directement sur disque fichier par fichier
	 * pour eviter de charger toute l'archive en memoire.
	 *
	 * @param array  $files    Map chemin_relatif => chemin_absolu.
	 * @param string $tar_path Chemin de sortie (.tar.gz).
	 *
	 * @return array{missing: array}|WP_Error
	 */
	private function create_tar_gz_streaming( array $files, $tar_path ) {
		if ( ! function_exists( 'gzopen' ) ) {
			return new WP_Error(
				'wboard_backup_no_gzip',
				__( 'Extension zlib non disponible pour la compression gzip.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		$gz_handle = gzopen( $tar_path, 'wb9' );
		if ( false === $gz_handle ) {
			return new WP_Error(
				'wboard_backup_gzip_open_failed',
				__( 'Impossible de creer le fichier tar.gz.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		$missing = array();

		foreach ( $files as $relative_path => $absolute_path ) {
			if ( ! file_exists( $absolute_path ) || ! is_readable( $absolute_path ) ) {
				$missing[] = $relative_path;
				continue;
			}

			$file_size = filesize( $absolute_path );

			// Header tar (512 bytes).
			$header = $this->build_tar_header( $relative_path, $file_size, filemtime( $absolute_path ) );
			gzwrite( $gz_handle, $header );

			// Contenu du fichier en chunks de 8 Ko.
			$file_handle = fopen( $absolute_path, 'r' );
			if ( false !== $file_handle ) {
				while ( ! feof( $file_handle ) ) {
					$chunk = fread( $file_handle, 8192 );
					gzwrite( $gz_handle, $chunk );
				}
				fclose( $file_handle );
			}

			// Padding a 512 bytes.
			$padding = 512 - ( $file_size % 512 );
			if ( 512 !== $padding ) {
				gzwrite( $gz_handle, str_repeat( "\0", $padding ) );
			}
		}

		// End-of-archive marker (deux blocs de 512 zeros).
		gzwrite( $gz_handle, str_repeat( "\0", 1024 ) );
		gzclose( $gz_handle );

		return array( 'missing' => $missing );
	}

	/**
	 * Construit un header tar POSIX pour un fichier.
	 *
	 * @param string $name  Nom du fichier dans l'archive.
	 * @param int    $size  Taille en bytes.
	 * @param int    $mtime Timestamp de modification.
	 *
	 * @return string Header tar de 512 bytes.
	 */
	private function build_tar_header( $name, $size, $mtime ) {
		$header  = str_pad( $name, 100, "\0" );
		$header .= str_pad( decoct( 0644 ), 7, '0', STR_PAD_LEFT ) . "\0";
		$header .= str_pad( decoct( 0 ), 7, '0', STR_PAD_LEFT ) . "\0";
		$header .= str_pad( decoct( 0 ), 7, '0', STR_PAD_LEFT ) . "\0";
		$header .= str_pad( decoct( $size ), 11, '0', STR_PAD_LEFT ) . "\0";
		$header .= str_pad( decoct( $mtime ), 11, '0', STR_PAD_LEFT ) . "\0";
		$header .= '        ';
		$header .= '0';
		$header .= str_repeat( "\0", 100 );
		$header .= 'ustar' . "\0";
		$header .= '00';
		$header .= str_repeat( "\0", 247 );

		$header = str_pad( $header, 512, "\0" );

		// Calcul du checksum.
		$checksum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}

		$checksum_str = str_pad( decoct( $checksum ), 6, '0', STR_PAD_LEFT ) . "\0 ";
		$header       = substr_replace( $header, $checksum_str, 148, 8 );

		return $header;
	}

	/**
	 * Detecte la meilleure methode de compression disponible.
	 *
	 * @return string Le nom de la methode ('ziparchive', 'pclzip', 'tar_gz', 'none').
	 */
	public static function detect_compression_method() {
		if ( class_exists( 'ZipArchive' ) ) {
			return 'ziparchive';
		}

		$pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
		if ( file_exists( $pclzip_path ) ) {
			return 'pclzip';
		}

		if ( function_exists( 'gzopen' ) ) {
			return 'tar_gz';
		}

		return 'none';
	}

	/**
	 * Assure que le repertoire temporaire existe et est protege.
	 *
	 * Methode statique pour etre reutilisee par d'autres modules (DB, cleanup).
	 *
	 * @return string|WP_Error Le chemin du repertoire temporaire.
	 */
	public static function ensure_temp_dir() {
		$temp_dir = WP_CONTENT_DIR . '/' . self::TEMP_DIR;

		if ( ! file_exists( $temp_dir ) ) {
			$created = wp_mkdir_p( $temp_dir );
			if ( ! $created ) {
				return new WP_Error(
					'wboard_backup_temp_dir_failed',
					__( 'Impossible de creer le repertoire temporaire.', 'wboard-connector' ),
					array( 'status' => 500 )
				);
			}
		}

		// Recree les fichiers de protection s'ils manquent.
		$index_path = $temp_dir . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_path, '<?php // Silence is golden.' );
		}

		$htaccess_path = $temp_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_path, 'deny from all' );
		}

		if ( ! is_writable( $temp_dir ) ) {
			return new WP_Error(
				'wboard_backup_temp_dir_not_writable',
				__( 'Le repertoire temporaire n\'est pas writable.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		return $temp_dir;
	}

	/**
	 * Supprime les fichiers temporaires associes a un archive_id.
	 *
	 * @param string $temp_dir   Chemin du repertoire temporaire.
	 * @param string $archive_id Identifiant de l'archive.
	 *
	 * @return void
	 */
	private function cleanup_by_id( $temp_dir, $archive_id ) {
		$patterns = array(
			$temp_dir . '/backup-' . $archive_id . '.zip',
			$temp_dir . '/backup-' . $archive_id . '.tar.gz',
		);

		foreach ( $patterns as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}
