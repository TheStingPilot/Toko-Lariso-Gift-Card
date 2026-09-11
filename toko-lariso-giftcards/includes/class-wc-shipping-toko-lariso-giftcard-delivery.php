<?php
/**
 * WooCommerce giftcard delivery shipping method.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Shipping_Method' ) || class_exists( 'WC_Shipping_Toko_Lariso_Giftcard_Delivery' ) ) {
	return;
}

/**
 * Zero-cost method available for giftcard-only shipping packages.
 */
class WC_Shipping_Toko_Lariso_Giftcard_Delivery extends WC_Shipping_Method {
	/**
	 * Constructor.
	 *
	 * @param int $instance_id Instance id.
	 */
	public function __construct( int $instance_id = 0 ) {
		$this->id                 = Toko_Lariso_Giftcards_Shipping::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Giftcard delivery', 'toko-lariso-giftcards' );
		$this->method_description = __( 'Free delivery method for Toko Lariso giftcard-only packages.', 'toko-lariso-giftcards' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );
		$this->enabled            = 'yes';
		$this->title              = __( 'Giftcard delivery', 'toko-lariso-giftcards' );

		$this->init();
	}

	/**
	 * Initializes method settings.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->instance_form_fields = array(
			'title' => array(
				'title'       => __( 'Title', 'toko-lariso-giftcards' ),
				'type'        => 'text',
				'description' => __( 'Displayed shipping method title.', 'toko-lariso-giftcards' ),
				'default'     => __( 'Giftcard delivery', 'toko-lariso-giftcards' ),
			),
		);

		$this->title = $this->get_option( 'title', $this->title );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Calculates zero-cost shipping for giftcard packages.
	 *
	 * @param array<string,mixed> $package Shipping package.
	 * @return void
	 */
	public function calculate_shipping( $package = array() ): void {
		if ( ! is_array( $package ) || ! Toko_Lariso_Giftcards_Shipping::package_is_giftcards_only( $package ) ) {
			return;
		}

		$this->add_rate(
			array(
				'id'       => $this->get_rate_id(),
				'label'    => $this->title,
				'cost'     => 0,
				'taxes'    => array(),
				'calc_tax' => 'per_order',
			)
		);
	}
}
