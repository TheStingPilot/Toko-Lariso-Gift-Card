<?php
/**
 * WooCommerce giftcard product type.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product type and product-page fields.
 */
class Toko_Lariso_Giftcards_Product_Type {
	/**
	 * Product type slug.
	 */
	public const TYPE = 'tokolarisogiftcard';

	/**
	 * Whether the single product add-to-cart form has rendered.
	 *
	 * @var bool
	 */
	private bool $single_form_rendered = false;

	/**
	 * Settings.
	 *
	 * @var Toko_Lariso_Giftcards_Settings
	 */
	private Toko_Lariso_Giftcards_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Toko_Lariso_Giftcards_Settings $settings Settings.
	 */
	public function __construct( Toko_Lariso_Giftcards_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'product_type_selector', array( $this, 'add_product_type' ) );
		add_filter( 'woocommerce_product_class', array( $this, 'product_class' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 10, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'force_giftcard_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'hide_irrelevant_tabs' ), 20 );
		add_filter( 'woocommerce_single_product_zoom_enabled', array( $this, 'disable_product_zoom' ) );
		add_filter( 'body_class', array( $this, 'add_giftcard_body_class' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'admin_notice' ) );
		add_action( 'woocommerce_process_product_meta_' . self::TYPE, array( $this, 'save_product' ) );
		add_action( 'woocommerce_' . self::TYPE . '_add_to_cart', array( $this, 'render_add_to_cart_form' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_add_to_cart_form_fallback' ), 28 );
		add_action( 'wp', array( $this, 'remove_product_gallery_zoom_support' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds product type to selector.
	 *
	 * @param array<string,string> $types Existing types.
	 * @return array<string,string>
	 */
	public function add_product_type( array $types ): array {
		$types[ self::TYPE ] = __( 'Toko Lariso giftcard', 'toko-lariso-giftcards' );
		return $types;
	}

	/**
	 * Maps product type to class.
	 *
	 * @param string $class_name Product class.
	 * @param string $product_type Product type.
	 * @return string
	 */
	public function product_class( string $class_name, string $product_type ): string {
		if ( self::TYPE === $product_type ) {
			return 'WC_Product_Toko_Lariso_Giftcard';
		}
		return $class_name;
	}

	/**
	 * Checks whether a product should be handled as a Toko Lariso giftcard.
	 *
	 * The custom product type is preferred. The SKU/title/slug fallback is intentionally
	 * client-specific so an existing simple product named "cadeaukaart" does not lose
	 * the giftcard builder when WooCommerce fails to load the custom type.
	 *
	 * @param mixed $product Product.
	 * @return bool
	 */
	public static function is_giftcard_product( mixed $product ): bool {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		if ( self::TYPE === $product->get_type() ) {
			return true;
		}

		$sku    = strtoupper( (string) $product->get_sku() );
		$name   = strtolower( (string) $product->get_name() );
		$slug   = strtolower( (string) $product->get_slug() );
		$legacy = str_starts_with( $sku, 'CADEAUKAART' ) || str_contains( $name, 'cadeaukaart' ) || str_contains( $name, 'giftcard' ) || str_contains( $slug, 'cadeaukaart' ) || str_contains( $slug, 'giftcard' );

		return (bool) apply_filters( 'tokolariso_giftcards_is_giftcard_product', $legacy, $product );
	}

	/**
	 * Keeps relevant product tabs visible for giftcards.
	 *
	 * @param array<string,array<string,mixed>> $tabs Product tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function hide_irrelevant_tabs( array $tabs ): array {
		if ( isset( $tabs['inventory'] ) ) {
			$tabs['inventory']['class'][] = 'show_if_' . self::TYPE;
		}
		return $tabs;
	}

	/**
	 * Keeps recognized giftcard products purchasable even when no fixed catalog price is set.
	 *
	 * @param bool       $purchasable Existing purchasable state.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public function force_giftcard_purchasable( bool $purchasable, WC_Product $product ): bool {
		if ( self::is_giftcard_product( $product ) ) {
			return $product->exists() && ( 'publish' === $product->get_status() || current_user_can( 'edit_post', $product->get_id() ) );
		}

		return $purchasable;
	}

	/**
	 * Disables WooCommerce's gallery zoom on giftcard product pages.
	 *
	 * @param bool $enabled Existing zoom state.
	 * @return bool
	 */
	public function disable_product_zoom( bool $enabled ): bool {
		return $this->is_current_giftcard_product() ? false : $enabled;
	}

	/**
	 * Adds a body class used to suppress third-party image zoom overlays.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function add_giftcard_body_class( array $classes ): array {
		if ( $this->is_current_giftcard_product() ) {
			$classes[] = 'tokolariso-giftcard-product-page';
		}

		return $classes;
	}

	/**
	 * Removes theme support for product gallery zoom on giftcard product pages.
	 *
	 * @return void
	 */
	public function remove_product_gallery_zoom_support(): void {
		if ( $this->is_current_giftcard_product() ) {
			remove_theme_support( 'wc-product-gallery-zoom' );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		}
	}

	/**
	 * Shows a helpful dynamic price label for giftcard products.
	 *
	 * @param string     $price_html Existing price html.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function price_html( string $price_html, WC_Product $product ): string {
		if ( ! self::is_giftcard_product( $product ) ) {
			return $price_html;
		}

		$amounts = $this->settings->fixed_amounts();
		if ( $amounts ) {
			return '<span class="tokolariso-giftcard-price">' . sprintf(
				/* translators: %s: lowest selectable giftcard amount */
				esc_html__( 'From %s', 'toko-lariso-giftcards' ),
				wp_kses_post( wc_price( min( $amounts ) ) )
			) . '</span>';
		}

		if ( $this->settings->custom_amount_enabled() ) {
			return '<span class="tokolariso-giftcard-price">' . esc_html__( 'Choose an amount', 'toko-lariso-giftcards' ) . '</span>';
		}

		return $price_html;
	}

	/**
	 * Shows admin guidance.
	 *
	 * @return void
	 */
	public function admin_notice(): void {
		global $product_object;

		if ( ! $product_object || self::TYPE !== $product_object->get_type() ) {
			return;
		}

		echo '<div class="options_group show_if_' . esc_attr( self::TYPE ) . '">';
		echo '<p class="form-field"><span class="description">';
		echo esc_html__( 'Giftcard amounts are configured in WooCommerce > Toko Lariso Giftcards. This product is sold as a non-virtual, non-taxable multi-purpose voucher with zero-cost giftcard delivery.', 'toko-lariso-giftcards' );
		echo '</span></p>';
		echo '</div>';
	}

	/**
	 * Saves type-specific product settings.
	 *
	 * @param int $post_id Product id.
	 * @return void
	 */
	public function save_product( int $post_id ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		$product->set_virtual( false );
		$product->set_weight( '' );
		$product->set_tax_status( 'none' );
		$product->set_sold_individually( false );
		$product->save();
	}

	/**
	 * Enqueues product page styles.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_style( 'tokolariso-giftcards-product', TOKO_LARISO_GIFTCARDS_URL . 'assets/css/product.css', array(), TOKO_LARISO_GIFTCARDS_VERSION );
			wp_enqueue_script( 'tokolariso-giftcards-product', TOKO_LARISO_GIFTCARDS_URL . 'assets/js/product.js', array(), TOKO_LARISO_GIFTCARDS_VERSION, true );
		}
	}

	/**
	 * Checks the current frontend product.
	 *
	 * @return bool
	 */
	private function is_current_giftcard_product(): bool {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		global $product;

		$current_product = wc_get_product( get_queried_object_id() );
		if ( ! $current_product && $product instanceof WC_Product ) {
			$current_product = $product;
		}

		return self::is_giftcard_product( $current_product );
	}

	/**
	 * Renders the complete add-to-cart form for the custom product type.
	 *
	 * @return void
	 */
	public function render_add_to_cart_form(): void {
		global $product;

		if ( $this->single_form_rendered || ! self::is_giftcard_product( $product ) ) {
			return;
		}

		$this->single_form_rendered = true;

		if ( ! $product->is_in_stock() ) {
			echo wp_kses_post( wc_get_stock_html( $product ) );
			return;
		}

		$amounts = $this->settings->fixed_amounts();
		if ( ! $amounts && ! $this->settings->custom_amount_enabled() ) {
			echo '<p class="stock out-of-stock">';
			echo esc_html__( 'No giftcard amounts are configured yet. Please contact the store.', 'toko-lariso-giftcards' );
			echo '</p>';
			return;
		}

		$theme_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) ? wc_wp_theme_get_element_class_name( 'button' ) : '';

		do_action( 'woocommerce_before_add_to_cart_form' );
		?>
		<form class="cart tokolariso-giftcard-cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">
			<?php $this->render_purchase_fields(); ?>

			<input type="hidden" name="quantity" value="1" />

			<button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="single_add_to_cart_button button alt<?php echo esc_attr( $theme_button_class ? ' ' . $theme_button_class : '' ); ?>">
				<?php echo esc_html( $product->single_add_to_cart_text() ); ?>
			</button>

			<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
		</form>
		<?php
		do_action( 'woocommerce_after_add_to_cart_form' );
	}

	/**
	 * Renders the giftcard form when a theme skips the custom product add-to-cart action.
	 *
	 * @return void
	 */
	public function render_add_to_cart_form_fallback(): void {
		global $product;

		if ( $this->single_form_rendered || ! self::is_giftcard_product( $product ) ) {
			return;
		}

		$this->render_add_to_cart_form();
	}

	/**
	 * Renders giftcard purchase fields on product pages.
	 *
	 * @return void
	 */
	public function render_purchase_fields(): void {
		global $product;

		if ( ! self::is_giftcard_product( $product ) ) {
			return;
		}

		$amounts   = $this->settings->fixed_amounts();
		$image_ids = array_values(
			array_unique(
				array_filter(
					array_merge(
						array( (int) $product->get_image_id() ),
						array_map( 'absint', $product->get_gallery_image_ids() )
					)
				)
			)
		);
		$default_amount = $amounts ? (float) reset( $amounts ) : 0.0;
		$default_image  = $image_ids ? wp_get_attachment_image_url( (int) $image_ids[0], 'large' ) : wc_placeholder_img_src( 'large' );

		wp_nonce_field( 'tokolariso_giftcard_add_to_cart', 'tokolariso_giftcard_nonce' );
		?>
		<div class="tokolariso-giftcard-fields">
			<div class="tokolariso-giftcard-panel-title"><?php esc_html_e( 'Toko Lariso Cadeaubon', 'toko-lariso-giftcards' ); ?></div>
			<div class="tokolariso-giftcard-builder">
				<div class="tokolariso-giftcard-preview" aria-live="polite">
					<div class="tokolariso-giftcard-preview-image">
						<img src="<?php echo esc_url( (string) $default_image ); ?>" alt="<?php echo esc_attr__( 'Selected giftcard design', 'toko-lariso-giftcards' ); ?>" data-tokolariso-preview-image />
					</div>
					<div class="tokolariso-giftcard-preview-footer">
						<strong><?php echo esc_html( $product->get_name() ); ?></strong>
						<span data-tokolariso-preview-amount><?php echo wp_kses_post( wc_price( $default_amount ) ); ?></span>
					</div>
				</div>

				<div class="tokolariso-giftcard-controls">
					<p class="tokolariso-giftcard-intro"><?php esc_html_e( 'Surprise friends, colleagues, or family with a Toko Lariso giftcard.', 'toko-lariso-giftcards' ); ?></p>

					<fieldset class="tokolariso-giftcard-amounts">
						<legend><?php esc_html_e( 'Choose an amount', 'toko-lariso-giftcards' ); ?></legend>
						<?php foreach ( $amounts as $index => $amount ) : ?>
							<label>
								<input type="radio" name="tokolariso_giftcard_amount_choice" value="fixed:<?php echo esc_attr( wc_format_decimal( $amount ) ); ?>" data-price="<?php echo esc_attr( wp_strip_all_tags( wc_price( $amount ) ) ); ?>" <?php checked( 0, $index ); ?> />
								<span><?php echo wp_kses_post( wc_price( $amount ) ); ?></span>
							</label>
						<?php endforeach; ?>
						<?php if ( $this->settings->custom_amount_enabled() ) : ?>
							<label class="tokolariso-giftcard-custom-amount">
								<input type="radio" name="tokolariso_giftcard_amount_choice" value="custom" />
								<span><?php esc_html_e( 'Custom amount', 'toko-lariso-giftcards' ); ?></span>
								<input type="number" name="tokolariso_giftcard_custom_amount" min="1" step="0.01" inputmode="decimal" />
							</label>
						<?php endif; ?>
					</fieldset>

					<?php if ( $image_ids ) : ?>
						<fieldset class="tokolariso-giftcard-designs">
							<legend><?php esc_html_e( 'Choose a picture', 'toko-lariso-giftcards' ); ?></legend>
							<?php foreach ( $image_ids as $index => $image_id ) : ?>
								<label>
									<input type="radio" name="tokolariso_giftcard_image_id" value="<?php echo esc_attr( $image_id ); ?>" data-image="<?php echo esc_url( (string) wp_get_attachment_image_url( $image_id, 'large' ) ); ?>" <?php checked( 0, $index ); ?> />
									<?php echo wp_kses_post( wp_get_attachment_image( $image_id, 'thumbnail' ) ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>

					<div class="tokolariso-giftcard-recipient">
						<h3><?php esc_html_e( 'Recipient information', 'toko-lariso-giftcards' ); ?></h3>
						<p>
							<label class="screen-reader-text" for="tokolariso_giftcard_recipient_name"><?php esc_html_e( 'Recipient name', 'toko-lariso-giftcards' ); ?></label>
							<input type="text" id="tokolariso_giftcard_recipient_name" name="tokolariso_giftcard_recipient_name" placeholder="<?php echo esc_attr__( 'Receiver name', 'toko-lariso-giftcards' ); ?>" required />
						</p>
						<p>
							<label class="screen-reader-text" for="tokolariso_giftcard_recipient_email"><?php esc_html_e( 'Recipient email', 'toko-lariso-giftcards' ); ?></label>
							<input type="email" id="tokolariso_giftcard_recipient_email" name="tokolariso_giftcard_recipient_email" placeholder="<?php echo esc_attr__( 'Receiver email', 'toko-lariso-giftcards' ); ?>" required />
						</p>
						<p class="tokolariso-giftcard-message-field">
							<label class="screen-reader-text" for="tokolariso_giftcard_message"><?php esc_html_e( 'Personal message', 'toko-lariso-giftcards' ); ?></label>
							<textarea id="tokolariso_giftcard_message" name="tokolariso_giftcard_message" rows="4" maxlength="250" placeholder="<?php echo esc_attr__( 'Greeting/message', 'toko-lariso-giftcards' ); ?>"></textarea>
							<small><span data-tokolariso-message-count>0</span>/250 <?php esc_html_e( 'characters', 'toko-lariso-giftcards' ); ?></small>
						</p>
						<p>
							<label class="screen-reader-text" for="tokolariso_giftcard_sender_name"><?php esc_html_e( 'Sender name', 'toko-lariso-giftcards' ); ?></label>
							<input type="text" id="tokolariso_giftcard_sender_name" name="tokolariso_giftcard_sender_name" placeholder="<?php echo esc_attr__( 'Sender name', 'toko-lariso-giftcards' ); ?>" />
						</p>
						<p>
							<label class="screen-reader-text" for="tokolariso_giftcard_delivery_date"><?php esc_html_e( 'Delivery date', 'toko-lariso-giftcards' ); ?></label>
							<input type="date" id="tokolariso_giftcard_delivery_date" name="tokolariso_giftcard_delivery_date" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

/**
 * Defines the product class once WooCommerce product classes are available.
 *
 * @return void
 */
function tokolariso_giftcards_register_product_class(): void {
	if ( ! class_exists( 'WC_Product_Simple' ) || class_exists( 'WC_Product_Toko_Lariso_Giftcard' ) ) {
		return;
	}

	/**
	 * Giftcard product object.
	 */
	class WC_Product_Toko_Lariso_Giftcard extends WC_Product_Simple {
		/**
		 * Gets product type.
		 *
		 * @return string
		 */
		public function get_type(): string {
			return Toko_Lariso_Giftcards_Product_Type::TYPE;
		}

		/**
		 * Giftcards must open the builder first so recipient, message, design, and amount are captured.
		 *
		 * @param string $feature Product feature.
		 * @return bool
		 */
		public function supports( $feature ): bool {
			if ( 'ajax_add_to_cart' === $feature ) {
				return false;
			}

			return parent::supports( $feature );
		}

		/**
		 * Giftcards are intentionally non-virtual so order connectors can treat them as real products.
		 *
		 * @return bool
		 */
		public function is_virtual(): bool {
			return false;
		}

		/**
		 * Giftcards use a shopper-selected dynamic amount, so they remain purchasable without a fixed catalog price.
		 *
		 * @return bool
		 */
		public function is_purchasable(): bool {
			return $this->exists() && ( 'publish' === $this->get_status() || current_user_can( 'edit_post', $this->get_id() ) );
		}

		/**
		 * Single product button text.
		 *
		 * @return string
		 */
		public function single_add_to_cart_text(): string {
			return __( 'Add giftcard to cart', 'toko-lariso-giftcards' );
		}

		/**
		 * Catalog button text.
		 *
		 * @return string
		 */
		public function add_to_cart_text(): string {
			return __( 'Choose giftcard', 'toko-lariso-giftcards' );
		}

		/**
		 * Giftcard voucher sales are treated as non-taxable.
		 *
		 * @param string $context Context.
		 * @return string
		 */
		public function get_tax_status( $context = 'view' ): string {
			return 'none';
		}
	}
}

tokolariso_giftcards_register_product_class();
add_action( 'woocommerce_loaded', 'tokolariso_giftcards_register_product_class' );
