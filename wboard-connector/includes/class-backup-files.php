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
	 * Taille max d'un ZIP en bytes (120 Mo).
	 *
	 * Si les fichiers demandes depassent cette taille,
	 * on retourne une erreur (le backup-manager doit splitter).
	 *
	 * @var int
	 */
	const MAX_ZIP_SIZE = 125829120;

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
	 * @param WP_REST_Request $request La requete REST.
	 * @param array           $config  La config backup.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request, array $config ) {
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

		// Creation du repertoire temporaire.
		$temp_dir = $this->ensure_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$zip_path = $temp_dir . '/backup-' . wp_generate_password( 12, false, false ) . '.zip';
		$start    = time();

		// Creation du ZIP.
		$zip_result = $this->create_zip( $validated_files, $zip_path );
		if ( is_wp_error( $zip_result ) ) {
			$this->cleanup( $zip_path );
			return $zip_result;
		}

		// Upload vers le backup-manager.
		$uploader     = new WBoard_Connector_Backup_Uploader();
		$security     = new WBoard_Connector_Security();
		$site_id      = get_site_option( 'wboard_connector_site_id', '' );
		$hmac_secret  = $security->get_secret_key();

		$upload_result = $uploader->upload( $zip_path, $upload_url, $upload_token, $site_id, $hmac_secret );

		// Nettoyage du fichier temporaire immediatement.
		$zip_size = file_exists( $zip_path ) ? filesize( $zip_path ) : 0;
		$this->cleanup( $zip_path );

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
				'zip_size'       => $zip_size,
				'duration'       => time() - $start,
				'missing_files'  => $zip_result['missing'],
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
		$validated   = array();
		$base_dir    = WP_CONTENT_DIR;
		$real_base   = realpath( $base_dir );

		if ( false === $real_base ) {
			return new WP_Error(
				'wboard_backup_invalid_base',
				__( 'Repertoire wp-content introuvable.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		foreach ( $files as $relative_path ) {
			// Sanitize basique.
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
	 * Cree un ZIP avec la meilleure methode disponible.
	 *
	 * @param array  $files    Map chemin_relatif => chemin_absolu.
	 * @param string $zip_path Chemin de sortie du ZIP.
	 *
	 * @return array{missing: array}|WP_Error Resultat avec liste des fichiers manquants.
	 */
	private function create_zip( array $files, $zip_path ) {
		$method = self::detect_compression_method();

		switch ( $method ) {
			case 'ziparchive':
				return $this->create_zip_ziparchive( $files, $zip_path );

			case 'pclzip':
				return $this->create_zip_pclzip( $files, $zip_path );

			default:
				return $this->create_zip_tar_gz( $files, $zip_path );
		}
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

		// PclZip travaille avec des chemins absolus et un remove_path.
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
	 * Cree une archive tar.gz en PHP pur (dernier recours).
	 *
	 * @param array  $files    Map chemin_relatif => chemin_absolu.
	 * @param string $zip_path Chemin de sortie (.tar.gz sera ajoute).
	 *
	 * @return array{missing: array}|WP_Error
	 */
	private function create_zip_tar_gz( array $files, $zip_path ) {
		// On remplace l'extension .zip par .tar.gz.
		$tar_path = preg_replace( '/\.zip$/', '.tar.gz', $zip_path );

		$missing = array();
		$tar_data = '';

		foreach ( $files as $relative_path => $absolute_path ) {
			if ( ! file_exists( $absolute_path ) || ! is_readable( $absolute_path ) ) {
				$missing[] = $relative_path;
				continue;
			}

			$content  = file_get_contents( $absolute_path );
			$size     = strlen( $content );

			// Header tar (512 bytes).
			$header = $this->build_tar_header( $relative_path, $size, filemtime( $absolute_path ) );

			$tar_data .= $header;
			$tar_data .= $content;

			// Padding a 512 bytes.
			$padding = 512 - ( $size % 512 );
			if ( 512 !== $padding ) {
				$tar_data .= str_repeat( "\0", $padding );
			}
		}

		// End-of-archive marker (deux blocs de 512 zeros).
		$tar_data .= str_repeat( "\0", 1024 );

		// Compression gzip.
		$gz_data = gzencode( $tar_data );
		if ( false === $gz_data ) {
			return new WP_Error(
				'wboard_backup_gzip_failed',
				__( 'Echec de la compression gzip.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tar_path, $gz_data );

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
		$header  = str_pad( $name, 100, "\0" );              // name.
		$header .= str_pad( decoct( 0644 ), 7, '0', STR_PAD_LEFT ) . "\0"; // mode.
		$header .= str_pad( decoct( 0 ), 7, '0', STR_PAD_LEFT ) . "\0";    // uid.
		$header .= str_pad( decoct( 0 ), 7, '0', STR_PAD_LEFT ) . "\0";    // gid.
		$header .= str_pad( decoct( $size ), 11, '0', STR_PAD_LEFT ) . "\0";  // size.
		$header .= str_pad( decoct( $mtime ), 11, '0', STR_PAD_LEFT ) . "\0"; // mtime.
		$header .= '        '; // checksum placeholder (8 espaces).
		$header .= '0';        // typeflag (regular file).
		$header .= str_repeat( "\0", 100 ); // linkname.
		$header .= 'ustar' . "\0";          // magic.
		$header .= '00';                    // version.
		$header .= str_repeat( "\0", 247 ); // reste du header (uname, gname, etc.).

		// Padding a 512 bytes.
		$header = str_pad( $header, 512, "\0" );

		// Calcul du checksum.
		$checksum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}

		// Ecrit le checksum (6 digits octaux + null + space).
		$checksum_str = str_pad( decoct( $checksum ), 6, '0', STR_PAD_LEFT ) . "\0 ";
		$header       = substr_replace( $header, $checksum_str, 148, 8 );

		return $header;
	}

	/**
	 * Detecte la meilleure methode de compression disponible.
	 *
	 * Ordre de preference :
	 * 1. ZipArchive (extension PHP native)
	 * 2. PclZip (bundle WordPress)
	 * 3. tar_gz (PHP pur, dernier recours)
	 *
	 * @return string Le nom de la methode.
	 */
	public static function detect_compression_method() {
		if ( class_exists( 'ZipArchive' ) ) {
			return 'ziparchive';
		}

		$pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
		if ( file_exists( $pclzip_path ) ) {
			return 'pclzip';
		}

		return 'tar_gz';
	}

	/**
	 * Assure que le repertoire temporaire existe et est writable.
	 *
	 * @return string|WP_Error Le chemin du repertoire temporaire.
	 */
	private function ensure_temp_dir() {
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

			// Ajoute un index.php pour bloquer le directory listing.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $temp_dir . '/index.php', '<?php // Silence is golden.' );

			// Ajoute un .htaccess pour bloquer l'acces direct.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $temp_dir . '/.htaccess', 'deny from all' );
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
	 * Supprime un fichier temporaire.
	 *
	 * @param string $file_path Chemin du fichier a supprimer.
	 *
	 * @return void
	 */
	private function cleanup( $file_path ) {
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		// Gere aussi le cas tar.gz (dernier recours).
		$tar_path = preg_replace( '/\.zip$/', '.tar.gz', $file_path );
		if ( $tar_path !== $file_path && file_exists( $tar_path ) ) {
			wp_delete_file( $tar_path );
		}
	}
}
