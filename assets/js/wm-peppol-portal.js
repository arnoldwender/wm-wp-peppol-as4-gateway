/**
 * Public invoice status portal: one handler per [data-wm-peppol-portal] section.
 *
 * Posts invoice number and buyer id to wm-peppol/v1/invoice-status and shows the stored state it answers.
 * Endpoint and strings come from PHP in the data attribute; every string is written with textContent.
 */
( function () {
	'use strict';

	function init( section ) {
		if ( section.getAttribute( 'data-wm-peppol-ready' ) ) {
			return;
		}
		section.setAttribute( 'data-wm-peppol-ready', '1' );

		var config;
		try {
			config = JSON.parse( section.getAttribute( 'data-wm-peppol-portal' ) || '{}' );
		} catch ( e ) {
			return;
		}

		var t = config.i18n || {};
		var form = section.querySelector( 'form' );
		var box = section.querySelector( '.wm-peppol-portal__result' );
		if ( ! form || ! box || ! config.endpoint ) {
			return;
		}
		var title = box.querySelector( '.wm-peppol-portal__result-title' );
		var body = box.querySelector( '.wm-peppol-portal__result-body' );

		function row( label, value ) {
			var dt = document.createElement( 'dt' );
			var dd = document.createElement( 'dd' );
			dt.textContent = label;
			dd.textContent = value;
			body.appendChild( dt );
			body.appendChild( dd );
		}

		function show( text, state ) {
			title.textContent = text;
			box.setAttribute( 'data-state', state );
			box.hidden = false;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var button = form.querySelector( 'button[type="submit"]' );
			var label = button.textContent;
			button.disabled = true;
			button.textContent = t.loading || label;
			body.textContent = '';

			window.fetch( config.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					invoice_number: form.elements.invoice_number.value.trim(),
					buyer_participant_id: form.elements.buyer_participant_id.value.trim()
				} )
			} )
				.then( function ( response ) {
					return response.json().catch( function () {
						return {};
					} ).then( function ( data ) {
						return { ok: response.ok, data: data || {} };
					} );
				} )
				.then( function ( result ) {
					var d = result.data;
					if ( ! result.ok || ! d.success ) {
						// Rate limit and invalid input arrive as WP_Error with a message.
						show( d.message || t.failed, 'error' );
						return;
					}
					if ( ! d.found ) {
						show( t.notFound, 'warning' );
						return;
					}
					show( t.found, 'found' );
					row( t.issued, d.issue_date || '-' );
					row( t.amount, ( d.total_payable || '-' ) + ' ' + ( d.currency_code || '' ) );
					row( t.status, d.invoice_status || '-' );
					row( t.transport, 'none' === d.transport_status ? t.none : d.transport_status );
					row( t.receipt, d.has_receipt ? t.yes : t.no );
					if ( d.as4_error_code ) {
						row( t.error, d.as4_error_code );
					}
					row( t.xml, d.xml_sha256 || '-' );
				} )
				.catch( function () {
					show( t.failed, 'error' );
				} )
				.then( function () {
					button.disabled = false;
					button.textContent = label;
				} );
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-wm-peppol-portal]' ), init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
