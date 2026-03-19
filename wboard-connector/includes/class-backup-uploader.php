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
	 *
	 * @return array{success: bool, http_code: int, error: string}
	 */
	public function upload( $file_path, $upload_url, $upload_token, $site_id, $hmac_secret ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return $this->make_result( false, 0, 'Extension curl non disponible.' );
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return $this->make_result( false, 0, 'Fichier introuvable ou non lisible : ' . $file_path );
		}

		$file_handle = fopen( $file_path, 'r' );
		if ( false === $file_handle ) {
			return $this->make_result( false, 0, 'Impossible d\'ouvrir le fichier : ' . $file_path );
		}

		$file_size = filesize( $file_path );

		// Construction de l'URL avec le token.
		$full_url = add_query_arg( 'token', $upload_token, $upload_url );

		// Headers HMAC pour l'authentification.
		$hmac_headers = $this->build_hmac_headers( $site_id, $hmac_secret, 'PUT', $upload_url, $file_path );

		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, $full_url );
		curl_setopt( $curl, CURLOPT_PUT, true );
		curl_setopt( $curl, CURLOPT_INFILE, $file_handle );
		curl_setopt( $curl, CURLOPT_INFILESIZE, $file_size );
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $hmac_headers );
		curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_TIMEOUT, self::DEFAULT_TIMEOUT );

		$response  = curl_exec( $curl );
		$http_code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$error     = curl_error( $curl );

		curl_close( $curl );
		fclose( $file_handle );

		if ( false === $response ) {
			return $this->make_result( false, $http_code, 'Erreur curl : ' . $error );
		}

		if ( 200 !== $http_code ) {
			return $this->make_result(
				false,
				$http_code,
				sprintf( 'Upload echoue (HTTP %d) : %s', $http_code, substr( $response, 0, 200 ) )
			);
		}

		return $this->make_result( true, $http_code, '' );
	}

	/**
	 * Construit les headers HMAC pour l'upload.
	 *
	 * Utilise le format HMAC existant du connector :
	 * signature = sha256=hash_hmac('sha256', json({"timestamp": X, "data": {}}), secret)
	 *
	 * Pour les uploads binaires, le body est un hash SHA256 du fichier
	 * (pas le fichier entier, sinon on charge tout en RAM).
	 *
	 * @param string $site_id     Identifiant du site.
	 * @param string $hmac_secret Secret HMAC.
	 * @param string $method      Methode HTTP.
	 * @param string $url         URL de destination.
	 * @param string $file_path   Chemin du fichier uploade.
	 *
	 * @return array Headers HTTP formates pour curl.
	 */
	private function build_hmac_headers( $site_id, $hmac_secret, $method, $url, $file_path ) {
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
	private function make_result( $success, $http_code, $error ) {
		return array(
			'success'   => $success,
			'http_code' => $http_code,
			'error'     => $error,
		);
	}
}
