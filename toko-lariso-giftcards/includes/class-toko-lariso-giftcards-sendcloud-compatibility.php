<?php
/**
 * SendCloud compatibility fixes.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prevents SendCloud service-point checkout handlers from fatalling on giftcard-only Blocks orders.
 */
class Toko_Lariso_Giftcards_Sendcloud_Compatibility {
	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'normalize_chosen_shipping_methods' ), 1, 1 );
	}

	/**
	 * Ensures SendCloud receives an array for chosen_shipping_methods during Store API checkout.
	 *
	 * SendCloud's Blocks service-point handler calls reset() on the session value without
	 * checking the type. Giftcard-only carts intentionally do not require shipping, so
	 * WooCommerce may leave the session value unset. Setting a non-SendCloud method id
	 * avoids SendCloud validation while keeping the giftcard order line non-virtual.
	 *
	 * @param WC_Order $order Processed order.
	 * @return void
	 */
	public function normalize_chosen_shipping_methods( WC_Order $order ): void {
		if ( ! $this->is_giftcard_only_without_shipping_items( $order ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$chosen = WC()->session->get( 'chosen_shipping_methods', null );
		if ( is_array( $chosen ) && $chosen ) {
			$first = reset( $chosen );
			if ( is_string( $first ) ) {
				return;
			}
		}

		if ( is_string( $chosen ) && '' !== $chosen ) {
			WC()->session->set( 'chosen_shipping_methods', array( $chosen ) );
			return;
		}

		WC()->session->set( 'chosen_shipping_methods', array( Toko_Lariso_Giftcards_Shipping::METHOD_ID ) );
	}

	/**
	 * Checks whether an order contains only Toko Lariso giftcard line items and no shipping item.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function is_giftcard_only_without_shipping_items( WC_Order $order ): bool {
		if ( $order->get_items( 'shipping' ) ) {
			return false;
		}

		$line_items = $order->get_items( 'line_item' );
		if ( ! $line_items ) {
			return false;
		}

		foreach ( $line_items as $item ) {
			if ( 'yes' === $item->get_meta( '_tokolariso_is_giftcard', true ) ) {
				continue;
			}

			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if (
				is_object( $product )
				&& is_callable( array( $product, 'get_type' ) )
				&& Toko_Lariso_Giftcards_Product_Type::TYPE === $product->get_type()
			) {
				continue;
			}

			return false;
		}

		return true;
	}
}
