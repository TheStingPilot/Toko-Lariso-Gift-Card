<?php
/**
 * WPML integration.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and translates plugin-owned strings for WPML.
 */
class Toko_Lariso_Giftcards_WPML {
	private const CONTEXT = 'toko-lariso-giftcards';

	/**
	 * Translatable option keys.
	 *
	 * @var string[]
	 */
	private const STRING_KEYS = array(
		'email_subject',
		'email_heading',
		'email_intro',
		'email_button_label',
		'email_shop_url',
	);

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
		add_action( 'init', array( $this, 'register_strings' ), 20 );
		add_action( 'update_option_' . Toko_Lariso_Giftcards_Settings::OPTION_KEY, array( $this, 'register_strings' ), 20, 0 );
		add_filter( 'tokolariso_giftcards_translate_setting', array( $this, 'translate_setting' ), 10, 2 );
	}

	/**
	 * Registers option-backed strings with WPML String Translation.
	 *
	 * @return void
	 */
	public function register_strings(): void {
		if ( ! has_action( 'wpml_register_single_string' ) ) {
			return;
		}

		foreach ( self::STRING_KEYS as $key ) {
			$value = (string) $this->settings->get( $key, '' );
			do_action( 'wpml_register_single_string', self::CONTEXT, $key, $value );
		}
	}

	/**
	 * Translates a setting in the current WPML language.
	 *
	 * @param string $value Original setting value.
	 * @param string $key Setting key.
	 * @return string
	 */
	public function translate_setting( string $value, string $key ): string {
		if ( ! in_array( $key, self::STRING_KEYS, true ) || ! has_filter( 'wpml_translate_single_string' ) ) {
			return $value;
		}

		return (string) apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $key );
	}
}
