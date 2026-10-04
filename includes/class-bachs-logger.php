<?php
defined( 'ABSPATH' ) || exit;

final class Bachs_Logger {
	private bool $enabled;

	public function __construct( bool $enabled ) {
		$this->enabled = $enabled;
	}

	public function debug( string $message, array $context = array() ): void {
		if ( ! $this->enabled || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->debug( $message . ' ' . wp_json_encode( $this->redact( $context ) ), array( 'source' => 'bachs-for-woocommerce' ) );
	}

	public function error( string $message, array $context = array() ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message . ' ' . wp_json_encode( $this->redact( $context ) ), array( 'source' => 'bachs-for-woocommerce' ) );
		}
	}

	private function redact( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->redact( $item );
			} elseif ( preg_match( '/(authorization|secret|api[_-]?key|signature)/i', (string) $key ) ) {
				$value[ $key ] = '[REDACTED]';
			}
		}
		return $value;
	}
}
