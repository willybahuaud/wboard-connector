<?php
/**
 * Interdit la mise en cache des réponses de l'API WBoard.
 *
 * @package WBoard_Connector
 */

// Empêche l'accès direct au fichier.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe WBoard_Connector_Cache_Control
 *
 * Les caches de page (LiteSpeed / PowerBoost chez O2switch, entre autres)
 * peuvent stocker une réponse GET de l'API REST sans tenir compte des headers
 * de signature. La réponse faite au board est alors resservie à n'importe
 * quel visiteur anonyme, sans repasser par la vérification HMAC.
 * Toute réponse de notre namespace est donc marquée comme non cachable.
 */
class WBoard_Connector_Cache_Control {

	/**
	 * Préfixe des routes REST du connecteur.
	 *
	 * @var string
	 */
	const ROUTE_PREFIX = '/wboard/v1';

	/**
	 * Valeur du header Cache-Control envoyé sur nos réponses.
	 *
	 * @var string
	 */
	const CACHE_CONTROL = 'no-store, no-cache, must-revalidate, max-age=0, private';

	/**
	 * Enregistre les hooks WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'rest_post_dispatch', array( $this, 'forbid_caching' ), 999, 3 );
	}

	/**
	 * Marque la réponse d'une route WBoard comme non cachable.
	 *
	 * Branché sur rest_post_dispatch : le filtre voit aussi les réponses
	 * d'erreur (401, 404), qui ne doivent pas être mises en cache non plus.
	 *
	 * @param WP_HTTP_Response $response La réponse REST.
	 * @param WP_REST_Server   $server   Le serveur REST.
	 * @param WP_REST_Request  $request  La requête REST.
	 *
	 * @return WP_HTTP_Response
	 */
	public function forbid_caching( $response, $server, $request ) {
		if ( 0 !== strpos( $request->get_route(), self::ROUTE_PREFIX ) ) {
			return $response;
		}

		// Convention suivie par la plupart des plugins de cache (WP Rocket, W3TC, WP Super Cache…).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// LiteSpeed Cache ignore Cache-Control pour l'API REST : il faut passer par son API.
		do_action( 'litespeed_control_set_nocache', 'wboard-connector' );

		if ( $response instanceof WP_HTTP_Response ) {
			$response->header( 'Cache-Control', self::CACHE_CONTROL );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}

		return $response;
	}
}
