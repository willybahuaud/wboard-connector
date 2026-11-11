<?php
/**
 * Inventaire des mu-plugins du site.
 *
 * Le board tient un catalogue de patches de mitigation, posés à la main dans
 * mu-plugins. Pour savoir où un patch est réellement en place, il a besoin de
 * la liste des fichiers et de leur empreinte. Lecture seule : aucun contenu
 * de fichier ne quitte le site, seulement son nom, sa taille et son hash.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Mu_Plugins
 *
 * Liste les fichiers PHP chargés automatiquement depuis mu-plugins.
 */
class WBoard_Connector_Mu_Plugins {

	/**
	 * Nombre maximum de fichiers remontés.
	 *
	 * @var int
	 */
	const MAX_FILES = 100;

	/**
	 * Taille au-delà de laquelle un fichier n'est pas hashé (un patch fait quelques Ko).
	 *
	 * @var int
	 */
	const MAX_HASHED_BYTES = 1048576;

	/**
	 * Retourne les fichiers de mu-plugins sans jamais faire échouer l'appelant.
	 *
	 * Cette liste est une information de confort pour le board. Si elle ne
	 * peut pas être établie (fonction désactivée par l'hébergeur, droits,
	 * mémoire), le statut du site doit quand même partir : sinon le board
	 * verrait le site comme injoignable pour une donnée secondaire.
	 *
	 * @return array|null Liste de fichiers, ou null si elle n'a pas pu être établie.
	 */
	public static function get_files_safely() {
		try {
			return self::get_files();
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Retourne les fichiers PHP à la racine de mu-plugins.
	 *
	 * Seule la racine compte : WordPress ne charge pas automatiquement les
	 * fichiers des sous-dossiers, un patch qui s'y trouverait ne s'exécute pas.
	 *
	 * @return array Liste de fichiers : file, sha256 (null si trop gros ou illisible), size.
	 */
	public static function get_files() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! is_dir( WPMU_PLUGIN_DIR ) ) {
			return array();
		}

		// Certains hébergeurs désactivent glob() : on renonce plutôt que de provoquer une erreur fatale.
		if ( ! function_exists( 'glob' ) ) {
			return array();
		}

		$paths = glob( trailingslashit( WPMU_PLUGIN_DIR ) . '*.php' );

		if ( empty( $paths ) ) {
			return array();
		}

		sort( $paths );

		$files = array();

		foreach ( array_slice( $paths, 0, self::MAX_FILES ) as $path ) {
			$files[] = array(
				'file'   => basename( $path ),
				'sha256' => self::hash_file( $path ),
				'size'   => (int) filesize( $path ),
			);
		}

		return $files;
	}

	/**
	 * Calcule l'empreinte normalisée d'un fichier.
	 *
	 * Les fins de ligne et les espaces de fin sont normalisés, comme côté
	 * board : un transfert FTP en mode texte ou un éditeur qui ajoute une
	 * ligne vide ne doit pas faire passer un patch pour un autre.
	 *
	 * @param string $path Chemin absolu du fichier.
	 *
	 * @return string|null Hash sha256 hexadécimal, ou null si non calculable.
	 */
	private static function hash_file( $path ) {
		if ( ! is_readable( $path ) || filesize( $path ) > self::MAX_HASHED_BYTES ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local, pas de requête distante.
		$content = file_get_contents( $path );

		if ( false === $content ) {
			return null;
		}

		return hash( 'sha256', rtrim( str_replace( "\r\n", "\n", $content ) ) );
	}
}
