<?php
/**
 * Classe de sécurité pour la vérification des requêtes HMAC.
 *
 * Gère la validation des signatures HMAC-SHA256 et des timestamps
 * pour sécuriser les communications avec le board.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Security
 *
 * Vérifie l'authenticité des requêtes provenant du board WBoard.
 */
class WBoard_Connector_Security {

	/**
	 * Durée de validité du timestamp en secondes.
	 *
	 * Les requêtes avec un timestamp plus ancien sont rejetées
	 * pour prévenir les attaques par rejeu.
	 *
	 * @var int
	 */
	const TIMESTAMP_TOLERANCE = 300; // 5 minutes.

	/**
	 * Nom du header contenant le timestamp.
	 *
	 * @var string
	 */
	const HEADER_TIMESTAMP = 'X-WBoard-Timestamp';

	/**
	 * Nom du header contenant la signature.
	 *
	 * @var string
	 */
	const HEADER_SIGNATURE = 'X-WBoard-Signature';

	/**
	 * Nom du header contenant l'identifiant du site.
	 *
	 * @var string
	 */
	const HEADER_SITE_ID = 'X-WBoard-Site-ID';

	/**
	 * Vérifie si une requête est authentique.
	 *
	 * Contrôle la signature HMAC et la validité du timestamp.
	 *
	 * @param WP_REST_Request $request La requête REST à vérifier.
	 *
	 * @return bool|WP_Error True si valide, WP_Error sinon.
	 */
	public function verify_request( WP_REST_Request $request ) {
		$timestamp = $request->get_header( 'X-WBoard-Timestamp' );
		$signature = $request->get_header( 'X-WBoard-Signature' );

		// Vérifie la présence des headers requis.
		if ( empty( $timestamp ) || empty( $signature ) ) {
			return new WP_Error(
				'wboard_missing_headers',
				__( 'Headers de sécurité manquants.', 'wboard-connector' ),
				array( 'status' => 401 )
			);
		}

		// Vérifie la validité du timestamp.
		$timestamp_check = $this->verify_timestamp( (int) $timestamp );
		if ( is_wp_error( $timestamp_check ) ) {
			return $timestamp_check;
		}

		// Vérifie la signature HMAC.
		$signature_check = $this->verify_signature( $request, $signature, (int) $timestamp );
		if ( is_wp_error( $signature_check ) ) {
			return $signature_check;
		}

		// Met à jour la date de dernière requête reçue.
		$this->update_last_request_time();

		return true;
	}

	/**
	 * Vérifie que le timestamp est dans la fenêtre de tolérance.
	 *
	 * @param int $timestamp Timestamp Unix de la requête.
	 *
	 * @return bool|WP_Error True si valide, WP_Error sinon.
	 */
	private function verify_timestamp( $timestamp ) {
		$current_time = time();
		$difference   = abs( $current_time - $timestamp );

		if ( $difference > self::TIMESTAMP_TOLERANCE ) {
			return new WP_Error(
				'wboard_invalid_timestamp',
				__( 'Timestamp expiré ou invalide.', 'wboard-connector' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Vérifie la signature HMAC de la requête.
	 *
	 * @param WP_REST_Request $request   La requête REST.
	 * @param string          $signature La signature reçue.
	 * @param int             $timestamp Le timestamp de la requête.
	 *
	 * @return bool|WP_Error True si valide, WP_Error sinon.
	 */
	private function verify_signature( WP_REST_Request $request, $signature, $timestamp ) {
		$secret_key = $this->get_secret_key();

		if ( empty( $secret_key ) ) {
			return new WP_Error(
				'wboard_no_secret_key',
				__( 'Clé secrète non configurée.', 'wboard-connector' ),
				array( 'status' => 500 )
			);
		}

		// Reconstruit le payload comme le fait le board.
		$body    = $request->get_body();
		$payload = wp_json_encode(
			array(
				'timestamp' => $timestamp,
				'data'      => ! empty( $body ) ? json_decode( $body, true ) : array(),
			)
		);

		// Calcule la signature attendue.
		$expected_signature = 'sha256=' . hash_hmac( 'sha256', $payload, $secret_key );

		// Comparaison en temps constant pour éviter les timing attacks.
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return new WP_Error(
				'wboard_invalid_signature',
				__( 'Signature invalide.', 'wboard-connector' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Récupère la clé secrète du plugin.
	 *
	 * @return string|false La clé secrète ou false si non définie.
	 */
	public function get_secret_key() {
		return get_option( 'wboard_connector_secret_key', false );
	}

	/**
	 * Génère une nouvelle clé secrète.
	 *
	 * @return string La nouvelle clé secrète.
	 */
	public function regenerate_secret_key() {
		$new_key = wp_generate_password( 64, true, true );
		update_option( 'wboard_connector_secret_key', $new_key );

		return $new_key;
	}

	/**
	 * Met à jour l'horodatage de la dernière requête reçue.
	 *
	 * Stocké en transient pour un accès rapide.
	 *
	 * @return void
	 */
	private function update_last_request_time() {
		set_transient( 'wboard_connector_last_request', current_time( 'mysql' ), DAY_IN_SECONDS );
	}

	/**
	 * Récupère la date de la dernière requête reçue.
	 *
	 * @return string|false La date au format MySQL ou false si jamais reçu.
	 */
	public function get_last_request_time() {
		return get_transient( 'wboard_connector_last_request' );
	}
}
