<?php
/**
 * Cart and checkout behavior.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles giftcard sales and cart credit application.
 */
class Toko_Lariso_Giftcards_Cart {
	public const SESSION_APPLIED     = 'tokolariso_giftcards_applied';
	public const SESSION_ALLOCATIONS = 'tokolariso_giftcards_allocations';
	private const SESSION_PENDING_CODE = 'tokolariso_giftcard_pending_code';
	private const COOKIE_PENDING_CODE = 'tokolariso_giftcard_pending';

	/**
	 * Settings.
	 *
	 * @var Toko_Lariso_Giftcards_Settings
	 */
	private Toko_Lariso_Giftcards_Settings $settings;

	/**
	 * Repository.
	 *
	 * @var Toko_Lariso_Giftcards_Repository
	 */
	private Toko_Lariso_Giftcards_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Toko_Lariso_Giftcards_Settings   $settings Settings.
	 * @param Toko_Lariso_Giftcards_Repository $repository Repository.
	 */
	public function __construct( Toko_Lariso_Giftcards_Settings $settings, Toko_Lariso_Giftcards_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_giftcard_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_giftcard_cart_prices' ), 20 );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'refresh_allocations_for_cart' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_item_mix' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_cart_item_mix' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
		add_action( 'wp_loaded', array( $this, 'remember_giftcard_from_url' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_apply_giftcard_from_url' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'maybe_apply_pending_giftcard_cookie' ), 20 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'maybe_apply_pending_giftcard_cookie' ), 20 );
	}

	/**
	 * Remembers a giftcard code from a PDF/QR URL as early as possible.
	 *
	 * @return void
	 */
	public function remember_giftcard_from_url(): void {
		if ( is_admin() || empty( $_GET['tokolariso_giftcard'] ) ) {
			return;
		}

		if ( ! $this->ensure_wc_cart() ) {
			Toko_Lariso_Giftcards_Debug::log( 'pending_url_code_no_wc_cart' );
			return;
		}

		$code = sanitize_text_field( wp_unslash( $_GET['tokolariso_giftcard'] ) );
		if ( '' === $code ) {
			return;
		}

		try {
			$this->validate_remembered_code( $code );
			$this->set_pending_giftcard_code( $code );
			Toko_Lariso_Giftcards_Debug::log( 'pending_url_code_saved', array( 'has_cart_items' => $this->cart_has_items() ? 'yes' : 'no' ) );

			wc_add_notice( __( 'Giftcard code saved. Add products to your cart and the giftcard will be applied automatically.', 'toko-lariso-giftcards' ), 'success' );
		} catch ( Throwable $exception ) {
			$this->clear_pending_giftcard_code();
			Toko_Lariso_Giftcards_Debug::log( 'pending_url_code_failed', array( 'error' => $exception->getMessage() ), 'warning' );
			wc_add_notice( $exception->getMessage(), 'error' );
		}

		wp_safe_redirect( remove_query_arg( 'tokolariso_giftcard' ) );
		exit;
	}

	/**
	 * Applies a pending giftcard when cart/checkout is viewed.
	 *
	 * @return void
	 */
	public function maybe_apply_giftcard_from_url(): void {
		if ( is_admin() || ! $this->ensure_wc_cart() ) {
			return;
		}

		if ( ( is_cart() || is_checkout() ) && $this->cart_has_items() ) {
			$this->maybe_apply_pending_giftcard_cookie();
		}
	}

	/**
	 * Applies a remembered giftcard code once a cart is available.
	 *
	 * @return void
	 */
	public function maybe_apply_pending_giftcard_cookie(): void {
		if ( is_admin() || ! $this->ensure_wc_cart() || ! $this->cart_has_items() ) {
			return;
		}

		$code = $this->pending_giftcard_code();
		if ( '' === $code ) {
			return;
		}

		try {
			$this->apply_code_to_session( $code );
			wc_add_notice( __( 'Giftcard applied.', 'toko-lariso-giftcards' ), 'success' );
			Toko_Lariso_Giftcards_Debug::log( 'pending_code_applied' );
			$this->clear_pending_giftcard_code();
		} catch ( Throwable $exception ) {
			wc_add_notice( $exception->getMessage(), 'error' );
			Toko_Lariso_Giftcards_Debug::log( 'pending_code_apply_failed', array( 'error' => $exception->getMessage() ), 'warning' );
			if ( $this->pending_code_error_should_clear( $exception ) ) {
				$this->clear_pending_giftcard_code();
			}
		}
	}

	/**
	 * Makes sure WooCommerce cart/session objects are available.
	 *
	 * @return bool
	 */
	private function ensure_wc_cart(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		if ( ( ! WC()->session || ! WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		return (bool) ( WC()->session && WC()->cart );
	}

	/**
	 * Checks whether the current cart has lines.
	 *
	 * @return bool
	 */
	private function cart_has_items(): bool {
		return WC()->cart && ! WC()->cart->is_empty();
	}

	/**
	 * Validates a remembered code without applying it to the cart yet.
	 *
	 * @param string $code Giftcard code.
	 * @return void
	 */
	private function validate_remembered_code( string $code ): void {
		$card = $this->repository->get_by_code( $code );
		if ( ! $card ) {
			throw new InvalidArgumentException( __( 'Giftcard code was not found or cannot be used.', 'toko-lariso-giftcards' ) );
		}

		if ( $this->repository->is_expired_by_date( $card ) ) {
			$this->repository->expire( (int) $card['id'] );
			throw new InvalidArgumentException( $this->giftcard_expired_message() );
		}

		if ( (float) $card['current_balance'] <= 0 ) {
			throw new InvalidArgumentException( $this->giftcard_fully_used_message() );
		}

		if ( 'active' !== $card['status'] ) {
			throw new InvalidArgumentException( __( 'Giftcard code cannot be used because this giftcard is not active.', 'toko-lariso-giftcards' ) );
		}
	}

	/**
	 * Stores a pending giftcard code in a short-lived browser cookie.
	 *
	 * @param string $code Giftcard code.
	 * @return void
	 */
	private function set_pending_giftcard_code( string $code ): void {
		if ( WC()->session ) {
			if ( is_callable( array( WC()->session, 'set_customer_session_cookie' ) ) ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->session->set( self::SESSION_PENDING_CODE, $code );
		}
		$this->set_giftcard_cookie( rawurlencode( $code ), time() + ( 14 * DAY_IN_SECONDS ) );
	}

	/**
	 * Reads a pending giftcard code from session or browser cookie.
	 *
	 * @return string
	 */
	private function pending_giftcard_code(): string {
		if ( WC()->session ) {
			$session_code = WC()->session->get( self::SESSION_PENDING_CODE, '' );
			if ( is_string( $session_code ) && '' !== $session_code ) {
				return sanitize_text_field( $session_code );
			}
		}

		if ( empty( $_COOKIE[ self::COOKIE_PENDING_CODE ] ) ) {
			return '';
		}

		return sanitize_text_field( rawurldecode( wp_unslash( $_COOKIE[ self::COOKIE_PENDING_CODE ] ) ) );
	}

	/**
	 * Clears the pending giftcard cookie.
	 *
	 * @return void
	 */
	private function clear_pending_giftcard_code(): void {
		if ( WC()->session ) {
			WC()->session->__unset( self::SESSION_PENDING_CODE );
		}
		$this->set_giftcard_cookie( '', time() - HOUR_IN_SECONDS );
		unset( $_COOKIE[ self::COOKIE_PENDING_CODE ] );
	}

	/**
	 * Writes the pending giftcard cookie.
	 *
	 * @param string $value Cookie value.
	 * @param int    $expires Expiry timestamp.
	 * @return void
	 */
	private function set_giftcard_cookie( string $value, int $expires ): void {
		$options = array(
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
			$options['domain'] = COOKIE_DOMAIN;
		}

		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( self::COOKIE_PENDING_CODE, $value, $expires, is_ssl(), true );
		}
		setcookie( self::COOKIE_PENDING_CODE, $value, $options );
		if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH && SITECOOKIEPATH !== $options['path'] ) {
			$options['path'] = SITECOOKIEPATH;
			setcookie( self::COOKIE_PENDING_CODE, $value, $options );
		}
		if ( $expires > time() ) {
			$_COOKIE[ self::COOKIE_PENDING_CODE ] = $value;
		}
	}

	/**
	 * Determines whether a pending-code apply failure should clear storage.
	 *
	 * @param Throwable $exception Exception.
	 * @return bool
	 */
	private function pending_code_error_should_clear( Throwable $exception ): bool {
		return $exception instanceof InvalidArgumentException;
	}

	/**
	 * Applies a giftcard code to the session.
	 *
	 * @param string $code Code entered by shopper.
	 * @param mixed  $max_amount Optional maximum amount to redeem.
	 * @return array<string,mixed>
	 */
	public function apply_code_to_session( string $code, mixed $max_amount = null ): array {
		Toko_Lariso_Giftcards_Debug::log( 'cart_apply_code_start', array( 'has_cart' => WC()->cart ? 'yes' : 'no' ) );

		if ( $this->cart_contains_giftcard_purchase() ) {
			$this->clear_session();
			Toko_Lariso_Giftcards_Debug::log( 'cart_apply_code_blocked_giftcard_purchase_in_cart' );
			throw new InvalidArgumentException( $this->giftcard_payment_blocked_message() );
		}

		$card = $this->repository->get_by_code( $code );
		if ( ! $card ) {
			Toko_Lariso_Giftcards_Debug::log( 'cart_apply_code_not_found' );
			throw new InvalidArgumentException( __( 'Giftcard code was not found or cannot be used.', 'toko-lariso-giftcards' ) );
		}

		if ( $this->repository->is_expired_by_date( $card ) ) {
			$this->repository->expire( (int) $card['id'] );
			Toko_Lariso_Giftcards_Debug::log( 'cart_apply_code_expired', array( 'giftcard_id' => (int) $card['id'], 'code_mask' => (string) $card['code_mask'] ) );
			throw new InvalidArgumentException( $this->giftcard_expired_message() );
		}

		if ( (float) $card['current_balance'] <= 0 ) {
			Toko_Lariso_Giftcards_Debug::log(
				'cart_apply_code_fully_used',
				array(
					'giftcard_id'     => (int) $card['id'],
					'code_mask'       => (string) $card['code_mask'],
					'status'          => (string) $card['status'],
					'current_balance' => (float) $card['current_balance'],
				)
			);
			throw new InvalidArgumentException( $this->giftcard_fully_used_message() );
		}

		if ( 'active' !== $card['status'] ) {
			Toko_Lariso_Giftcards_Debug::log(
				'cart_apply_code_unusable',
				array(
					'giftcard_id'     => (int) $card['id'],
					'code_mask'       => (string) $card['code_mask'],
					'status'          => (string) $card['status'],
					'current_balance' => (float) $card['current_balance'],
				)
			);
			throw new InvalidArgumentException( __( 'Giftcard code cannot be used because this giftcard is not active.', 'toko-lariso-giftcards' ) );
		}

		$max_amount = $this->normalize_redemption_limit( $max_amount );
		$applied = $this->get_applied_cards();
		if ( ! $this->settings->multiple_giftcards_enabled() ) {
			$applied = array();
		}

		foreach ( $applied as $index => $existing ) {
			if ( (int) $existing['id'] === (int) $card['id'] ) {
				$applied[ $index ]['max_amount'] = $max_amount;
				$this->set_applied_cards( $applied );
				$this->recalculate_cart();
				return $card;
			}
		}

		$applied[] = array(
			'id'         => (int) $card['id'],
			'code_mask'  => (string) $card['code_mask'],
			'max_amount' => $max_amount,
		);

		$this->set_applied_cards( $applied );
		$this->recalculate_cart();

		Toko_Lariso_Giftcards_Debug::log(
			'cart_apply_code_saved',
			array(
				'giftcard_id'     => (int) $card['id'],
				'code_mask'       => (string) $card['code_mask'],
				'current_balance' => (float) $card['current_balance'],
				'max_amount'      => $max_amount,
				'applied_count'   => count( $applied ),
				'allocations'     => $this->get_allocations(),
			)
		);

		return $card;
	}

	/**
	 * Removes a card from session.
	 *
	 * @param int $giftcard_id Giftcard id.
	 * @return void
	 */
	public function remove_card_from_session( int $giftcard_id ): void {
		Toko_Lariso_Giftcards_Debug::log( 'cart_remove_code_start', array( 'giftcard_id' => $giftcard_id ) );

		$applied = array_values(
			array_filter(
				$this->get_applied_cards(),
				static fn( array $card ): bool => (int) $card['id'] !== $giftcard_id
			)
		);

		$this->set_applied_cards( $applied );
		$this->recalculate_cart();
		Toko_Lariso_Giftcards_Debug::log( 'cart_remove_code_saved', array( 'giftcard_id' => $giftcard_id, 'applied_count' => count( $applied ) ) );
	}

	/**
	 * Clears session giftcards.
	 *
	 * @return void
	 */
	public function clear_session(): void {
		if ( WC()->session ) {
			WC()->session->__unset( self::SESSION_APPLIED );
			WC()->session->__unset( self::SESSION_ALLOCATIONS );
		}
	}

	/**
	 * Gets applied cards from session.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_applied_cards(): array {
		if ( ! WC()->session ) {
			return array();
		}

		$applied = WC()->session->get( self::SESSION_APPLIED, array() );
		return is_array( $applied ) ? $applied : array();
	}

	/**
	 * Gets current cart allocations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_allocations(): array {
		if ( ! WC()->session ) {
			return array();
		}

		$allocations = WC()->session->get( self::SESSION_ALLOCATIONS, array() );
		return is_array( $allocations ) ? $allocations : array();
	}

	/**
	 * Calculates current giftcard allocations for an arbitrary payable amount.
	 *
	 * This is used during checkout order creation because the order total is the
	 * authoritative amount handed to payment gateways.
	 *
	 * @param float $amount Payable amount before giftcard partial payment.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_allocations_for_amount( float $amount ): array {
		if ( $this->cart_contains_giftcard_purchase() ) {
			$this->clear_session();
			return array();
		}

		$allocations = $this->get_allocations();
		if ( ! $allocations ) {
			$applied = $this->get_applied_cards();
			if ( $applied ) {
				$allocations = $this->calculate_allocations_for_amount( $applied, $amount );
				if ( $allocations ) {
					$this->store_allocations_for_cart( $allocations );
					Toko_Lariso_Giftcards_Debug::log(
						'cart_order_allocations_rebuilt_from_applied_cards',
						array(
							'amount'      => $amount,
							'allocations' => $allocations,
						)
					);
					return $allocations;
				}

				Toko_Lariso_Giftcards_Debug::log( 'cart_order_allocations_cleared_unusable_applied_cards' );
			}
			$this->clear_session();
			return array();
		}

		return $this->calculate_allocations_for_amount( $allocations, $amount );
	}

	/**
	 * Gets summary data safe for public Store API responses.
	 *
	 * @return array<string,mixed>
	 */
	public function get_public_summary(): array {
		$can_apply       = ! $this->cart_contains_giftcard_purchase();
		$blocked_message = $can_apply ? '' : $this->giftcard_payment_blocked_message();

		$cart_total = 0.0;
		if ( WC()->cart ) {
			$this->refresh_allocations_for_cart( WC()->cart );
			$cart_total = $this->cart_total_including_tax( WC()->cart );
		}

		$allocations = $this->get_allocations();
		$total       = 0.0;
		$items       = array();

		foreach ( $allocations as $allocation ) {
			$amount = (float) ( $allocation['amount'] ?? 0 );
			$total += $amount;
			$items[] = array(
				'id'                          => (int) ( $allocation['id'] ?? 0 ),
				'code'                        => (string) ( $allocation['code_mask'] ?? '' ),
				'amount'                      => $amount,
				'amount_formatted'            => $this->format_price_text( $amount ),
				'max_amount'                  => (float) ( $allocation['max_amount'] ?? 0 ),
				'max_amount_formatted'        => $this->format_optional_price_text( (float) ( $allocation['max_amount'] ?? 0 ) ),
				'balance'                     => (float) ( $allocation['balance'] ?? 0 ),
				'balance_formatted'           => $this->format_price_text( (float) ( $allocation['balance'] ?? 0 ) ),
				'remaining_balance'           => (float) ( $allocation['remaining_balance'] ?? 0 ),
				'remaining_balance_formatted' => $this->format_price_text( (float) ( $allocation['remaining_balance'] ?? 0 ) ),
			);
		}

		return array(
			'applied'                 => $items,
			'total_applied'           => $this->repository->normalize_amount( $total ),
			'total_applied_formatted' => $this->format_price_text( $total ),
			'cart_total'              => $cart_total,
			'cart_total_formatted'    => $this->format_price_text( $cart_total ),
			'remaining_total'         => $this->repository->normalize_amount( max( 0.0, $cart_total - $total ) ),
			'remaining_total_formatted' => $this->format_price_text( max( 0.0, $cart_total - $total ) ),
			'allow_multiple'          => $this->settings->multiple_giftcards_enabled(),
			'can_apply'               => $can_apply,
			'blocked_message'         => $blocked_message,
		);
	}

	/**
	 * Validates add-to-cart for giftcard products.
	 *
	 * @param bool $passed Existing pass status.
	 * @param int  $product_id Product id.
	 * @param int  $quantity Quantity.
	 * @return bool
	 */
	public function validate_giftcard_add_to_cart( bool $passed, int $product_id, int $quantity ): bool {
		$product = wc_get_product( $product_id );
		$is_giftcard = Toko_Lariso_Giftcards_Product_Type::is_giftcard_product( $product );

		if ( $is_giftcard && $this->cart_contains_regular_product() ) {
			wc_add_notice( $this->giftcard_mixed_cart_blocked_message(), 'error' );
			return false;
		}

		if ( ! $is_giftcard ) {
			if ( $this->cart_contains_giftcard_purchase() ) {
				wc_add_notice( $this->giftcard_mixed_cart_blocked_message(), 'error' );
				return false;
			}

			return $passed;
		}

		if ( $quantity > 1 ) {
			wc_add_notice( __( 'Please add giftcards one at a time so each giftcard can have its own recipient and message.', 'toko-lariso-giftcards' ), 'error' );
			return false;
		}

		$nonce = isset( $_POST['tokolariso_giftcard_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'tokolariso_giftcard_add_to_cart' ) ) {
			wc_add_notice( __( 'Giftcard form security check failed. Please try again.', 'toko-lariso-giftcards' ), 'error' );
			return false;
		}

		$amount = $this->read_purchase_amount();
		if ( $amount <= 0 ) {
			wc_add_notice( __( 'Please choose a valid giftcard amount.', 'toko-lariso-giftcards' ), 'error' );
			return false;
		}

		$name  = isset( $_POST['tokolariso_giftcard_recipient_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_recipient_name'] ) ) : '';
		$email = isset( $_POST['tokolariso_giftcard_recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['tokolariso_giftcard_recipient_email'] ) ) : '';
		if ( '' === $name || ! is_email( $email ) ) {
			wc_add_notice( __( 'Please enter a recipient name and a valid recipient email address.', 'toko-lariso-giftcards' ), 'error' );
			return false;
		}

		$date = isset( $_POST['tokolariso_giftcard_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_delivery_date'] ) ) : '';
		if ( $date && strtotime( $date ) < strtotime( gmdate( 'Y-m-d' ) ) ) {
			wc_add_notice( __( 'Delivery date cannot be in the past.', 'toko-lariso-giftcards' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Blocks checkout for existing mixed carts created before this rule was active.
	 *
	 * @return void
	 */
	public function validate_cart_item_mix(): void {
		if ( ! $this->cart_contains_giftcard_purchase() || ! $this->cart_contains_regular_product() ) {
			return;
		}

		wc_add_notice( $this->giftcard_mixed_cart_blocked_message(), 'error' );
	}

	/**
	 * Adds giftcard purchase data to cart item.
	 *
	 * @param array<string,mixed> $cart_item_data Cart data.
	 * @param int                 $product_id Product id.
	 * @param int                 $variation_id Variation id.
	 * @return array<string,mixed>
	 */
	public function add_cart_item_data( array $cart_item_data, int $product_id, int $variation_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! Toko_Lariso_Giftcards_Product_Type::is_giftcard_product( $product ) ) {
			return $cart_item_data;
		}

		$image_id = isset( $_POST['tokolariso_giftcard_image_id'] ) ? absint( $_POST['tokolariso_giftcard_image_id'] ) : 0;
		$message  = isset( $_POST['tokolariso_giftcard_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tokolariso_giftcard_message'] ) ) : '';
		$date     = isset( $_POST['tokolariso_giftcard_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_delivery_date'] ) ) : '';
		$sender   = isset( $_POST['tokolariso_giftcard_sender_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_sender_name'] ) ) : '';

		$cart_item_data['tokolariso_giftcard'] = array(
			'amount'          => $this->read_purchase_amount(),
			'recipient_name'  => isset( $_POST['tokolariso_giftcard_recipient_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_recipient_name'] ) ) : '',
			'recipient_email' => isset( $_POST['tokolariso_giftcard_recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['tokolariso_giftcard_recipient_email'] ) ) : '',
			'sender_name'     => $sender,
			'message'         => $message,
			'delivery_date'   => $date,
			'image_id'        => $image_id,
			'image_url'       => $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '',
			'unique_key'      => wp_generate_uuid4(),
		);

		return $cart_item_data;
	}

	/**
	 * Displays giftcard item data in cart and checkout.
	 *
	 * @param array<int,array<string,string>> $item_data Item data.
	 * @param array<string,mixed>             $cart_item Cart item.
	 * @return array<int,array<string,string>>
	 */
	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item['tokolariso_giftcard'] ) || ! is_array( $cart_item['tokolariso_giftcard'] ) ) {
			return $item_data;
		}

		$data = $cart_item['tokolariso_giftcard'];
		$item_data[] = array(
			'key'   => __( 'Giftcard amount', 'toko-lariso-giftcards' ),
			'value' => wp_strip_all_tags( wc_price( (float) $data['amount'] ) ),
		);
		$item_data[] = array(
			'key'   => __( 'Recipient', 'toko-lariso-giftcards' ),
			'value' => esc_html( (string) $data['recipient_name'] ) . ' &lt;' . esc_html( (string) $data['recipient_email'] ) . '&gt;',
		);
		if ( ! empty( $data['delivery_date'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Delivery date', 'toko-lariso-giftcards' ),
				'value' => esc_html( (string) $data['delivery_date'] ),
			);
		}

		return $item_data;
	}

	/**
	 * Sets dynamic cart item prices for giftcards.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function set_giftcard_cart_prices( WC_Cart $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['tokolariso_giftcard']['amount'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}

			$cart_item['data']->set_price( (float) $cart_item['tokolariso_giftcard']['amount'] );
			$cart_item['data']->set_tax_status( 'none' );
			$cart_item['data']->set_virtual( false );
			$cart_item['data']->set_weight( '' );
		}
	}

	/**
	 * Refreshes giftcard allocations without altering WooCommerce cart totals or VAT.
	 *
	 * The giftcard is a partial payment. Products, shipping, fees, discounts, taxes,
	 * and the WooCommerce cart total stay untouched so VAT displays remain stable.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public function refresh_allocations_for_cart( WC_Cart $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( $this->cart_contains_giftcard_purchase( $cart ) ) {
			if ( $this->get_applied_cards() || $this->get_allocations() ) {
				Toko_Lariso_Giftcards_Debug::log( 'cart_allocations_cleared_giftcard_purchase_in_cart' );
			}
			$this->clear_session();
			return;
		}

		$applied = $this->get_applied_cards();
		if ( ! $applied ) {
			$this->set_allocations( array() );
			return;
		}

		$cart_total  = $this->cart_total_including_tax( $cart );
		$allocations = $this->calculate_allocations_for_amount( $applied, $cart_total );

		$this->store_allocations_for_cart( $allocations );
		Toko_Lariso_Giftcards_Debug::log(
			'cart_allocations_refreshed',
			array(
				'cart_total'   => $cart_total,
				'allocations'  => $allocations,
				'applied_card' => $this->get_applied_cards(),
			)
		);
	}

	/**
	 * Adds giftcard sale metadata to order line items.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array<string,mixed>   $values Cart item values.
	 * @param WC_Order              $order Order.
	 * @return void
	 */
	public function add_order_item_meta( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
		if ( empty( $values['tokolariso_giftcard'] ) || ! is_array( $values['tokolariso_giftcard'] ) ) {
			return;
		}

		$data = $values['tokolariso_giftcard'];
		$item->add_meta_data( '_tokolariso_is_giftcard', 'yes', true );
		$item->add_meta_data( '_tokolariso_giftcard_amount', (float) $data['amount'], true );
		$item->add_meta_data( '_tokolariso_giftcard_recipient_name', (string) $data['recipient_name'], true );
		$item->add_meta_data( '_tokolariso_giftcard_recipient_email', (string) $data['recipient_email'], true );
		$item->add_meta_data( '_tokolariso_giftcard_sender_name', (string) ( $data['sender_name'] ?? '' ), true );
		$item->add_meta_data( '_tokolariso_giftcard_message', (string) $data['message'], true );
		$item->add_meta_data( '_tokolariso_giftcard_delivery_date', (string) $data['delivery_date'], true );
		$item->add_meta_data( '_tokolariso_giftcard_image_id', (int) $data['image_id'], true );
		$item->add_meta_data( '_tokolariso_giftcard_image_url', (string) $data['image_url'], true );

		$item->add_meta_data( __( 'Giftcard amount', 'toko-lariso-giftcards' ), wp_strip_all_tags( wc_price( (float) $data['amount'] ) ), true );
		$item->add_meta_data( __( 'Recipient', 'toko-lariso-giftcards' ), sanitize_text_field( (string) $data['recipient_name'] ) . ' <' . sanitize_email( (string) $data['recipient_email'] ) . '>', true );
		if ( ! empty( $data['sender_name'] ) ) {
			$item->add_meta_data( __( 'Sender', 'toko-lariso-giftcards' ), sanitize_text_field( (string) $data['sender_name'] ), true );
		}
	}

	/**
	 * Reads selected purchase amount from POST.
	 *
	 * @return float
	 */
	private function read_purchase_amount(): float {
		$choice = isset( $_POST['tokolariso_giftcard_amount_choice'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_amount_choice'] ) ) : '';

		if ( 'custom' === $choice && $this->settings->custom_amount_enabled() ) {
			$amount = isset( $_POST['tokolariso_giftcard_custom_amount'] ) ? wc_clean( wp_unslash( $_POST['tokolariso_giftcard_custom_amount'] ) ) : 0;
			return $this->repository->normalize_amount( $amount );
		}

		$amount = str_starts_with( $choice, 'fixed:' ) ? substr( $choice, 6 ) : 0;
		$amount = $this->repository->normalize_amount( $amount );

		foreach ( $this->settings->fixed_amounts() as $fixed ) {
			if ( abs( $amount - (float) $fixed ) < 0.0001 ) {
				return $amount;
			}
		}

		return 0.0;
	}

	/**
	 * Formats a WooCommerce price as plain text safe for JSON/React rendering.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function format_price_text( float $amount ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';
		$text    = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES | ENT_HTML5, $charset );
		$nbsp    = html_entity_decode( '&nbsp;', ENT_QUOTES | ENT_HTML5, $charset );

		return trim( str_replace( $nbsp, ' ', $text ) );
	}

	/**
	 * Formats an optional WooCommerce price as plain text.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function format_optional_price_text( float $amount ): string {
		if ( $amount <= 0 ) {
			return '';
		}

		return $this->format_price_text( $amount );
	}

	/**
	 * Reads the WooCommerce cart total including VAT without changing totals.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return float
	 */
	private function cart_total_including_tax( WC_Cart $cart ): float {
		return $this->repository->normalize_amount( max( 0.0, (float) $cart->get_total( 'edit' ) ) );
	}

	/**
	 * Stores calculated allocations and keeps the applied session list canonical.
	 *
	 * @param array<int,array<string,mixed>> $allocations Allocations.
	 * @return void
	 */
	private function store_allocations_for_cart( array $allocations ): void {
		$this->set_applied_cards(
			array_map(
				static fn( array $allocation ): array => array(
					'id'         => (int) $allocation['id'],
					'code_mask'  => (string) $allocation['code_mask'],
					'max_amount' => (float) ( $allocation['max_amount'] ?? 0 ),
				),
				$allocations
			)
		);
		$this->set_allocations( $allocations );
	}

	/**
	 * Calculates giftcard allocations without mutating cart totals.
	 *
	 * @param array<int,array<string,mixed>> $applied Applied session card refs.
	 * @param float                          $amount Amount to cover.
	 * @return array<int,array<string,mixed>>
	 */
	private function calculate_allocations_for_amount( array $applied, float $amount ): array {
		if ( ! $this->settings->multiple_giftcards_enabled() ) {
			$applied = array_slice( $applied, 0, 1 );
		}

		$remaining   = $this->repository->normalize_amount( max( 0.0, $amount ) );
		$allocations = array();

		foreach ( $applied as $session_card ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$card = $this->repository->get_by_id( (int) ( $session_card['id'] ?? 0 ) );
			if ( ! $card ) {
				continue;
			}

			if ( $this->repository->is_expired_by_date( $card ) ) {
				$this->repository->expire( (int) $card['id'] );
				continue;
			}

			if ( 'active' !== $card['status'] || (float) $card['current_balance'] <= 0 ) {
				continue;
			}

			$max_amount =
				$this->repository->normalize_amount(
					max(
						0.0,
						(float) ( $session_card['max_amount'] ?? 0 )
					)
				);

			$available_balance = (float) $card['current_balance'];
			if ( $max_amount > 0 ) {
				$available_balance = min( $available_balance, $max_amount );
			}

			$allocation_amount = $this->repository->normalize_amount( min( $available_balance, $remaining ) );
			if ( $allocation_amount <= 0 ) {
				continue;
			}

			$allocations[] = array(
				'id'                => (int) $card['id'],
				'code_mask'         => (string) $card['code_mask'],
				'amount'            => $allocation_amount,
				'max_amount'        => $max_amount,
				'balance'           => (float) $card['current_balance'],
				'remaining_balance' => $this->repository->normalize_amount( max( 0.0, (float) $card['current_balance'] - $allocation_amount ) ),
			);
			$remaining     = $this->repository->normalize_amount( $remaining - $allocation_amount );
		}

		return $allocations;
	}

	/**
	 * Normalizes the optional maximum redemption amount.
	 *
	 * A zero value means "no manual limit".
	 *
	 * @param mixed $amount Raw amount.
	 * @return float
	 */
	private function normalize_redemption_limit( mixed $amount ): float {
		if ( null === $amount || '' === $amount ) {
			return 0.0;
		}

		$raw_amount = is_scalar( $amount ) ? (string) $amount : '';
		if ( '' === trim( $raw_amount ) ) {
			return 0.0;
		}

		$amount = wc_clean( wp_unslash( $raw_amount ) );
		$amount = $this->repository->normalize_amount( $amount );

		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( __( 'Please enter a valid giftcard amount to use, or leave the amount empty.', 'toko-lariso-giftcards' ) );
		}

		return $amount;
	}

	/**
	 * Returns whether the current cart contains a giftcard purchase.
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return bool
	 */
	public function cart_contains_giftcard_purchase( ?WC_Cart $cart = null ): bool {
		$cart = $cart ?: ( WC()->cart ?? null );
		if ( ! $cart ) {
			return false;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['tokolariso_giftcard'] ) ) {
				return true;
			}

			if ( ! empty( $cart_item['data'] ) && Toko_Lariso_Giftcards_Product_Type::is_giftcard_product( $cart_item['data'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether the current cart contains a non-giftcard product.
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return bool
	 */
	public function cart_contains_regular_product( ?WC_Cart $cart = null ): bool {
		$cart = $cart ?: ( WC()->cart ?? null );
		if ( ! $cart ) {
			return false;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) ) {
				continue;
			}

			if ( ! Toko_Lariso_Giftcards_Product_Type::is_giftcard_product( $cart_item['data'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Customer-facing message when giftcard redemption is blocked.
	 *
	 * @return string
	 */
	public function giftcard_payment_blocked_message(): string {
		return __( 'Giftcards cannot be used to buy another giftcard. Remove the giftcard product from your cart before applying a giftcard code.', 'toko-lariso-giftcards' );
	}

	/**
	 * Customer-facing message when a giftcard has expired.
	 *
	 * @return string
	 */
	private function giftcard_expired_message(): string {
		return __( 'This giftcard has been expired and can no longer be used.', 'toko-lariso-giftcards' );
	}

	/**
	 * Customer-facing message when a giftcard has no remaining balance.
	 *
	 * @return string
	 */
	private function giftcard_fully_used_message(): string {
		return __( 'The full balance of this giftcard has already been used.', 'toko-lariso-giftcards' );
	}

	/**
	 * Customer-facing message when a cart mixes giftcards and regular products.
	 *
	 * @return string
	 */
	public function giftcard_mixed_cart_blocked_message(): string {
		return __( 'Giftcards must be ordered separately from other products. Please place one order for the giftcard and a separate order for your other products.', 'toko-lariso-giftcards' );
	}

	/**
	 * Stores applied card references in session.
	 *
	 * @param array<int,array<string,mixed>> $cards Applied cards.
	 * @return void
	 */
	private function set_applied_cards( array $cards ): void {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_APPLIED, $cards );
		}
	}

	/**
	 * Stores current allocations in session.
	 *
	 * @param array<int,array<string,mixed>> $allocations Allocations.
	 * @return void
	 */
	private function set_allocations( array $allocations ): void {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_ALLOCATIONS, $allocations );
		}
	}

	/**
	 * Recalculates cart totals when possible.
	 *
	 * @return void
	 */
	private function recalculate_cart(): void {
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}
}
