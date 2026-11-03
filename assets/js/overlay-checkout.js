( function () {
	'use strict';

	const button = document.getElementById( 'bachs-wc-overlay-open' );
	const status = document.getElementById( 'bachs-wc-overlay-status' );
	const config = window.BachsWooCheckout;
	const messages = config && config.messages ? config.messages : {};

	if ( ! button || ! status || ! config ) {
		return;
	}

	function showStatus( message ) {
		status.textContent = message;
	}

	function handleEvent( event ) {
		if ( ! event || ! event.type ) {
			return;
		}

		if ( 'checkout.completed' === event.type ) {
			showStatus( messages.completed || 'Payment submitted. Returning to your order; the store will confirm it from Bachs.' );
			window.setTimeout( function () {
				window.location.assign( config.returnUrl );
			}, 1200 );
		} else if ( 'checkout.failed' === event.type ) {
			showStatus( messages.failed || 'The payment did not complete. You can try again.' );
			button.disabled = false;
		} else if ( 'checkout.expired' === event.type ) {
			showStatus( messages.expired || 'This checkout session expired. Return to checkout and start a new payment.' );
			button.disabled = true;
		} else if ( 'checkout.closed' === event.type ) {
			showStatus( messages.closed || 'Checkout closed. You can reopen it or use the hosted checkout link.' );
			button.disabled = false;
		} else if ( 'checkout.error' === event.type ) {
			showStatus( messages.error || 'The Bachs checkout could not be opened. Use the hosted checkout link or try again.' );
			button.disabled = false;
		}
	}

	if ( ! window.Bachs || ! window.Bachs.Checkout ) {
		showStatus( messages.not_loaded || 'The Bachs overlay could not be loaded. Use the hosted checkout link below.' );
		return;
	}

	window.Bachs.Initialize( { onEvent: handleEvent } );
	button.addEventListener( 'click', function () {
		button.disabled = true;
		showStatus( messages.loading || 'Opening Bachs secure checkout…' );

		window.Bachs.Checkout.open( {
			checkoutUrl: config.checkoutUrl,
			onEvent: handleEvent,
		} ).then( function () {
			showStatus( messages.opened || 'Complete your payment in the Bachs checkout window.' );
		} ).catch( function () {
			showStatus( messages.error || 'The Bachs checkout could not be opened. Use the hosted checkout link below.' );
			button.disabled = false;
		} );
	} );
} )();
