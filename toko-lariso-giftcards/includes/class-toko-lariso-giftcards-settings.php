<?php
/**
 * Settings service.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin options.
 */
class Toko_Lariso_Giftcards_Settings {
	public const OPTION_KEY = 'tokolariso_giftcards_settings';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( TOKO_LARISO_GIFTCARDS_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'expiry_days'              => 730,
			'fixed_amounts'            => '15,25,50,75,100',
			'allow_custom_amount'      => 'yes',
			'allow_multiple_giftcards' => 'yes',
			'refund_behavior'          => 'manual',
			'debug_logging'            => 'no',
			'delete_data_on_uninstall' => 'no',
			'email_subject'            => 'Your Toko Lariso giftcard',
			'email_heading'            => 'Your Toko Lariso giftcard',
			'email_intro'              => 'A giftcard has been prepared for you.',
			'email_button_label'       => 'Shop at Toko Lariso',
			'email_shop_url'           => home_url( '/' ),
			'pdf_logo_image_id'        => 0,
			'pdf_header_color'         => '#7d49b4',
			'giftcard_redeem_url'      => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ),
		);
	}

	/**
	 * Gets all settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );
	}

	/**
	 * Gets one setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Optional fallback.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Saves settings after sanitization.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return void
	 */
	public function save( array $input ): void {
		$defaults = $this->defaults();
		$output   = array();

		$output['expiry_days']              = max( 1, absint( $input['expiry_days'] ?? $defaults['expiry_days'] ) );
		$output['fixed_amounts']            = $this->sanitize_amounts( (string) ( $input['fixed_amounts'] ?? $defaults['fixed_amounts'] ) );
		$output['allow_custom_amount']      = ! empty( $input['allow_custom_amount'] ) ? 'yes' : 'no';
		$output['allow_multiple_giftcards'] = ! empty( $input['allow_multiple_giftcards'] ) ? 'yes' : 'no';
		$output['debug_logging']            = ! empty( $input['debug_logging'] ) ? 'yes' : 'no';
		$output['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] ) ? 'yes' : 'no';

		$refund_behavior = sanitize_key( (string) ( $input['refund_behavior'] ?? $defaults['refund_behavior'] ) );
		$output['refund_behavior'] = in_array( $refund_behavior, array( 'manual', 'restore_full_refund' ), true ) ? $refund_behavior : 'manual';

		$output['email_subject']      = sanitize_text_field( (string) ( $input['email_subject'] ?? $defaults['email_subject'] ) );
		$output['email_heading']      = sanitize_text_field( (string) ( $input['email_heading'] ?? $defaults['email_heading'] ) );
		$output['email_intro']        = sanitize_textarea_field( (string) ( $input['email_intro'] ?? $defaults['email_intro'] ) );
		$output['email_button_label'] = sanitize_text_field( (string) ( $input['email_button_label'] ?? $defaults['email_button_label'] ) );
		$output['email_shop_url']     = esc_url_raw( (string) ( $input['email_shop_url'] ?? $defaults['email_shop_url'] ) );
		$output['pdf_logo_image_id']  = absint( $input['pdf_logo_image_id'] ?? $defaults['pdf_logo_image_id'] );
		$output['pdf_header_color']   = sanitize_hex_color( (string) ( $input['pdf_header_color'] ?? $defaults['pdf_header_color'] ) ) ?: $defaults['pdf_header_color'];
		$output['giftcard_redeem_url'] = esc_url_raw( (string) ( $input['giftcard_redeem_url'] ?? $defaults['giftcard_redeem_url'] ) );

		update_option( self::OPTION_KEY, $output, false );
	}

	/**
	 * Gets configured fixed amounts as floats.
	 *
	 * @return float[]
	 */
	public function fixed_amounts(): array {
		$raw = (string) $this->get( 'fixed_amounts', '15,25,50,75,100' );
		return array_map( 'floatval', array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Whether custom amounts are enabled.
	 *
	 * @return bool
	 */
	public function custom_amount_enabled(): bool {
		return 'yes' === $this->get( 'allow_custom_amount', 'yes' );
	}

	/**
	 * Whether multiple giftcards are enabled.
	 *
	 * @return bool
	 */
	public function multiple_giftcards_enabled(): bool {
		return 'yes' === $this->get( 'allow_multiple_giftcards', 'yes' );
	}

	/**
	 * Adds settings link to plugin row.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function add_settings_link( array $links ): array {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=tokolariso-giftcards&tab=settings' ) ),
			esc_html__( 'Settings', 'toko-lariso-giftcards' )
		);
		return $links;
	}

	/**
	 * Sanitizes a comma-separated amount list.
	 *
	 * @param string $value Raw amount list.
	 * @return string
	 */
	private function sanitize_amounts( string $value ): string {
		$amounts = array();
		foreach ( explode( ',', $value ) as $amount ) {
			$decimal = wc_format_decimal( $amount, wc_get_price_decimals() );
			if ( '' !== $decimal && (float) $decimal > 0 ) {
				$amounts[] = $decimal;
			}
		}

		$amounts = array_values( array_unique( $amounts ) );
		return implode( ',', $amounts );
	}
}
