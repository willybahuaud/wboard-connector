/**
 * Scripts de la page de réglages WBoard Connector.
 *
 * @package WBoard_Connector
 */

(function($) {
	'use strict';

	/**
	 * Initialise les gestionnaires d'événements.
	 */
	function init() {
		initToggleKey();
		initCopyKey();
		initRegenerateKey();
	}

	/**
	 * Toggle affichage/masquage de la clé secrète.
	 */
	function initToggleKey() {
		$('#wboard-toggle-key').on('click', handleToggleKey);
	}

	/**
	 * Gestionnaire du toggle de la clé.
	 *
	 * @param {Event} event Événement click.
	 */
	function handleToggleKey(event) {
		event.preventDefault();

		var $input = $('#wboard-secret-key');
		var $icon = $(this).find('.dashicons');

		if ($input.attr('type') === 'password') {
			$input.attr('type', 'text');
			$icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
		} else {
			$input.attr('type', 'password');
			$icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
		}
	}

	/**
	 * Copie de la clé dans le presse-papier.
	 */
	function initCopyKey() {
		$('#wboard-copy-key').on('click', handleCopyKey);
	}

	/**
	 * Gestionnaire de la copie de la clé.
	 *
	 * @param {Event} event Événement click.
	 */
	function handleCopyKey(event) {
		event.preventDefault();

		var $button = $(this);
		var $input = $('#wboard-secret-key');
		var originalText = $button.text();

		navigator.clipboard.writeText($input.val()).then(
			function() {
				$button.addClass('wboard-copied').text(wboardConnector.strings.copied);

				setTimeout(function() {
					$button.removeClass('wboard-copied').html(
						'<span class="dashicons dashicons-clipboard"></span> ' + originalText.trim()
					);
				}, 2000);
			},
			function() {
				// Fallback pour les navigateurs plus anciens.
				$input.attr('type', 'text').select();
				document.execCommand('copy');
				$input.attr('type', 'password');
			}
		);
	}

	/**
	 * Régénération de la clé secrète.
	 */
	function initRegenerateKey() {
		$('#wboard-regenerate-key').on('click', handleRegenerateKey);
	}

	/**
	 * Gestionnaire de la régénération de la clé.
	 *
	 * @param {Event} event Événement click.
	 */
	function handleRegenerateKey(event) {
		event.preventDefault();

		if (!confirm(wboardConnector.strings.confirmRegenerate)) {
			return;
		}

		var $button = $(this);
		$button.addClass('wboard-loading');

		$.ajax({
			url: wboardConnector.ajaxUrl,
			method: 'POST',
			data: {
				action: 'wboard_regenerate_key',
				nonce: wboardConnector.nonce
			},
			success: handleRegenerateSuccess,
			error: handleRegenerateError,
			complete: function() {
				$button.removeClass('wboard-loading');
			}
		});
	}

	/**
	 * Succès de la régénération de la clé.
	 *
	 * @param {Object} response Réponse AJAX.
	 */
	function handleRegenerateSuccess(response) {
		if (response.success && response.data.key) {
			$('#wboard-secret-key').val(response.data.key);
			alert(response.data.message);
		} else {
			alert(wboardConnector.strings.error);
		}
	}

	/**
	 * Erreur lors de la régénération de la clé.
	 */
	function handleRegenerateError() {
		alert(wboardConnector.strings.error);
	}

	// Lance l'initialisation au chargement du DOM.
	$(document).ready(init);

})(jQuery);
