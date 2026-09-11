<?php
/**
 * Plugin orchestrator.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps plugin services.
 */
final class Toko_Lariso_Giftcards_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Settings service.
	 *
	 * @var Toko_Lariso_Giftcards_Settings
	 */
	public Toko_Lariso_Giftcards_Settings $settings;

	/**
	 * WPML service.
	 *
	 * @var Toko_Lariso_Giftcards_WPML
	 */
	public Toko_Lariso_Giftcards_WPML $wpml;

	/**
	 * Repository service.
	 *
	 * @var Toko_Lariso_Giftcards_Repository
	 */
	public Toko_Lariso_Giftcards_Repository $repository;

	/**
	 * Email service.
	 *
	 * @var Toko_Lariso_Giftcards_Email
	 */
	public Toko_Lariso_Giftcards_Email $email;

	/**
	 * Cart service.
	 *
	 * @var Toko_Lariso_Giftcards_Cart
	 */
	public Toko_Lariso_Giftcards_Cart $cart;

	/**
	 * Gets singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings   = new Toko_Lariso_Giftcards_Settings();
		$this->wpml       = new Toko_Lariso_Giftcards_WPML( $this->settings );
		$this->repository = new Toko_Lariso_Giftcards_Repository();
		$this->email      = new Toko_Lariso_Giftcards_Email( $this->settings, $this->repository );
		$this->cart       = new Toko_Lariso_Giftcards_Cart( $this->settings, $this->repository );
	}

	/**
	 * Starts services.
	 *
	 * @return void
	 */
	public function init(): void {
		load_plugin_textdomain( 'toko-lariso-giftcards', false, dirname( plugin_basename( TOKO_LARISO_GIFTCARDS_FILE ) ) . '/languages' );

		$this->settings->init();
		$this->wpml->init();
		$this->email->init();
		( new Toko_Lariso_Giftcards_Product_Type( $this->settings ) )->init();
		( new Toko_Lariso_Giftcards_Shipping() )->init();
		( new Toko_Lariso_Giftcards_Sendcloud_Compatibility() )->init();
		$this->cart->init();
		( new Toko_Lariso_Giftcards_Store_API( $this->settings, $this->repository, $this->cart ) )->init();
		( new Toko_Lariso_Giftcards_Order( $this->settings, $this->repository, $this->email, $this->cart ) )->init();
		( new Toko_Lariso_Giftcards_PDF( $this->repository ) )->init();
		( new Toko_Lariso_Giftcards_My_Account( $this->repository ) )->init();

		if ( is_admin() ) {
			( new Toko_Lariso_Giftcards_Admin( $this->settings, $this->repository, $this->email ) )->init();
		}
	}
}
