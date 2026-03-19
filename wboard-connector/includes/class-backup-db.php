<?php
/**
 * Module d'export base de donnees pour le backup.
 *
 * Deux endpoints :
 * - Listing des tables avec empreintes (INFORMATION_SCHEMA)
 * - Export SQL par table avec curseur par cle primaire
 *
 * L'export utilise des curseurs par PK (WHERE pk > X ORDER BY pk ASC LIMIT Y)
 * au lieu de OFFSET/LIMIT pour de meilleures performances sur grosses tables.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Backup_Db
 *
 * Gere l'export de la base de donnees pour le backup.
 */
class WBoard_Connector_Backup_Db {

	/**
	 * Taille de batch par defaut (nombre de lignes).
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 2000;

	/**
	 * Repertoire temporaire pour les fichiers SQL.
	 *
	 * @var string
	 */
	const TEMP_DIR = 'wboard-tmp';

	/**
	 * Taille minimale de batch.
	 *
	 * @var int
	 */
	const MIN_BATCH_SIZE = 100;

	/**
	 * Taille maximale de batch.
	 *
	 * @var int
	 */
	const MAX_BATCH_SIZE = 5000;

	/**
	 * Gere la requete de listing des tables.
	 *
	 * Retourne la liste des tables du site avec empreintes
	 * basees sur INFORMATION_SCHEMA.TABLES.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 * @param array           $config  La config backup.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_tables( WP_REST_Request $request, array $config ) {
		global $wpdb;

		$body              = json_decode( $request->get_body(), true );
		$excluded_patterns = isset( $body['excluded_tables'] ) ? (array) $body['excluded_tables'] : array();

		$tables  = $this->get_tables_info();
		$prefix  = $wpdb->prefix;
		$result  = array();

		foreach ( $tables as $table ) {
			$table_name = $table['table_name'];

			// Filtre par prefixe WordPress (securite : on n'exporte pas les tables d'autres apps).
			if ( strpos( $table_name, $prefix ) !== 0 ) {
				continue;
			}

			// Verification des exclusions (patterns avec wildcard).
			if ( $this->is_table_excluded( $table_name, $excluded_patterns, $prefix ) ) {
				continue;
			}

			// Detection de la cle primaire.
			$primary_key = $this->get_primary_key( $table_name );

			// Construction de l'empreinte.
			$fingerprint = $this->build_fingerprint( $table );

			$result[] = array(
				'name'        => $table_name,
				'rows'        => (int) $table['table_rows'],
				'data_length' => (int) $table['data_length'],
				'update_time' => $table['update_time'],
				'fingerprint' => $fingerprint,
				'primary_key' => $primary_key,
				'has_pk'      => ! empty( $primary_key ),
			);
		}

		return new WP_REST_Response(
			array(
				'tables'    => $result,
				'prefix'    => $prefix,
				'db_name'   => DB_NAME,
				'charset'   => $wpdb->charset,
				'collation' => $wpdb->collate,
			),
			200
		);
	}

	/**
	 * Taille d'un bloc tar (standard POSIX).
	 *
	 * @var int
	 */
	const TAR_BLOCK_SIZE = 512;

	/**
	 * Gere la requete de streaming tar de l'export DB complet.
	 *
	 * Body attendu :
	 * {
	 *   "tables": [
	 *     {"name": "wp_posts", "primary_key": "ID", "batch_size": 2000},
	 *     {"name": "wp_options", "primary_key": "option_id", "batch_size": 2000}
	 *   ]
	 * }
	 *
	 * Retourne un flux tar contenant un fichier .sql par table.
	 * Une seule requete HTTP pour toutes les tables.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 * @param array           $config  La config backup.
	 *
	 * @return WP_REST_Response|WP_Error|void
	 */
	public function handle_stream_export( WP_REST_Request $request, array $config ) {
		global $wpdb;

		$body   = json_decode( $request->get_body(), true );
		$tables = isset( $body['tables'] ) ? (array) $body['tables'] : array();

		if ( empty( $tables ) ) {
			return new WP_Error(
				'wboard_backup_db_no_tables',
				__( 'Aucune table specifiee.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		// Validation de toutes les tables avant de commencer le streaming.
		$validated = array();
		foreach ( $tables as $table_info ) {
			$name = isset( $table_info['name'] ) ? $table_info['name'] : '';

			if ( strpos( $name, $wpdb->prefix ) !== 0 ) {
				continue;
			}

			if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $name ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$name
				)
			);

			if ( ! $exists ) {
				continue;
			}

			$validated[] = array(
				'name'        => $name,
				'primary_key' => isset( $table_info['primary_key'] ) ? $table_info['primary_key'] : null,
				'batch_size'  => isset( $table_info['batch_size'] ) ? (int) $table_info['batch_size'] : self::DEFAULT_BATCH_SIZE,
			);
		}

		if ( empty( $validated ) ) {
			return new WP_Error(
				'wboard_backup_db_no_valid_tables',
				__( 'Aucune table valide.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		$this->stream_tables_tar( $validated );
		exit;
	}

	/**
	 * Stream toutes les tables en tar brut.
	 *
	 * Pour chaque table :
	 * 1. Export complet en fichier SQL temporaire (pagination interne)
	 * 2. Ecriture du header tar + contenu du fichier dans le flux HTTP
	 * 3. Suppression du fichier temporaire
	 *
	 * Le fichier temporaire est necessaire pour connaitre la taille
	 * (requise par le header tar) avant de streamer le contenu.
	 *
	 * @param array $tables Liste des tables validees.
	 *
	 * @return void
	 */
	private function stream_tables_tar( array $tables ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		set_time_limit( 0 );

		header( 'Content-Type: application/x-tar' );
		header( 'X-WBoard-Tables-Count: ' . count( $tables ) );

		$temp_dir = self::ensure_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return;
		}

		foreach ( $tables as $table_info ) {
			$name       = $table_info['name'];
			$pk         = $table_info['primary_key'];
			$batch_size = $this->adapt_batch_size( $table_info['batch_size'] );

			// Export complet de la table vers un fichier temporaire.
			$file_id  = wp_generate_password( 8, false, false );
			$sql_path = $temp_dir . '/stream-' . $file_id . '.sql';

			$this->export_full_table_to_file( $name, $pk, $batch_size, $sql_path );

			$size = @filesize( $sql_path );
			if ( false === $size || 0 === $size ) {
				@unlink( $sql_path );
				continue;
			}

			// Header tar pour cette table.
			echo $this->build_db_tar_header( $name . '.sql', $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			// Stream le contenu du fichier SQL.
			$handle = @fopen( $sql_path, 'rb' );
			if ( false !== $handle ) {
				while ( ! feof( $handle ) ) {
					echo fread( $handle, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$size -= 8192;
				}
				fclose( $handle );
			}

			// Padding tar a 512 octets.
			$real_size = filesize( $sql_path );
			$padding   = self::TAR_BLOCK_SIZE - ( $real_size % self::TAR_BLOCK_SIZE );
			if ( $padding < self::TAR_BLOCK_SIZE ) {
				echo str_repeat( "\0", $padding ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			@unlink( $sql_path );

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
	 * Exporte une table complete vers un fichier SQL.
	 *
	 * Pagine en interne via curseur PK ou OFFSET.
	 * Le fichier contient le CREATE TABLE + tous les INSERT.
	 *
	 * @param string      $table       Nom de la table.
	 * @param string|null $primary_key Colonne PK (null si pas de PK).
	 * @param int         $batch_size  Lignes par requete SQL.
	 * @param string      $file_path   Chemin du fichier de sortie.
	 *
	 * @return void
	 */
	private function export_full_table_to_file( $table, $primary_key, $batch_size, $file_path ) {
		global $wpdb;

		$handle = fopen( $file_path, 'w' );
		if ( false === $handle ) {
			return;
		}

		// Header SQL : CREATE TABLE.
		$create_table = $this->get_create_table_statement( $table );
		if ( $create_table ) {
			fwrite( $handle, "-- Table: {$table}\n" );
			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
			fwrite( $handle, $create_table . ";\n\n" );
		}

		// Pagination interne : itere jusqu'a epuisement des lignes.
		$cursor = 0;
		while ( true ) {
			if ( ! empty( $primary_key ) ) {
				$rows = $this->fetch_rows_by_pk( $table, $primary_key, $cursor, $batch_size );
			} else {
				$rows = $this->fetch_rows_by_offset( $table, $cursor, $batch_size );
			}

			if ( empty( $rows ) ) {
				break;
			}

			$columns         = array_keys( (array) $rows[0] );
			$columns_escaped = array_map( array( $this, 'escape_column_name' ), $columns );
			$columns_str     = implode( ', ', $columns_escaped );

			foreach ( $rows as $row ) {
				$row    = (array) $row;
				$values = array();

				foreach ( $row as $value ) {
					if ( null === $value ) {
						$values[] = 'NULL';
					} else {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$values[] = "'" . $wpdb->_real_escape( $value ) . "'";
					}
				}

				fwrite( $handle, "INSERT INTO `{$table}` ({$columns_str}) VALUES (" . implode( ', ', $values ) . ");\n" );

				if ( ! empty( $primary_key ) && isset( $row[ $primary_key ] ) ) {
					$cursor = (int) $row[ $primary_key ];
				}
			}

			if ( empty( $primary_key ) ) {
				$cursor += count( $rows );
			}

			if ( count( $rows ) < $batch_size ) {
				break;
			}
		}

		fclose( $handle );
	}

	/**
	 * Construit un header tar POSIX pour un fichier SQL.
	 *
	 * @param string $name Nom du fichier dans l'archive.
	 * @param int    $size Taille en octets.
	 *
	 * @return string Header tar de 512 octets.
	 */
	private function build_db_tar_header( $name, $size ) {
		$header = str_repeat( "\0", self::TAR_BLOCK_SIZE );

		$header = $this->tar_write_field( $header, 0, $name, 100 );
		$header = $this->tar_write_field( $header, 100, sprintf( '%07o', 0644 ), 8 );
		$header = $this->tar_write_field( $header, 108, sprintf( '%07o', 0 ), 8 );
		$header = $this->tar_write_field( $header, 116, sprintf( '%07o', 0 ), 8 );
		$header = $this->tar_write_field( $header, 124, sprintf( '%011o', $size ), 12 );
		$header = $this->tar_write_field( $header, 136, sprintf( '%011o', time() ), 12 );

		$header[156] = '0';

		$header = $this->tar_write_field( $header, 257, "ustar\0", 6 );
		$header = $this->tar_write_field( $header, 263, '00', 2 );

		for ( $i = 148; $i < 156; $i++ ) {
			$header[ $i ] = ' ';
		}

		$checksum = 0;
		for ( $i = 0; $i < self::TAR_BLOCK_SIZE; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}

		$checksum_str = sprintf( '%06o', $checksum ) . "\0 ";
		$header       = $this->tar_write_field( $header, 148, $checksum_str, 8 );

		return $header;
	}

	/**
	 * Ecrit un champ dans un header tar.
	 *
	 * @param string $header Le header (512 octets).
	 * @param int    $offset Position.
	 * @param string $value  Valeur.
	 * @param int    $length Longueur max.
	 *
	 * @return string Header modifie.
	 */
	private function tar_write_field( $header, $offset, $value, $length ) {
		$value = substr( $value, 0, $length );
		for ( $i = 0; $i < strlen( $value ); $i++ ) {
			$header[ $offset + $i ] = $value[ $i ];
		}
		return $header;
	}

	/**
	 * Gere la requete d'export SQL d'une table.
	 *
	 * Body attendu :
	 * {
	 *   "table": "wp_posts",
	 *   "cursor": 0,
	 *   "batch_size": 2000,
	 *   "upload_url": "https://backup-1.wabeo.work/ingest/db",
	 *   "upload_token": "tok_abc123"
	 * }
	 *
	 * @param WP_REST_Request $request     La requete REST.
	 * @param array           $config      La config backup.
	 * @param string          $site_id     Identifiant du site.
	 * @param string          $hmac_secret Secret HMAC du site.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_export( WP_REST_Request $request, array $config, $site_id, $hmac_secret ) {
		global $wpdb;

		$body         = json_decode( $request->get_body(), true );
		$table        = isset( $body['table'] ) ? $body['table'] : '';
		$cursor       = isset( $body['cursor'] ) ? (int) $body['cursor'] : 0;
		$batch_size   = isset( $body['batch_size'] ) ? (int) $body['batch_size'] : self::DEFAULT_BATCH_SIZE;
		$upload_url   = isset( $body['upload_url'] ) ? $body['upload_url'] : '';
		$upload_token = isset( $body['upload_token'] ) ? $body['upload_token'] : '';

		// Validation.
		if ( empty( $table ) ) {
			return new WP_Error(
				'wboard_backup_db_no_table',
				__( 'Nom de table requis.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		// Securite : la table doit commencer par le prefixe WP.
		if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
			return new WP_Error(
				'wboard_backup_db_invalid_table',
				__( 'Table non autorisee (prefixe incorrect).', 'wboard-connector' ),
				array( 'status' => 403 )
			);
		}

		// Securite : le nom de table ne doit contenir que des caracteres valides.
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return new WP_Error(
				'wboard_backup_db_invalid_table_name',
				__( 'Nom de table invalide.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		// Verifie que la table existe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);

		if ( ! $table_exists ) {
			return new WP_Error(
				'wboard_backup_db_table_not_found',
				sprintf( 'Table %s introuvable.', $table ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $upload_url ) || empty( $upload_token ) ) {
			return new WP_Error(
				'wboard_backup_db_missing_upload',
				__( 'upload_url et upload_token requis.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		// Adapte la taille de batch a la memoire disponible.
		$batch_size = $this->adapt_batch_size( $batch_size );

		// Detection de la cle primaire.
		$primary_key = $this->get_primary_key( $table );

		// Export SQL.
		$start  = time();
		$result = $this->export_table( $table, $primary_key, $cursor, $batch_size );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Upload vers le backup-manager.
		$uploader    = new WBoard_Connector_Backup_Uploader();
		$manager_url = isset( $config['manager_url'] ) ? $config['manager_url'] : '';

		$upload_result = $uploader->upload(
			$result['file_path'],
			$upload_url,
			$upload_token,
			$site_id,
			$hmac_secret,
			$manager_url
		);

		// Nettoyage du fichier temporaire.
		$file_size = file_exists( $result['file_path'] ) ? filesize( $result['file_path'] ) : 0;
		if ( file_exists( $result['file_path'] ) ) {
			wp_delete_file( $result['file_path'] );
		}

		if ( ! $upload_result['success'] ) {
			return new WP_Error(
				'wboard_backup_db_upload_failed',
				$upload_result['error'],
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response(
			array(
				'table'         => $table,
				'last_id'       => $result['last_id'],
				'complete'      => $result['complete'],
				'rows_exported' => $result['rows_exported'],
				'file_size'     => $file_size,
				'has_pk'        => ! empty( $primary_key ),
				'duration'      => time() - $start,
			),
			200
		);
	}

	/**
	 * Recupere les infos des tables via INFORMATION_SCHEMA.
	 *
	 * @return array Liste des tables avec metadata.
	 */
	private function get_tables_info() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME AS table_name,
					TABLE_ROWS AS table_rows,
					DATA_LENGTH AS data_length,
					UPDATE_TIME AS update_time,
					AUTO_INCREMENT AS auto_increment
				FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_SCHEMA = %s",
				DB_NAME
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Construit une empreinte pour detecter les changements.
	 *
	 * Combine TABLE_ROWS + DATA_LENGTH + UPDATE_TIME.
	 * Si UPDATE_TIME est null (certains InnoDB), utilise AUTO_INCREMENT.
	 *
	 * @param array $table_info Infos de la table (INFORMATION_SCHEMA).
	 *
	 * @return string Hash MD5 de l'empreinte.
	 */
	private function build_fingerprint( array $table_info ) {
		$parts = array(
			$table_info['table_rows'],
			$table_info['data_length'],
		);

		if ( ! empty( $table_info['update_time'] ) ) {
			$parts[] = $table_info['update_time'];
		} elseif ( ! empty( $table_info['auto_increment'] ) ) {
			$parts[] = $table_info['auto_increment'];
		}

		return md5( implode( ':', $parts ) );
	}

	/**
	 * Detecte la cle primaire d'une table.
	 *
	 * @param string $table_name Nom de la table.
	 *
	 * @return string|null Le nom de la colonne PK, ou null si pas de PK.
	 */
	private function get_primary_key( $table_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$pk = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME
				FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				WHERE TABLE_SCHEMA = %s
					AND TABLE_NAME = %s
					AND CONSTRAINT_NAME = 'PRIMARY'
				LIMIT 1",
				DB_NAME,
				$table_name
			)
		);

		return $pk ? $pk : null;
	}

	/**
	 * Verifie si une table correspond a un pattern d'exclusion.
	 *
	 * Patterns supportes :
	 * - Nom exact : "wp_sessions"
	 * - Wildcard debut : "*_sessions" (match toute table finissant par _sessions)
	 * - Wildcard fin : "wp_action*" (match toute table commencant par wp_action)
	 * - Double wildcard : "*_actionscheduler_*"
	 *
	 * Le matching se fait sur le nom complet de la table.
	 *
	 * @param string $table_name Nom complet de la table.
	 * @param array  $patterns   Patterns d'exclusion.
	 * @param string $prefix     Prefixe WordPress (non utilise dans le matching simple).
	 *
	 * @return bool True si la table est exclue.
	 */
	private function is_table_excluded( $table_name, array $patterns, $prefix ) {
		foreach ( $patterns as $pattern ) {
			// Echappe le pattern pour regex, sauf les *.
			// On remplace d'abord les * par un placeholder, on quote, puis on remet.
			$placeholder = '___WILDCARD___';
			$safe        = str_replace( '*', $placeholder, $pattern );
			$safe        = preg_quote( $safe, '/' );
			$regex       = str_replace( $placeholder, '.*', $safe );

			if ( preg_match( '/^' . $regex . '$/', $table_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adapte la taille de batch a la memoire disponible.
	 *
	 * Utilise 30% de la memoire restante, clamp entre MIN et MAX.
	 *
	 * @param int $requested Taille de batch demandee.
	 *
	 * @return int Taille de batch effective.
	 */
	private function adapt_batch_size( $requested ) {
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		// Si illimite, utilise la taille demandee.
		if ( -1 === (int) $memory_limit || 0 === $memory_limit ) {
			return max( self::MIN_BATCH_SIZE, min( $requested, self::MAX_BATCH_SIZE ) );
		}

		$memory_used   = memory_get_usage( true );
		$memory_free   = $memory_limit - $memory_used;
		$usable_memory = (int) ( $memory_free * 0.3 );

		// Estimation grossiere : ~1 Ko par ligne en moyenne.
		$estimated_batch = (int) ( $usable_memory / 1024 );

		$batch = min( $requested, $estimated_batch );
		$batch = max( $batch, self::MIN_BATCH_SIZE );
		$batch = min( $batch, self::MAX_BATCH_SIZE );

		return $batch;
	}

	/**
	 * Exporte les lignes d'une table en fichier SQL.
	 *
	 * Precondition : $table a ete valide par regex /^[a-zA-Z0-9_]+$/ dans handle_export().
	 *
	 * @param string      $table       Nom de la table.
	 * @param string|null $primary_key Nom de la colonne PK (null si pas de PK).
	 * @param int         $cursor      Valeur du curseur (dernier ID exporte).
	 * @param int         $batch_size  Nombre de lignes a exporter.
	 *
	 * @return array{file_path: string, last_id: int, complete: bool, rows_exported: int}|WP_Error
	 */
	private function export_table( $table, $primary_key, $cursor, $batch_size ) {
		global $wpdb;

		// Reutilise le temp dir securise (avec index.php et .htaccess).
		$temp_dir = self::ensure_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		// Nom de fichier imprevisible (anti-acces direct + anti-concurrence).
		$file_id   = wp_generate_password( 8, false, false );
		$file_path = $temp_dir . '/export-' . $file_id . '.sql';
		$handle    = fopen( $file_path, 'w' );

		if ( false === $handle ) {
			return new WP_Error(
				'wboard_backup_db_file_create_failed',
				__( 'Impossible de creer le fichier SQL temporaire.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		// Header SQL si premier batch (cursor = 0).
		if ( 0 === $cursor ) {
			$create_table = $this->get_create_table_statement( $table );
			if ( $create_table ) {
				fwrite( $handle, "-- Table: {$table}\n" );
				fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
				fwrite( $handle, $create_table . ";\n\n" );
			}
		}

		// Requete avec curseur PK ou fallback OFFSET.
		if ( ! empty( $primary_key ) ) {
			$rows = $this->fetch_rows_by_pk( $table, $primary_key, $cursor, $batch_size );
		} else {
			$rows = $this->fetch_rows_by_offset( $table, $cursor, $batch_size );
		}

		$rows_exported = 0;
		$last_id       = $cursor;

		if ( ! empty( $rows ) ) {
			$columns         = array_keys( (array) $rows[0] );
			$columns_escaped = array_map( array( $this, 'escape_column_name' ), $columns );
			$columns_str     = implode( ', ', $columns_escaped );

			foreach ( $rows as $row ) {
				$row = (array) $row;

				$values = array();
				foreach ( $row as $value ) {
					if ( null === $value ) {
						$values[] = 'NULL';
					} else {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$values[] = "'" . $wpdb->_real_escape( $value ) . "'";
					}
				}

				$sql_line = "INSERT INTO `{$table}` ({$columns_str}) VALUES (" . implode( ', ', $values ) . ");\n";
				fwrite( $handle, $sql_line );

				$rows_exported++;

				if ( ! empty( $primary_key ) && isset( $row[ $primary_key ] ) ) {
					$last_id = (int) $row[ $primary_key ];
				}
			}
		}

		fclose( $handle );

		// Si pas de PK, le curseur est l'offset.
		if ( empty( $primary_key ) ) {
			$last_id = $cursor + $rows_exported;
		}

		$is_complete = ( count( $rows ) < $batch_size );

		return array(
			'file_path'     => $file_path,
			'last_id'       => $last_id,
			'complete'      => $is_complete,
			'rows_exported' => $rows_exported,
		);
	}

	/**
	 * Echappe un nom de colonne SQL avec des backticks.
	 *
	 * @param string $column_name Le nom de la colonne.
	 *
	 * @return string Le nom echappe.
	 */
	private function escape_column_name( $column_name ) {
		return '`' . $column_name . '`';
	}

	/**
	 * Recupere les lignes par curseur sur cle primaire.
	 *
	 * WHERE pk > $cursor ORDER BY pk ASC LIMIT $batch_size
	 *
	 * @param string $table       Nom de la table.
	 * @param string $primary_key Nom de la colonne PK.
	 * @param int    $cursor      Dernier ID exporte.
	 * @param int    $batch_size  Nombre de lignes.
	 *
	 * @return array Les lignes.
	 */
	private function fetch_rows_by_pk( $table, $primary_key, $cursor, $batch_size ) {
		global $wpdb;

		// Le nom de table et de colonne ont ete valides par regex dans handle_export().
		// On ne peut pas utiliser prepare() pour les identifiants SQL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE `{$primary_key}` > %d ORDER BY `{$primary_key}` ASC LIMIT %d",
				$cursor,
				$batch_size
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Recupere les lignes par OFFSET/LIMIT (fallback sans PK).
	 *
	 * @param string $table      Nom de la table.
	 * @param int    $offset     Offset de depart.
	 * @param int    $batch_size Nombre de lignes.
	 *
	 * @return array Les lignes.
	 */
	private function fetch_rows_by_offset( $table, $offset, $batch_size ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Recupere le CREATE TABLE statement.
	 *
	 * SHOW CREATE TABLE n'accepte pas de placeholder prepare(),
	 * mais le nom de table a ete valide par regex en amont.
	 *
	 * @param string $table Nom de la table (valide par regex /^[a-zA-Z0-9_]+$/).
	 *
	 * @return string|null Le CREATE TABLE ou null.
	 */
	private function get_create_table_statement( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_A );

		if ( $row && isset( $row['Create Table'] ) ) {
			return $row['Create Table'];
		}

		return null;
	}

	/**
	 * Assure que le repertoire temporaire existe et est protege.
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

		// Fichiers de protection contre l'acces direct.
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
}
