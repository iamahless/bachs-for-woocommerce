<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
	abstract class AbstractPaymentMethodType {
		protected $settings = array();
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BACHS_WC_URL', 'https://example.test/wp-content/plugins/bachs-for-woocommerce/' );
	define( 'BACHS_WC_VERSION', '1.0.0-test' );

	$bachs_test_settings = array();
	$bachs_test_currency = 'USD';
	$bachs_registered_scripts = array();

	function get_option( string $option, mixed $default = false ): mixed {
		global $bachs_test_settings;
		return 'woocommerce_bachs_settings' === $option ? $bachs_test_settings : $default;
	}

	function get_woocommerce_currency(): string {
		global $bachs_test_currency;
		return $bachs_test_currency;
	}

	function __( string $text ): string {
		return $text;
	}

	function wp_register_script( string $handle, string $src, array $dependencies, string $version, bool $in_footer ): bool {
		global $bachs_registered_scripts;
		$bachs_registered_scripts[ $handle ] = compact( 'src', 'dependencies', 'version', 'in_footer' );
		return true;
	}

	function bachs_blocks_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, $message . PHP_EOL );
			exit( 1 );
		}
	}

	require dirname( __DIR__ ) . '/includes/class-bachs-blocks-support.php';

	$bachs_test_settings = array(
		'enabled'            => 'yes',
		'testmode'           => 'yes',
		'sandbox_secret_key' => 'sk_sandbox_test',
		'live_secret_key'    => '',
	);
	$integration = new Bachs_Blocks_Support();
	$integration->initialize();
	bachs_blocks_assert( $integration->is_active(), 'Sandbox Bachs settings should activate the Blocks integration.' );
	bachs_blocks_assert( array( 'bachs-blocks' ) === $integration->get_payment_method_script_handles(), 'Frontend Blocks script handle is incorrect.' );
	bachs_blocks_assert( array( 'bachs-blocks' ) === $integration->get_payment_method_script_handles_for_admin(), 'Editor Blocks script handle is incorrect.' );
	bachs_blocks_assert( array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ) === $bachs_registered_scripts['bachs-blocks']['dependencies'], 'Blocks script dependencies are incorrect.' );
	bachs_blocks_assert( 'https://example.test/wp-content/plugins/bachs-for-woocommerce/assets/js/blocks.js' === $bachs_registered_scripts['bachs-blocks']['src'], 'Blocks script URL is incorrect.' );
	bachs_blocks_assert( array( 'products' ) === $integration->get_payment_method_data()['supports'], 'Blocks feature support is incorrect.' );

	$bachs_test_currency = 'NGN';
	bachs_blocks_assert( ! $integration->is_active(), 'Non-USD stores must not activate Bachs Blocks.' );
	$bachs_test_currency = 'USD';
	$bachs_test_settings['testmode'] = 'no';
	$integration->initialize();
	bachs_blocks_assert( ! $integration->is_active(), 'Live mode without a live key must not activate Bachs Blocks.' );
	$bachs_test_settings['live_secret_key'] = 'sk_live_test';
	$integration->initialize();
	bachs_blocks_assert( $integration->is_active(), 'Live mode with a live key should activate Bachs Blocks.' );

	echo "Blocks PHP integration passed\n";
}
