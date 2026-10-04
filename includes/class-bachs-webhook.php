<?php
defined( 'ABSPATH' ) || exit;

final class Bachs_Webhook {
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'order_details' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'bachs/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'bachs/v1',
			'/order-status/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'order_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public static function url(): string {
		return rest_url( 'bachs/v1/webhook' );
	}

	public static function receive( WP_REST_Request $request ): WP_REST_Response {
		$raw         = $request->get_body();
		$timestamp   = (string) $request->get_header( 'x-bachs-timestamp' );
		$signature   = (string) $request->get_header( 'x-bachs-signature' );
		$options     = (array) get_option( 'woocommerce_bachs_settings', array() );
		$environment = self::verified_environment( $raw, $timestamp, $signature, $options );
		if ( '' === $environment ) {
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}
		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}
		$event['_bachs_environment'] = $environment;
		$logger                      = new Bachs_Logger( 'yes' === ( $options['debug_logging'] ?? 'no' ) );
		$logger->debug( 'Verified Bachs webhook.', array( 'payload' => $event ) );
		if ( str_starts_with( (string) ( $event['type'] ?? '' ), 'refund.' ) ) {
			return self::handle_refund( $event );
		}
		if ( 'customer.subscription.created' === ( $event['type'] ?? '' ) ) {
			return self::handle_subscription_created( $event );
		}
		if ( in_array( (string) ( $event['type'] ?? '' ), array( 'customer.subscription.updated', 'customer.subscription.deleted' ), true ) ) {
			return self::handle_subscription_state( $event );
		}
		if ( 'invoice.paid' === ( $event['type'] ?? '' ) ) {
			return self::handle_invoice_paid( $event );
		}
		if ( 'invoice.payment_failed' === ( $event['type'] ?? '' ) ) {
			return self::handle_invoice_payment_failed( $event );
		}
		if ( 'collection.succeeded' !== ( $event['type'] ?? '' ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		$data     = (array) ( $event['data'] ?? array() );
		$metadata = (array) ( $data['metadata'] ?? $event['metadata'] ?? array() );
		$order    = self::find_order( $metadata, (string) ( $event['reference'] ?? $data['reference'] ?? '' ), (string) ( $data['checkout_id'] ?? '' ) );
		if ( ! $order ) {
			$logger->error( 'Verified Bachs webhook could not be matched to an order.', array( 'event' => $event ) );
			return new WP_REST_Response( array( 'error' => 'order_not_found' ), 404 );
		}
		$reference    = (string) ( $event['reference'] ?? $data['reference'] ?? '' );
		$has_metadata = ! empty( $metadata['wc_order_id'] ) || ! empty( $metadata['wc_order_key'] );
		if ( $has_metadata ) {
			if ( empty( $metadata['wc_order_id'] ) || absint( $metadata['wc_order_id'] ) !== $order->get_id() || empty( $metadata['wc_order_key'] ) || ! hash_equals( $order->get_order_key(), (string) $metadata['wc_order_key'] ) ) {
				return new WP_REST_Response( array( 'error' => 'order_key_mismatch' ), 400 );
			}
		} elseif ( '' === (string) $order->get_meta( '_bachs_checkout_reference', true ) || ! hash_equals( (string) $order->get_meta( '_bachs_checkout_reference', true ), $reference ) ) {
			return new WP_REST_Response( array( 'error' => 'reference_mismatch' ), 400 );
		}
		$stored_checkout_id = (string) $order->get_meta( '_bachs_checkout_id', true );
		if ( (string) $order->get_meta( '_bachs_environment', true ) !== $environment ) {
			return new WP_REST_Response( array( 'error' => 'environment_mismatch' ), 400 );
		}
		$incoming_checkout_id = sanitize_text_field( (string) ( $data['checkout_id'] ?? '' ) );
		if ( '' === $stored_checkout_id || '' === $incoming_checkout_id || ! hash_equals( $stored_checkout_id, $incoming_checkout_id ) ) {
			return new WP_REST_Response( array( 'error' => 'checkout_mismatch' ), 400 );
		}
		if ( $order->get_meta( '_bachs_charge_id', true ) ) {
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		if ( $order->is_paid() ) {
			$logger->error(
				'Verified Bachs collection event targets an order already paid without a Bachs charge.',
				array(
					'order_id' => $order->get_id(),
					'event_id' => $event['id'] ?? '',
				)
			);
			return new WP_REST_Response( array( 'error' => 'order_already_paid' ), 409 );
		}
		if ( self::event_processed( $order, (string) ( $event['id'] ?? '' ) ) ) {
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		$charge_id   = sanitize_text_field( (string) ( $data['charge_id'] ?? '' ) );
		$checkout_id = $incoming_checkout_id;
		if ( '' === $charge_id || '' === $checkout_id || 'SUCCEEDED' !== strtoupper( (string) ( $data['status'] ?? '' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_collection' ), 400 );
		}
		if ( 'USD' !== strtoupper( (string) ( $data['currency'] ?? '' ) ) || wc_format_decimal( $order->get_total(), 2 ) !== wc_format_decimal( (string) ( $data['amount'] ?? '' ), 2 ) ) {
			return new WP_REST_Response( array( 'error' => 'amount_mismatch' ), 400 );
		}
		$order->update_meta_data( '_bachs_charge_id', $charge_id );
		$order->update_meta_data( '_bachs_checkout_id', $checkout_id );
		$order->update_meta_data( '_bachs_checkout_amount', sanitize_text_field( (string) ( $data['amount'] ?? '' ) ) );
		$order->update_meta_data( '_bachs_checkout_currency', sanitize_text_field( (string) ( $data['currency'] ?? '' ) ) );
		$order->update_meta_data( '_bachs_webhook_payload', wp_json_encode( $event ) );
		$order->update_meta_data( '_bachs_webhook_event_id', sanitize_text_field( (string) ( $event['id'] ?? '' ) ) );
		self::mark_event_processed( $order, (string) ( $event['id'] ?? '' ) );
		$order->payment_complete( $charge_id );
		/* translators: %s: Bachs charge ID. */
		$order->add_order_note( sprintf( __( 'Payment confirmed via Bachs. Charge: %s', 'bachs-for-woocommerce' ), $charge_id ) );
		$order->save();
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function handle_refund( array $event ): WP_REST_Response {
		$data      = (array) ( $event['data'] ?? array() );
		$charge_id = sanitize_text_field( (string) ( $data['charge_id'] ?? '' ) );
		if ( '' === $charge_id ) {
			return new WP_REST_Response( array( 'error' => 'refund_missing_charge' ), 400 );
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_bachs_charge_id',
				'meta_value' => $charge_id,
				'return'     => 'objects',
			)
		);
		$order  = $orders[0] ?? false;
		if ( ! $order ) {
			return new WP_REST_Response( array( 'error' => 'refund_order_not_found' ), 404 );
		}
		if ( (string) $order->get_meta( '_bachs_environment', true ) !== (string) $event['_bachs_environment'] ) {
			return new WP_REST_Response( array( 'error' => 'environment_mismatch' ), 400 );
		}
		if ( self::event_processed( $order, (string) ( $event['id'] ?? '' ) ) ) {
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		$status = sanitize_key( (string) ( $data['status'] ?? '' ) );
		$order->update_meta_data( '_bachs_refund_id', sanitize_text_field( (string) ( $data['refund_id'] ?? $order->get_meta( '_bachs_refund_id', true ) ) ) );
		$order->update_meta_data( '_bachs_refund_status', $status );
		$order->update_meta_data( '_bachs_refund_payload', wp_json_encode( $event ) );
		self::mark_event_processed( $order, (string) ( $event['id'] ?? '' ) );
		/* translators: %s: Bachs refund status. */
		$order->add_order_note( sprintf( __( 'Bachs refund update: %s.', 'bachs-for-woocommerce' ), $status ) );
		$order->save();
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function handle_subscription_created( array $event ): WP_REST_Response {
		$data            = (array) ( $event['data'] ?? array() );
		$metadata        = (array) ( $data['metadata'] ?? array() );
		$subscription_id = sanitize_text_field( (string) ( $data['subscription_id'] ?? $data['id'] ?? '' ) );
		if ( empty( $metadata['wc_subscription_id'] ) || empty( $metadata['wc_order_id'] ) || empty( $metadata['wc_order_key'] ) || '' === $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return new WP_REST_Response( array( 'error' => 'subscription_metadata_missing' ), 409 );
		}
		$subscription = wcs_get_subscription( absint( $metadata['wc_subscription_id'] ) );
		if ( ! $subscription instanceof WC_Order ) {
			return new WP_REST_Response( array( 'error' => 'subscription_not_found' ), 409 );
		}
		$parent_order = wc_get_order( $subscription->get_parent_id() );
		if ( ! $parent_order instanceof WC_Order || absint( $metadata['wc_order_id'] ) !== $parent_order->get_id() || ! hash_equals( $parent_order->get_order_key(), (string) $metadata['wc_order_key'] ) ) {
			return new WP_REST_Response( array( 'error' => 'subscription_order_mismatch' ), 400 );
		}
		if ( self::event_processed( $subscription, (string) ( $event['id'] ?? '' ) ) ) {
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		$subscription->update_meta_data( '_bachs_subscription_id', $subscription_id );
		$subscription->update_meta_data( '_bachs_environment', (string) $event['_bachs_environment'] );
		$subscription->update_meta_data( '_bachs_initial_invoice_pending', 'yes' );
		$initial_period_start = sanitize_text_field( (string) ( $data['previously_billed_at'] ?? $data['current_period_start'] ?? '' ) );
		if ( '' === $initial_period_start ) {
			return new WP_REST_Response( array( 'error' => 'subscription_period_missing' ), 409 );
		}
		$subscription->update_meta_data( '_bachs_initial_invoice_period_start', $initial_period_start );
		self::mark_event_processed( $subscription, (string) ( $event['id'] ?? '' ) );
		$subscription->save();
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function handle_subscription_state( array $event ): WP_REST_Response {
		$data            = (array) ( $event['data'] ?? array() );
		$subscription_id = sanitize_text_field( (string) ( $data['subscription_id'] ?? $data['id'] ?? '' ) );
		$subscription    = self::subscription_for_bachs_id( $subscription_id );
		if ( ! $subscription ) {
			return new WP_REST_Response( array( 'error' => 'subscription_not_ready' ), 409 );
		}
		if ( (string) $subscription->get_meta( '_bachs_environment', true ) !== (string) $event['_bachs_environment'] ) {
			return new WP_REST_Response( array( 'error' => 'environment_mismatch' ), 400 );
		}
		if ( self::event_processed( $subscription, (string) ( $event['id'] ?? '' ) ) ) {
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		$status          = 'customer.subscription.deleted' === ( $event['type'] ?? '' ) ? 'canceled' : sanitize_key( (string) ( $data['status'] ?? '' ) );
		$event_timestamp = self::event_timestamp( $event );
		if ( false === $event_timestamp ) {
			return new WP_REST_Response( array( 'error' => 'invalid_event_timestamp' ), 400 );
		}
		$last_timestamp = self::stored_event_timestamp( $subscription, '_bachs_remote_subscription_event_at' );
		if ( false !== $last_timestamp && $event_timestamp <= $last_timestamp ) {
			self::mark_event_processed( $subscription, (string) ( $event['id'] ?? '' ) );
			$subscription->save();
			return new WP_REST_Response(
				array(
					'received' => true,
					'stale'    => true,
				),
				200
			);
		}
		$target = match ( $status ) {
			'active', 'trialing' => 'active',
			'past_due', 'unpaid' => 'on-hold',
			'canceled', 'cancelled' => 'cancelled',
			default => '',
		};
		if ( '' !== $target ) {
			self::set_remote_subscription_status( $subscription, $target, $status );
		}
		$subscription->update_meta_data( '_bachs_remote_subscription_status', $status );
		$subscription->update_meta_data( '_bachs_remote_subscription_event_at', (string) ( $event['created_at'] ?? '' ) );
		self::mark_event_processed( $subscription, (string) ( $event['id'] ?? '' ) );
		$subscription->save();
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function handle_invoice_paid( array $event ): WP_REST_Response {
		$data            = (array) ( $event['data'] ?? array() );
		$subscription    = (array) ( $data['subscription'] ?? array() );
		$subscription_id = sanitize_text_field( (string) ( $subscription['subscription_id'] ?? '' ) );
		if ( '' === $subscription_id || ! function_exists( 'wcs_get_subscriptions' ) ) {
			return new WP_REST_Response( array( 'error' => 'subscription_not_ready' ), 409 );
		}
		$subscription = self::subscription_for_bachs_id( $subscription_id );
		if ( ! $subscription ) {
			return new WP_REST_Response( array( 'error' => 'subscription_not_ready' ), 409 );
		}
		if ( (string) $subscription->get_meta( '_bachs_environment', true ) !== (string) $event['_bachs_environment'] ) {
			return new WP_REST_Response( array( 'error' => 'environment_mismatch' ), 400 );
		}
		if ( $subscription instanceof WC_Order ) {
			$invoice_id = sanitize_text_field( (string) ( $data['invoice_id'] ?? '' ) );
			if ( '' === $invoice_id || 'paid' !== strtolower( (string) ( $data['status'] ?? '' ) ) || 'USD' !== strtoupper( (string) ( $data['currency'] ?? '' ) ) || wc_format_decimal( $subscription->get_total(), 2 ) !== wc_format_decimal( (string) ( $data['amount_paid'] ?? '' ), 2 ) ) {
				return new WP_REST_Response( array( 'error' => 'invalid_invoice' ), 400 );
			}
			if ( self::event_processed( $subscription, (string) ( $event['id'] ?? '' ) ) ) {
				return new WP_REST_Response(
					array(
						'received'  => true,
						'duplicate' => true,
					),
					200
				);
			}
			if ( 'paid' === self::invoice_state( $subscription, $invoice_id ) ) {
				return new WP_REST_Response(
					array(
						'received'  => true,
						'duplicate' => true,
					),
					200
				);
			}
			$charge         = (array) ( $data['charge'] ?? array() );
			$transaction_id = sanitize_text_field( (string) ( $charge['charge_id'] ?? $invoice_id ) );
			$is_initial     = 'yes' === $subscription->get_meta( '_bachs_initial_invoice_pending', true ) && self::same_bachs_period( (string) $subscription->get_meta( '_bachs_initial_invoice_period_start', true ), (string) ( $data['period_start'] ?? '' ) );
			if ( $is_initial ) {
				$subscription->update_meta_data( '_bachs_initial_invoice_pending', 'no' );
			} else {
				$renewal_order = self::renewal_order_for_invoice( $subscription );
				if ( is_wp_error( $renewal_order ) ) {
					return new WP_REST_Response( array( 'error' => 'renewal_order_creation_failed' ), 500 );
				}
				$renewal_order->payment_complete( $transaction_id );
				/* translators: %s: Bachs invoice ID. */
				$renewal_order->add_order_note( sprintf( __( 'Bachs recurring invoice paid: %s.', 'bachs-for-woocommerce' ), sanitize_text_field( (string) ( $data['invoice_id'] ?? '' ) ) ) );
				$renewal_order->save();
				self::set_remote_subscription_status( $subscription, 'active', 'active' );
			}
			/*
			 * The initial checkout's collection.succeeded event completes the parent
			 * order. Later invoices complete their renewal order above. Calling
			 * payment_complete() on the subscription here as well would advance its
			 * billing schedule twice in WooCommerce Subscriptions.
			 */
			$subscription->update_meta_data( '_bachs_last_invoice_id', $invoice_id );
			$subscription->update_meta_data( '_bachs_last_invoice_paid_at', current_time( 'mysql', true ) );
			self::set_invoice_state( $subscription, $invoice_id, 'paid' );
			self::mark_event_processed( $subscription, (string) ( $event['id'] ?? '' ) );
			/* translators: %s: Bachs invoice ID. */
			$subscription->add_order_note( sprintf( __( 'Bachs subscription invoice paid: %s.', 'bachs-for-woocommerce' ), sanitize_text_field( (string) ( $data['invoice_id'] ?? '' ) ) ) );
			$subscription->save();
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function handle_invoice_payment_failed( array $event ): WP_REST_Response {
		$data            = (array) ( $event['data'] ?? array() );
		$subscription    = (array) ( $data['subscription'] ?? array() );
		$subscription_id = sanitize_text_field( (string) ( $subscription['subscription_id'] ?? '' ) );
		if ( '' === $subscription_id || ! function_exists( 'wcs_get_subscriptions' ) ) {
			return new WP_REST_Response( array( 'error' => 'subscription_not_ready' ), 409 );
		}
		$subscription = self::subscription_for_bachs_id( $subscription_id );
		if ( ! $subscription ) {
			return new WP_REST_Response( array( 'error' => 'subscription_not_ready' ), 409 );
		}
		if ( (string) $subscription->get_meta( '_bachs_environment', true ) !== (string) $event['_bachs_environment'] ) {
			return new WP_REST_Response( array( 'error' => 'environment_mismatch' ), 400 );
		}
		if ( self::event_processed( $subscription, (string) ( $event['id'] ?? '' ) ) ) {
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		$invoice_id = sanitize_text_field( (string) ( $data['invoice_id'] ?? '' ) );
		if ( '' === $invoice_id || 'USD' !== strtoupper( (string) ( $data['currency'] ?? '' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_invoice' ), 400 );
		}
		if ( '' !== self::invoice_state( $subscription, $invoice_id ) ) {
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		if ( 'yes' === $subscription->get_meta( '_bachs_initial_invoice_pending', true ) && self::same_bachs_period( (string) $subscription->get_meta( '_bachs_initial_invoice_period_start', true ), (string) ( $data['period_start'] ?? '' ) ) ) {
			self::set_invoice_state( $subscription, $invoice_id, 'failed' );
			self::mark_event_processed( $subscription, (string) ( $event['id'] ?? '' ) );
			$subscription->save();
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		$renewal_order = self::renewal_order_for_invoice( $subscription );
		if ( is_wp_error( $renewal_order ) ) {
			return new WP_REST_Response( array( 'error' => 'renewal_order_creation_failed' ), 500 );
		}
		$renewal_order->update_status( 'failed', __( 'Bachs recurring payment failed.', 'bachs-for-woocommerce' ) );
		if ( method_exists( $subscription, 'update_status' ) ) {
			$subscription->update_status( 'on-hold', __( 'Bachs recurring payment failed.', 'bachs-for-woocommerce' ) );
		}
		self::set_invoice_state( $subscription, $invoice_id, 'failed' );
		self::mark_event_processed( $subscription, (string) ( $event['id'] ?? '' ) );
		$subscription->save();
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function event_processed( WC_Order $order, string $event_id ): bool {
		return '' !== $event_id && in_array( $event_id, (array) $order->get_meta( '_bachs_processed_event_ids', true ), true );
	}

	private static function invoice_state( WC_Order $subscription, string $invoice_id ): string {
		if ( '' === $invoice_id ) {
			return '';
		}
		$states = (array) $subscription->get_meta( '_bachs_invoice_states', true );
		$state  = $states[ $invoice_id ] ?? '';
		if ( in_array( $state, array( 'failed', 'paid' ), true ) ) {
			return $state;
		}
		// Old metadata has no outcome, so retain its terminal, idempotent behavior.
		return in_array( $invoice_id, (array) $subscription->get_meta( '_bachs_processed_invoice_ids', true ), true ) ? 'paid' : '';
	}

	private static function set_invoice_state( WC_Order $subscription, string $invoice_id, string $state ): void {
		if ( '' === $invoice_id || ! in_array( $state, array( 'failed', 'paid' ), true ) ) {
			return;
		}
		$states                = (array) $subscription->get_meta( '_bachs_invoice_states', true );
		$states[ $invoice_id ] = $state;
		$subscription->update_meta_data( '_bachs_invoice_states', array_slice( $states, -50, null, true ) );
	}

	private static function subscription_for_bachs_id( string $subscription_id ): WC_Order|false {
		if ( '' === $subscription_id || ! function_exists( 'wcs_get_subscriptions' ) ) {
			return false;
		}
		$subscriptions = wcs_get_subscriptions(
			array(
				'limit'      => 1,
				'meta_key'   => '_bachs_subscription_id',
				'meta_value' => $subscription_id,
			)
		);
		$subscription  = reset( $subscriptions );
		return $subscription instanceof WC_Order ? $subscription : false;
	}

	private static function same_bachs_period( string $expected, string $actual ): bool {
		$expected_time = strtotime( $expected );
		$actual_time   = strtotime( $actual );
		return false !== $expected_time && false !== $actual_time && $expected_time === $actual_time;
	}

	private static function set_remote_subscription_status( WC_Order $subscription, string $status, string $reason ): void {
		if ( ! method_exists( $subscription, 'update_status' ) || $subscription->get_status() === $status || ( in_array( $subscription->get_status(), array( 'cancelled', 'canceled' ), true ) && 'cancelled' !== $status ) ) {
			return;
		}
		$subscription->update_meta_data( '_bachs_remote_status_sync', 'yes' );
		$subscription->save();
		/* translators: %s: Bachs subscription status. */
		$subscription->update_status( $status, sprintf( __( 'Bachs subscription status: %s.', 'bachs-for-woocommerce' ), $reason ) );
		$subscription->delete_meta_data( '_bachs_remote_status_sync' );
		$subscription->save();
	}

	private static function event_timestamp( array $event ): int|false {
		return strtotime( (string) ( $event['created_at'] ?? '' ) );
	}

	private static function stored_event_timestamp( WC_Order $subscription, string $meta_key ): int|false {
		return strtotime( (string) $subscription->get_meta( $meta_key, true ) );
	}

	private static function renewal_order_for_invoice( WC_Order $subscription ): WC_Order|WP_Error {
		$orders = function_exists( 'wcs_get_related_orders' ) ? array_reverse( wcs_get_related_orders( $subscription, 'renewal' ) ) : array();
		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && ! $order->is_paid() ) {
				return $order;
			}
		}
		$order = wcs_create_renewal_order( $subscription );
		return $order instanceof WC_Order ? $order : new WP_Error( 'bachs_renewal_order', __( 'Unable to create the WooCommerce renewal order.', 'bachs-for-woocommerce' ) );
	}

	private static function verified_environment( string $raw, string $timestamp, string $signature, array $options ): string {
		$environment_secrets = array(
			'sandbox' => trim( (string) ( $options['sandbox_webhook_secret'] ?? '' ) ),
			'live'    => trim( (string) ( $options['live_webhook_secret'] ?? '' ) ),
		);
		foreach ( $environment_secrets as $environment => $secret ) {
			if ( self::valid_signature( $raw, $timestamp, $signature, $secret ) ) {
				return $environment;
			}
		}
		/*
		 * A legacy secret cannot distinguish two Bachs environments. It is only
		 * safe as a backwards-compatible fallback when no scoped secret exists;
		 * classify it using the gateway's configured active environment.
		 */
		$legacy_secret = trim( (string) ( $options['webhook_secret'] ?? '' ) );
		if ( '' === $environment_secrets['sandbox'] && '' === $environment_secrets['live'] && self::valid_signature( $raw, $timestamp, $signature, $legacy_secret ) ) {
			return 'yes' === ( $options['testmode'] ?? 'yes' ) ? 'sandbox' : 'live';
		}
		return '';
	}

	private static function mark_event_processed( WC_Order $order, string $event_id ): void {
		if ( '' === $event_id ) {
			return;
		}
		$ids   = (array) $order->get_meta( '_bachs_processed_event_ids', true );
		$ids[] = sanitize_text_field( $event_id );
		$order->update_meta_data( '_bachs_processed_event_ids', array_slice( array_values( array_unique( $ids ) ), -50 ) );
	}

	public static function valid_signature( string $raw, string $timestamp, string $signature, string $secret ): bool {
		if ( '' === $secret || '' === $timestamp || '' === $signature || ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );
		return hash_equals( $expected, strtolower( trim( $signature ) ) );
	}

	private static function find_order( array $metadata, string $reference, string $checkout_id ): WC_Order|false {
		if ( ! empty( $metadata['wc_order_id'] ) ) {
			$order = wc_get_order( absint( $metadata['wc_order_id'] ) );
			if ( $order ) {
				return $order;
			}
		}
		if ( preg_match( '/^wc_order_(\d+)/', $reference, $matches ) ) {
			$order = wc_get_order( absint( $matches[1] ) );
			if ( $order ) {
				return $order;
			}
		}
		if ( '' !== $checkout_id ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => '_bachs_checkout_id',
					'meta_value' => $checkout_id,
					'return'     => 'objects',
				)
			);
			return $orders[0] ?? false;
		}
		return false;
	}

	public static function order_status( WP_REST_Request $request ): WP_REST_Response {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order || ! hash_equals( $order->get_order_key(), (string) $request->get_param( 'key' ) ) ) {
			return new WP_REST_Response( array( 'is_paid' => false ), 404 );
		}
		return new WP_REST_Response( array( 'is_paid' => (bool) $order->get_meta( '_bachs_charge_id', true ) ), 200 );
	}

	public static function order_details( WC_Order $order ): void {
		$values = array_filter(
			array(
				__( 'Bachs checkout ID', 'bachs-for-woocommerce' ) => $order->get_meta( '_bachs_checkout_id', true ),
				__( 'Bachs charge ID', 'bachs-for-woocommerce' )   => $order->get_meta( '_bachs_charge_id', true ),
				__( 'Bachs amount/currency', 'bachs-for-woocommerce' ) => trim( $order->get_meta( '_bachs_checkout_amount', true ) . ' ' . $order->get_meta( '_bachs_checkout_currency', true ) ),
			)
		);
		if ( $values ) {
			echo '<div class="address"><p><strong>' . esc_html__( 'Bachs payment', 'bachs-for-woocommerce' ) . '</strong></p>';
			foreach ( $values as $label => $value ) {
				echo '<p>' . esc_html( $label ) . ': ' . esc_html( $value ) . '</p>';
			}
			echo '</div>';
		}
		$raw_payload = (string) $order->get_meta( '_bachs_webhook_payload', true );
		if ( '' !== $raw_payload ) {
			echo '<details class="bachs-webhook-payload"><summary>' . esc_html__( 'Raw Bachs webhook payload', 'bachs-for-woocommerce' ) . '</summary><pre>' . esc_html( $raw_payload ) . '</pre></details>';
		}
	}
}
