( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}

	const settings = window.wc.wcSettings.getSetting( 'bachs_data', {} );
	const createElement = window.wp.element.createElement;
	const registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;

	function Description() {
		return createElement(
			'div',
			{ className: 'wc-bachs-payment__description' },
			settings.description || ''
		);
	}

	registerPaymentMethod( {
		name: 'bachs',
		label: createElement( 'span', {}, settings.title || 'Bachs' ),
		ariaLabel: settings.title || 'Bachs',
		content: createElement( Description, {} ),
		edit: createElement( Description, {} ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
