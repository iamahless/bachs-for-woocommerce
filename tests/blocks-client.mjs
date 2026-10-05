import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile( new URL( '../assets/js/blocks.js', import.meta.url ), 'utf8' );
let registered;
const window = {
	wc: {
		wcBlocksRegistry: {
			registerPaymentMethod: ( method ) => {
				registered = method;
			},
		},
		wcSettings: {
			getSetting: ( key ) => {
				assert.equal( key, 'bachs_data' );
				return { title: 'Pay &amp; Bachs', description: 'Redirect &amp; pay', supports: [ 'products' ] };
			},
		},
	},
	wp: {
		element: {
			createElement: ( type, props, ...children ) => ( { type, props, children } ),
		},
		htmlEntities: {
			decodeEntities: ( value ) => value.replaceAll( '&amp;', '&' ),
		},
	},
};

vm.runInNewContext( source, { window } );

assert.ok( registered, 'Bachs must register with the Checkout Block.' );
assert.equal( registered.name, 'bachs' );
assert.equal( registered.ariaLabel, 'Pay & Bachs' );
assert.equal( registered.canMakePayment(), true );
assert.deepEqual( registered.supports.features, [ 'products' ] );
const content = registered.content.type();
assert.equal( content.type, 'div' );
assert.equal( content.children[ 0 ], 'Redirect & pay' );

console.log( 'Blocks client integration passed' );
