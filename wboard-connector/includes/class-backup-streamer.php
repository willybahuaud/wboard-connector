<?php
/**
 * Module de streaming tar pour le backup.
 *
 * Recoit une liste de fichiers du backup-manager et les stream
 * directement en reponse HTTP au format tar brut (pas de compression).
 * Aucun fichier temporaire cree, memoire constante.
 *
 * Le backup-manager (VPS) se charge de la compression et de l'archivage.
 * Le plugin fait le minimum : lire les fichiers et les streamer.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Backup_Streamer
 *
 * Stream des fichiers en tar brut via la reponse HTTP.
 */
class WBoard_Connector_Backup_Streamer {

	/**
	 * Taille d'un bloc tar (standard POSIX).
	 *
	 * @var int
	 */
	const TAR_BLOCK_SIZE = 512;

	/**
	 * Nombre max de fichiers par requete de streaming.
	 *
	 * @var int
	 */
	const MAX_FILES_PER_REQUEST = 1000;

	/**
	 * Gere la requete de streaming tar.
	 *
	 * Body attendu :
	 * {
	 *   "files": ["plugins/woocommerce/woocommerce.php", "themes/astra/style.css"]
	 * }
	 *
	 * Retourne directement un flux tar dans la reponse HTTP.
	 * Le Content-Type est application/x-tar.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 * @param array           $config  La config backup.
	 *
	 * @return WP_REST_Response|WP_Error|void
	 */
	public function handle( WP_REST_Request $request, array $config ) {
		$body  = json_decode( $request->get_body(), true );
		$files = isset( $body['files'] ) ? (array) $body['files'] : array();

		if ( empty( $files ) ) {
			return new WP_Error(
				'wboard_backup_no_files',
				__( 'Aucun fichier specifie.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $files ) > self::MAX_FILES_PER_REQUEST ) {
			return new WP_Error(
				'wboard_backup_too_many_files',
				sprintf(
					'Trop de fichiers (%d, max %d).',
					count( $files ),
					self::MAX_FILES_PER_REQUEST
				),
				array( 'status' => 400 )
			);
		}

		$validated_files = $this->validate_file_paths( $files );
		if ( is_wp_error( $validated_files ) ) {
			return $validated_files;
		}

		// On bypass la reponse REST standard pour streamer directement.
		$this->stream_tar( $validated_files );

		// Empeche WordPress d'envoyer une reponse apres le streaming.
		exit;
	}

	/**
	 * Stream les fichiers au format tar brut.
	 *
	 * Format tar POSIX simplifie :
	 * - Pour chaque fichier : header 512 octets + contenu + padding a 512 octets
	 * - Fin d'archive : 2 blocs de zeros (1024 octets)
	 *
	 * @param array $files Map chemin_relatif => chemin_absolu.
	 *
	 * @return void
	 */
	private function stream_tar( array $files ) {
		// Desactive les buffers de sortie PHP pour streamer en direct.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Pas de timeout pour le streaming.
		set_time_limit( 0 );

		// Headers HTTP.
		header( 'Content-Type: application/x-tar' );
		header( 'Content-Disposition: attachment; filename="backup.tar"' );
		header( 'X-WBoard-Files-Count: ' . count( $files ) );

		$missing = array();

		foreach ( $files as $relative_path => $absolute_path ) {
			$size = @filesize( $absolute_path );
			if ( false === $size ) {
				$missing[] = $relative_path;
				continue;
			}

			$handle = @fopen( $absolute_path, 'rb' );
			if ( false === $handle ) {
				$missing[] = $relative_path;
				continue;
			}

			// Ecrit le header tar.
			$header = $this->build_tar_header( $relative_path, $size );
			echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			// Stream le contenu du fichier.
			$remaining = $size;
			while ( $remaining > 0 ) {
				$chunk_size = min( $remaining, 8192 );
				$chunk      = fread( $handle, $chunk_size );
				if ( false === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$remaining -= strlen( $chunk );
			}
			fclose( $handle );

			// Padding a 512 octets.
			$padding = self::TAR_BLOCK_SIZE - ( $size % self::TAR_BLOCK_SIZE );
			if ( $padding < self::TAR_BLOCK_SIZE ) {
				echo str_repeat( "\0", $padding ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			// Flush pour maintenir la connexion active (evite timeout proxy).
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}

		// Fin d'archive tar : 2 blocs de zeros.
		echo str_repeat( "\0", self::TAR_BLOCK_SIZE * 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	/**
	 * Construit un header tar POSIX pour un fichier regulier.
	 *
	 * Le format tar header fait exactement 512 octets.
	 * On utilise le format USTAR pour la compatibilite avec Go archive/tar.
	 *
	 * @param string $name Chemin relatif du fichier dans l'archive.
	 * @param int    $size Taille du fichier en octets.
	 *
	 * @return string Header tar de 512 octets.
	 */
	private function build_tar_header( $name, $size ) {
		// Header tar : 512 octets, rempli de zeros.
		$header = str_repeat( "\0", self::TAR_BLOCK_SIZE );

		// Champs du header tar POSIX/USTAR.
		// name : 0-99 (100 bytes).
		$header = $this->write_field( $header, 0, $name, 100 );

		// mode : 100-107 (8 bytes, octal).
		$header = $this->write_field( $header, 100, sprintf( '%07o', 0644 ), 8 );

		// uid : 108-115 (8 bytes, octal).
		$header = $this->write_field( $header, 108, sprintf( '%07o', 0 ), 8 );

		// gid : 116-123 (8 bytes, octal).
		$header = $this->write_field( $header, 116, sprintf( '%07o', 0 ), 8 );

		// size : 124-135 (12 bytes, octal).
		$header = $this->write_field( $header, 124, sprintf( '%011o', $size ), 12 );

		// mtime : 136-147 (12 bytes, octal).
		$header = $this->write_field( $header, 136, sprintf( '%011o', time() ), 12 );

		// typeflag : 156 (1 byte) — '0' = fichier regulier.
		$header[156] = '0';

		// magic : 257-262 (6 bytes) — "ustar\0" pour USTAR format.
		$header = $this->write_field( $header, 257, "ustar\0", 6 );

		// version : 263-264 (2 bytes) — "00".
		$header = $this->write_field( $header, 263, '00', 2 );

		// Calcul du checksum (somme des octets du header avec le champ checksum = espaces).
		// checksum : 148-155 (8 bytes).
		// Remplit temporairement le champ checksum avec des espaces.
		for ( $i = 148; $i < 156; $i++ ) {
			$header[ $i ] = ' ';
		}

		$checksum = 0;
		for ( $i = 0; $i < self::TAR_BLOCK_SIZE; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}

		// Ecrit le checksum en octal, termine par null + espace.
		$checksum_str = sprintf( '%06o', $checksum ) . "\0 ";
		$header       = $this->write_field( $header, 148, $checksum_str, 8 );

		return $header;
	}

	/**
	 * Ecrit un champ dans le header tar.
	 *
	 * @param string $header Le header tar (512 octets).
	 * @param int    $offset Position dans le header.
	 * @param string $value  Valeur a ecrire.
	 * @param int    $length Longueur max du champ.
	 *
	 * @return string Le header modifie.
	 */
	private function write_field( $header, $offset, $value, $length ) {
		$value = substr( $value, 0, $length );
		for ( $i = 0; $i < strlen( $value ); $i++ ) {
			$header[ $offset + $i ] = $value[ $i ];
		}
		return $header;
	}

	/**
	 * Valide les chemins de fichiers.
	 *
	 * Identique a WBoard_Connector_Backup_Files::validate_file_paths().
	 * Les fichiers sont relatifs a wp-content/.
	 *
	 * @param array $files Liste des chemins relatifs.
	 *
	 * @return array|WP_Error Map chemin_relatif => chemin_absolu.
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
}
