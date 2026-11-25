<?php
/**
 * Comptes à privilèges et mots de passe d'application du site.
 *
 * @package WBoard_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe WBoard_Connector_Privileged_Accounts.
 *
 * Remonte au board de quoi repérer une prise de contrôle : qui administre le
 * site, et quels mots de passe d'application existent. Un mot de passe
 * d'application survit à un changement de mot de passe et contourne la double
 * authentification : c'est la porte dérobée la plus discrète de WordPress.
 *
 * Aucun secret ne sort d'ici : ni le hash du mot de passe d'application, ni
 * celui du compte.
 */
class WBoard_Connector_Privileged_Accounts {

	/**
	 * Nombre maximal de mots de passe d'application remontés.
	 *
	 * @var int
	 */
	const MAX_APPLICATION_PASSWORDS = 200;

	/**
	 * Nombre maximal de sites regardés pour trouver le rôle d'un utilisateur en multisite.
	 *
	 * @var int
	 */
	const MAX_SITES_PER_USER = 50;

	/**
	 * Rôles natifs, du plus fort au plus faible.
	 *
	 * @var string[]
	 */
	const ROLES_BY_STRENGTH = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	/**
	 * Complète la fiche d'un administrateur.
	 *
	 * @param WP_User $user Utilisateur.
	 *
	 * @return array{email: string, registered_at: string|null}
	 */
	public static function describe_user( WP_User $user ) {
		return array(
			'email'         => (string) $user->user_email,
			'registered_at' => self::to_iso( strtotime( (string) $user->user_registered . ' UTC' ) ),
		);
	}

	/**
	 * Liste les mots de passe d'application du site, tous utilisateurs confondus.
	 *
	 * Tous les rôles sont couverts : un mot de passe d'application posé sur un
	 * compte éditeur suffit à réécrire les contenus.
	 *
	 * @return array Liste de { user_id, username, role, uuid, name, created_at, last_used_at, last_ip }.
	 */
	public static function get_application_passwords() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'blog_id'      => 0,
				'orderby'      => 'ID',
				'order'        => 'ASC',
			)
		);

		$passwords = array();
		foreach ( $users as $user ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $password ) {
				if ( count( $passwords ) >= self::MAX_APPLICATION_PASSWORDS ) {
					return $passwords;
				}

				$passwords[] = self::describe_application_password( $user, $password );
			}
		}

		return $passwords;
	}

	/**
	 * Décrit un mot de passe d'application, sans son secret.
	 *
	 * @param WP_User $user     Propriétaire.
	 * @param array   $password Mot de passe d'application tel que stocké par WordPress.
	 *
	 * @return array
	 */
	private static function describe_application_password( WP_User $user, array $password ) {
		return array(
			'user_id'      => $user->ID,
			'username'     => $user->user_login,
			'role'         => self::get_strongest_role( $user ),
			'uuid'         => (string) ( $password['uuid'] ?? '' ),
			'name'         => (string) ( $password['name'] ?? '' ),
			'created_at'   => self::to_iso( $password['created'] ?? null ),
			'last_used_at' => self::to_iso( $password['last_used'] ?? null ),
			'last_ip'      => (string) ( $password['last_ip'] ?? '' ),
		);
	}

	/**
	 * Retourne le rôle le plus élevé d'un utilisateur.
	 *
	 * En multisite, un rôle ne vaut que pour un site : l'administrateur d'un
	 * sous-site est simple abonné vu du site principal. On regarde donc tous
	 * ses sites, sinon le board afficherait « abonné » pour un compte qui
	 * administre une filiale.
	 *
	 * @param WP_User $user Utilisateur.
	 *
	 * @return string Rôle, ou chaîne vide s'il n'en a aucun.
	 */
	private static function get_strongest_role( WP_User $user ) {
		if ( ! is_multisite() ) {
			return (string) ( reset( $user->roles ) ?: '' );
		}

		if ( is_super_admin( $user->ID ) ) {
			return 'super_admin';
		}

		global $wpdb;

		$roles = array();
		foreach ( array_slice( get_blogs_of_user( $user->ID ), 0, self::MAX_SITES_PER_USER ) as $site ) {
			// Lecture directe de la méta du site : pas de switch_to_blog() par utilisateur.
			$capabilities = get_user_meta( $user->ID, $wpdb->get_blog_prefix( $site->userblog_id ) . 'capabilities', true );
			$roles        = array_merge( $roles, array_keys( array_filter( (array) $capabilities ) ) );
		}

		foreach ( self::ROLES_BY_STRENGTH as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return $role;
			}
		}

		return (string) ( reset( $roles ) ?: '' );
	}

	/**
	 * Convertit un horodatage Unix en date ISO 8601 UTC.
	 *
	 * @param mixed $timestamp Horodatage Unix, ou valeur vide.
	 *
	 * @return string|null
	 */
	private static function to_iso( $timestamp ) {
		$timestamp = (int) $timestamp;

		return $timestamp > 0 ? gmdate( 'c', $timestamp ) : null;
	}
}
