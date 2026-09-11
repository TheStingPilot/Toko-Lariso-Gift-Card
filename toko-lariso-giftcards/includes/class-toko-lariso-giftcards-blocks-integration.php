<?php
/**
 * Blocks script integration.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Enqueues Cart and Checkout Blocks assets.
 */
class Toko_Lariso_Giftcards_Blocks_Integration implements IntegrationInterface {
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
	 * Integration name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'tokolariso-giftcards';
	}

	/**
	 * Registers scripts and styles.
	 *
	 * @return void
	 */
	public function initialize(): void {
		$script_path = 'assets/js/blocks.js';
		$style_path  = 'assets/css/blocks.css';
		$dependencies = array( 'wp-element', 'wp-plugins', 'wp-i18n', 'wp-data', 'wc-blocks-checkout' );

		if ( wp_script_is( 'wc-blocks-data-store', 'registered' ) ) {
			$dependencies[] = 'wc-blocks-data-store';
		} elseif ( wp_script_is( 'wc-blocks-data', 'registered' ) ) {
			$dependencies[] = 'wc-blocks-data';
		}

		wp_register_style(
			'tokolariso-giftcards-blocks',
			TOKO_LARISO_GIFTCARDS_URL . $style_path,
			array(),
			$this->asset_version( $style_path )
		);

		wp_register_script(
			'tokolariso-giftcards-blocks',
			TOKO_LARISO_GIFTCARDS_URL . $script_path,
			$dependencies,
			$this->asset_version( $script_path ),
			true
		);

		wp_set_script_translations( 'tokolariso-giftcards-blocks', 'toko-lariso-giftcards', TOKO_LARISO_GIFTCARDS_PATH . 'languages' );
	}

	/**
	 * Frontend script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles(): array {
		wp_enqueue_style( 'tokolariso-giftcards-blocks' );
		return array( 'tokolariso-giftcards-blocks' );
	}

	/**
	 * Editor script handles.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles(): array {
		wp_enqueue_style( 'tokolariso-giftcards-blocks' );
		return array( 'tokolariso-giftcards-blocks' );
	}

	/**
	 * Script data available through getSetting.
	 *
	 * @return array<string,mixed>
	 */
	public function get_script_data(): array {
		return array(
			'namespace'      => Toko_Lariso_Giftcards_Store_API::NAMESPACE,
			'allowMultiple'  => $this->settings->multiple_giftcards_enabled(),
			'labels'         => array(
				'title'       => __( 'Giftcard', 'toko-lariso-giftcards' ),
				'placeholder' => __( 'Enter giftcard code', 'toko-lariso-giftcards' ),
				'apply'       => __( 'Apply', 'toko-lariso-giftcards' ),
				'remove'      => __( 'Remove', 'toko-lariso-giftcards' ),
				'applied'     => __( 'Giftcard partial payment', 'toko-lariso-giftcards' ),
			),
		);
	}

	/**
	 * Gets asset version.
	 *
	 * @param string $relative Relative file path.
	 * @return string
	 */
	private function asset_version( string $relative ): string {
		$file = TOKO_LARISO_GIFTCARDS_PATH . $relative;
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
			return (string) filemtime( $file );
		}

		return TOKO_LARISO_GIFTCARDS_VERSION;
	}
}
