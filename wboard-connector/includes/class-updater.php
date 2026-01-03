<?php
/**
 * Classe de gestion des mises à jour du plugin via GitHub Releases.
 *
 * Intègre le plugin au système de mise à jour WordPress
 * en utilisant l'API GitHub Releases comme source.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Updater
 *
 * Gère les mises à jour automatiques depuis GitHub Releases.
 */
class WBoard_Connector_Updater {

	/**
	 * Propriétaire du dépôt GitHub.
	 *
	 * @var string
	 */
	const GITHUB_OWNER = 'willybahuaud';

	/**
	 * Nom du dépôt GitHub.
	 *
	 * @var string
	 */
	const GITHUB_REPO = 'wboard-connector';

	/**
	 * URL de base de l'API GitHub.
	 *
	 * @var string
	 */
	const GITHUB_API_URL = 'https://api.github.com';

	/**
	 * Durée du cache en secondes (12 heures).
	 *
	 * @var int
	 */
	const CACHE_DURATION = 43200;

	/**
	 * Slug du plugin.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Basename du plugin (dossier/fichier.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Données de la dernière release GitHub (cache mémoire).
	 *
	 * @var object|null
	 */
	private $github_release = null;

	/**
	 * Constructeur.
	 */
	public function __construct() {
		$this->plugin_slug     = 'wboard-connector';
		$this->plugin_basename = WBOARD_CONNECTOR_BASENAME;
	}

	/**
	 * Enregistre les hooks WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
	}

	/**
	 * Vérifie si une mise à jour est disponible sur GitHub.
	 *
	 * @param object $transient Transient des mises à jour plugins.
	 *
	 * @return object Transient modifié.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_github_release();

		if ( ! $release ) {
			return $transient;
		}

		$latest_version  = $this->parse_version( $release->tag_name );
		$current_version = WBOARD_CONNECTOR_VERSION;

		if ( version_compare( $latest_version, $current_version, '>' ) ) {
			$download_url = $this->get_download_url( $release );

			if ( $download_url ) {
				$transient->response[ $this->plugin_basename ] = (object) array(
					'slug'        => $this->plugin_slug,
					'plugin'      => $this->plugin_basename,
					'new_version' => $latest_version,
					'url'         => $release->html_url,
					'package'     => $download_url,
					'icons'       => array(),
					'banners'     => array(),
					'tested'      => '',
					'requires'    => '6.0',
				);
			}
		}

		return $transient;
	}

	/**
	 * Fournit les informations détaillées du plugin.
	 *
	 * Affiché dans la popup "Voir les détails" de l'admin.
	 *
	 * @param false|object|array $result Résultat par défaut.
	 * @param string             $action Type d'action.
	 * @param object             $args   Arguments de la requête.
	 *
	 * @return false|object Informations du plugin ou false.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		$release = $this->get_github_release();

		if ( ! $release ) {
			return $result;
		}

		$latest_version = $this->parse_version( $release->tag_name );

		return (object) array(
			'name'          => 'WBoard Connector',
			'slug'          => $this->plugin_slug,
			'version'       => $latest_version,
			'author'        => '<a href="https://github.com/' . self::GITHUB_OWNER . '">Willy Bahuaud</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'requires'      => '6.0',
			'tested'        => '',
			'downloaded'    => 0,
			'last_updated'  => $release->published_at,
			'sections'      => array(
				'description' => $this->get_plugin_description(),
				'changelog'   => $this->format_changelog( $release->body ),
			),
			'download_link' => $this->get_download_url( $release ),
		);
	}

	/**
	 * Corrige le nom du dossier après extraction du ZIP GitHub.
	 *
	 * GitHub nomme le dossier "repo-tag" au lieu de "repo".
	 *
	 * @param string      $source        Chemin du dossier extrait.
	 * @param string      $remote_source Chemin distant.
	 * @param WP_Upgrader $upgrader      Instance de l'upgrader.
	 * @param array       $hook_extra    Données supplémentaires.
	 *
	 * @return string|WP_Error Chemin corrigé ou erreur.
	 */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		// Vérifie si c'est notre plugin.
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		// Normalise les chemins pour comparaison (supprime trailing slash).
		$source_normalized   = untrailingslashit( $source );
		$expected_dir        = untrailingslashit( trailingslashit( $remote_source ) . $this->plugin_slug );

		// Si le dossier a déjà le bon nom, on ne fait rien.
		if ( $source_normalized === $expected_dir ) {
			return $source;
		}

		// Vérifie si le nom du dossier source se termine par le slug attendu.
		if ( basename( $source_normalized ) === $this->plugin_slug ) {
			return $source;
		}

		// Renomme le dossier.
		if ( $wp_filesystem->move( $source, trailingslashit( $expected_dir ) ) ) {
			return trailingslashit( $expected_dir );
		}

		return new WP_Error(
			'rename_failed',
			__( 'Impossible de renommer le dossier du plugin.', 'wboard-connector' )
		);
	}

	/**
	 * Récupère les informations de la dernière release GitHub.
	 *
	 * @return object|false Données de la release ou false si erreur.
	 */
	private function get_github_release() {
		// Cache mémoire pour éviter les requêtes multiples dans la même exécution.
		if ( null !== $this->github_release ) {
			return $this->github_release;
		}

		// Cache transient pour éviter les requêtes trop fréquentes.
		$cache_key = 'wboard_connector_github_release';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$this->github_release = $cached;
			return $cached;
		}

		$api_url = sprintf(
			'%s/repos/%s/%s/releases/latest',
			self::GITHUB_API_URL,
			self::GITHUB_OWNER,
			self::GITHUB_REPO
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WBoard-Connector/' . WBOARD_CONNECTOR_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->github_release = false;
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// 404 = pas encore de release, on retourne false sans erreur.
		if ( 404 === $code ) {
			$this->github_release = false;
			return false;
		}

		if ( 200 !== $code ) {
			$this->github_release = false;
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( empty( $data ) || ! isset( $data->tag_name ) ) {
			$this->github_release = false;
			return false;
		}

		set_transient( $cache_key, $data, self::CACHE_DURATION );
		$this->github_release = $data;

		return $data;
	}

	/**
	 * Extrait le numéro de version depuis un tag GitHub.
	 *
	 * Supprime le préfixe "v" si présent (v1.0.0 -> 1.0.0).
	 *
	 * @param string $tag_name Nom du tag GitHub.
	 *
	 * @return string Numéro de version.
	 */
	private function parse_version( $tag_name ) {
		return ltrim( $tag_name, 'vV' );
	}

	/**
	 * Récupère l'URL de téléchargement du ZIP depuis la release.
	 *
	 * Cherche d'abord un asset ZIP uploadé, sinon utilise le zipball auto-généré.
	 *
	 * @param object $release Données de la release GitHub.
	 *
	 * @return string|false URL de téléchargement ou false.
	 */
	private function get_download_url( $release ) {
		// Cherche un asset ZIP uploadé manuellement (préférable car nommé correctement).
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->content_type ) && 'application/zip' === $asset->content_type ) {
					return $asset->browser_download_url;
				}
				// Fallback sur l'extension du nom de fichier.
				if ( isset( $asset->name ) && str_ends_with( $asset->name, '.zip' ) ) {
					return $asset->browser_download_url;
				}
			}
		}

		// Fallback sur le zipball auto-généré par GitHub.
		if ( ! empty( $release->zipball_url ) ) {
			return $release->zipball_url;
		}

		return false;
	}

	/**
	 * Retourne la description du plugin pour la popup.
	 *
	 * @return string Description HTML.
	 */
	private function get_plugin_description() {
		return '<p>' . esc_html__(
			'Connecteur pour WBoard - Permet la supervision centralisée de votre site WordPress.',
			'wboard-connector'
		) . '</p>';
	}

	/**
	 * Formate le changelog depuis le corps de la release GitHub.
	 *
	 * @param string $body Corps de la release (Markdown).
	 *
	 * @return string Changelog formaté en HTML.
	 */
	private function format_changelog( $body ) {
		if ( empty( $body ) ) {
			return '<p>' . esc_html__( 'Aucune note de version disponible.', 'wboard-connector' ) . '</p>';
		}

		// Conversion basique Markdown -> HTML.
		$html = esc_html( $body );
		$html = nl2br( $html );

		// Convertit les listes Markdown.
		$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html );

		return $html;
	}
}
