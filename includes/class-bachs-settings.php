<?php
defined( 'ABSPATH' ) || exit;

final class Bachs_Settings {
	public const CURRENCIES = array( 'NGN', 'GHS', 'KES', 'MWK', 'RWF', 'TZS', 'UGX', 'XAF', 'XOF', 'ZMW' );

	public static function currency_options( mixed $value ): array|WP_Error {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		if ( empty( $value ) ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'bachs_invalid_prices', __( 'Locked local prices must be a list of currency and amount pairs.', 'bachs-for-woocommerce' ) );
		}
		$result = array();
		foreach ( $value as $row ) {
			$currency = strtoupper( trim( sanitize_text_field( (string) ( $row['currency'] ?? '' ) ) ) );
			$amount   = trim( sanitize_text_field( (string) ( $row['amount'] ?? '' ) ) );
			if ( '' === $currency && '' === $amount ) {
				continue;
			}
			if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
				return new WP_Error( 'bachs_invalid_currency', __( 'Each locked local price must use a supported fiat currency. USD and payment-rail/crypto codes are not allowed.', 'bachs-for-woocommerce' ) );
			}
			if ( isset( $result[ $currency ] ) ) {
				return new WP_Error( 'bachs_duplicate_currency', __( 'Each locked local price currency may only be entered once.', 'bachs-for-woocommerce' ) );
			}
			if ( ! preg_match( '/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount ) || ! self::is_positive_decimal( $amount ) ) {
				return new WP_Error( 'bachs_invalid_amount', __( 'Each locked local price amount must be a positive decimal amount.', 'bachs-for-woocommerce' ) );
			}
			$result[ $currency ] = self::format_decimal( $amount );
		}
		return $result;
	}

	private static function is_positive_decimal( string $amount ): bool {
		return '' !== ltrim( str_replace( '.', '', $amount ), '0' );
	}

	private static function format_decimal( string $amount ): string {
		$parts    = explode( '.', $amount, 2 );
		$integer  = ltrim( $parts[0], '0' );
		$fraction = $parts[1] ?? '';
		return ( '' === $integer ? '0' : $integer ) . '.' . str_pad( $fraction, 2, '0' );
	}

	public static function error_message( string $code ): string {
		$messages = array(
			'BILLING_CURRENCY_NOT_AVAILABLE'         => __( 'No Bachs price is available in the selected billing currency.', 'bachs-for-woocommerce' ),
			'BILLING_CURRENCY_HAS_NO_PAYMENT_METHOD' => __( 'No Bachs payment method can charge the selected billing currency.', 'bachs-for-woocommerce' ),
			'BASE_CURRENCY_NOT_HELD_BY_ORG'          => __( 'The organization does not hold the required base currency for recurring billing.', 'bachs-for-woocommerce' ),
			'BASE_CURRENCY_NOT_COLLECTIBLE'          => __( 'The configured base currency cannot be collected.', 'bachs-for-woocommerce' ),
			'BASE_CURRENCY_NOT_ENABLED'              => __( 'The configured base currency is not enabled for this Bachs organization.', 'bachs-for-woocommerce' ),
			'BASE_CURRENCY_NOT_CONVERTIBLE'          => __( 'No Bachs FX rate is available for this checkout.', 'bachs-for-woocommerce' ),
			'CART_CURRENCY_MISMATCH'                 => __( 'The Bachs checkout cart contains incompatible currencies.', 'bachs-for-woocommerce' ),
			'VALIDATION_ERROR'                       => __( 'Bachs rejected the checkout request as invalid. Review the gateway configuration.', 'bachs-for-woocommerce' ),
		);
		return $messages[ $code ] ?? __( 'Bachs could not process the API request. Review the WooCommerce logs.', 'bachs-for-woocommerce' );
	}
}
