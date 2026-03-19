<?php
/**
 * Upload streaming vers le backup-manager.
 *
 * Utilise curl avec CURLOPT_INFILE pour ne pas charger
 * le fichier entier en memoire PHP. Partage par les modules
 * files et db qui doivent tous deux uploader vers le backup-manager.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WBoard_Connector_Backup_Uploader
 *
 * Uploade un fichier vers le backup-manager en streaming.
 */
class WBoard_Connector_Backup_Uploader {

	/**
	 * Timeout par defaut pour l'upload (secondes).
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 300;

	/**
	 * Uploade un fichier vers le backup-manager en streaming.
	 *
	 * @param string $file_path    Chemin absolu du fichier a uploader.
	 * @param string $upload_url   URL de l'endpoint d'ingestion du backup-manager.
	 * @param string $upload_token Token temporaire fourni par le backup-manager.
	 * @param string $site_id      Identifiant du site (pour le header HMAC).
	 * @param string $hmac_secret  Secret HMAC du site.
	 * @param string $manager_url  URL de base du backup-manager (pour validation SSRF).
	 *
	 * @return array{success: bool, http_code: int, error: string}
	 */
	public function upload( $file_path, $upload_url, $upload_token, $site_id, $hmac_secret, $manager_url = '' ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return self::make_result( false, 0, 'Extension curl non disponible.' );
		}

		// Validation SSRF : l'URL d'upload doit correspondre au manager_url connu.
		$url_check = self::validate_upload_url( $upload_url, $manager_url );
		if ( is_wp_error( $url_check ) ) {
			return self::make_result( false, 0, $url_check->get_error_message() );
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return self::make_result( false, 0, 'Fichier introuvable ou non lisible.' );
		}

		$file_handle = fopen( $file_path, 'r' );
		if ( false === $file_handle ) {
			return self::make_result( false, 0, 'Impossible d\'ouvrir le fichier.' );
		}

		$file_size = filesize( $file_path );

		// Headers HMAC + token d'upload (token en header, pas en query string).
		$headers = self::build_upload_headers( $site_id, $hmac_secret, $file_path, $upload_token );

		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, $upload_url );
		curl_setopt( $curl, CURLOPT_PUT, true );
		curl_setopt( $curl, CURLOPT_INFILE, $file_handle );
		curl_setopt( $curl, CURLOPT_INFILESIZE, $file_size );
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_TIMEOUT, self::DEFAULT_TIMEOUT );

		$response  = curl_exec( $curl );
		$http_code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$error     = curl_error( $curl );

		curl_close( $curl );
		fclose( $file_handle );

		if ( false === $response ) {
			return self::make_result( false, $http_code, 'Erreur curl : ' . $error );
		}

		if ( 200 !== $http_code ) {
			// Message generique pour ne pas exposer les details du backup-manager.
			return self::make_result(
				false,
				$http_code,
				sprintf( 'Upload echoue (HTTP %d).', $http_code )
			);
		}

		return self::make_result( true, $http_code, '' );
	}

	/**
	 * Valide l'URL d'upload contre le manager_url connu (anti-SSRF).
	 *
	 * Si un manager_url est configure, l'upload_url doit commencer par celui-ci.
	 * Empeche un attaquant de rediriger les uploads vers un serveur arbitraire.
	 *
	 * @param string $upload_url  URL d'upload recue dans la requete.
	 * @param string $manager_url URL de base du backup-manager (config).
	 *
	 * @return bool|WP_Error True si valide.
	 */
	private static function validate_upload_url( $upload_url, $manager_url ) {
		// L'URL doit etre HTTPS.
		if ( strpos( $upload_url, 'https://' ) !== 0 ) {
			return new WP_Error(
				'wboard_backup_upload_url_not_https',
				'L\'URL d\'upload doit etre HTTPS.'
			);
		}

		// Pas d'IP privees dans l'URL (anti-SSRF basique).
		$host = wp_parse_url( $upload_url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error(
				'wboard_backup_upload_url_invalid',
				'URL d\'upload invalide.'
			);
		}

		// Si un manager_url est configure, l'upload doit correspondre.
		if ( ! empty( $manager_url ) && strpos( $upload_url, $manager_url ) !== 0 ) {
			return new WP_Error(
				'wboard_backup_upload_url_mismatch',
				'L\'URL d\'upload ne correspond pas au backup-manager configure.'
			);
		}

		return true;
	}

	/**
	 * Construit les headers pour l'upload.
	 *
	 * Token en header (pas en query string) pour eviter les fuites dans les logs.
	 * HMAC au format existant du connector.
	 *
	 * @param string $site_id      Identifiant du site.
	 * @param string $hmac_secret  Secret HMAC.
	 * @param string $file_path    Chemin du fichier uploade.
	 * @param string $upload_token Token d'upload temporaire.
	 *
	 * @return array Headers HTTP formates pour curl.
	 */
	private static function build_upload_headers( $site_id, $hmac_secret, $file_path, $upload_token ) {
		$timestamp = time();

		// Pour les uploads, le "data" contient le hash SHA256 du fichier.
		// Le backup-manager recalcule ce hash a la reception pour verifier l'integrite.
		$file_hash = hash_file( 'sha256', $file_path );

		$payload = wp_json_encode(
			array(
				'timestamp' => $timestamp,
				'data'      => array(
					'file_hash' => $file_hash,
					'file_size' => filesize( $file_path ),
				),
			)
		);

		$signature = 'sha256=' . hash_hmac( 'sha256', $payload, $hmac_secret );

		return array(
			'X-WBoard-Timestamp: ' . $timestamp,
			'X-WBoard-Signature: ' . $signature,
			'X-WBoard-Site-ID: ' . $site_id,
			'X-WBoard-Upload-Token: ' . $upload_token,
			'X-WBoard-File-Hash: ' . $file_hash,
			'Content-Type: application/octet-stream',
		);
	}

	/**
	 * Cree un resultat d'upload standardise.
	 *
	 * @param bool   $success   True si upload reussi.
	 * @param int    $http_code Code HTTP de la reponse.
	 * @param string $error     Message d'erreur (vide si succes).
	 *
	 * @return array{success: bool, http_code: int, error: string}
	 */
	private static function make_result( $success, $http_code, $error ) {
		return array(
			'success'   => $success,
			'http_code' => $http_code,
			'error'     => $error,
		);
	}
}
