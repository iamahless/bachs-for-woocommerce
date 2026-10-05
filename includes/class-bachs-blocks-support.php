<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/** Registers Bachs with WooCommerce's Checkout block. */
final class Bachs_Blocks_Support extends AbstractPaymentMethodType {
	protected $name = 'bachs';

	public function initialize(): void {
		$this->settings = (array) get_option( 'woocommerce_bachs_settings', array() );
	}

	public function is_active(): bool {
		if ( 'yes' !== ( $this->settings['enabled'] ?? 'no' ) || 'USD' !== strtoupper( get_woocommerce_currency() ) ) {
			return false;
		}

		$key_name = 'yes' === ( $this->settings['testmode'] ?? 'yes' ) ? 'sandbox_secret_key' : 'live_secret_key';
		return '' !== trim( (string) ( $this->settings[ $key_name ] ?? '' ) );
	}

	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'bachs-blocks',
			BACHS_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			BACHS_WC_VERSION,
			true
		);

		return array( 'bachs-blocks' );
	}

	public function get_payment_method_script_handles_for_admin(): array {
		return $this->get_payment_method_script_handles();
	}

	public function get_payment_method_data(): array {
		return array(
			'title'       => (string) ( $this->settings['title'] ?? __( 'Pay with Bachs', 'bachs-for-woocommerce' ) ),
			'description' => (string) ( $this->settings['description'] ?? __( 'You will be redirected to Bachs to complete your payment securely.', 'bachs-for-woocommerce' ) ),
			'supports'    => array( 'products' ),
		);
	}
}
