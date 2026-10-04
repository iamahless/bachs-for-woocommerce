<?php
declare(strict_types=1);

/* Lightweight regression fixtures for signature and environment routing. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text ) : string {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ) : string {
		return $text;
	}
}
if ( ! class_exists( 'WC_Order' ) ) {
	final class WC_Order {
		private array $meta;

		public function __construct( array $meta = array() ) {
			$this->meta = $meta;
		}

		public function get_meta( string $key, bool $single = true ) : mixed {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( string $key, mixed $value ) : void {
			$this->meta[ $key ] = $value;
		}
	}
}
require dirname( __DIR__ ) . '/includes/class-bachs-webhook.php';
require dirname( __DIR__ ) . '/includes/class-bachs-settings.php';

function bachs_fixture_assert( bool $condition, string $message ) : void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

$body      = '{"id":"evt_fixture","type":"collection.succeeded"}';
$timestamp = (string) time();
$sandbox   = 'sandbox-fixture-secret';
$live      = 'live-fixture-secret';
$sandbox_signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $sandbox );
$live_signature    = hash_hmac( 'sha256', $timestamp . '.' . $body, $live );

bachs_fixture_assert( Bachs_Webhook::valid_signature( $body, $timestamp, $sandbox_signature, $sandbox ), 'Valid Bachs signature was rejected.' );
bachs_fixture_assert( ! Bachs_Webhook::valid_signature( $body, $timestamp, $live_signature, $sandbox ), 'Invalid Bachs signature was accepted.' );

$method = new ReflectionMethod( Bachs_Webhook::class, 'verified_environment' );
$method->setAccessible( true );
$scoped_options = array( 'sandbox_webhook_secret' => $sandbox, 'live_webhook_secret' => $live, 'webhook_secret' => 'legacy', 'testmode' => 'no' );
bachs_fixture_assert( 'sandbox' === $method->invoke( null, $body, $timestamp, $sandbox_signature, $scoped_options ), 'Sandbox scoped secret did not select sandbox.' );
bachs_fixture_assert( 'live' === $method->invoke( null, $body, $timestamp, $live_signature, $scoped_options ), 'Live scoped secret did not select live.' );

$legacy_options = array( 'webhook_secret' => 'legacy', 'testmode' => 'no' );
$legacy_signature = hash_hmac( 'sha256', $timestamp . '.' . $body, 'legacy' );
bachs_fixture_assert( 'live' === $method->invoke( null, $body, $timestamp, $legacy_signature, $legacy_options ), 'Legacy secret did not select the active environment.' );

$period_method = new ReflectionMethod( Bachs_Webhook::class, 'same_bachs_period' );
$period_method->setAccessible( true );
bachs_fixture_assert( $period_method->invoke( null, '2026-04-01T00:00:00Z', '2026-04-01T00:00:00+00:00' ), 'Equivalent Bachs billing periods did not match.' );
bachs_fixture_assert( ! $period_method->invoke( null, '2026-04-01T00:00:00Z', '2026-05-01T00:00:00Z' ), 'Different Bachs billing periods matched.' );

$timestamp_method = new ReflectionMethod( Bachs_Webhook::class, 'event_timestamp' );
$timestamp_method->setAccessible( true );
bachs_fixture_assert( 1775001600 === $timestamp_method->invoke( null, array( 'created_at' => '2026-04-01T00:00:00Z' ) ), 'Bachs event timestamp was not parsed.' );
bachs_fixture_assert( false === $timestamp_method->invoke( null, array( 'created_at' => 'not-a-date' ) ), 'Invalid Bachs event timestamp was accepted.' );

$prices = Bachs_Settings::currency_options( array( array( 'currency' => 'NGN', 'amount' => '12345678901234567890.01' ) ) );
bachs_fixture_assert( is_array( $prices ) && '12345678901234567890.01' === ( $prices['NGN'] ?? '' ), 'Locked local price lost decimal precision.' );

$state_method = new ReflectionMethod( Bachs_Webhook::class, 'invoice_state' );
$state_method->setAccessible( true );
$set_state_method = new ReflectionMethod( Bachs_Webhook::class, 'set_invoice_state' );
$set_state_method->setAccessible( true );
$subscription = new WC_Order();
$set_state_method->invoke( null, $subscription, 'inv_recovery', 'failed' );
bachs_fixture_assert( 'failed' === $state_method->invoke( null, $subscription, 'inv_recovery' ), 'Failed invoice state was not recorded.' );
$set_state_method->invoke( null, $subscription, 'inv_recovery', 'paid' );
bachs_fixture_assert( 'paid' === $state_method->invoke( null, $subscription, 'inv_recovery' ), 'Failed invoice state could not transition to paid.' );
$legacy_subscription = new WC_Order( array( '_bachs_processed_invoice_ids' => array( 'inv_legacy' ) ) );
bachs_fixture_assert( 'paid' === $state_method->invoke( null, $legacy_subscription, 'inv_legacy' ), 'Legacy invoice metadata did not retain terminal idempotency.' );

echo "Webhook fixtures passed\n";
