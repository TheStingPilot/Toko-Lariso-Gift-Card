<?php
/**
 * Order redemption, issuance, and refund handling.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles order lifecycle.
 */
class Toko_Lariso_Giftcards_Order {
	private const ORDER_APPLICATIONS_META = '_tokolariso_giftcard_applications';
	private const ORDER_REDEEMED_META     = '_tokolariso_giftcards_redeemed';
	private const ORDER_REFUNDED_META     = '_tokolariso_giftcards_refunded';
	private const ORDER_PENDING_APPLICATIONS_META = '_tokolariso_giftcard_pending_applications';
	private const ORDER_PREPARED_META             = '_tokolariso_giftcards_payment_prepared';
	private const ORDER_ORIGINAL_TOTAL_META       = '_tokolariso_order_total_before_giftcards';
	private const ORDER_GIFTCARD_PAYMENT_META     = '_tokolariso_giftcard_payment_total';
	private const ORDER_PAYMENT_DUE_META          = '_tokolariso_payment_due_after_giftcards';

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
	 * Email service.
	 *
	 * @var Toko_Lariso_Giftcards_Email
	 */
	private Toko_Lariso_Giftcards_Email $email;

	/**
	 * Cart service.
	 *
	 * @var Toko_Lariso_Giftcards_Cart
	 */
	private Toko_Lariso_Giftcards_Cart $cart;

	/**
	 * Constructor.
	 *
	 * @param Toko_Lariso_Giftcards_Settings   $settings Settings.
	 * @param Toko_Lariso_Giftcards_Repository $repository Repository.
	 * @param Toko_Lariso_Giftcards_Email      $email Email.
	 * @param Toko_Lariso_Giftcards_Cart       $cart Cart.
	 */
	public function __construct( Toko_Lariso_Giftcards_Settings $settings, Toko_Lariso_Giftcards_Repository $repository, Toko_Lariso_Giftcards_Email $email, Toko_Lariso_Giftcards_Cart $cart ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->email      = $email;
		$this->cart       = $cart;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'prepare_store_api_order_meta' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'prepare_store_api_order' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'prepare_classic_order' ), 10, 3 );

		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_redeem_paid_order' ), 5, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_redeem_paid_order' ), 5, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_redeem_paid_order' ), 5, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_issue_purchased_giftcards' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_issue_purchased_giftcards' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_issue_purchased_giftcards' ), 10, 1 );

		add_action( 'woocommerce_order_status_refunded', array( $this, 'maybe_restore_on_full_refund' ), 10, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_partial_refund_note' ), 10, 2 );
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'add_order_giftcard_totals' ), 20, 3 );
		add_filter( 'woocommerce_order_get_total', array( $this, 'filter_order_total_for_giftcard_payment' ), 20, 2 );
		$this->register_mollie_amount_filters();
	}

	/**
	 * Prepares giftcard partial payment while the Store API updates order meta.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function prepare_store_api_order_meta( WC_Order $order ): void {
		$this->prepare_order_for_payment( $order, 'store_api_checkout_update_order_meta', false );
	}

	/**
	 * Prepares giftcard partial payment during Store API checkout before payment is processed.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function prepare_store_api_order( WC_Order $order ): void {
		$this->prepare_order_for_payment( $order, 'store_api_checkout_order_processed', true );
	}

	/**
	 * Prepares giftcard partial payment during classic checkout.
	 *
	 * @param int      $order_id Order id.
	 * @param array    $posted_data Posted data.
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function prepare_classic_order( int $order_id, array $posted_data, WC_Order $order ): void {
		$this->prepare_order_for_payment( $order, 'classic_checkout_order_processed', true );
	}

	/**
	 * Redeems prepared giftcard payments only after payment has succeeded.
	 *
	 * @param int|WC_Order $order_input Order id or object.
	 * @return void
	 */
	public function maybe_redeem_paid_order( int|WC_Order $order_input ): void {
		$order = $order_input instanceof WC_Order ? $order_input : wc_get_order( $order_input );
		if ( ! $order ) {
			return;
		}

		$this->redeem_prepared_order( $order );
	}

	/**
	 * Issues giftcards purchased in a paid order.
	 *
	 * @param int|WC_Order $order_input Order id or object.
	 * @return void
	 */
	public function maybe_issue_purchased_giftcards( int|WC_Order $order_input ): void {
		$order = $order_input instanceof WC_Order ? $order_input : wc_get_order( $order_input );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return;
		}

		if ( $this->has_unredeemed_pending_applications( $order ) ) {
			return;
		}

		$changed = false;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( 'yes' !== $item->get_meta( '_tokolariso_is_giftcard', true ) ) {
				continue;
			}
			if ( $item->get_meta( '_tokolariso_giftcard_id', true ) ) {
				continue;
			}

			$amount = (float) $item->get_meta( '_tokolariso_giftcard_amount', true );
			if ( $amount <= 0 ) {
				$amount = (float) $item->get_total() + (float) $item->get_total_tax();
			}

			$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * absint( $this->settings->get( 'expiry_days', 730 ) ) ) );
			$card       = $this->repository->create_giftcard(
				array(
					'amount'                  => $amount,
					'currency'                => $order->get_currency(),
					'expires_at'              => $expires_at,
					'purchased_order_id'      => $order->get_id(),
					'purchased_order_item_id' => $item_id,
					'recipient_name'          => (string) $item->get_meta( '_tokolariso_giftcard_recipient_name', true ),
					'recipient_email'         => (string) $item->get_meta( '_tokolariso_giftcard_recipient_email', true ),
					'message'                 => (string) $item->get_meta( '_tokolariso_giftcard_message', true ),
					'image_id'                => (int) $item->get_meta( '_tokolariso_giftcard_image_id', true ),
					'image_url'               => (string) $item->get_meta( '_tokolariso_giftcard_image_url', true ),
				)
			);

			$item->add_meta_data( '_tokolariso_giftcard_id', (int) $card['id'], true );
			$item->add_meta_data( '_tokolariso_giftcard_code_mask', (string) $card['code_mask'], true );
			$item->save();

			$delivery_date = (string) $item->get_meta( '_tokolariso_giftcard_delivery_date', true );
			$this->email->send_or_schedule( $card, $delivery_date );

			$order->add_order_note(
				sprintf(
					/* translators: 1: masked code, 2: recipient email */
					__( 'Giftcard %1$s issued and queued/sent to %2$s.', 'toko-lariso-giftcards' ),
					(string) $card['code_mask'],
					(string) $card['recipient_email']
				)
			);
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Restores giftcard credit when full-refund behavior is enabled and the order is refunded.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function maybe_restore_on_full_refund( int $order_id ): void {
		if ( 'restore_full_refund' !== $this->settings->get( 'refund_behavior', 'manual' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( self::ORDER_REFUNDED_META, true ) ) {
			return;
		}

		$applications = $this->get_order_applications( $order );
		if ( ! $applications ) {
			return;
		}

		foreach ( $applications as $application ) {
			$this->repository->credit(
				(int) $application['id'],
				(float) $application['amount'],
				'refunded',
				$order->get_id(),
				__( 'Giftcard credit restored after full order refund.', 'toko-lariso-giftcards' )
			);
		}

		$order->update_meta_data( self::ORDER_REFUNDED_META, 'yes' );
		$order->add_order_note( __( 'Giftcard credit was restored because the order was fully refunded.', 'toko-lariso-giftcards' ) );
		$order->save();
	}

	/**
	 * Adds a note on partial refunds when automatic restoration is not safe.
	 *
	 * @param int $order_id Order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public function handle_partial_refund_note( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->get_order_applications( $order ) ) {
			return;
		}

		if ( 'restore_full_refund' === $this->settings->get( 'refund_behavior', 'manual' ) && $order->has_status( 'refunded' ) ) {
			return;
		}

		$order->add_order_note( __( 'This order used giftcard credit. Review the refund and restore giftcard balance manually if appropriate.', 'toko-lariso-giftcards' ) );
		$order->save();
	}

	/**
	 * Gets order giftcard applications.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_order_applications( WC_Order $order ): array {
		$applications = $order->get_meta( self::ORDER_APPLICATIONS_META, true );
		return is_array( $applications ) ? $applications : array();
	}

	/**
	 * Adds clear customer-facing order total rows for giftcard partial payments.
	 *
	 * @param array<string,array<string,string>> $rows Order total rows.
	 * @param WC_Order                          $order Order.
	 * @param string                            $tax_display Tax display mode.
	 * @return array<string,array<string,string>>
	 */
	public function add_order_giftcard_totals( array $rows, WC_Order $order, string $tax_display = '' ): array {
		$giftcard_payment = (float) $order->get_meta( self::ORDER_GIFTCARD_PAYMENT_META, true );
		if ( $giftcard_payment <= 0 ) {
			return $rows;
		}

		$original_total = (float) $order->get_meta( self::ORDER_ORIGINAL_TOTAL_META, true );
		if ( $original_total <= 0 ) {
			return $rows;
		}

		$payment_due        = (float) $order->get_meta( self::ORDER_PAYMENT_DUE_META, true );
		$has_pending_payment = $this->has_unredeemed_pending_applications( $order );
		$insert = array(
			'tokolariso_original_total'   => array(
				'label' => __( 'Order total before giftcard:', 'toko-lariso-giftcards' ),
				'value' => wp_kses_post( wc_price( $original_total, array( 'currency' => $order->get_currency() ) ) ),
			),
			'tokolariso_giftcard_payment' => array(
				'label' => $has_pending_payment ? __( 'Giftcard partial payment:', 'toko-lariso-giftcards' ) : __( 'Paid with giftcard:', 'toko-lariso-giftcards' ),
				'value' => wp_kses_post( wc_price( $giftcard_payment, array( 'currency' => $order->get_currency() ) ) ),
			),
		);

		if ( $has_pending_payment ) {
			$insert['tokolariso_giftcard_pending'] = array(
				'label' => __( 'Giftcard status:', 'toko-lariso-giftcards' ),
				'value' => esc_html__( 'Pending until payment succeeds', 'toko-lariso-giftcards' ),
			);
		}

		$updated = array();
		foreach ( $rows as $key => $row ) {
			if ( 'order_total' === $key ) {
				$updated += $insert;
				$row      = array(
					'label' => __( 'Amount paid by selected payment method:', 'toko-lariso-giftcards' ),
					'value' => wp_kses_post( wc_price( $payment_due, array( 'currency' => $order->get_currency() ) ) ),
				);
			}
			$updated[ $key ] = $row;
		}

		if ( ! isset( $rows['order_total'] ) ) {
			$updated += $insert;
		}

		return $updated;
	}

	/**
	 * Ensures payment gateways read the remaining payable amount when giftcard meta is present.
	 *
	 * @param mixed    $total Current total.
	 * @param WC_Order $order Order.
	 * @return mixed
	 */
	public function filter_order_total_for_giftcard_payment( mixed $total, WC_Order $order ): mixed {
		$giftcard_payment = (float) $order->get_meta( self::ORDER_GIFTCARD_PAYMENT_META, true );
		$payment_due      = $order->get_meta( self::ORDER_PAYMENT_DUE_META, true );

		if ( $giftcard_payment <= 0 || '' === $payment_due ) {
			return $total;
		}

		if ( $this->should_clear_stale_checkout_payment_meta( $order ) ) {
			$restored_total = $this->clear_stale_checkout_payment_meta( $order, 'order_total_filter' );
			return $this->repository->normalize_amount( $restored_total > 0 ? $restored_total : $total );
		}

		$payment_due = $this->repository->normalize_amount( $payment_due );
		$current     = $this->repository->normalize_amount( $total );
		if ( abs( $current - $payment_due ) < 0.0001 ) {
			return $total;
		}

		Toko_Lariso_Giftcards_Debug::log(
			'order_total_filter_applied',
			array(
				'order_id'         => $order->get_id(),
				'current_total'    => $current,
				'payment_due_meta' => $payment_due,
				'giftcard_payment' => $giftcard_payment,
			)
		);

		return $payment_due;
	}

	/**
	 * Forces Mollie API request data to use the same remaining amount shown on the checkout button.
	 *
	 * @param array<string,mixed> $args Mollie request args.
	 * @param WC_Order            $order Order.
	 * @return array<string,mixed>
	 */
	public function filter_mollie_payment_args( array $args, WC_Order $order ): array {
		$payment_due = $this->get_order_payment_due_for_gateway( $order );
		if ( null === $payment_due ) {
			Toko_Lariso_Giftcards_Debug::log(
				'mollie_args_amount_skipped',
				array(
					'order_id'       => $order->get_id(),
					'payment_method' => $order->get_payment_method(),
					'amount'         => $args['amount'] ?? null,
				),
				'warning'
			);
			return $args;
		}

		$previous_amount = $args['amount']['value'] ?? null;
		$args['amount']  = is_array( $args['amount'] ?? null ) ? $args['amount'] : array();
		$args['amount']['currency'] = (string) ( $args['amount']['currency'] ?? $order->get_currency() );
		$args['amount']['value']    = number_format( $payment_due, 2, '.', '' );

		Toko_Lariso_Giftcards_Debug::log(
			'mollie_args_amount_forced',
			array(
				'order_id'        => $order->get_id(),
				'payment_method'  => $order->get_payment_method(),
				'previous_amount' => $previous_amount,
				'forced_amount'   => $args['amount']['value'],
				'button_amount'   => $payment_due,
			)
		);

		return $args;
	}

	/**
	 * Prepares current session allocations for the order without mutating giftcard balances.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $source_hook Hook/source name.
	 * @param bool     $finalize_checkout Whether this is the final pre-payment checkout hook.
	 * @return void
	 */
	private function prepare_order_for_payment( WC_Order $order, string $source_hook, bool $finalize_checkout ): void {
		Toko_Lariso_Giftcards_Debug::log(
			'prepare_order_start',
			array(
				'source_hook'      => $source_hook,
				'finalize'         => $finalize_checkout ? 'yes' : 'no',
				'order'            => Toko_Lariso_Giftcards_Debug::order_context( $order ),
				'applied_sessions' => count( $this->cart->get_applied_cards() ),
			)
		);

		if ( 'yes' === $order->get_meta( self::ORDER_REDEEMED_META, true ) ) {
			Toko_Lariso_Giftcards_Debug::log( 'prepare_order_skipped_already_redeemed', array( 'order_id' => $order->get_id(), 'source_hook' => $source_hook ) );
			return;
		}

		if ( $this->cart->cart_contains_giftcard_purchase() ) {
			$this->cart->clear_session();
			$this->clear_prepared_payment_meta( $order );
			$order->save();
			Toko_Lariso_Giftcards_Debug::log( 'prepare_order_skipped_giftcard_purchase_in_cart', array( 'order_id' => $order->get_id(), 'source_hook' => $source_hook ) );
			return;
		}

		$allocations             = $this->cart->get_allocations();
		$prepared_original_total = (float) $order->get_meta( self::ORDER_ORIGINAL_TOTAL_META, true );
		$original_total          = 'yes' === $order->get_meta( self::ORDER_PREPARED_META, true ) && $prepared_original_total > 0
			? $this->repository->normalize_amount( $prepared_original_total )
			: $this->order_total_before_giftcards( $order, (bool) $allocations );
		if ( $original_total <= 0 ) {
			Toko_Lariso_Giftcards_Debug::log( 'prepare_order_skipped_zero_original_total', array( 'order_id' => $order->get_id(), 'source_hook' => $source_hook ) );
			return;
		}

		if ( 'yes' === $order->get_meta( self::ORDER_PREPARED_META, true ) && $this->should_clear_stale_checkout_payment_meta( $order ) ) {
			$this->clear_stale_checkout_payment_meta( $order, $source_hook );
			Toko_Lariso_Giftcards_Debug::log(
				'prepare_order_cleared_stale_payment_meta',
				array(
					'order_id'       => $order->get_id(),
					'source_hook'    => $source_hook,
					'original_total' => $original_total,
					'order'          => Toko_Lariso_Giftcards_Debug::order_context( $order ),
				)
			);
			return;
		}

		if ( 'yes' === $order->get_meta( self::ORDER_PREPARED_META, true ) ) {
			$payment_due = $order->get_meta( self::ORDER_PAYMENT_DUE_META, true );

			if ( '' !== $payment_due && abs( $prepared_original_total - $original_total ) < 0.0001 ) {
				$payment_due = $this->repository->normalize_amount( $payment_due );
				$order->set_total( $payment_due );
				$order->save();

				Toko_Lariso_Giftcards_Debug::log(
					'prepare_order_reapplied_prepared_total',
					array(
						'order_id'                => $order->get_id(),
						'source_hook'             => $source_hook,
						'original_total'          => $original_total,
						'prepared_original_total' => $prepared_original_total,
						'payment_due'             => $payment_due,
						'order'                   => Toko_Lariso_Giftcards_Debug::order_context( $order ),
					)
				);

				if ( $finalize_checkout ) {
					if ( 0.0 === $payment_due ) {
						$this->cart->clear_session();
						$this->redeem_prepared_order( $order );
					}
				}
				return;
			}

			$this->clear_prepared_payment_meta( $order );
			Toko_Lariso_Giftcards_Debug::log(
				'prepare_order_reset_stale_prepared_meta',
				array(
					'order_id'                => $order->get_id(),
					'source_hook'             => $source_hook,
					'original_total'          => $original_total,
					'prepared_original_total' => $prepared_original_total,
				)
			);
			$order->save();
		}

		$allocations = $this->cart->get_allocations_for_amount( $original_total );
		if ( ! $allocations ) {
			Toko_Lariso_Giftcards_Debug::log(
				'prepare_order_skipped_no_allocations',
				array(
					'order_id'        => $order->get_id(),
					'source_hook'     => $source_hook,
					'original_total'  => $original_total,
					'applied_session' => $this->cart->get_applied_cards(),
					'allocations'     => $this->cart->get_allocations(),
				)
			);
			return;
		}

		$applications = $this->pending_applications_from_allocations( $allocations, $original_total );
		if ( ! $applications ) {
			Toko_Lariso_Giftcards_Debug::log(
				'prepare_order_skipped_no_applications',
				array(
					'order_id'       => $order->get_id(),
					'source_hook'    => $source_hook,
					'original_total' => $original_total,
					'allocations'    => $allocations,
				)
			);
			return;
		}

		$giftcard_payment = min( $original_total, $this->applications_total( $applications ) );
		$payment_due      = $this->repository->normalize_amount( max( 0.0, $original_total - $giftcard_payment ) );

		$order->set_total( $payment_due );
		$order->update_meta_data( self::ORDER_PREPARED_META, 'yes' );
		$order->update_meta_data( self::ORDER_PENDING_APPLICATIONS_META, $applications );
		$order->update_meta_data( self::ORDER_ORIGINAL_TOTAL_META, $original_total );
		$order->update_meta_data( self::ORDER_GIFTCARD_PAYMENT_META, $giftcard_payment );
		$order->update_meta_data( self::ORDER_PAYMENT_DUE_META, $payment_due );
		$order->add_order_note( $this->format_prepare_order_note( $applications, $original_total, $payment_due ) );
		$order->save();

		Toko_Lariso_Giftcards_Debug::log(
			'prepare_order_saved',
			array(
				'order_id'         => $order->get_id(),
				'source_hook'      => $source_hook,
				'original_total'   => $original_total,
				'giftcard_payment' => $giftcard_payment,
				'payment_due'      => $payment_due,
				'applications'     => $applications,
				'order'            => Toko_Lariso_Giftcards_Debug::order_context( $order ),
			)
		);

		if ( $finalize_checkout ) {
			if ( 0.0 === $payment_due ) {
				$this->cart->clear_session();
				$this->redeem_prepared_order( $order );
			}
		}
	}

	/**
	 * Clears prepared giftcard payment metadata from a draft/reused order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function clear_prepared_payment_meta( WC_Order $order ): void {
		$order->delete_meta_data( self::ORDER_PREPARED_META );
		$order->delete_meta_data( self::ORDER_PENDING_APPLICATIONS_META );
		$order->delete_meta_data( self::ORDER_ORIGINAL_TOTAL_META );
		$order->delete_meta_data( self::ORDER_GIFTCARD_PAYMENT_META );
		$order->delete_meta_data( self::ORDER_PAYMENT_DUE_META );
	}

	/**
	 * Returns whether stored giftcard payment meta no longer matches the current checkout cart.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function should_clear_stale_checkout_payment_meta( WC_Order $order ): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}

		if ( $this->cart->get_allocations() ) {
			return false;
		}

		if ( $this->cart->get_applied_cards() ) {
			return true;
		}

		return 'yes' === $order->get_meta( self::ORDER_PREPARED_META, true )
			|| (float) $order->get_meta( self::ORDER_GIFTCARD_PAYMENT_META, true ) > 0
			|| '' !== $order->get_meta( self::ORDER_PAYMENT_DUE_META, true );
	}

	/**
	 * Clears stale checkout giftcard payment meta and restores the order total.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $source Source label.
	 * @return float Restored total.
	 */
	private function clear_stale_checkout_payment_meta( WC_Order $order, string $source ): float {
		$restored_total = $this->order_total_before_giftcards( $order, false );
		$old_meta       = array(
			'original_total'   => $order->get_meta( self::ORDER_ORIGINAL_TOTAL_META, true ),
			'giftcard_payment' => $order->get_meta( self::ORDER_GIFTCARD_PAYMENT_META, true ),
			'payment_due'      => $order->get_meta( self::ORDER_PAYMENT_DUE_META, true ),
		);

		$this->clear_prepared_payment_meta( $order );
		if ( $restored_total > 0 ) {
			$order->set_total( $restored_total );
		}
		$order->save();

		Toko_Lariso_Giftcards_Debug::log(
			'stale_checkout_payment_meta_cleared',
			array(
				'order_id'       => $order->get_id(),
				'source'         => $source,
				'restored_total' => $restored_total,
				'old_meta'       => $old_meta,
				'order'          => Toko_Lariso_Giftcards_Debug::order_context( $order ),
			)
		);

		return $restored_total;
	}

	/**
	 * Redeems giftcard balance for a prepared and paid order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function redeem_prepared_order( WC_Order $order ): void {
		if ( 'yes' === $order->get_meta( self::ORDER_REDEEMED_META, true ) ) {
			Toko_Lariso_Giftcards_Debug::log( 'redeem_prepared_skipped_already_redeemed', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$pending = $this->get_pending_applications( $order );
		if ( ! $pending ) {
			Toko_Lariso_Giftcards_Debug::log( 'redeem_prepared_skipped_no_pending', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$applications = array();
		Toko_Lariso_Giftcards_Debug::log(
			'redeem_prepared_start',
			array(
				'order'   => Toko_Lariso_Giftcards_Debug::order_context( $order ),
				'pending' => $pending,
			)
		);

		try {
			foreach ( $pending as $application ) {
				$giftcard_id = (int) ( $application['id'] ?? 0 );
				$amount      = (float) ( $application['amount'] ?? 0 );
				if ( $giftcard_id <= 0 || $amount <= 0 ) {
					continue;
				}

				$card = $this->repository->redeem(
					$giftcard_id,
					$amount,
					$order->get_id(),
					sprintf(
						/* translators: %d: order id */
						__( 'Redeemed after successful payment for order #%d.', 'toko-lariso-giftcards' ),
						$order->get_id()
					)
				);

				$applications[] = array(
					'id'        => $giftcard_id,
					'code_mask' => (string) ( $application['code_mask'] ?? $card['code_mask'] ),
					'amount'    => $amount,
					'ledger_id' => (int) ( $card['ledger_id'] ?? 0 ),
				);
			}
		} catch ( Throwable $exception ) {
			Toko_Lariso_Giftcards_Debug::log(
				'redeem_prepared_failed',
				array(
					'order_id' => $order->get_id(),
					'error'    => $exception->getMessage(),
					'pending'  => $pending,
				),
				'error'
			);
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Giftcard redemption failed after payment: %s. Review this order manually before fulfilment.', 'toko-lariso-giftcards' ),
					$exception->getMessage()
				)
			);
			if ( ! $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
				$order->update_status( 'on-hold', __( 'Giftcard redemption failed after payment. Manual review required.', 'toko-lariso-giftcards' ) );
			} else {
				$order->save();
			}
			return;
		}

		if ( ! $applications ) {
			return;
		}

		$original_total   = (float) $order->get_meta( self::ORDER_ORIGINAL_TOTAL_META, true );
		$giftcard_payment = (float) $order->get_meta( self::ORDER_GIFTCARD_PAYMENT_META, true );
		$payment_due      = (float) $order->get_meta( self::ORDER_PAYMENT_DUE_META, true );

		$order->update_meta_data( self::ORDER_REDEEMED_META, 'yes' );
		$order->update_meta_data( self::ORDER_APPLICATIONS_META, $applications );
		$order->delete_meta_data( self::ORDER_PENDING_APPLICATIONS_META );
		$order->add_order_note( $this->format_redeem_order_note( $applications, $original_total, $giftcard_payment, $payment_due ) );
		$order->save();

		Toko_Lariso_Giftcards_Debug::log(
			'redeem_prepared_saved',
			array(
				'order_id'         => $order->get_id(),
				'original_total'   => $original_total,
				'giftcard_payment' => $giftcard_payment,
				'payment_due'      => $payment_due,
				'applications'     => $applications,
			)
		);
	}

	/**
	 * Builds pending order applications from calculated cart allocations.
	 *
	 * @param array<int,array<string,mixed>> $allocations Allocations.
	 * @param float                          $original_total Original total.
	 * @return array<int,array<string,mixed>>
	 */
	private function pending_applications_from_allocations( array $allocations, float $original_total ): array {
		$remaining    = $original_total;
		$applications = array();

		foreach ( $allocations as $allocation ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$giftcard_id = (int) ( $allocation['id'] ?? 0 );
			$amount      = $this->repository->normalize_amount( min( (float) ( $allocation['amount'] ?? 0 ), $remaining ) );
			if ( $giftcard_id <= 0 || $amount <= 0 ) {
				continue;
			}

			$applications[] = array(
				'id'        => $giftcard_id,
				'code_mask' => (string) ( $allocation['code_mask'] ?? '' ),
				'amount'    => $amount,
			);
			$remaining      = $this->repository->normalize_amount( $remaining - $amount );
		}

		return $applications;
	}

	/**
	 * Calculates the order amount including VAT and shipping before giftcard payment.
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	private function order_total_before_giftcards( WC_Order $order, bool $prefer_cart_total = false ): float {
		$component_total = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$component_total += (float) $item->get_total() + (float) $item->get_total_tax();
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$component_total += (float) $item->get_total() + (float) $item->get_total_tax();
		}

		foreach ( $order->get_items( 'fee' ) as $item ) {
			$component_total += (float) $item->get_total() + (float) $item->get_total_tax();
		}

		$order_total     = $this->repository->normalize_amount( max( 0.0, (float) $order->get_total( 'edit' ) ) );
		$cart_total      = $prefer_cart_total ? $this->current_cart_total() : 0.0;
		$component_total = $this->repository->normalize_amount( max( 0.0, $component_total ) );
		$selected_total  = max( $order_total, $component_total, $cart_total );

		if ( abs( $selected_total - $component_total ) >= 0.01 || ( $order_total > 0 && abs( $selected_total - $order_total ) >= 0.01 ) ) {
			Toko_Lariso_Giftcards_Debug::log(
				'order_total_before_giftcards_mismatch',
				array(
					'order_id'        => $order->get_id(),
					'order_total'     => $order_total,
					'component_total' => $component_total,
					'cart_total'      => $cart_total,
					'selected_total'  => $selected_total,
				)
			);
		}

		return $this->repository->normalize_amount( $selected_total );
	}

	/**
	 * Reads the current cart total including VAT when checkout allocations are active.
	 *
	 * @return float
	 */
	private function current_cart_total(): float {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		return $this->repository->normalize_amount( max( 0.0, (float) WC()->cart->get_total( 'edit' ) ) );
	}

	/**
	 * Registers Mollie request filters for the gateway ids used by Mollie Payments for WooCommerce.
	 *
	 * @return void
	 */
	private function register_mollie_amount_filters(): void {
		$methods = array(
			'ideal',
			'creditcard',
			'paypal',
			'bancontact',
			'banktransfer',
			'belfius',
			'kbc',
			'paysafecard',
			'giftcard',
			'in3',
			'klarna',
			'klarnapaylater',
			'klarnasliceit',
			'klarnapaynow',
			'mybank',
			'sofort',
			'eps',
			'giropay',
			'applepay',
			'voucher',
			'paybybank',
			'trustly',
			'twint',
			'blik',
			'alma',
			'billie',
			'satispay',
			'swish',
			'mobilepay',
			'mbway',
			'przelewy24',
			'wero',
		);

		foreach ( $methods as $method ) {
			$gateway_id = 'mollie_wc_gateway_' . $method;
			add_filter( 'woocommerce_' . $gateway_id . '_args', array( $this, 'filter_mollie_payment_args' ), 20, 2 );
			add_filter( 'woocommerce_' . $gateway_id . 'payment_args', array( $this, 'filter_mollie_payment_args' ), 20, 2 );
		}
	}

	/**
	 * Gets the amount Mollie should charge, preparing the order as a fallback if needed.
	 *
	 * @param WC_Order $order Order.
	 * @return float|null
	 */
	private function get_order_payment_due_for_gateway( WC_Order $order ): ?float {
		$giftcard_payment = (float) $order->get_meta( self::ORDER_GIFTCARD_PAYMENT_META, true );
		$payment_due      = $order->get_meta( self::ORDER_PAYMENT_DUE_META, true );

		if ( $giftcard_payment <= 0 || '' === $payment_due ) {
			$this->prepare_order_for_payment( $order, 'mollie_args_fallback_prepare', false );
			$giftcard_payment = (float) $order->get_meta( self::ORDER_GIFTCARD_PAYMENT_META, true );
			$payment_due      = $order->get_meta( self::ORDER_PAYMENT_DUE_META, true );
		}

		if ( $giftcard_payment <= 0 || '' === $payment_due ) {
			return null;
		}

		return $this->repository->normalize_amount( $payment_due );
	}

	/**
	 * Totals redeemed giftcard applications.
	 *
	 * @param array<int,array<string,mixed>> $applications Applications.
	 * @return float
	 */
	private function applications_total( array $applications ): float {
		$total = 0.0;

		foreach ( $applications as $application ) {
			$total += (float) ( $application['amount'] ?? 0 );
		}

		return $this->repository->normalize_amount( $total );
	}

	/**
	 * Gets pending giftcard applications for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_pending_applications( WC_Order $order ): array {
		$applications = $order->get_meta( self::ORDER_PENDING_APPLICATIONS_META, true );
		return is_array( $applications ) ? $applications : array();
	}

	/**
	 * Returns whether an order still has giftcard payment waiting for successful payment.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function has_unredeemed_pending_applications( WC_Order $order ): bool {
		return 'yes' !== $order->get_meta( self::ORDER_REDEEMED_META, true ) && ! empty( $this->get_pending_applications( $order ) );
	}

	/**
	 * Formats order note for prepared partial payments.
	 *
	 * @param array<int,array<string,mixed>> $applications Applications.
	 * @param float                          $original_total Original order total.
	 * @param float                          $payment_due Remaining payment-method amount.
	 * @return string
	 */
	private function format_prepare_order_note( array $applications, float $original_total, float $payment_due ): string {
		$parts = array();
		foreach ( $applications as $application ) {
			$parts[] = sprintf(
				'%s: %s',
				(string) $application['code_mask'],
				wp_strip_all_tags( wc_price( (float) $application['amount'] ) )
			);
		}

		return sprintf(
			/* translators: 1: comma-separated giftcard applications, 2: original total, 3: remaining payment due */
			__( 'Giftcard partial payment prepared: %1$s. Order total before giftcard payment: %2$s. Remaining amount charged by payment method: %3$s. Giftcard balance will be redeemed only after successful payment.', 'toko-lariso-giftcards' ),
			implode( ', ', $parts ),
			wp_strip_all_tags( wc_price( $original_total ) ),
			wp_strip_all_tags( wc_price( $payment_due ) )
		);
	}

	/**
	 * Formats order note for completed giftcard redemptions.
	 *
	 * @param array<int,array<string,mixed>> $applications Applications.
	 * @param float                          $original_total Original order total.
	 * @param float                          $giftcard_payment Giftcard payment amount.
	 * @param float                          $payment_due Remaining payment-method amount.
	 * @return string
	 */
	private function format_redeem_order_note( array $applications, float $original_total, float $giftcard_payment, float $payment_due ): string {
		$parts = array();
		foreach ( $applications as $application ) {
			$parts[] = sprintf(
				'%s: %s',
				(string) $application['code_mask'],
				wp_strip_all_tags( wc_price( (float) $application['amount'] ) )
			);
		}

		return sprintf(
			/* translators: 1: comma-separated giftcard applications, 2: original total, 3: giftcard payment, 4: remaining payment due */
			__( 'Giftcard partial payment redeemed after successful payment: %1$s. Order total before giftcard: %2$s. Paid with giftcard: %3$s. Charged by payment method: %4$s.', 'toko-lariso-giftcards' ),
			implode( ', ', $parts ),
			wp_strip_all_tags( wc_price( $original_total ) ),
			wp_strip_all_tags( wc_price( $giftcard_payment ) ),
			wp_strip_all_tags( wc_price( $payment_due ) )
		);
	}
}
