<?php
/**
 * WooCommerce Store API integration.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

/**
 * Extends Store API data and callbacks.
 */
class Toko_Lariso_Giftcards_Store_API {
	public const NAMESPACE = 'tokolariso-giftcards';

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
	 * Cart.
	 *
	 * @var Toko_Lariso_Giftcards_Cart
	 */
	private Toko_Lariso_Giftcards_Cart $cart;

	/**
	 * Constructor.
	 *
	 * @param Toko_Lariso_Giftcards_Settings   $settings Settings.
	 * @param Toko_Lariso_Giftcards_Repository $repository Repository.
	 * @param Toko_Lariso_Giftcards_Cart       $cart Cart service.
	 */
	public function __construct( Toko_Lariso_Giftcards_Settings $settings, Toko_Lariso_Giftcards_Repository $repository, Toko_Lariso_Giftcards_Cart $cart ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->cart       = $cart;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_extensions' ) );
		add_action( 'woocommerce_blocks_cart_block_registration', array( $this, 'register_blocks_integration' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_blocks_integration' ) );
	}

	/**
	 * Registers Store API endpoint data and update callback.
	 *
	 * @return void
	 */
	public function register_store_api_extensions(): void {
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) && class_exists( CartSchema::class ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => CartSchema::IDENTIFIER,
					'namespace'       => self::NAMESPACE,
					'data_callback'   => array( $this, 'cart_data' ),
					'schema_callback' => array( $this, 'cart_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) && class_exists( CheckoutSchema::class ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => CheckoutSchema::IDENTIFIER,
					'namespace'       => self::NAMESPACE,
					'data_callback'   => array( $this, 'cart_data' ),
					'schema_callback' => array( $this, 'cart_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE,
					'callback'  => array( $this, 'handle_cart_update' ),
				)
			);
		}
	}

	/**
	 * Registers Blocks integration scripts.
	 *
	 * @param mixed $integration_registry WooCommerce integration registry.
	 * @return void
	 */
	public function register_blocks_integration( mixed $integration_registry ): void {
		if ( interface_exists( \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface::class ) && ! class_exists( 'Toko_Lariso_Giftcards_Blocks_Integration' ) ) {
			require_once TOKO_LARISO_GIFTCARDS_PATH . 'includes/class-toko-lariso-giftcards-blocks-integration.php';
		}

		if ( class_exists( 'Toko_Lariso_Giftcards_Blocks_Integration' ) ) {
			$integration_registry->register( new Toko_Lariso_Giftcards_Blocks_Integration( $this->settings ) );
		}
	}

	/**
	 * Store API public cart data.
	 *
	 * @return array<string,mixed>
	 */
	public function cart_data(): array {
		$summary = $this->cart->get_public_summary();
		if ( ! empty( $summary['applied'] ) ) {
			Toko_Lariso_Giftcards_Debug::log(
				'store_api_cart_data',
				array(
					'total_applied'   => $summary['total_applied'] ?? 0,
					'cart_total'      => $summary['cart_total'] ?? 0,
					'remaining_total' => $summary['remaining_total'] ?? 0,
					'applied'         => $summary['applied'] ?? array(),
				)
			);
		}
		return $summary;
	}

	/**
	 * Store API schema.
	 *
	 * @return array<string,mixed>
	 */
	public function cart_schema(): array {
		return array(
			'applied'                 => array(
				'description' => __( 'Applied masked giftcards.', 'toko-lariso-giftcards' ),
				'type'        => 'array',
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'                => array( 'type' => 'integer' ),
						'code'              => array( 'type' => 'string' ),
						'amount'            => array( 'type' => 'number' ),
						'amount_formatted'  => array( 'type' => 'string' ),
						'max_amount'        => array( 'type' => 'number' ),
						'max_amount_formatted' => array( 'type' => 'string' ),
						'balance'           => array( 'type' => 'number' ),
						'balance_formatted' => array( 'type' => 'string' ),
						'remaining_balance' => array( 'type' => 'number' ),
						'remaining_balance_formatted' => array( 'type' => 'string' ),
					),
				),
			),
			'total_applied'           => array(
				'description' => __( 'Total giftcard amount applied.', 'toko-lariso-giftcards' ),
				'type'        => 'number',
				'readonly'    => true,
			),
			'total_applied_formatted' => array(
				'description' => __( 'Formatted giftcard total.', 'toko-lariso-giftcards' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'cart_total'              => array(
				'description' => __( 'Cart total before giftcard partial payment.', 'toko-lariso-giftcards' ),
				'type'        => 'number',
				'readonly'    => true,
			),
			'cart_total_formatted'    => array(
				'description' => __( 'Formatted cart total before giftcard partial payment.', 'toko-lariso-giftcards' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'remaining_total'         => array(
				'description' => __( 'Remaining amount to pay after giftcard partial payment.', 'toko-lariso-giftcards' ),
				'type'        => 'number',
				'readonly'    => true,
			),
			'remaining_total_formatted' => array(
				'description' => __( 'Formatted remaining amount to pay after giftcard partial payment.', 'toko-lariso-giftcards' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'allow_multiple'          => array(
				'description' => __( 'Whether multiple giftcards may be applied.', 'toko-lariso-giftcards' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
		);
	}

	/**
	 * Handles Blocks cart/extensions calls.
	 *
	 * @param array<string,mixed> $data Posted data.
	 * @return void
	 */
	public function handle_cart_update( array $data ): void {
		$action = sanitize_key( (string) ( $data['action'] ?? '' ) );
		Toko_Lariso_Giftcards_Debug::log( 'store_api_cart_update_start', array( 'action' => $action ) );

		if ( 'apply' === $action ) {
			$code = sanitize_text_field( (string) ( $data['code'] ?? '' ) );
			if ( '' === $code ) {
				throw new InvalidArgumentException( __( 'Please enter a giftcard code.', 'toko-lariso-giftcards' ) );
			}
			$card = $this->cart->apply_code_to_session( $code, $data['max_amount'] ?? null );
			Toko_Lariso_Giftcards_Debug::log(
				'store_api_cart_update_apply_success',
				array(
					'giftcard_id' => (int) $card['id'],
					'code_mask'   => (string) $card['code_mask'],
					'summary'     => $this->cart->get_public_summary(),
				)
			);
			return;
		}

		if ( 'remove' === $action ) {
			$this->cart->remove_card_from_session( absint( $data['id'] ?? 0 ) );
			Toko_Lariso_Giftcards_Debug::log( 'store_api_cart_update_remove_success', array( 'id' => absint( $data['id'] ?? 0 ) ) );
			return;
		}

		throw new InvalidArgumentException( __( 'Unsupported giftcard action.', 'toko-lariso-giftcards' ) );
	}
}
