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
			'role'         => is_super_admin( $user->ID ) && is_multisite() ? 'super_admin' : (string) ( $user->roles[0] ?? '' ),
			'uuid'         => (string) ( $password['uuid'] ?? '' ),
			'name'         => (string) ( $password['name'] ?? '' ),
			'created_at'   => self::to_iso( $password['created'] ?? null ),
			'last_used_at' => self::to_iso( $password['last_used'] ?? null ),
			'last_ip'      => (string) ( $password['last_ip'] ?? '' ),
		);
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
