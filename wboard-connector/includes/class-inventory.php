<?php
/**
 * Module d'inventaire pour les exclusions de backup.
 *
 * Expose GET /wboard/v1/inventory.
 * Retourne un inventaire compact : structure de wp-content avec tailles
 * agregees par dossier (profondeur limitee), stats d'extensions, et liste
 * des tables BDD avec taille + nombre de lignes + moteur.
 *
 * But : permettre au board de suggerer des exclusions backup intelligentes
 * en croisant ces donnees avec des regles curatees + raisonnement LLM.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestion de la route /inventory.
 */
class WBoard_Connector_Inventory {

	/**
	 * Profondeur maximale du scan de fichiers.
	 *
	 * Au-dela de cette profondeur, on ne descend plus dans l'arborescence,
	 * on agrege juste la taille recursive. Garde la reponse raisonnable
	 * meme sur des sites avec 50k+ sous-dossiers.
	 *
	 * @var int
	 */
	private const FILE_SCAN_MAX_DEPTH = 3;

	/**
	 * Nombre max de "top extensions" retourne par dossier scanne.
	 *
	 * @var int
	 */
	private const TOP_EXTENSIONS_LIMIT = 8;

	/**
	 * Instance Security pour la verif HMAC.
	 *
	 * @var WBoard_Connector_Security
	 */
	private $security;

	/**
	 * Constructeur.
	 *
	 * @param WBoard_Connector_Security $security Instance Security (verif HMAC).
	 */
	public function __construct( WBoard_Connector_Security $security ) {
		$this->security = $security;
	}

	/**
	 * Enregistre les hooks WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Enregistre la route REST /inventory.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'wboard/v1',
			'/inventory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_inventory' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission callback - reutilise la verif HMAC standard du plugin.
	 *
	 * @param WP_REST_Request $request La requete.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		return $this->security->verify_request( $request );
	}

	/**
	 * Endpoint principal : retourne l'inventaire compact.
	 *
	 * @param WP_REST_Request $request La requete.
	 *
	 * @return WP_REST_Response
	 */
	public function get_inventory( WP_REST_Request $request ) {
		$start = microtime( true );

		$data = array(
			'plugin_version'   => defined( 'WBOARD_CONNECTOR_VERSION' ) ? WBOARD_CONNECTOR_VERSION : 'unknown',
			'inventory_schema' => 1,
			'generated_at'     => gmdate( 'c' ),
			'wp_content_path'  => WP_CONTENT_DIR,
			'files'            => $this->scan_files(),
			'tables'           => $this->scan_tables(),
		);

		$data['scan_duration_ms'] = (int) ( ( microtime( true ) - $start ) * 1000 );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Scan recursif de wp-content avec profondeur limitee.
	 *
	 * @return array<string,mixed>
	 */
	private function scan_files() {
		if ( ! is_readable( WP_CONTENT_DIR ) ) {
			return array( 'error' => 'wp-content not readable' );
		}

		return $this->scan_directory( WP_CONTENT_DIR, 0 );
	}

	/**
	 * Scan recursif d'un dossier.
	 *
	 * - Profondeur < MAX_DEPTH : descend dans les sous-dossiers et expose leur structure.
	 * - Profondeur >= MAX_DEPTH : ne descend plus, agrege juste taille + nb fichiers.
	 *
	 * @param string $path  Chemin absolu.
	 * @param int    $depth Profondeur actuelle.
	 *
	 * @return array<string,mixed>
	 */
	private function scan_directory( $path, $depth ) {
		$total_size   = 0;
		$file_count   = 0;
		$dir_count    = 0;
		$extensions   = array();
		$children     = array();
		$latest_mtime = 0;

		$entries = @scandir( $path );
		if ( false === $entries ) {
			return array(
				'size_bytes'  => 0,
				'file_count'  => 0,
				'error'       => 'not_readable',
			);
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$full = $path . DIRECTORY_SEPARATOR . $entry;

			if ( is_link( $full ) ) {
				// Skip les symlinks pour eviter les boucles + faux positifs de taille.
				continue;
			}

			if ( is_dir( $full ) ) {
				$dir_count++;
				if ( $depth < self::FILE_SCAN_MAX_DEPTH ) {
					$child                  = $this->scan_directory( $full, $depth + 1 );
					$children[ $entry ]     = $child;
					$total_size            += isset( $child['size_bytes'] ) ? (int) $child['size_bytes'] : 0;
					$file_count            += isset( $child['file_count'] ) ? (int) $child['file_count'] : 0;
					// On utilise _mtime_ts (int epoch) pour la comparaison, pas latest_mtime (string ISO).
					if ( isset( $child['_mtime_ts'] ) && (int) $child['_mtime_ts'] > $latest_mtime ) {
						$latest_mtime = (int) $child['_mtime_ts'];
					}
				} else {
					// Au-dela de MAX_DEPTH on agrege juste sans exposer la structure.
					$agg         = $this->recursive_size_only( $full );
					$total_size += $agg['size_bytes'];
					$file_count += $agg['file_count'];
					if ( $agg['latest_mtime'] > $latest_mtime ) {
						$latest_mtime = $agg['latest_mtime'];
					}
				}
				continue;
			}

			if ( is_file( $full ) ) {
				$size        = (int) @filesize( $full );
				$mtime       = (int) @filemtime( $full );
				$total_size += $size;
				$file_count++;
				if ( $mtime > $latest_mtime ) {
					$latest_mtime = $mtime;
				}

				$ext                  = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
				$ext_key              = '' === $ext ? '(no-ext)' : $ext;
				$extensions[ $ext_key ] = ( $extensions[ $ext_key ] ?? 0 ) + 1;
			}
		}

		arsort( $extensions );
		$top_ext = array_slice( $extensions, 0, self::TOP_EXTENSIONS_LIMIT, true );

		$result = array(
			'size_bytes'    => $total_size,
			'file_count'    => $file_count,
			'dir_count'     => $dir_count,
			// latest_mtime : ISO 8601 pour conso humaine/Claude.
			'latest_mtime'  => $latest_mtime > 0 ? gmdate( 'c', $latest_mtime ) : null,
			// _mtime_ts : epoch int pour comparaison interne (recursion parent).
			'_mtime_ts'     => $latest_mtime > 0 ? $latest_mtime : 0,
			'top_extensions' => $top_ext,
		);

		if ( ! empty( $children ) ) {
			$result['children'] = $children;
		}

		return $result;
	}

	/**
	 * Calcule la taille recursive d'un dossier sans exposer la structure.
	 *
	 * Utilise au-dela de MAX_DEPTH. Iteratif (sans recursion PHP) pour eviter
	 * stack overflow sur des arborescences profondes.
	 *
	 * @param string $root Chemin absolu racine.
	 *
	 * @return array{size_bytes:int,file_count:int,latest_mtime:int}
	 */
	private function recursive_size_only( $root ) {
		$size        = 0;
		$count       = 0;
		$latest      = 0;
		$stack       = array( $root );

		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			$entries = @scandir( $current );
			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$full = $current . DIRECTORY_SEPARATOR . $entry;
				if ( is_link( $full ) ) {
					continue;
				}
				if ( is_dir( $full ) ) {
					$stack[] = $full;
				} elseif ( is_file( $full ) ) {
					$size += (int) @filesize( $full );
					$count++;
					$m = (int) @filemtime( $full );
					if ( $m > $latest ) {
						$latest = $m;
					}
				}
			}
		}

		return array(
			'size_bytes'   => $size,
			'file_count'   => $count,
			'latest_mtime' => $latest,
		);
	}

	/**
	 * Liste les tables de la BDD avec leur taille + nb lignes + moteur.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_tables() {
		global $wpdb;

		$db_name = $wpdb->dbname;
		if ( empty( $db_name ) ) {
			return array();
		}

		// Pas de prepare() sur SCHEMA car le nom de la DB ne vient pas d'input user
		// mais de la config WP. On caste en string par precaution.
		$sql = $wpdb->prepare(
			'SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, TABLE_ROWS, ENGINE
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = %s
			ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
			$db_name
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$data_len  = (int) ( $row['DATA_LENGTH'] ?? 0 );
			$index_len = (int) ( $row['INDEX_LENGTH'] ?? 0 );
			$out[]     = array(
				'name'         => (string) $row['TABLE_NAME'],
				'size_bytes'   => $data_len + $index_len,
				'data_bytes'   => $data_len,
				'index_bytes'  => $index_len,
				'rows'         => (int) ( $row['TABLE_ROWS'] ?? 0 ),
				'engine'       => (string) ( $row['ENGINE'] ?? '' ),
			);
		}

		return $out;
	}
}
