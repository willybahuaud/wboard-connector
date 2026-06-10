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
			$table_name = $table['name'];

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
				'name'         => $table_name,
				'rows'         => $table['table_rows'],
				'data_length'  => $table['data_length'],
				'index_length' => $table['index_length'],
				'update_time'  => $table['update_time'],
				'fingerprint'  => $fingerprint,
				'primary_key'  => $primary_key,
				'has_pk'       => ! empty( $primary_key ),
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

			if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $name ) ) {
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

			// Securite : on ignore le primary_key du body HTTP (risque SQLi).
			// On le deduit cote serveur via INFORMATION_SCHEMA.
			$server_pk = $this->get_primary_key( $name );

			$validated[] = array(
				'name'        => $name,
				'primary_key' => $server_pk,
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
	 * Gere la requete de streaming SQL direct d'UNE seule table.
	 *
	 * Body attendu :
	 * {
	 *   "name": "wp_postmeta",
	 *   "batch_size": 2000  // optionnel
	 * }
	 *
	 * Retourne le SQL brut (CREATE TABLE + INSERTs) en text/plain.
	 * Pas de tar, pas de fichier temp : le SQL est emis au fil de l'eau, ligne par
	 * ligne, avec flush() periodique pour maintenir la connexion vivante. C'est
	 * crucial pour les grosses tables (postmeta...) qui sinon font crasher la
	 * connexion par idle proxy / PHP-FPM.
	 *
	 * @param WP_REST_Request $request La requete REST.
	 * @param array           $config  La config backup.
	 *
	 * @return WP_REST_Response|WP_Error|void
	 */
	public function handle_stream_single_table( WP_REST_Request $request, array $config ) {
		global $wpdb;

		$body = json_decode( $request->get_body(), true );
		$name = isset( $body['name'] ) ? $body['name'] : '';

		if ( '' === $name || strpos( $name, $wpdb->prefix ) !== 0 ) {
			return new WP_Error(
				'wboard_backup_db_invalid_table',
				__( 'Nom de table invalide.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $name ) ) {
			return new WP_Error(
				'wboard_backup_db_invalid_table',
				__( 'Nom de table invalide.', 'wboard-connector' ),
				array( 'status' => 400 )
			);
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
			return new WP_Error(
				'wboard_backup_db_table_not_found',
				__( 'Table introuvable.', 'wboard-connector' ),
				array( 'status' => 404 )
			);
		}

		// On (re)deduit la PK cote serveur pour ne pas accepter une PK fournie par le client.
		$primary_key = $this->get_primary_key( $name );
		$batch_size  = isset( $body['batch_size'] ) ? (int) $body['batch_size'] : self::DEFAULT_BATCH_SIZE;

		$this->stream_single_table_to_response( $name, $primary_key, $batch_size );
		exit;
	}

	/**
	 * Stream le SQL d'une table directement dans la reponse HTTP.
	 *
	 * Format : "CREATE TABLE ...;\n\nINSERT ...;\nINSERT ...;\n..."
	 * Aucun fichier temp, aucun tar : la connexion porte du trafic en permanence
	 * (flush apres chaque batch) → le proxy ne coupe pas.
	 *
	 * @param string      $table       Nom de la table.
	 * @param string|null $primary_key Colonne PK (null si pas de PK).
	 * @param int         $batch_size  Lignes par requete SQL interne.
	 *
	 * @return void
	 */
	private function stream_single_table_to_response( $table, $primary_key, $batch_size ) {
		global $wpdb;

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		set_time_limit( 0 );

		// Tente d'augmenter la memoire pour les grosses tables.
		$current_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $current_limit > 0 && $current_limit < 512 * 1024 * 1024 ) {
			@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		}

		header( 'Content-Type: application/sql; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $table . '.sql"' );
		header( 'X-Accel-Buffering: no' ); // Desactive le buffering nginx s'il existe.

		$batch_size = $this->adapt_batch_size( $batch_size );

		// Header SQL : CREATE TABLE.
		$create_table = $this->get_create_table_statement( $table );
		if ( $create_table ) {
			echo "-- Table: {$table}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "DROP TABLE IF EXISTS `{$table}`;\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $create_table . ";\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}

		// Boucle d'export streamee : MYSQLI_USE_RESULT (unbuffered) pour ne jamais
		// charger un batch complet en memoire PHP.
		$cursor     = 0;
		$total_rows = 0;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[WBoard DB] Stream-table debut %s (pk=%s, batch=%d)', $table, $primary_key ?? 'null', $batch_size ) );

		while ( true ) {
			if ( method_exists( $wpdb, 'check_connection' ) ) {
				$wpdb->check_connection();
			}

			if ( ! empty( $primary_key ) ) {
				$sql = $wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE `{$primary_key}` > %d ORDER BY `{$primary_key}` ASC LIMIT %d",
					$cursor,
					$batch_size
				);
			} else {
				$sql = $wpdb->prepare(
					"SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
					$batch_size,
					$cursor
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->dbh->query( $sql, MYSQLI_USE_RESULT );

			if ( ! $result ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[WBoard DB] Stream-table erreur SQL %s curseur=%d : %s', $table, $cursor, $wpdb->last_error ) );
				break;
			}

			$columns_str = null;
			$batch_count = 0;

			// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( $row = $result->fetch_assoc() ) {
				if ( null === $columns_str ) {
					$columns_escaped = array_map( array( $this, 'escape_column_name' ), array_keys( $row ) );
					$columns_str     = implode( ', ', $columns_escaped );
				}

				$values = array();
				foreach ( $row as $value ) {
					if ( null === $value ) {
						$values[] = 'NULL';
					} else {
						$values[] = "'" . $wpdb->dbh->real_escape_string( $value ) . "'";
					}
				}

				echo "INSERT INTO `{$table}` ({$columns_str}) VALUES (" . implode( ', ', $values ) . ");\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				if ( ! empty( $primary_key ) && isset( $row[ $primary_key ] ) ) {
					$cursor = (int) $row[ $primary_key ];
				}

				$batch_count++;
			}

			$result->free();

			if ( empty( $primary_key ) ) {
				$cursor += $batch_count;
			}

			$total_rows += $batch_count;

			// Flush apres chaque batch : maintient la connexion active.
			if ( function_exists( 'flush' ) ) {
				flush();
			}

			if ( $batch_count < $batch_size ) {
				break;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[WBoard DB] Stream-table fini %s : %d lignes', $table, $total_rows ) );
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
		global $wpdb;

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		set_time_limit( 0 );

		// Tente d'augmenter la memoire pour les grosses tables.
		$current_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $current_limit > 0 && $current_limit < 512 * 1024 * 1024 ) {
			@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		}

		header( 'Content-Type: application/x-tar' );
		header( 'X-WBoard-Tables-Count: ' . count( $tables ) );

		$temp_dir = self::ensure_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[WBoard DB] Debut streaming %d tables (memory: %s, limit: %s, temp: %s)', count( $tables ), size_format( memory_get_usage( true ) ), ini_get( 'memory_limit' ), $temp_dir ) );

		foreach ( $tables as $table_info ) {
			$name       = $table_info['name'];
			$pk         = $table_info['primary_key'];
			$batch_size = $this->adapt_batch_size( $table_info['batch_size'] );

			// Liberation memoire entre les tables.
			$wpdb->flush();

			// Export complet de la table vers un fichier temporaire.
			$file_id  = wp_generate_password( 8, false, false );
			$sql_path = $temp_dir . '/stream-' . $file_id . '.sql';

			$this->export_full_table_to_file( $name, $pk, $batch_size, $sql_path );

			$handle = @fopen( $sql_path, 'rb' );
			if ( false === $handle ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[WBoard DB] Table %s skippee : fopen echoue (pk=%s, batch=%d)', $name, $pk ?? 'null', $batch_size ) );
				@unlink( $sql_path );
				continue;
			}

			// Lecture de la taille via fstat() sur le handle ouvert (et pas filesize()
			// qui peut renvoyer une valeur cachee/incoherente avec ce que fread va lire).
			// La taille annoncee dans le header tar DOIT correspondre exactement au
			// nombre d'octets emis dans le body, sinon le parser tar cote backup-manager
			// se decale et l'archive entiere est corrompue.
			$stat = @fstat( $handle );
			$size = ( is_array( $stat ) && isset( $stat['size'] ) ) ? (int) $stat['size'] : 0;
			if ( 0 === $size ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[WBoard DB] Table %s skippee : fichier vide (pk=%s, batch=%d, memory=%s)', $name, $pk ?? 'null', $batch_size, size_format( memory_get_usage( true ) ) ) );
				fclose( $handle );
				@unlink( $sql_path );
				continue;
			}

			// Header tar pour cette table.
			echo $this->build_db_tar_header( $name . '.sql', $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			// Stream le contenu : on lit exactement $size octets, sortie de boucle
			// sur chunk vide ou false (EOF / erreur).
			$remaining = $size;
			while ( $remaining > 0 ) {
				$chunk_size = min( $remaining, 8192 );
				$chunk      = fread( $handle, $chunk_size );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$remaining -= strlen( $chunk );
			}
			fclose( $handle );

			// Si la lecture a ete plus courte que $size annonce, on bourre avec des
			// zeros pour rester aligne avec le header tar (sinon batch DB corrompu).
			if ( $remaining > 0 ) {
				echo str_repeat( "\0", $remaining ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			// Padding tar a 512 octets (base sur la taille annoncee, pas relue du FS).
			$padding = self::TAR_BLOCK_SIZE - ( $size % self::TAR_BLOCK_SIZE );
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
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[WBoard DB] fopen echoue pour %s', $file_path ) );
			return;
		}

		// Header SQL : CREATE TABLE.
		$create_table = $this->get_create_table_statement( $table );
		if ( $create_table ) {
			fwrite( $handle, "-- Table: {$table}\n" );
			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
			fwrite( $handle, $create_table . ";\n\n" );
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[WBoard DB] SHOW CREATE TABLE echoue pour %s (last_error: %s)', $table, $wpdb->last_error ) );
		}

		// Export unbuffered : les lignes sont streamees une par une depuis MySQL
		// sans charger le batch complet en memoire PHP.
		// Pagination par curseur PK (ou OFFSET) pour ne pas bloquer la table.
		$cursor     = 0;
		$total_rows = 0;

		while ( true ) {
			$batch_rows = $this->stream_rows_to_file( $handle, $table, $primary_key, $cursor, $batch_size );

			if ( false === $batch_rows ) {
				// Erreur MySQL.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[WBoard DB] Erreur export %s au curseur %d : %s', $table, $cursor, $wpdb->last_error ) );
				break;
			}

			$total_rows += $batch_rows;

			if ( $batch_rows < $batch_size ) {
				break;
			}
		}

		fclose( $handle );

		$final_size = @filesize( $file_path );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[WBoard DB] Export %s : %d lignes, %d octets (pk=%s, batch=%d)', $table, $total_rows, $final_size, $primary_key ?? 'null', $batch_size ) );
	}

	/**
	 * Streame les lignes d'un batch directement dans le fichier SQL.
	 *
	 * Utilise MYSQLI_USE_RESULT (unbuffered) pour ne jamais charger
	 * plus d'une ligne a la fois en memoire PHP.
	 * Chaque ligne est ecrite immediatement dans le fichier puis liberee.
	 *
	 * @param resource    $handle      Handle du fichier de sortie.
	 * @param string      $table       Nom de la table.
	 * @param string|null $primary_key Colonne PK (null si pas de PK).
	 * @param int         &$cursor     Curseur (PK ou offset), modifie en place.
	 * @param int         $batch_size  Nombre de lignes max par requete.
	 *
	 * @return int|false Nombre de lignes ecrites, ou false en cas d'erreur.
	 */
	private function stream_rows_to_file( $handle, $table, $primary_key, &$cursor, $batch_size ) {
		global $wpdb;

		// Reconnexion si necessaire.
		if ( method_exists( $wpdb, 'check_connection' ) ) {
			$wpdb->check_connection();
		}

		// Construction de la requete SQL.
		if ( ! empty( $primary_key ) ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE `{$primary_key}` > %d ORDER BY `{$primary_key}` ASC LIMIT %d",
				$cursor,
				$batch_size
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
				$batch_size,
				$cursor
			);
		}

		// Requete unbuffered : MySQL envoie les lignes a la demande,
		// PHP ne stocke qu'une seule ligne a la fois en memoire.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->dbh->query( $sql, MYSQLI_USE_RESULT );

		if ( ! $result ) {
			return false;
		}

		$columns_str = null;
		$count       = 0;

		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( $row = $result->fetch_assoc() ) {
			// Header colonnes au premier passage.
			if ( null === $columns_str ) {
				$columns_escaped = array_map( array( $this, 'escape_column_name' ), array_keys( $row ) );
				$columns_str     = implode( ', ', $columns_escaped );
			}

			$values = array();
			foreach ( $row as $value ) {
				if ( null === $value ) {
					$values[] = 'NULL';
				} else {
					$values[] = "'" . $wpdb->dbh->real_escape_string( $value ) . "'";
				}
			}

			fwrite( $handle, "INSERT INTO `{$table}` ({$columns_str}) VALUES (" . implode( ', ', $values ) . ");\n" );

			// Met a jour le curseur.
			if ( ! empty( $primary_key ) && isset( $row[ $primary_key ] ) ) {
				$cursor = (int) $row[ $primary_key ];
			}

			$count++;
		}

		// Libere le result set (obligatoire avec MYSQLI_USE_RESULT
		// avant toute autre requete sur cette connexion).
		$result->free();

		if ( empty( $primary_key ) ) {
			$cursor += $count;
		}

		return $count;
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
	 * Recupere les infos des tables via la brique partagee d'introspection.
	 *
	 * @return array Liste des tables avec metadata.
	 */
	private function get_tables_info() {
		return WBoard_Connector_Db_Tables::inspect();
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

		// Verifie que la connexion MySQL est toujours active.
		// Reconnexion automatique si elle a ete coupee (timeout, etc.).
		if ( method_exists( $wpdb, 'check_connection' ) ) {
			$wpdb->check_connection();
		}

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

		// Verifie que la connexion MySQL est toujours active.
		if ( method_exists( $wpdb, 'check_connection' ) ) {
			$wpdb->check_connection();
		}

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
	 * @param string $table Nom de la table (valide par regex /^[a-zA-Z0-9_-]+$/).
	 *
	 * @return string|null Le CREATE TABLE ou null.
	 */
	private function get_create_table_statement( $table ) {
		global $wpdb;

		// Verifie que la connexion MySQL est toujours active.
		if ( method_exists( $wpdb, 'check_connection' ) ) {
			$wpdb->check_connection();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_A );

		if ( $row && isset( $row['Create Table'] ) ) {
			return $row['Create Table'];
		}

		return null;
	}

	/**
	 * Assure que le repertoire temporaire existe.
	 *
	 * Utilise sys_get_temp_dir() pour placer les fichiers hors du webroot
	 * (protection contre l'acces direct, quel que soit le serveur web).
	 * Fallback sur WP_CONTENT_DIR/wboard-tmp si sys_get_temp_dir() echoue.
	 *
	 * @return string|WP_Error Le chemin du repertoire temporaire.
	 */
	public static function ensure_temp_dir() {
		// Priorite : hors webroot (securite Nginx/Apache/LiteSpeed).
		$sys_temp = sys_get_temp_dir() . '/wboard-backup';

		if ( ! file_exists( $sys_temp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			@mkdir( $sys_temp, 0700, true );
		}

		if ( is_dir( $sys_temp ) && is_writable( $sys_temp ) ) {
			return $sys_temp;
		}

		// Fallback : wp-content/wboard-tmp avec protections web.
		return self::ensure_temp_dir_fallback();
	}

	/**
	 * Fallback : cree le repertoire temporaire dans wp-content avec protections.
	 *
	 * @return string|WP_Error Le chemin du repertoire temporaire.
	 */
	private static function ensure_temp_dir_fallback() {
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

		// Protection Apache.
		$htaccess_path = $temp_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_path, 'deny from all' );
		}

		// Protection Nginx (hint : si Nginx est configure avec include).
		$nginx_path = $temp_dir . '/nginx.conf';
		if ( ! file_exists( $nginx_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $nginx_path, "location ~* /wboard-tmp/ {\n\tdeny all;\n\treturn 403;\n}" );
		}

		// Index PHP silencieux.
		$index_path = $temp_dir . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_path, '<?php // Silence is golden.' );
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
