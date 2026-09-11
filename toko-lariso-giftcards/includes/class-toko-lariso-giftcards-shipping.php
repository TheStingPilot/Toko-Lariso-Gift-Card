<?php
/**
 * Giftcard shipping behavior.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps giftcard products non-virtual while preventing giftcards from triggering shipping-rate waits.
 */
class Toko_Lariso_Giftcards_Shipping {
	/**
	 * Free shipping method id for giftcard-only packages.
	 */
	public const METHOD_ID = 'tokolariso_giftcard_delivery';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_shipping_init', array( $this, 'define_shipping_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
		add_filter( 'woocommerce_product_needs_shipping', array( $this, 'giftcard_product_needs_no_shipping_rate' ), 10, 2 );
		add_filter( 'woocommerce_cart_needs_shipping', array( $this, 'cart_needs_shipping' ), 10, 1 );
		add_filter( 'woocommerce_cart_needs_shipping_address', array( $this, 'cart_needs_shipping_address' ), 10, 1 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'prepare_giftcard_shipping_packages' ), 100 );
		add_filter( 'woocommerce_package_rates', array( $this, 'zero_giftcard_package_rates' ), 100, 2 );
		add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'free_shipping_requires_regular_cart_value' ), 10, 3 );
	}

	/**
	 * Defines the zero-cost shipping method.
	 *
	 * @return void
	 */
	public function define_shipping_method(): void {
		if ( ! class_exists( 'WC_Shipping_Method' ) || class_exists( 'WC_Shipping_Toko_Lariso_Giftcard_Delivery' ) ) {
			return;
		}

		require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-wc-shipping-toko-lariso-giftcard-delivery.php';
	}

	/**
	 * Registers the shipping method with WooCommerce.
	 *
	 * @param array<string,string> $methods Shipping methods.
	 * @return array<string,string>
	 */
	public function register_shipping_method( array $methods ): array {
		$methods[ self::METHOD_ID ] = 'WC_Shipping_Toko_Lariso_Giftcard_Delivery';
		return $methods;
	}

	/**
	 * Keeps giftcards as non-virtual products while excluding them from shipping-rate calculation.
	 *
	 * @param bool       $needs_shipping Existing shipping need.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public function giftcard_product_needs_no_shipping_rate( bool $needs_shipping, WC_Product $product ): bool {
		if ( self::product_is_giftcard( $product ) ) {
			return false;
		}

		return $needs_shipping;
	}

	/**
	 * Cart needs shipping only when it contains a non-giftcard shippable item.
	 *
	 * @param bool $needs_shipping Existing cart shipping need.
	 * @return bool
	 */
	public function cart_needs_shipping( bool $needs_shipping ): bool {
		if ( ! $needs_shipping ) {
			return false;
		}

		return $this->cart_has_regular_shippable_items();
	}

	/**
	 * Cart needs a shipping address only when a non-giftcard item requires shipping.
	 *
	 * @param bool $needs_address Existing address need.
	 * @return bool
	 */
	public function cart_needs_shipping_address( bool $needs_address ): bool {
		if ( ! $needs_address ) {
			return false;
		}

		return $this->cart_has_regular_shippable_items();
	}

	/**
	 * Marks giftcard-only packages and keeps mixed packages as one package for Store API stability.
	 *
	 * @param array<int,array<string,mixed>> $packages Packages.
	 * @return array<int,array<string,mixed>>
	 */
	public function prepare_giftcard_shipping_packages( array $packages ): array {
		$prepared_packages = array();

		foreach ( $packages as $package ) {
			$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
			if ( ! $contents ) {
				$prepared_packages[] = $package;
				continue;
			}

			$giftcards       = array();
			$regular         = array();
			$has_giftcards   = false;
			$has_regular     = false;

			foreach ( $contents as $cart_item_key => $cart_item ) {
				if ( self::cart_item_is_giftcard( $cart_item ) ) {
					$giftcards[ $cart_item_key ] = $cart_item;
					$has_giftcards               = true;
				} else {
					$regular[ $cart_item_key ] = $cart_item;
					$has_regular               = true;
				}
			}

			if ( $has_giftcards && ! $has_regular ) {
				$package['tokolariso_giftcard_package'] = true;
				$package['tokolariso_has_giftcards']     = true;
				$package['tokolariso_regular_total']     = 0.0;
				$package['package_name']                = __( 'Giftcards', 'toko-lariso-giftcards' );
				$prepared_packages[]                    = $package;
				continue;
			}

			if ( $has_giftcards && $has_regular ) {
				$package['contents_cost']               = $this->package_contents_cost( $regular );
				$package['tokolariso_has_giftcards']    = true;
				$package['tokolariso_regular_total']    = $this->package_contents_total_including_tax( $regular );
				$package['tokolariso_regular_contents'] = $regular;
			} else {
				$package['contents_cost'] = $this->package_contents_cost( $contents );
			}

			$prepared_packages[] = $package;
		}

		return $prepared_packages;
	}

	/**
	 * Sets all rates for giftcard-only packages to zero, including third-party rates.
	 *
	 * @param array<string,WC_Shipping_Rate> $rates Rates.
	 * @param array<string,mixed>            $package Package.
	 * @return array<string,WC_Shipping_Rate>
	 */
	public function zero_giftcard_package_rates( array $rates, array $package ): array {
		if ( ! self::package_is_giftcards_only( $package ) ) {
			if ( $this->mixed_package_is_below_regular_free_shipping_threshold( $package ) ) {
				foreach ( $rates as $rate_key => $rate ) {
					if ( $this->rate_is_unearned_free_shipping_candidate( $rate ) ) {
						unset( $rates[ $rate_key ] );
					}
				}
			}

			return $rates;
		}

		if ( ! $rates && class_exists( 'WC_Shipping_Rate' ) ) {
			$rates[ self::METHOD_ID ] = new WC_Shipping_Rate(
				self::METHOD_ID,
				__( 'Giftcard delivery', 'toko-lariso-giftcards' ),
				0,
				array(),
				self::METHOD_ID
			);
		}

		foreach ( $rates as $rate ) {
			if ( is_callable( array( $rate, 'set_cost' ) ) ) {
				$rate->set_cost( 0 );
			} else {
				$rate->cost = 0;
			}

			$taxes = is_callable( array( $rate, 'get_taxes' ) ) ? $rate->get_taxes() : array();
			if ( is_array( $taxes ) ) {
				$zero_taxes = array();
				foreach ( $taxes as $tax_id => $tax_amount ) {
					$zero_taxes[ $tax_id ] = 0;
				}
				if ( is_callable( array( $rate, 'set_taxes' ) ) ) {
					$rate->set_taxes( $zero_taxes );
				} else {
					$rate->taxes = $zero_taxes;
				}
			}
		}

		return $rates;
	}

	/**
	 * Keeps WooCommerce core free shipping from counting giftcard value in mixed carts.
	 *
	 * @param bool                $is_available Existing availability.
	 * @param array<string,mixed> $package Shipping package.
	 * @param mixed               $shipping_method Shipping method.
	 * @return bool
	 */
	public function free_shipping_requires_regular_cart_value( bool $is_available, array $package = array(), mixed $shipping_method = null ): bool {
		if ( ! $is_available || ! $this->mixed_package_is_below_regular_free_shipping_threshold( $package, $shipping_method ) ) {
			return $is_available;
		}

		return false;
	}

	/**
	 * Checks if a shipping package contains only giftcards.
	 *
	 * @param array<string,mixed> $package Package.
	 * @return bool
	 */
	public static function package_is_giftcards_only( array $package ): bool {
		$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
		if ( ! $contents ) {
			return false;
		}

		foreach ( $contents as $cart_item ) {
			if ( ! self::cart_item_is_giftcard( $cart_item ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks whether a cart item is a Toko Lariso giftcard product.
	 *
	 * @param mixed $cart_item Cart item.
	 * @return bool
	 */
	private static function cart_item_is_giftcard( mixed $cart_item ): bool {
		if ( ! is_array( $cart_item ) || empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
			return false;
		}

		return self::product_is_giftcard( $cart_item['data'] );
	}

	/**
	 * Checks whether a product is a Toko Lariso giftcard.
	 *
	 * @param mixed $product Product.
	 * @return bool
	 */
	private static function product_is_giftcard( mixed $product ): bool {
		return Toko_Lariso_Giftcards_Product_Type::is_giftcard_product( $product );
	}

	/**
	 * Checks whether the current cart has shippable items other than giftcards.
	 *
	 * @return bool
	 */
	private function cart_has_regular_shippable_items(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return true;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( self::cart_item_is_giftcard( $cart_item ) ) {
				continue;
			}

			$product = isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( $product && is_callable( array( $product, 'needs_shipping' ) ) && $product->needs_shipping() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculates package contents cost.
	 *
	 * @param array<string,array<string,mixed>> $contents Contents.
	 * @return float
	 */
	private function package_contents_cost( array $contents ): float {
		$cost = 0.0;
		foreach ( $contents as $cart_item ) {
			$cost += isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0;
		}

		return $cost;
	}

	/**
	 * Calculates package contents total including tax.
	 *
	 * @param array<string,array<string,mixed>> $contents Contents.
	 * @return float
	 */
	private function package_contents_total_including_tax( array $contents ): float {
		$total = 0.0;
		foreach ( $contents as $cart_item ) {
			$total += isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0;
			$total += isset( $cart_item['line_tax'] ) ? (float) $cart_item['line_tax'] : 0.0;
		}

		return $total;
	}

	/**
	 * Checks whether a mixed giftcard package is below the free-shipping threshold.
	 *
	 * @param array<string,mixed> $package Shipping package.
	 * @param mixed              $shipping_method Optional shipping method.
	 * @return bool
	 */
	private function mixed_package_is_below_regular_free_shipping_threshold( array $package, mixed $shipping_method = null ): bool {
		if ( ! $this->package_has_giftcards_and_regular_items( $package ) ) {
			return false;
		}

		$min_amount = $this->free_shipping_min_amount( $shipping_method );
		if ( $min_amount <= 0 ) {
			return false;
		}

		return $this->package_regular_total_including_tax( $package ) + 0.0001 < $min_amount;
	}

	/**
	 * Checks whether a package contains giftcards and regular items.
	 *
	 * @param array<string,mixed> $package Shipping package.
	 * @return bool
	 */
	private function package_has_giftcards_and_regular_items( array $package ): bool {
		$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
		if ( ! $contents ) {
			return false;
		}

		$has_giftcard = false;
		$has_regular  = false;

		foreach ( $contents as $cart_item ) {
			if ( self::cart_item_is_giftcard( $cart_item ) ) {
				$has_giftcard = true;
			} else {
				$has_regular = true;
			}
		}

		return $has_giftcard && $has_regular;
	}

	/**
	 * Gets the package value that may count toward free shipping.
	 *
	 * @param array<string,mixed> $package Shipping package.
	 * @return float
	 */
	private function package_regular_total_including_tax( array $package ): float {
		if ( isset( $package['tokolariso_regular_total'] ) ) {
			return (float) $package['tokolariso_regular_total'];
		}

		$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
		$regular  = array();

		foreach ( $contents as $cart_item_key => $cart_item ) {
			if ( ! self::cart_item_is_giftcard( $cart_item ) ) {
				$regular[ $cart_item_key ] = $cart_item;
			}
		}

		return $this->package_contents_total_including_tax( $regular );
	}

	/**
	 * Resolves the free-shipping threshold, preferring WooCommerce's configured method value.
	 *
	 * @param mixed $shipping_method Optional shipping method.
	 * @return float
	 */
	private function free_shipping_min_amount( mixed $shipping_method = null ): float {
		$min_amount = $this->free_shipping_min_amount_from_method( $shipping_method );

		if ( $min_amount <= 0 ) {
			$min_amount = $this->configured_free_shipping_min_amount();
		}

		if ( $min_amount <= 0 ) {
			$min_amount = 45.0;
		}

		return max( 0.0, (float) apply_filters( 'tokolariso_giftcards_free_shipping_min_amount', $min_amount, $shipping_method ) );
	}

	/**
	 * Reads a threshold from a shipping method instance.
	 *
	 * @param mixed $shipping_method Shipping method.
	 * @return float
	 */
	private function free_shipping_min_amount_from_method( mixed $shipping_method ): float {
		if ( ! is_object( $shipping_method ) ) {
			return 0.0;
		}

		if ( isset( $shipping_method->min_amount ) ) {
			return (float) $shipping_method->min_amount;
		}

		if ( is_callable( array( $shipping_method, 'get_option' ) ) ) {
			return (float) $shipping_method->get_option( 'min_amount', 0 );
		}

		return 0.0;
	}

	/**
	 * Reads the first configured WooCommerce free-shipping threshold.
	 *
	 * @return float
	 */
	private function configured_free_shipping_min_amount(): float {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return 0.0;
		}

		$amounts = array();
		$zones   = WC_Shipping_Zones::get_zones();

		foreach ( $zones as $zone ) {
			if ( empty( $zone['shipping_methods'] ) || ! is_array( $zone['shipping_methods'] ) ) {
				continue;
			}
			$amounts = array_merge( $amounts, $this->free_shipping_min_amounts_from_methods( $zone['shipping_methods'] ) );
		}

		$default_zone = WC_Shipping_Zones::get_zone( 0 );
		if ( $default_zone && is_callable( array( $default_zone, 'get_shipping_methods' ) ) ) {
			$amounts = array_merge( $amounts, $this->free_shipping_min_amounts_from_methods( $default_zone->get_shipping_methods() ) );
		}

		$amounts = array_filter(
			array_map( 'floatval', $amounts ),
			static function ( float $amount ): bool {
				return $amount > 0;
			}
		);

		return $amounts ? min( $amounts ) : 0.0;
	}

	/**
	 * Reads thresholds from shipping method objects.
	 *
	 * @param array<int|string,mixed> $methods Shipping methods.
	 * @return array<int,float>
	 */
	private function free_shipping_min_amounts_from_methods( array $methods ): array {
		$amounts = array();

		foreach ( $methods as $method ) {
			if ( ! is_object( $method ) || empty( $method->id ) || 'free_shipping' !== $method->id ) {
				continue;
			}

			if ( isset( $method->enabled ) && 'yes' !== $method->enabled ) {
				continue;
			}

			$amount = $this->free_shipping_min_amount_from_method( $method );
			if ( $amount > 0 ) {
				$amounts[] = $amount;
			}
		}

		return $amounts;
	}

	/**
	 * Checks whether a returned rate is a free-shipping result that should be suppressed.
	 *
	 * @param mixed $rate Shipping rate.
	 * @return bool
	 */
	private function rate_is_unearned_free_shipping_candidate( mixed $rate ): bool {
		if ( ! is_object( $rate ) ) {
			return false;
		}

		$method_id = is_callable( array( $rate, 'get_method_id' ) ) ? (string) $rate->get_method_id() : (string) ( $rate->method_id ?? '' );
		if ( self::METHOD_ID === $method_id || str_contains( $method_id, 'local_pickup' ) || str_contains( $method_id, 'pickup' ) ) {
			return false;
		}

		if ( 'free_shipping' === $method_id ) {
			return true;
		}

		$cost = is_callable( array( $rate, 'get_cost' ) ) ? (float) $rate->get_cost() : (float) ( $rate->cost ?? 0 );
		return $cost <= 0.0001;
	}
}
