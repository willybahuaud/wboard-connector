<?php
/**
 * Inspection des tables MySQL via INFORMATION_SCHEMA.
 *
 * Brique partagee entre l'inventaire (audit exclusions backup) et
 * l'export DB (scan pour bucketisation des lots cote backup-manager).
 *
 * Centralise la requete pour garantir que tous les consommateurs
 * disposent du meme jeu de metadata (data_length + index_length, etc.)
 * et evite que l'un sous-estime la taille reelle d'une table.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Db_Tables
 */
class WBoard_Connector_Db_Tables {

	/**
	 * Liste les tables de la base courante avec leurs metadata utiles.
	 *
	 * Trie par taille totale (data + index) descendante pour faciliter
	 * la consommation cote audit. L'ordre n'a pas d'impact pour les
	 * consommateurs qui ne s'en servent pas (ex. scan backup).
	 *
	 * Chaque entree contient :
	 *   - name           (string)  Nom de la table.
	 *   - table_rows     (int)     Nombre approximatif de lignes (InnoDB : estimation).
	 *   - data_length    (int)     Taille des donnees en octets.
	 *   - index_length   (int)     Taille des index en octets.
	 *   - total_length   (int)     data_length + index_length.
	 *   - engine         (string)  Moteur de stockage (InnoDB, MyISAM...).
	 *   - update_time    (?string) Date de derniere modif (peut etre null en InnoDB).
	 *   - auto_increment (?int)    Valeur courante de l'AUTO_INCREMENT, si applicable.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function inspect() {
		global $wpdb;

		$db_name = $wpdb->dbname;
		if ( empty( $db_name ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS table_name,
					TABLE_ROWS AS table_rows,
					DATA_LENGTH AS data_length,
					INDEX_LENGTH AS index_length,
					ENGINE AS engine,
					UPDATE_TIME AS update_time,
					AUTO_INCREMENT AS auto_increment
				FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_SCHEMA = %s
				ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
				$db_name
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$data_len  = (int) ( $row['data_length'] ?? 0 );
			$index_len = (int) ( $row['index_length'] ?? 0 );

			$out[] = array(
				'name'           => (string) $row['table_name'],
				'table_rows'     => (int) ( $row['table_rows'] ?? 0 ),
				'data_length'    => $data_len,
				'index_length'   => $index_len,
				'total_length'   => $data_len + $index_len,
				'engine'         => (string) ( $row['engine'] ?? '' ),
				'update_time'    => isset( $row['update_time'] ) ? (string) $row['update_time'] : null,
				'auto_increment' => isset( $row['auto_increment'] ) ? (int) $row['auto_increment'] : null,
			);
		}

		return $out;
	}
}
