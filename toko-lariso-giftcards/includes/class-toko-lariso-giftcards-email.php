<?php
/**
 * Giftcard email handling.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends giftcard emails through WooCommerce mailer.
 */
class Toko_Lariso_Giftcards_Email {
	/**
	 * Settings service.
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
	 * @param Toko_Lariso_Giftcards_Settings   $settings Settings service.
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
		add_action( 'tokolariso_giftcards_send_scheduled_email', array( $this, 'send_scheduled_email' ), 10, 1 );
	}

	/**
	 * Sends a scheduled giftcard email.
	 *
	 * @param int $giftcard_id Giftcard id.
	 * @return void
	 */
	public function send_scheduled_email( int $giftcard_id ): void {
		$card = $this->repository->get_by_id( $giftcard_id );
		if ( $card ) {
			$this->send_giftcard( $card );
		}
	}

	/**
	 * Sends a giftcard to the recipient.
	 *
	 * @param array<string,mixed> $card Giftcard row.
	 * @return bool
	 */
	public function send_giftcard( array $card ): bool {
		$to = sanitize_email( (string) ( $card['recipient_email'] ?? '' ) );
		if ( ! is_email( $to ) ) {
			return false;
		}

		$code    = $this->repository->decrypt_card_code( $card );
		$subject = $this->replace_placeholders( $this->translated_setting( 'email_subject', 'Your Toko Lariso giftcard' ), $card, $code );
		$heading = $this->replace_placeholders( $this->translated_setting( 'email_heading', 'Your Toko Lariso giftcard' ), $card, $code );
		$body    = $this->build_body( $card, $code );

		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $body );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return (bool) $mailer->send( $to, $subject, $message, $headers, array() );
	}

	/**
	 * Schedules or sends a giftcard email based on delivery date.
	 *
	 * @param array<string,mixed> $card Giftcard row.
	 * @param string|null         $delivery_date Optional Y-m-d date.
	 * @return void
	 */
	public function send_or_schedule( array $card, ?string $delivery_date ): void {
		if ( $delivery_date ) {
			$timestamp = strtotime( $delivery_date . ' 08:00:00' );
			if ( $timestamp && $timestamp > time() ) {
				wp_schedule_single_event( $timestamp, 'tokolariso_giftcards_send_scheduled_email', array( (int) $card['id'] ) );
				return;
			}
		}

		$this->send_giftcard( $card );
	}

	/**
	 * Builds the HTML email body.
	 *
	 * @param array<string,mixed> $card Giftcard row.
	 * @param string              $code Plain code.
	 * @return string
	 */
	private function build_body( array $card, string $code ): string {
		$intro        = $this->replace_placeholders( $this->translated_setting( 'email_intro', '' ), $card, $code );
		$button_label = $this->translated_setting( 'email_button_label', 'Shop at Toko Lariso' );
		$shop_url     = esc_url( $this->translated_setting( 'email_shop_url', home_url( '/' ) ) );
		$expires      = ! empty( $card['expires_at'] ) ? wc_format_datetime( new WC_DateTime( (string) $card['expires_at'] ) ) : __( 'No expiry date', 'toko-lariso-giftcards' );

		ob_start();
		?>
		<p><?php echo esc_html( $intro ); ?></p>
		<?php if ( ! empty( $card['image_url'] ) ) : ?>
			<p><img src="<?php echo esc_url( (string) $card['image_url'] ); ?>" alt="<?php echo esc_attr__( 'Giftcard', 'toko-lariso-giftcards' ); ?>" style="max-width: 360px; width: 100%; height: auto;" /></p>
		<?php endif; ?>
		<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; border-color: #dddddd;">
			<tr>
				<th scope="row" style="text-align: left;"><?php esc_html_e( 'Giftcard code', 'toko-lariso-giftcards' ); ?></th>
				<td><strong style="font-size: 18px; letter-spacing: 1px;"><?php echo esc_html( $code ); ?></strong></td>
			</tr>
			<tr>
				<th scope="row" style="text-align: left;"><?php esc_html_e( 'Value', 'toko-lariso-giftcards' ); ?></th>
				<td><?php echo wp_kses_post( wc_price( (float) $card['initial_amount'], array( 'currency' => (string) $card['currency'] ) ) ); ?></td>
			</tr>
			<tr>
				<th scope="row" style="text-align: left;"><?php esc_html_e( 'Valid until', 'toko-lariso-giftcards' ); ?></th>
				<td><?php echo esc_html( $expires ); ?></td>
			</tr>
		</table>
		<?php if ( ! empty( $card['message'] ) ) : ?>
			<p><?php echo nl2br( esc_html( (string) $card['message'] ) ); ?></p>
		<?php endif; ?>
		<p>
			<a href="<?php echo $shop_url; ?>" style="display: inline-block; padding: 10px 16px; background: #7a1f1f; color: #ffffff; text-decoration: none;">
				<?php echo esc_html( $button_label ); ?>
			</a>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Replaces email placeholders.
	 *
	 * @param string              $text Template text.
	 * @param array<string,mixed> $card Giftcard row.
	 * @param string              $code Plain code.
	 * @return string
	 */
	private function replace_placeholders( string $text, array $card, string $code ): string {
		$expires = ! empty( $card['expires_at'] ) ? wc_format_datetime( new WC_DateTime( (string) $card['expires_at'] ) ) : '';

		return strtr(
			$text,
			array(
				'{recipient_name}' => (string) ( $card['recipient_name'] ?? '' ),
				'{code}'           => $code,
				'{amount}'         => wp_strip_all_tags( wc_price( (float) $card['initial_amount'], array( 'currency' => (string) $card['currency'] ) ) ),
				'{balance}'        => wp_strip_all_tags( wc_price( (float) $card['current_balance'], array( 'currency' => (string) $card['currency'] ) ) ),
				'{expires_at}'     => $expires,
				'{message}'        => (string) ( $card['message'] ?? '' ),
			)
		);
	}

	/**
	 * Gets a translatable email setting.
	 *
	 * @param string $key Setting key.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function translated_setting( string $key, string $fallback ): string {
		$value = (string) $this->settings->get( $key, $fallback );
		return (string) apply_filters( 'tokolariso_giftcards_translate_setting', $value, $key );
	}
}
