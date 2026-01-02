<?php
/**
 * Template de la page de réglages.
 *
 * @package WBoard_Connector
 */

defined( 'ABSPATH' ) || exit;

$security         = new WBoard_Connector_Security();
$secret_key       = $security->get_secret_key();
$last_request     = $security->get_last_request_time();
$plugin_version   = WBOARD_CONNECTOR_VERSION;
?>

<div class="wrap">
	<h1><?php esc_html_e( 'WBoard Connector', 'wboard-connector' ); ?></h1>

	<div class="wboard-settings-container">

		<!-- Section Clé secrète -->
		<div class="wboard-card">
			<h2><?php esc_html_e( 'Clé secrète', 'wboard-connector' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Cette clé est utilisée pour sécuriser les communications avec le board. Copiez-la dans la configuration de votre site sur WBoard.', 'wboard-connector' ); ?>
			</p>

			<div class="wboard-secret-key-wrapper">
				<input
					type="password"
					id="wboard-secret-key"
					value="<?php echo esc_attr( $secret_key ); ?>"
					readonly
					class="regular-text"
				/>
				<button type="button" id="wboard-toggle-key" class="button">
					<span class="dashicons dashicons-visibility"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Afficher/Masquer', 'wboard-connector' ); ?></span>
				</button>
				<button type="button" id="wboard-copy-key" class="button">
					<span class="dashicons dashicons-clipboard"></span>
					<?php esc_html_e( 'Copier', 'wboard-connector' ); ?>
				</button>
			</div>

			<p class="wboard-key-actions">
				<button type="button" id="wboard-regenerate-key" class="button button-secondary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Régénérer la clé', 'wboard-connector' ); ?>
				</button>
				<span class="wboard-warning">
					<?php esc_html_e( 'Attention : régénérer la clé invalidera la connexion actuelle avec le board.', 'wboard-connector' ); ?>
				</span>
			</p>
		</div>

		<!-- Section Statut -->
		<div class="wboard-card">
			<h2><?php esc_html_e( 'Statut de connexion', 'wboard-connector' ); ?></h2>

			<table class="form-table wboard-status-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Version du plugin', 'wboard-connector' ); ?></th>
					<td>
						<code><?php echo esc_html( $plugin_version ); ?></code>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dernière requête reçue', 'wboard-connector' ); ?></th>
					<td>
						<?php if ( $last_request ) : ?>
							<span class="wboard-status wboard-status-ok">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php echo esc_html( $last_request ); ?>
							</span>
						<?php else : ?>
							<span class="wboard-status wboard-status-unknown">
								<span class="dashicons dashicons-minus"></span>
								<?php esc_html_e( 'Aucune requête reçue', 'wboard-connector' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Endpoint API', 'wboard-connector' ); ?></th>
					<td>
						<code><?php echo esc_url( rest_url( 'wboard/v1/status' ) ); ?></code>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Mises à jour', 'wboard-connector' ); ?></th>
					<td>
						<a href="https://github.com/willybahuaud/wboard-connector/releases" target="_blank" rel="noopener">
							GitHub Releases
							<span class="dashicons dashicons-external"></span>
						</a>
					</td>
				</tr>
			</table>
		</div>

	</div>
</div>
