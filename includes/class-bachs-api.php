<?php
defined( 'ABSPATH' ) || exit;

final class Bachs_API {
	private string $key;
	private string $base_url;
	private Bachs_Logger $logger;

	public function __construct( bool $testmode, string $key, Bachs_Logger $logger ) {
		$this->key      = trim( $key );
		$this->base_url = $testmode ? 'https://sandbox-api.bachs.io/v1' : 'https://api.bachs.io/v1';
		$this->logger   = $logger;
	}

	public function create_checkout_session( array $payload ): array|WP_Error {
		return $this->request( 'POST', '/checkout-sessions', $payload, 'wc-checkout-' . md5( (string) ( $payload['reference'] ?? wp_json_encode( $payload ) ) ) );
	}

	public function refund( string $charge_id, string $amount, string $reference, string $reason = '' ): array|WP_Error {
		$payload = array_filter(
			array(
				'charge_id' => $charge_id,
				'amount'    => $amount,
				'reference' => $reference,
				'reason'    => $reason,
			),
			static fn( $v ) => '' !== $v
		);
		return $this->request( 'POST', '/refunds', $payload, 'wc-refund-' . md5( $reference ) );
	}

	public function create_product( array $payload ): array|WP_Error {
		return $this->request( 'POST', '/products', $payload, 'wc-product-' . md5( wp_json_encode( $payload ) ) );
	}

	public function cancel_subscription( string $subscription_id, bool $at_period_end, string $reason = '' ): array|WP_Error {
		$payload = array_filter(
			array(
				'cancel_at_period_end' => $at_period_end,
				'reason'               => $reason,
			),
			static fn( $value ) => '' !== $value
		);
		return $this->request( 'DELETE', '/subscriptions/' . rawurlencode( $subscription_id ), $payload, 'wc-subscription-cancel-' . md5( $subscription_id . (int) $at_period_end ) );
	}

	public function request( string $method, string $path, array $payload = array(), string $idempotency_key = '' ): array|WP_Error {
		if ( '' === $this->key ) {
			return new WP_Error( 'bachs_missing_key', __( 'No Bachs secret key is configured for the active mode.', 'bachs-for-woocommerce' ) );
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $this->key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = substr( $idempotency_key, 0, 255 );
		}
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);
		if ( ! empty( $payload ) ) {
			$args['body'] = wp_json_encode( $payload );
		}
		$url      = $this->base_url . $path;
		$response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'Bachs transport failure.',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);
			return new WP_Error( 'bachs_transport', __( 'Unable to reach Bachs. Please try again.', 'bachs-for-woocommerce' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$code = is_array( $data ) ? (string) ( $data['error_code'] ?? 'API_ERROR' ) : 'API_ERROR';
			$this->logger->error(
				'Bachs API failure.',
				array(
					'path'     => $path,
					'status'   => $status,
					'response' => ! empty( $data ) ? $data : $body,
				)
			);
			return new WP_Error(
				'bachs_' . strtolower( $code ),
				Bachs_Settings::error_message( $code ),
				array(
					'bachs_code' => $code,
					'status'     => $status,
				)
			);
		}
		$this->logger->debug(
			'Bachs API request succeeded.',
			array(
				'path'     => $path,
				'status'   => $status,
				'response' => $data,
			)
		);
		return $data;
	}
}
