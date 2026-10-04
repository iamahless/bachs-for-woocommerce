<?php
defined( 'ABSPATH' ) || exit;

class WC_Gateway_Bachs extends WC_Payment_Gateway {
	private Bachs_Logger $logger;

	public function __construct() {
		$this->id                 = 'bachs';
		$this->method_title       = __( 'Bachs', 'bachs-for-woocommerce' );
		$this->method_description = __( 'Redirect customers to Bachs hosted checkout. Card details never pass through this store.', 'bachs-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds', 'subscriptions', 'subscription_cancellation' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title', __( 'Pay with Bachs', 'bachs-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', '' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );
		$this->logger      = new Bachs_Logger( 'yes' === $this->get_option( 'debug_logging', 'no' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'sync_subscription_cancellation' ), 10, 3 );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'                => array(
				'title'   => __( 'Enable/Disable', 'bachs-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Bachs hosted checkout', 'bachs-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                  => array(
				'title'   => __( 'Title', 'bachs-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Pay with Bachs', 'bachs-for-woocommerce' ),
			),
			'description'            => array(
				'title'   => __( 'Description', 'bachs-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'You will be redirected to Bachs to complete your payment securely.', 'bachs-for-woocommerce' ),
			),
			'testmode'               => array(
				'title'   => __( 'Test mode', 'bachs-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Use Bachs sandbox', 'bachs-for-woocommerce' ),
				'default' => 'yes',
			),
			'sandbox_secret_key'     => array(
				'title'       => __( 'Sandbox secret key', 'bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Starts with sk_sandbox_. Leave blank to retain the saved key.', 'bachs-for-woocommerce' ),
			),
			'live_secret_key'        => array(
				'title'       => __( 'Live secret key', 'bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Starts with sk_live_. Leave blank to retain the saved key.', 'bachs-for-woocommerce' ),
			),
			'sandbox_webhook_secret' => array(
				'title'       => __( 'Sandbox webhook secret', 'bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Secret for the sandbox webhook destination. Leave blank to retain it.', 'bachs-for-woocommerce' ),
			),
			'live_webhook_secret'    => array(
				'title'       => __( 'Live webhook secret', 'bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Secret for the live webhook destination. Leave blank to retain it.', 'bachs-for-woocommerce' ),
			),
			'webhook_secret'         => array(
				'title'       => __( 'Webhook secret (legacy fallback)', 'bachs-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Used only if neither environment-specific secret is configured, and assigned to the active mode. Configure separate sandbox and live secrets before receiving events from both environments.', 'bachs-for-woocommerce' ),
			),
			'debug_logging'          => array(
				'title'   => __( 'Debug logging', 'bachs-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log Bachs requests and verified webhook payloads (secrets are redacted).', 'bachs-for-woocommerce' ),
				'default' => 'no',
			),
			'locked_local_prices'    => array(
				'title'       => __( 'Locked local prices', 'bachs-for-woocommerce' ),
				'type'        => 'locked_local_prices',
				'description' => __( 'Optional exact local checkout prices. USD is always the base price and cannot be entered here.', 'bachs-for-woocommerce' ),
			),
			'webhook_url'            => array(
				'title' => __( 'Webhook URL', 'bachs-for-woocommerce' ),
				'type'  => 'webhook_url',
			),
		);
	}

	public function is_available(): bool {
		if ( 'USD' !== strtoupper( get_woocommerce_currency() ) || ! parent::is_available() ) {
			return false;
		}
		$key = 'yes' === $this->get_option( 'testmode', 'yes' ) ? $this->get_option( 'sandbox_secret_key' ) : $this->get_option( 'live_secret_key' );
		return '' !== trim( (string) $key );
	}

	public function admin_options(): void {
		echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2><p>' . esc_html( $this->get_method_description() ) . '</p>';
		if ( 'USD' !== strtoupper( get_woocommerce_currency() ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Gateway disabled: Bachs checkout must be priced in USD. Change WooCommerce currency to USD and re-enter catalog prices in USD; the plugin does not convert prices.', 'bachs-for-woocommerce' ) . '</p></div>';
		}
		if ( ! $this->active_key() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Gateway disabled: configure the secret key for the active mode.', 'bachs-for-woocommerce' ) . '</p></div>';
		}
		echo '<p><a href="https://app.bachs.io" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Bachs dashboard (settlement and balance currency settings)', 'bachs-for-woocommerce' ) . '</a></p>';
		parent::admin_options();
	}

	public function generate_password_html( $key, $data ): string {
		$field_key = $this->get_field_key( $key );
		return '<tr valign="top"><th scope="row" class="titledesc"><label for="' . esc_attr( $field_key ) . '">' . esc_html( $data['title'] ) . '</label></th><td class="forminp"><input class="input-text regular-input" type="password" name="' . esc_attr( $field_key ) . '" id="' . esc_attr( $field_key ) . '" value="" autocomplete="new-password" placeholder="••••••••" /><p class="description">' . esc_html( $data['description'] ?? '' ) . '</p></td></tr>';
	}

	public function validate_password_field( $key, $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return (string) $this->get_option( $key, '' );
		}
		$prefix = 'sandbox_secret_key' === $key ? 'sk_sandbox_' : ( 'live_secret_key' === $key ? 'sk_live_' : '' );
		if ( '' !== $prefix && ! str_starts_with( $value, $prefix ) ) {
			/* translators: %s: required Bachs API key prefix. */
			WC_Admin_Settings::add_error( sprintf( __( 'The key must start with %s.', 'bachs-for-woocommerce' ), $prefix ) );
			return (string) $this->get_option( $key, '' );
		}
		return sanitize_text_field( $value );
	}

	public function generate_locked_local_prices_html(): string {
		$saved = Bachs_Settings::currency_options( $this->get_option( 'locked_local_prices', array() ) );
		$rows  = is_wp_error( $saved ) ? array() : $saved;
		ob_start();
		?><tr valign="top"><th scope="row" class="titledesc"><label><?php esc_html_e( 'Locked local prices', 'bachs-for-woocommerce' ); ?></label></th><td class="forminp"><div id="bachs-locked-prices" data-name="<?php echo esc_attr( $this->get_field_key( 'locked_local_prices' ) ); ?>">
		<?php
		foreach ( $rows as $currency => $amount ) :
			?>
	<p class="bachs-price-row"><select name="<?php echo esc_attr( $this->get_field_key( 'locked_local_prices' ) ); ?>[currency][]"><option value=""><?php esc_html_e( 'Currency', 'bachs-for-woocommerce' ); ?></option>
			<?php
			foreach ( Bachs_Settings::CURRENCIES as $option ) :
				?>
	<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $currency, $option ); ?>><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select> <input type="text" name="<?php echo esc_attr( $this->get_field_key( 'locked_local_prices' ) ); ?>[amount][]" value="<?php echo esc_attr( $amount ); ?>" placeholder="0.00" /> <button type="button" class="button-link-delete bachs-remove-price"><?php esc_html_e( 'Remove', 'bachs-for-woocommerce' ); ?></button></p><?php endforeach; ?></div><button type="button" class="button bachs-add-price"><?php esc_html_e( 'Add local price', 'bachs-for-woocommerce' ); ?></button><p class="description"><?php esc_html_e( 'Supported: NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF, ZMW. Exact overrides apply on Bachs checkout; other currencies use live FX.', 'bachs-for-woocommerce' ); ?></p></td></tr>
		<?php
		return (string) ob_get_clean();
	}

	public function generate_webhook_url_html(): string {
		$url = Bachs_Webhook::url();
		return '<tr valign="top"><th scope="row">' . esc_html__( 'Webhook URL', 'bachs-for-woocommerce' ) . '</th><td><code id="bachs-webhook-url">' . esc_html( $url ) . '</code> <button type="button" class="button" id="bachs-copy-webhook">' . esc_html__( 'Copy', 'bachs-for-woocommerce' ) . '</button><p class="description">' . esc_html__( 'Register this HTTPS URL in each Bachs environment and subscribe to collection.succeeded, refund.created, refund.paid, refund.failed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, invoice.paid, and invoice.payment_failed.', 'bachs-for-woocommerce' ) . '</p></td></tr>';
	}

	public function validate_locked_local_prices_field( $key, $value ): string {
		$currencies = (array) ( $value['currency'] ?? array() );
		$amounts    = (array) ( $value['amount'] ?? array() );
		$rows       = array();
		foreach ( $currencies as $index => $currency ) {
			$rows[] = array(
				'currency' => $currency,
				'amount'   => $amounts[ $index ] ?? '',
			);
		}
		$validated = Bachs_Settings::currency_options( $rows );
		if ( is_wp_error( $validated ) ) {
			WC_Admin_Settings::add_error( $validated->get_error_message() );
			return (string) $this->get_option( $key, wp_json_encode( array() ) );
		}
		return wp_json_encode( $validated );
	}

	public function process_payment( $order_id ): array {
		$lock = $this->acquire_checkout_lock( absint( $order_id ) );
		if ( is_wp_error( $lock ) ) {
			wc_add_notice( __( 'A Bachs checkout is already being prepared. Please wait a moment and try again.', 'bachs-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		try {
			return $this->process_payment_locked( $order_id );
		} finally {
			$this->release_checkout_lock( absint( $order_id ), $lock );
		}
	}

	private function process_payment_locked( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => 'failure' );
		}
		if ( 'USD' !== strtoupper( $order->get_currency() ) || ! $this->is_available() ) {
			wc_add_notice( __( 'Payment could not be initiated, please try again.', 'bachs-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$reference = $this->checkout_reference( $order );
		$order->save();
		$payload = $this->session_payload( $order, $reference );
		if ( is_wp_error( $payload ) ) {
			$order->add_order_note( 'Bachs: ' . $payload->get_error_message() );
			wc_add_notice( __( 'Payment could not be initiated, please try again.', 'bachs-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$result = $this->api()->create_checkout_session( $payload );
		if ( is_wp_error( $result ) || empty( $result['checkout_id'] ) || empty( $result['checkout_url'] ) ) {
			$code = is_wp_error( $result ) ? (string) ( $result->get_error_data()['bachs_code'] ?? $result->get_error_code() ) : 'INVALID_RESPONSE';
			$order->add_order_note( 'Bachs session creation failed: ' . sanitize_text_field( $code ) );
			$this->logger->error(
				'Bachs checkout creation failed.',
				array(
					'order_id' => $order_id,
					'error'    => is_wp_error( $result ) ? $result->get_error_message() : $result,
				)
			);
			wc_add_notice( __( 'Payment could not be initiated, please try again.', 'bachs-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$order->update_meta_data( '_bachs_checkout_id', sanitize_text_field( $result['checkout_id'] ) );
		$order->update_meta_data( '_bachs_environment', $this->environment() );
		$order->update_status( 'pending', __( 'Awaiting Bachs payment confirmation.', 'bachs-for-woocommerce' ) );
		$order->save();
		wc_reduce_stock_levels( $order_id );
		WC()->cart?->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $result['checkout_url'] ),
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|WP_Error {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->get_meta( '_bachs_charge_id', true ) ) {
			return new WP_Error( 'bachs_refund_missing_charge', __( 'This order has no Bachs charge. Refund it from the Bachs dashboard if required.', 'bachs-for-woocommerce' ) );
		}
		$amount    = wc_format_decimal( null === $amount ? $order->get_total() : $amount, 2 );
		$reference = 'wc_refund_' . $order_id . '_' . wp_generate_uuid4();
		$api       = $this->api_for_resource( (string) $order->get_meta( '_bachs_environment', true ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$result = $api->refund( (string) $order->get_meta( '_bachs_charge_id', true ), $amount, substr( $reference, 0, 128 ), (string) $reason );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$refund_id = sanitize_text_field( (string) ( $result['refund_id'] ?? '' ) );
		if ( '' === $refund_id ) {
			return new WP_Error( 'bachs_refund_invalid_response', __( 'Bachs did not return a refund ID. Check the Bachs dashboard before trying again.', 'bachs-for-woocommerce' ) );
		}
		$order->update_meta_data( '_bachs_refund_id', $refund_id );
		/* translators: %s: Bachs refund ID. */
		$order->add_order_note( sprintf( __( 'Bachs refund initiated. Refund: %s', 'bachs-for-woocommerce' ), $refund_id ) );
		$order->save();
		return true;
	}

	private function session_payload( WC_Order $order, string $reference ): array|WP_Error {
		$customer = $this->checkout_customer( $order );
		$payload  = array(
			'customer'           => $customer,
			'success_url'        => $order->get_checkout_order_received_url(),
			'cancel_url'         => wc_get_cart_url(),
			'reference'          => $reference,
			'metadata'           => array(
				'wc_order_id'       => (string) $order->get_id(),
				'wc_order_key'      => $order->get_order_key(),
				'bachs_environment' => $this->environment(),
			),
			'expires_in_minutes' => 60,
		);
		if ( $this->is_subscription_order( $order ) ) {
			return $this->subscription_session_payload( $order, $payload, $customer );
		}
		return $this->one_time_session_payload( $payload, $order );
	}

	private function checkout_customer( WC_Order $order ): array {
		return array_filter(
			array(
				'email' => sanitize_email( $order->get_billing_email() ),
				'name'  => trim( $order->get_formatted_billing_full_name() ),
			)
		);
	}

	private function subscription_session_payload( WC_Order $order, array $payload, array $customer ): array|WP_Error {
		if ( empty( $customer['email'] ) ) {
			return new WP_Error( 'bachs_subscription_customer', __( 'Bachs subscriptions require a customer billing email address.', 'bachs-for-woocommerce' ) );
		}
		$product_id = $this->ensure_subscription_product( $order );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}
		$subscription = $this->subscription_for_order( $order );
		if ( ! $subscription instanceof WC_Order ) {
			return new WP_Error( 'bachs_subscription_missing', __( 'Unable to determine the WooCommerce subscription for this order.', 'bachs-for-woocommerce' ) );
		}
		$payload['metadata']['wc_subscription_id'] = (string) $subscription->get_id();
		$payload['product_cart']                   = array(
			array(
				'product_id' => $product_id,
				'quantity'   => 1,
			),
		);
		$payload['billing_currency']               = 'USD';
		$payload['payment_method_types']           = array( 'USD_CARD' );
		return $payload;
	}

	private function one_time_session_payload( array $payload, WC_Order $order ): array|WP_Error {
		$options = Bachs_Settings::currency_options( $this->get_option( 'locked_local_prices', array() ) );
		if ( is_wp_error( $options ) ) {
			return $options;
		}
		$pricing = array(
			'currency' => 'USD',
			'amount'   => wc_format_decimal( $order->get_total(), 2 ),
		);
		if ( $options ) {
			$pricing['currency_options'] = $options;
		}
		$payload['pricing'] = $pricing;
		return $payload;
	}

	private function is_subscription_order( WC_Order $order ): bool {
		return function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
	}

	private function ensure_subscription_product( WC_Order $order ): string|WP_Error {
		if ( 'USD' !== strtoupper( get_woocommerce_currency() ) ) {
			return new WP_Error( 'bachs_subscription_currency', __( 'Bachs recurring checkouts require USD pricing.', 'bachs-for-woocommerce' ) );
		}
		$subscription = $this->subscription_for_order( $order );
		if ( ! $subscription instanceof WC_Order ) {
			return new WP_Error( 'bachs_subscription_missing', __( 'Unable to determine the WooCommerce subscription for this order.', 'bachs-for-woocommerce' ) );
		}
		$interval = (int) $subscription->get_billing_interval();
		$period   = (string) $subscription->get_billing_period();
		if ( ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) || $interval < 1 ) {
			return new WP_Error( 'bachs_subscription_interval', __( 'This WooCommerce subscription billing interval is not supported by Bachs.', 'bachs-for-woocommerce' ) );
		}
		$price       = array(
			'currency' => 'USD',
			'amount'   => wc_format_decimal( $subscription->get_total(), 2 ),
		);
		$data        = array(
			/* translators: %d: WooCommerce subscription ID. */
			'name'          => sprintf( __( 'WooCommerce subscription #%d', 'bachs-for-woocommerce' ), $subscription->get_id() ),
			'description'   => __( 'Managed by Bachs for WooCommerce.', 'bachs-for-woocommerce' ),
			'price'         => $price,
			'billing_cycle' => array(
				'interval'  => $period,
				'frequency' => $interval,
			),
			'metadata'      => array(
				'wc_subscription_id' => (string) $subscription->get_id(),
				'wc_order_id'        => (string) $order->get_id(),
				'wc_order_key'       => $order->get_order_key(),
			),
		);
		$environment = $this->environment();
		$product_id  = (string) $subscription->get_meta( '_bachs_subscription_product_id', true );
		if ( '' !== $product_id && $environment === $subscription->get_meta( '_bachs_subscription_product_environment', true ) ) {
			return $product_id;
		}
		$api = $this->api_for_resource( $environment );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$result = $api->create_product( $data );
		if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'bachs_subscription_product', __( 'Bachs did not return a recurring product ID.', 'bachs-for-woocommerce' ) );
		}
		$product_id = sanitize_text_field( (string) $result['id'] );
		$subscription->update_meta_data( '_bachs_subscription_product_id', $product_id );
		$subscription->update_meta_data( '_bachs_subscription_product_environment', $environment );
		$subscription->save();
		return $product_id;
	}

	private function subscription_for_order( WC_Order $order ): WC_Order|false {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return false;
		}
		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		$subscription  = reset( $subscriptions );
		return $subscription instanceof WC_Order ? $subscription : false;
	}

	/** Sync WooCommerce cancellation states to the Bachs subscription. */
	public function sync_subscription_cancellation( $subscription, string $old_status, string $new_status ): void {
		if ( ! $subscription instanceof WC_Order || 'yes' === $subscription->get_meta( '_bachs_remote_status_sync', true ) || ! in_array( $new_status, array( 'pending-cancel', 'cancelled' ), true ) ) {
			return;
		}
		$bachs_subscription_id = (string) $subscription->get_meta( '_bachs_subscription_id', true );
		if ( '' === $bachs_subscription_id ) {
			return;
		}
		$api = $this->api_for_resource( (string) $subscription->get_meta( '_bachs_environment', true ) );
		if ( is_wp_error( $api ) ) {
			$subscription->add_order_note( $api->get_error_message() );
			$this->logger->error(
				'Bachs subscription cancellation could not be routed.',
				array(
					'subscription_id' => $subscription->get_id(),
					'error'           => $api->get_error_message(),
				)
			);
			return;
		}
		$result = $api->cancel_subscription( $bachs_subscription_id, 'pending-cancel' === $new_status, __( 'Cancelled in WooCommerce.', 'bachs-for-woocommerce' ) );
		if ( is_wp_error( $result ) ) {
			$subscription->add_order_note( __( 'Bachs subscription cancellation could not be synchronized. Review the Bachs dashboard.', 'bachs-for-woocommerce' ) );
			$this->logger->error(
				'Bachs subscription cancellation failed.',
				array(
					'subscription_id' => $subscription->get_id(),
					'error'           => $result->get_error_message(),
				)
			);
		}
	}

	/** WooCommerce renewal orders are settled only when Bachs sends invoice.paid. */
	public function scheduled_subscription_payment( $amount, $renewal_order ): void {
		if ( ! $renewal_order instanceof WC_Order ) {
			return;
		}
		$renewal_order->update_status( 'on-hold', __( 'Awaiting Bachs-managed recurring payment confirmation.', 'bachs-for-woocommerce' ) );
	}

	public function thankyou( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_meta( '_bachs_charge_id', true ) ) {
			return;
		}
		$url = add_query_arg( array( 'key' => rawurlencode( $order->get_order_key() ) ), rest_url( 'bachs/v1/order-status/' . $order->get_id() ) );
		echo '<div class="woocommerce-info bachs-awaiting-confirmation">' . esc_html__( 'Your payment is awaiting confirmation from Bachs. Your order will be updated automatically once confirmed.', 'bachs-for-woocommerce' ) . '</div>';
		echo '<script>!function(){const u=' . wp_json_encode( $url ) . ';let n=0,t=setInterval(function(){fetch(u,{credentials:"same-origin"}).then(r=>r.json()).then(d=>{if(d.is_paid||++n>20){clearInterval(t);if(d.is_paid)location.reload()}}).catch(()=>{if(++n>20)clearInterval(t)})},3000)}();</script>';
	}

	public function admin_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'bachs-admin', BACHS_WC_URL . 'assets/css/admin.css', array(), BACHS_WC_VERSION );
		wp_enqueue_script( 'bachs-admin', BACHS_WC_URL . 'assets/js/admin.js', array(), BACHS_WC_VERSION, true );
	}

	private function api(): Bachs_API {
		$environment = $this->environment();
		return new Bachs_API( 'sandbox' === $environment, (string) $this->get_option( 'sandbox' === $environment ? 'sandbox_secret_key' : 'live_secret_key', '' ), $this->logger );
	}

	private function api_for_resource( string $environment ): Bachs_API|WP_Error {
		if ( ! in_array( $environment, array( 'sandbox', 'live' ), true ) ) {
			return new WP_Error( 'bachs_resource_environment_unknown', __( 'This Bachs resource has no known environment. Review it in the Bachs dashboard.', 'bachs-for-woocommerce' ) );
		}
		$key = trim( (string) $this->get_option( 'sandbox' === $environment ? 'sandbox_secret_key' : 'live_secret_key', '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'bachs_resource_environment_unconfigured', __( 'The Bachs key for this resource\'s environment is not configured.', 'bachs-for-woocommerce' ) );
		}
		return new Bachs_API( 'sandbox' === $environment, $key, $this->logger );
	}

	private function active_key(): bool {
		$testmode = 'yes' === $this->get_option( 'testmode', 'yes' );
		return '' !== trim( (string) $this->get_option( $testmode ? 'sandbox_secret_key' : 'live_secret_key', '' ) );
	}

	private function environment(): string {
		return 'yes' === $this->get_option( 'testmode', 'yes' ) ? 'sandbox' : 'live';
	}

	/** Keep retries idempotent while allowing a new session after the 60-minute expiry. */
	private function checkout_reference( WC_Order $order ): string {
		$reference = (string) $order->get_meta( '_bachs_checkout_reference', true );
		$created   = (int) $order->get_meta( '_bachs_checkout_reference_created_at', true );
		if ( '' !== $reference && $created > 0 && ( time() - $created ) < HOUR_IN_SECONDS ) {
			return $reference;
		}
		$reference = 'wc_order_' . $order->get_id() . '_' . wp_generate_uuid4();
		$order->update_meta_data( '_bachs_checkout_reference', substr( $reference, 0, 128 ) );
		$order->update_meta_data( '_bachs_checkout_reference_created_at', time() );
		return $reference;
	}

	private function acquire_checkout_lock( int $order_id ): string|WP_Error {
		if ( $order_id < 1 ) {
			return new WP_Error( 'bachs_invalid_order', __( 'The order could not be locked for Bachs checkout.', 'bachs-for-woocommerce' ) );
		}
		$key   = 'bachs_checkout_lock_' . $order_id;
		$token = wp_generate_uuid4();
		$value = array(
			'token'      => $token,
			'created_at' => time(),
		);
		if ( add_option( $key, $value, '', 'no' ) ) {
			return $token;
		}
		$existing = get_option( $key, array() );
		if ( is_array( $existing ) && ! empty( $existing['created_at'] ) && ( time() - absint( $existing['created_at'] ) ) > MINUTE_IN_SECONDS ) {
			delete_option( $key );
			if ( add_option( $key, $value, '', 'no' ) ) {
				return $token;
			}
		}
		return new WP_Error( 'bachs_checkout_locked', __( 'A Bachs checkout is already being created for this order.', 'bachs-for-woocommerce' ) );
	}

	private function release_checkout_lock( int $order_id, string $token ): void {
		$key      = 'bachs_checkout_lock_' . $order_id;
		$existing = get_option( $key, array() );
		if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) {
			delete_option( $key );
		}
	}
}
