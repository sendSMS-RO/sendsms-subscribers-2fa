/**
 * SendSMS Dashboard – admin JS.
 *
 * Handles all interactive behaviour on the plugin's admin pages. jQuery is
 * available (registered as a dependency in Admin\Menu::enqueue_assets()), but
 * all DOM queries are done with vanilla JS where it keeps the code shorter.
 * The jBox modal dependency used by v1.x has been dropped entirely; status
 * messages are written into the inline `[role="status"]` divs rendered by
 * each page class.
 *
 * Global object (injected by wp_localize_script in Admin\Menu::enqueue_assets()):
 *
 *   window.sendsmsDashboard = {
 *     ajaxUrl : String,
 *     nonce   : String,
 *     i18n    : {
 *       sending    : String,
 *       sent       : String,
 *       failed     : String,
 *       confirmDel : String,
 *     },
 *   };
 */
( function ( $ ) {
	'use strict';

	var cfg = window.sendsmsDashboard || {};

	/* -------------------------------------------------------------------------
	 * Utility helpers
	 * ----------------------------------------------------------------------- */

	/**
	 * POST to admin-ajax.php using jQuery.post.
	 *
	 * @param {string}   action   WordPress AJAX action name.
	 * @param {Object}   payload  Additional POST fields.
	 * @returns {jqXHR}
	 */
	function ajaxPost( action, payload ) {
		payload         = payload || {};
		payload.action   = action;
		payload.security = cfg.nonce;
		return $.post( cfg.ajaxUrl, payload );
	}

	/**
	 * Write a status message into a `.role="status"` feedback element.
	 *
	 * @param {string|Element} selectorOrEl  CSS selector string or DOM element.
	 * @param {string}         message       Text to display.
	 * @param {boolean}        isOk          Drives the data-state attribute.
	 */
	function setFeedback( selectorOrEl, message, isOk ) {
		var el = ( typeof selectorOrEl === 'string' )
			? document.querySelector( selectorOrEl )
			: selectorOrEl;
		if ( ! el ) {
			return;
		}
		el.textContent   = message;
		el.dataset.state = isOk ? 'ok' : 'error';
	}

	/* -------------------------------------------------------------------------
	 * Character / message counter for <textarea> elements.
	 *
	 * Applies to every element with class `.sendsms_dashboard_content`.
	 * The textarea must carry a `data-sendsms-counter="<target-id>"` attribute
	 * pointing to the element that will receive the counter text.
	 * ----------------------------------------------------------------------- */
	document.addEventListener( 'DOMContentLoaded', function () {
		function lengthCounter( textarea, counter ) {
			if ( ! counter ) {
				return;
			}
			var length   = textarea.value.length;
			var messages = Math.floor( length / 160 ) + 1;
			if ( length > 0 ) {
				if ( length % 160 === 0 ) {
					messages--;
				}
				counter.textContent = ( cfg.i18n && cfg.i18n.msgContains ? cfg.i18n.msgContains : 'Message:' ) + ' ' + messages + ' (' + length + ')';
			} else {
				counter.textContent = ( cfg.i18n && cfg.i18n.msgEmpty ? cfg.i18n.msgEmpty : 'The field is empty' );
			}
		}

		var contentWrap = document.querySelector( '.sendsms_dashboard_content' );
		if ( contentWrap ) {
			// The selector may match one textarea; use the parent form's querySelectorAll
			// so counters work even when multiple textareas are present (future-proof).
			document.querySelectorAll( '.sendsms_dashboard_content' ).forEach( function ( ta ) {
				var counter = document.getElementById( ta.dataset.sendsmsCounter );
				ta.addEventListener( 'input',  function () { lengthCounter( ta, counter ); } );
				ta.addEventListener( 'change', function () { lengthCounter( ta, counter ); } );
			} );
		}
	} );

	/* -------------------------------------------------------------------------
	 * Test-send form  (TestSendPage — form.sendsms-dashboard-test-form)
	 * ----------------------------------------------------------------------- */
	$( document ).on( 'submit', 'form.sendsms-dashboard-test-form', function ( e ) {
		e.preventDefault();

		var $form   = $( this );
		var $submit = $form.find( '[type="submit"]' );
		var fbEl    = document.querySelector( '.sendsms-dashboard-test-feedback' );

		$submit.prop( 'disabled', true ).val( cfg.i18n ? cfg.i18n.sending : 'Sending…' );
		setFeedback( fbEl, cfg.i18n ? cfg.i18n.sending : 'Sending…', true );

		ajaxPost( 'sendsms_dashboard_test_send', {
			phone_number: $form.find( '[name="phone_number"]' ).val(),
			message:      $form.find( '[name="message"]' ).val(),
			gdpr:         $form.find( '[name="gdpr"]' ).is( ':checked' ) ? 'gdpr' : '',
			short:        $form.find( '[name="short"]' ).is( ':checked' ) ? 'short' : '',
		} ).done( function ( response ) {
			var ok  = response && response.success;
			var msg = ok
				? ( cfg.i18n ? cfg.i18n.sent : 'Sent.' )
				: ( response && response.data && response.data.message ) || ( cfg.i18n ? cfg.i18n.failed : 'Failed.' );
			setFeedback( fbEl, msg, ok );
		} ).fail( function () {
			setFeedback( fbEl, cfg.i18n ? cfg.i18n.failed : 'Failed.', false );
		} ).always( function () {
			$submit.prop( 'disabled', false ).val( cfg.i18n ? cfg.i18n.sent : 'Send Message' );
		} );
	} );

	/* -------------------------------------------------------------------------
	 * Mass-send form  (MassSendPage — form.sendsms-dashboard-mass-form)
	 * ----------------------------------------------------------------------- */

	// Show/hide the role selector row based on receiver_type radios.
	$( document ).on( 'change', '[name="receiver_type"]', function () {
		var isUsers = $( this ).val() === 'users';
		$( '#sendsms-role-row' ).toggle( isUsers );
	} );

	$( document ).on( 'submit', 'form.sendsms-dashboard-mass-form', function ( e ) {
		e.preventDefault();

		var $form   = $( this );
		var $submit = $form.find( '[type="submit"]' );
		var fbEl    = document.querySelector( '.sendsms-dashboard-mass-feedback' );

		$submit.prop( 'disabled', true ).val( cfg.i18n ? cfg.i18n.sending : 'Sending…' );
		setFeedback( fbEl, cfg.i18n ? cfg.i18n.sending : 'Sending…', true );

		ajaxPost( 'sendsms_dashboard_mass_send', {
			receiver_type: $form.find( '[name="receiver_type"]:checked' ).val(),
			role:          $form.find( '[name="role"]' ).val() || '',
			message:       $form.find( '[name="message"]' ).val(),
			gdpr:          $form.find( '[name="gdpr"]' ).is( ':checked' ) ? 'gdpr' : '',
			short:         $form.find( '[name="short"]' ).is( ':checked' ) ? 'short' : '',
		} ).done( function ( response ) {
			var ok  = response && response.success;
			var msg = ok
				? ( cfg.i18n ? cfg.i18n.sent : 'Sent.' )
				: ( response && response.data && response.data.message ) || ( cfg.i18n ? cfg.i18n.failed : 'Failed.' );
			setFeedback( fbEl, msg, ok );
		} ).fail( function () {
			setFeedback( fbEl, cfg.i18n ? cfg.i18n.failed : 'Failed.', false );
		} ).always( function () {
			$submit.prop( 'disabled', false ).val( cfg.i18n ? cfg.i18n.sent : 'Send Message' );
		} );
	} );

	/* -------------------------------------------------------------------------
	 * Subscriber Add form  (SubscribersPage — #sendsms-subscriber-add-form)
	 * ----------------------------------------------------------------------- */
	$( document ).on( 'submit', '#sendsms-subscriber-add-form', function ( e ) {
		e.preventDefault();

		var $form   = $( this );
		var $submit = $form.find( '#sendsms-subscriber-add-btn' );
		var msgEl   = document.getElementById( 'sendsms-subscriber-add-message' );

		$submit.prop( 'disabled', true );
		if ( msgEl ) {
			msgEl.style.display = 'none';
			msgEl.textContent   = '';
		}

		ajaxPost( 'sendsms_dashboard_subscriber_add', {
			phone:      $form.find( '[name="phone"]' ).val(),
			first_name: $form.find( '[name="first_name"]' ).val(),
			last_name:  $form.find( '[name="last_name"]' ).val(),
			// The nonce field is in the form; re-use the global nonce that
			// matches the one the handler checks (sendsms-security-nonce).
		} ).done( function ( response ) {
			if ( response && response.success ) {
				// Reload the page so the new subscriber appears in the table.
				window.location.reload();
			} else {
				var msg = ( response && response.data && response.data.message ) || ( cfg.i18n ? cfg.i18n.failed : 'Failed.' );
				if ( msgEl ) {
					msgEl.textContent   = msg;
					msgEl.style.display = 'block';
					msgEl.style.color   = '#721c24';
				}
				$submit.prop( 'disabled', false );
			}
		} ).fail( function () {
			if ( msgEl ) {
				msgEl.textContent   = cfg.i18n ? cfg.i18n.failed : 'Failed.';
				msgEl.style.display = 'block';
				msgEl.style.color   = '#721c24';
			}
			$submit.prop( 'disabled', false );
		} );
	} );

	/* -------------------------------------------------------------------------
	 * Subscriber Edit form  (SubscribersPage — #sendsms-subscriber-edit-form)
	 * ----------------------------------------------------------------------- */
	$( document ).on( 'submit', '#sendsms-subscriber-edit-form', function ( e ) {
		e.preventDefault();

		var $form   = $( this );
		var $submit = $form.find( '#sendsms-subscriber-edit-btn' );
		var msgEl   = document.getElementById( 'sendsms-subscriber-edit-message' );

		$submit.prop( 'disabled', true );
		if ( msgEl ) {
			msgEl.style.display = 'none';
			msgEl.textContent   = '';
		}

		ajaxPost( 'sendsms_dashboard_subscriber_update', {
			phone:      $form.find( '[name="phone"]' ).val(),
			first_name: $form.find( '[name="first_name"]' ).val(),
			last_name:  $form.find( '[name="last_name"]' ).val(),
		} ).done( function ( response ) {
			if ( response && response.success ) {
				window.location.reload();
			} else {
				var msg = ( response && response.data && response.data.message ) || ( cfg.i18n ? cfg.i18n.failed : 'Failed.' );
				if ( msgEl ) {
					msgEl.textContent   = msg;
					msgEl.style.display = 'block';
					msgEl.style.color   = '#721c24';
				}
				$submit.prop( 'disabled', false );
			}
		} ).fail( function () {
			if ( msgEl ) {
				msgEl.textContent   = cfg.i18n ? cfg.i18n.failed : 'Failed.';
				msgEl.style.display = 'block';
				msgEl.style.color   = '#721c24';
			}
			$submit.prop( 'disabled', false );
		} );
	} );

	/* -------------------------------------------------------------------------
	 * Delete subscriber  (data-action="sendsms-subscriber-delete")
	 * ----------------------------------------------------------------------- */
	$( document ).on( 'click', '[data-action="sendsms-subscriber-delete"]', function ( e ) {
		e.preventDefault();

		var phone   = $( this ).data( 'phone' );
		var confirm = cfg.i18n && cfg.i18n.confirmDel ? cfg.i18n.confirmDel : 'Delete this subscriber?';

		if ( ! window.confirm( confirm ) ) { // eslint-disable-line no-alert
			return;
		}

		var $link = $( this );
		$link.css( 'opacity', '0.4' );

		ajaxPost( 'sendsms_dashboard_subscriber_delete', { phone: phone } )
			.done( function ( response ) {
				if ( response && response.success ) {
					// Remove the table row that contains the delete link.
					$link.closest( 'tr' ).fadeOut( 300, function () {
						$( this ).remove();
					} );
				} else {
					var msg = ( response && response.data && response.data.message ) || ( cfg.i18n ? cfg.i18n.failed : 'Failed.' );
					window.alert( msg ); // eslint-disable-line no-alert
					$link.css( 'opacity', '' );
				}
			} )
			.fail( function () {
				window.alert( cfg.i18n ? cfg.i18n.failed : 'Failed.' ); // eslint-disable-line no-alert
				$link.css( 'opacity', '' );
			} );
	} );

	/* -------------------------------------------------------------------------
	 * Sync subscriber  (data-action="sendsms-subscriber-sync")
	 * ----------------------------------------------------------------------- */
	$( document ).on( 'click', '[data-action="sendsms-subscriber-sync"]', function ( e ) {
		e.preventDefault();

		var phone = $( this ).data( 'phone' );
		var $link = $( this );

		$link.css( 'opacity', '0.4' );

		ajaxPost( 'sendsms_dashboard_sync_contact', { phone: phone } )
			.done( function ( response ) {
				if ( response && response.success ) {
					// Update the "Synced" cell in the same row to reflect the new state.
					var $synced = $link.closest( 'tr' ).find( '.column-synced' );
					var newId   = response.data && response.data.synced ? response.data.synced : '';
					if ( $synced.length && newId ) {
						$synced.text( 'Yes (id: ' + newId + ')' );
					}
					// Change the link label to "Resync".
					$link.text( 'Resync' );
				} else {
					var msg = ( response && response.data && response.data.message ) || ( cfg.i18n ? cfg.i18n.failed : 'Failed.' );
					window.alert( msg ); // eslint-disable-line no-alert
				}
			} )
			.fail( function () {
				window.alert( cfg.i18n ? cfg.i18n.failed : 'Failed.' ); // eslint-disable-line no-alert
			} )
			.always( function () {
				$link.css( 'opacity', '' );
			} );
	} );

}( jQuery ) );
