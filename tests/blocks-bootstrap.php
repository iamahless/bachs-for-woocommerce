<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
	abstract class AbstractPaymentMethodType {
		protected $settings = array();
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

$bachs_registered_actions = array();

function add_action( string $hook_name, callable $callback ): void {
	global $bachs_registered_actions;
	$bachs_registered_actions[ $hook_name ][] = $callback;
}

function register_activation_hook( string $file, callable $callback ): void {}

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/bachs-for-woocommerce/';
}

function bachs_bootstrap_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/bachs-for-woocommerce.php';

bachs_bootstrap_assert( isset( $bachs_registered_actions['woocommerce_blocks_loaded'] ), 'The Blocks listener must be registered during plugin bootstrap.' );
bachs_bootstrap_assert( 1 === count( $bachs_registered_actions['woocommerce_blocks_loaded'] ), 'The Blocks listener should be registered once.' );
bachs_bootstrap_assert( isset( $bachs_registered_actions['plugins_loaded'] ), 'The regular WooCommerce bootstrap listener is missing.' );

$bachs_registered_actions['woocommerce_blocks_loaded'][0]();
bachs_bootstrap_assert( isset( $bachs_registered_actions['woocommerce_blocks_payment_method_type_registration'] ), 'The Blocks payment-method registration listener is missing.' );

$registry = new class() {
	public object $integration;

	public function register( object $integration ): void {
		$this->integration = $integration;
	}
};
$bachs_registered_actions['woocommerce_blocks_payment_method_type_registration'][0]( $registry );
bachs_bootstrap_assert( $registry->integration instanceof Bachs_Blocks_Support, 'The Blocks registry should receive the Bachs integration.' );

echo "Blocks bootstrap registration passed\n";
}
