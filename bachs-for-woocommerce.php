<?php
/**
 * Plugin Name: Bachs for WooCommerce
 * Description: Hosted Bachs checkout for WooCommerce. Bachs handles payment collection; this plugin never handles card data.
 * Version: 1.0.0
 * Requires at least: 6.3
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Author: Alexander Garuba
 * Author URI: https://github.com/iamahless
 * License: GPL-2.0-or-later
 * Text Domain: bachs-for-woocommerce
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'BACHS_WC_VERSION', '1.0.0' );
define( 'BACHS_WC_FILE', __FILE__ );
define( 'BACHS_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BACHS_WC_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BACHS_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BACHS_WC_FILE, true );
		}
	}
);

register_activation_hook(
	BACHS_WC_FILE,
	static function (): void {
		update_option( 'bachs_wc_activation_currency', get_option( 'woocommerce_currency', 'USD' ), false );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'bachs-for-woocommerce', false, dirname( plugin_basename( BACHS_WC_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Bachs for WooCommerce requires WooCommerce to be installed and active.', 'bachs-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}

		require_once BACHS_WC_DIR . 'includes/class-bachs-logger.php';
		require_once BACHS_WC_DIR . 'includes/class-bachs-settings.php';
		require_once BACHS_WC_DIR . 'includes/class-bachs-api.php';
		require_once BACHS_WC_DIR . 'includes/class-bachs-webhook.php';
		require_once BACHS_WC_DIR . 'includes/class-wc-gateway-bachs.php';

		Bachs_Webhook::init();
		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = 'WC_Gateway_Bachs';
				return $gateways;
			}
		);
	}
);

add_action(
	'woocommerce_blocks_loaded',
	static function (): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once BACHS_WC_DIR . 'includes/class-bachs-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $payment_method_registry ): void {
				$payment_method_registry->register( new Bachs_Blocks_Support() );
			}
		);
	}
);

add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$currency = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		if ( 'USD' !== $currency ) {
			$message = sprintf(
			/* translators: %s: current WooCommerce currency. */
				__( 'Bachs for WooCommerce requires a USD-priced store. Your catalog is currently in %s. Change the WooCommerce currency to USD in WooCommerce > Settings > General, then configure this gateway. Prices will need to be re-entered in USD. Bachs only performs currency conversion for USD-priced checkouts; local display prices come from Bachs.', 'bachs-for-woocommerce' ),
				$currency
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}
		$options = (array) get_option( 'woocommerce_bachs_settings', array() );
		if ( 'yes' === ( $options['enabled'] ?? 'no' ) ) {
			$testmode = 'yes' === ( $options['testmode'] ?? 'yes' );
			$key      = trim( (string) ( $options[ $testmode ? 'sandbox_secret_key' : 'live_secret_key' ] ?? '' ) );
			if ( '' === $key ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Bachs for WooCommerce is disabled because no secret key is configured for the active mode.', 'bachs-for-woocommerce' ) . '</p></div>';
			}
		}
	}
);
