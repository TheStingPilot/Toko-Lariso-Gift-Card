<?php
/**
 * Debug logging.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes opt-in diagnostics to WooCommerce logs.
 */
class Toko_Lariso_Giftcards_Debug {
	private const SOURCE = 'toko-lariso-giftcards';

	/**
	 * Whether debug logging is enabled.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$settings = get_option( Toko_Lariso_Giftcards_Settings::OPTION_KEY, array() );
		return is_array( $settings ) && 'yes' === ( $settings['debug_logging'] ?? 'no' );
	}

	/**
	 * Writes a debug log entry.
	 *
	 * @param string              $event Event name.
	 * @param array<string,mixed> $context Context.
	 * @param string              $level Log level.
	 * @return void
	 */
	public static function log( string $event, array $context = array(), string $level = 'debug' ): void {
		if ( ! self::enabled() || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		$logger->log(
			$level,
			$event . ' ' . wp_json_encode( self::redact_context( $context ) ),
			array( 'source' => self::SOURCE )
		);
	}

	/**
	 * Builds a compact order context.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function order_context( WC_Order $order ): array {
		return array(
			'order_id'       => $order->get_id(),
			'status'         => $order->get_status(),
			'order_total'    => $order->get_total( 'edit' ),
			'needs_payment'  => $order->needs_payment() ? 'yes' : 'no',
			'payment_method' => $order->get_payment_method(),
		);
	}

	/**
	 * Redacts values that should not be written to logs.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function redact_context( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $item ) {
				$key_string = is_string( $key ) ? strtolower( $key ) : (string) $key;
				if ( str_contains( $key_string, 'code' ) && ! str_contains( $key_string, 'mask' ) ) {
					$redacted[ $key ] = '[redacted]';
					continue;
				}
				$redacted[ $key ] = self::redact_context( $item );
			}
			return $redacted;
		}

		return $value;
	}
}
