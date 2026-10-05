( function () {
	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}

	const settings = window.wc.wcSettings.getSetting( 'bachs_data', {} );
	const label = window.wp.htmlEntities.decodeEntities( settings.title || 'Pay with Bachs' );
	const description = window.wp.htmlEntities.decodeEntities( settings.description || '' );
	const createElement = window.wp.element.createElement;
	const Content = () => createElement( 'div', null, description );

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'bachs',
		label: createElement( 'span', null, label ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: () => true,
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
}() );
