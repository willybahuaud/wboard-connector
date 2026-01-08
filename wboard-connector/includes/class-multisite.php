<?php
/**
 * Classe utilitaire pour le support WordPress Multisite.
 *
 * Fournit des méthodes helper pour détecter le contexte multisite,
 * vérifier les plugins activés au niveau réseau, et gérer les super admins.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Multisite
 *
 * Utilitaires pour la compatibilité multisite.
 */
class WBoard_Connector_Multisite {

	/**
	 * Vérifie si l'installation WordPress est un multisite.
	 *
	 * Wrapper autour de la fonction native pour faciliter les tests.
	 *
	 * @return bool True si multisite, false sinon.
	 */
	public static function is_multisite() {
		return is_multisite();
	}

	/**
	 * Vérifie si un plugin est actif, que ce soit au niveau site ou réseau.
	 *
	 * En multisite, un plugin peut être activé :
	 * - Au niveau du site uniquement (dans wp_options.active_plugins)
	 * - Au niveau du réseau (dans wp_sitemeta.active_sitewide_plugins)
	 * - Les deux à la fois
	 *
	 * @param string $plugin Chemin du plugin (ex: 'wpvivid-backuprestore/wpvivid-backuprestore.php').
	 *
	 * @return bool True si le plugin est actif à n'importe quel niveau.
	 */
	public static function is_plugin_active_anywhere( $plugin ) {
		// Charge la fonction is_plugin_active si nécessaire.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Vérifie l'activation au niveau du site.
		if ( is_plugin_active( $plugin ) ) {
			return true;
		}

		// En multisite, vérifie aussi l'activation au niveau réseau.
		if ( self::is_multisite() && is_plugin_active_for_network( $plugin ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Retourne le niveau d'activation d'un plugin.
	 *
	 * @param string $plugin Chemin du plugin.
	 *
	 * @return string Niveau d'activation : 'none', 'site', 'network', ou 'both'.
	 */
	public static function get_plugin_activation_level( $plugin ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$is_site_active    = is_plugin_active( $plugin );
		$is_network_active = self::is_multisite() && is_plugin_active_for_network( $plugin );

		if ( $is_site_active && $is_network_active ) {
			return 'both';
		}

		if ( $is_network_active ) {
			return 'network';
		}

		if ( $is_site_active ) {
			return 'site';
		}

		return 'none';
	}

	/**
	 * Vérifie si un utilisateur est super admin.
	 *
	 * En mono-site, retourne toujours false.
	 * En multisite, vérifie si l'utilisateur fait partie des super admins du réseau.
	 *
	 * @param int $user_id ID de l'utilisateur à vérifier.
	 *
	 * @return bool True si l'utilisateur est super admin.
	 */
	public static function is_user_super_admin( $user_id ) {
		if ( ! self::is_multisite() ) {
			return false;
		}

		return is_super_admin( $user_id );
	}

	/**
	 * Retourne la liste des super admins du réseau.
	 *
	 * En mono-site, retourne un tableau vide.
	 * En multisite, retourne les objets WP_User des super admins.
	 *
	 * @return WP_User[] Liste des super admins.
	 */
	public static function get_super_admins() {
		if ( ! self::is_multisite() ) {
			return array();
		}

		$super_admin_logins = get_super_admins();
		$super_admins       = array();

		foreach ( $super_admin_logins as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				$super_admins[] = $user;
			}
		}

		return $super_admins;
	}

	/**
	 * Retourne les informations sur le contexte multisite.
	 *
	 * Utilisé pour enrichir la réponse de l'API /status.
	 *
	 * @return array Informations multisite ou null si mono-site.
	 */
	public static function get_multisite_info() {
		if ( ! self::is_multisite() ) {
			return array(
				'is_multisite' => false,
			);
		}

		$current_blog_id = get_current_blog_id();
		$network         = get_network();

		return array(
			'is_multisite'   => true,
			'is_main_site'   => is_main_site(),
			'blog_id'        => $current_blog_id,
			'network_id'     => $network ? $network->id : null,
			'network_name'   => $network ? $network->site_name : null,
			'network_domain' => $network ? $network->domain : null,
			'site_count'     => self::get_site_count(),
		);
	}

	/**
	 * Retourne le nombre de sites dans le réseau.
	 *
	 * @return int Nombre de sites, ou 1 si mono-site.
	 */
	public static function get_site_count() {
		if ( ! self::is_multisite() ) {
			return 1;
		}

		return get_blog_count();
	}

	/**
	 * Retourne l'URL d'administration appropriée pour un utilisateur.
	 *
	 * - Super admin → network admin
	 * - Admin du site → admin du site
	 *
	 * @param int $user_id ID de l'utilisateur.
	 *
	 * @return string URL d'administration.
	 */
	public static function get_admin_url_for_user( $user_id ) {
		if ( self::is_user_super_admin( $user_id ) ) {
			return network_admin_url();
		}

		return admin_url();
	}

	/**
	 * Vérifie si un utilisateur peut administrer le site courant.
	 *
	 * Retourne true si :
	 * - L'utilisateur est administrateur du site courant
	 * - OU l'utilisateur est super admin (en multisite)
	 *
	 * @param int $user_id ID de l'utilisateur.
	 *
	 * @return bool True si l'utilisateur peut administrer.
	 */
	public static function user_can_administrate( $user_id ) {
		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return false;
		}

		// Super admin peut tout administrer.
		if ( self::is_user_super_admin( $user_id ) ) {
			return true;
		}

		// Vérifie le rôle administrateur sur le site courant.
		return user_can( $user, 'administrator' );
	}

	/**
	 * Récupère une option en vérifiant d'abord le site, puis le réseau.
	 *
	 * Utile pour les options qui peuvent être stockées à différents niveaux
	 * selon la configuration du plugin (ex: Vivid Backup).
	 *
	 * @param string $option_name Nom de l'option.
	 * @param mixed  $default     Valeur par défaut si l'option n'existe pas.
	 *
	 * @return mixed Valeur de l'option.
	 */
	public static function get_option_anywhere( $option_name, $default = false ) {
		// Essaie d'abord au niveau du site.
		$value = get_option( $option_name, null );

		if ( null !== $value ) {
			return $value;
		}

		// En multisite, essaie au niveau réseau.
		if ( self::is_multisite() ) {
			$value = get_site_option( $option_name, null );

			if ( null !== $value ) {
				return $value;
			}
		}

		return $default;
	}
}
