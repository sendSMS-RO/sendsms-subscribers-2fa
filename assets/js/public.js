/**
 * SendSMS Dashboard – public-side widget JS.
 *
 * Handles the subscribe and unsubscribe widget forms. Vanilla JS only;
 * no jQuery dependency. Reads configuration from the `sendsmsDashboardPublic`
 * object injected by wp_localize_script in Plugin::boot().
 *
 * Expected global object shape:
 *   window.sendsmsDashboardPublic = {
 *     ajaxUrl : String,   // wp-admin/admin-ajax.php URL
 *     nonce   : String,   // sendsms-security-nonce value
 *     i18n    : {
 *       sending  : String,
 *       success  : String,
 *       fail     : String,
 *       codeSent : String,
 *     },
 *   };
 */
( function () {
	'use strict';

	var cfg = window.sendsmsDashboardPublic || {};

	if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
		return;
	}

	/**
	 * Write a status message into the `.sendsms-dashboard-feedback` element
	 * inside the given form.
	 *
	 * @param {HTMLElement} form
	 * @param {string}      message
	 * @param {boolean}     isOk    True → data-state="ok", false → "error".
	 */
	function feedback( form, message, isOk ) {
		var el = form.querySelector( '.sendsms-dashboard-feedback' );
		if ( el ) {
			el.textContent    = message;
			el.dataset.state  = isOk ? 'ok' : 'error';
		}
	}

	/**
	 * POST to admin-ajax.php with a plain FormData body.
	 *
	 * @param {string} action  WordPress AJAX action name.
	 * @param {Object} payload Key/value pairs to include in the body.
	 * @returns {Promise<Object>} Parsed JSON response.
	 */
	function ajaxPost( action, payload ) {
		var body = new FormData();
		body.append( 'action',   action );
		body.append( 'security', cfg.nonce );

		Object.keys( payload ).forEach( function ( key ) {
			body.append( key, payload[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method:      'POST',
			body:        body,
			credentials: 'same-origin',
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/**
	 * Wire up one form class.
	 *
	 * @param {string} formClass  CSS class on the <form> element.
	 * @param {string} action     AJAX action for the first-step submit.
	 * @param {string} context    Verify-code context passed to the second step
	 *                            ('sub' or 'unsub').
	 */
	function bindForm( formClass, action, context ) {
		var forms = document.querySelectorAll( 'form.' + formClass );

		forms.forEach( function ( form ) {

			/* ------------------------------------------------------------------
			 * First step: submit the phone number.
			 * ----------------------------------------------------------------*/
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				var phoneInput = form.querySelector( '[name="phone_number"]' );
				var phone      = phoneInput ? phoneInput.value.trim() : '';

				if ( ! phone ) {
					feedback( form, cfg.i18n.fail, false );
					return;
				}

				feedback( form, cfg.i18n.sending, true );

				var payload = { phone_number: phone };

				var fnInput = form.querySelector( '[name="first_name"]' );
				var lnInput = form.querySelector( '[name="last_name"]' );
				if ( fnInput ) { payload.first_name = fnInput.value; }
				if ( lnInput ) { payload.last_name  = lnInput.value; }

				// GDPR checkbox: PHP rejects when missing or === 'false'.
				var gdprInput = form.querySelector( '[name="gdpr"]' );
				if ( gdprInput ) {
					payload.gdpr = gdprInput.checked ? 'true' : 'false';
				}

				ajaxPost( action, payload )
					.then( function ( r ) {
						if ( ! r ) {
							feedback( form, cfg.i18n.fail, false );
							return;
						}
						if ( ! r.success ) {
							feedback( form, ( r.data && r.data.message ) || cfg.i18n.fail, false );
							return;
						}
						if ( r.data && r.data.verify ) {
							// Phone verification required — reveal the code block.
							var verifyBlock = form.querySelector( '.sendsms-dashboard-verify' );
							if ( verifyBlock ) {
								verifyBlock.hidden = false;
							}
							feedback( form, cfg.i18n.codeSent || cfg.i18n.sending, true );
						} else {
							feedback( form, cfg.i18n.success, true );
							form.reset();
						}
					} )
					.catch( function () {
						feedback( form, cfg.i18n.fail, false );
					} );
			} );

			/* ------------------------------------------------------------------
			 * Second step: verify the SMS code.
			 * ----------------------------------------------------------------*/
			var verifyBtn = form.querySelector( '[data-action="verify"]' );
			if ( verifyBtn ) {
				verifyBtn.addEventListener( 'click', function () {
					var phoneInput = form.querySelector( '[name="phone_number"]' );
					var codeInput  = form.querySelector( '[name="code"]' );
					var phone      = phoneInput ? phoneInput.value.trim() : '';
					var code       = codeInput  ? codeInput.value.trim()  : '';

					if ( ! phone || ! code ) {
						return;
					}

					feedback( form, cfg.i18n.sending, true );

					ajaxPost( 'sendsms_dashboard_verify_code', {
						phone_number: phone,
						code:         code,
						context:      context,
					} )
						.then( function ( r ) {
							if ( ! r || ! r.success ) {
								feedback( form, ( r && r.data && r.data.message ) || cfg.i18n.fail, false );
								return;
							}
							feedback( form, cfg.i18n.success, true );
							form.reset();

							var verifyBlock = form.querySelector( '.sendsms-dashboard-verify' );
							if ( verifyBlock ) {
								verifyBlock.hidden = true;
							}
						} )
						.catch( function () {
							feedback( form, cfg.i18n.fail, false );
						} );
				} );
			}

		} ); // forEach form
	}

	/* ------------------------------------------------------------------------
	 * Bootstrap both widget form types once the DOM is ready.
	 * ---------------------------------------------------------------------- */
	document.addEventListener( 'DOMContentLoaded', function () {
		bindForm( 'sendsms-dashboard-subscribe',   'sendsms_dashboard_subscribe',   'sub' );
		bindForm( 'sendsms-dashboard-unsubscribe', 'sendsms_dashboard_unsubscribe', 'unsub' );
	} );

}() );
