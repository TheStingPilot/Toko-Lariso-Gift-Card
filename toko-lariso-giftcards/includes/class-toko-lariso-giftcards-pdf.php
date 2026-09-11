<?php
/**
 * Giftcard PDF download handling.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates self-contained giftcard PDFs after payment.
 */
class Toko_Lariso_Giftcards_PDF {
	/**
	 * Repository.
	 *
	 * @var Toko_Lariso_Giftcards_Repository
	 */
	private Toko_Lariso_Giftcards_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Toko_Lariso_Giftcards_Repository $repository Repository.
	 */
	public function __construct( Toko_Lariso_Giftcards_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'template_redirect', array( $this, 'handle_download_request' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_pdf_links' ), 20 );
	}

	/**
	 * Renders PDF buttons on paid order detail pages.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function render_order_pdf_links( WC_Order $order ): void {
		if ( ! $order->is_paid() ) {
			return;
		}

		$links = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( 'yes' !== $item->get_meta( '_tokolariso_is_giftcard', true ) ) {
				continue;
			}

			$giftcard_id = absint( $item->get_meta( '_tokolariso_giftcard_id', true ) );
			if ( ! $giftcard_id ) {
				continue;
			}

			$links[] = array(
				'url'   => $this->build_download_url( $order, (int) $item_id, $giftcard_id ),
				'label' => sprintf(
					/* translators: %s: order item name */
					__( 'Download PDF for %s', 'toko-lariso-giftcards' ),
					$item->get_name()
				),
			);
		}

		if ( ! $links ) {
			return;
		}

		echo '<section class="woocommerce-order-details tokolariso-giftcard-pdfs">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Giftcard PDFs', 'toko-lariso-giftcards' ) . '</h2>';
		foreach ( $links as $link ) {
			echo '<p><a class="button tokolariso-giftcard-pdf-button" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a></p>';
		}
		echo '</section>';
	}

	/**
	 * Handles secured PDF downloads.
	 *
	 * @return void
	 */
	public function handle_download_request(): void {
		if ( empty( $_GET['tokolariso_giftcard_pdf'] ) ) {
			return;
		}

		$giftcard_id = absint( $_GET['tokolariso_giftcard_pdf'] );
		$order_id    = absint( $_GET['order_id'] ?? 0 );
		$item_id     = absint( $_GET['item_id'] ?? 0 );
		$nonce       = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

		if ( ! $giftcard_id || ! $order_id || ! $item_id || ! wp_verify_nonce( $nonce, $this->nonce_action( $order_id, $item_id ) ) ) {
			wp_die( esc_html__( 'Giftcard PDF link is invalid or expired.', 'toko-lariso-giftcards' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->current_request_can_download( $order ) ) {
			wp_die( esc_html__( 'You are not allowed to download this giftcard PDF.', 'toko-lariso-giftcards' ), '', array( 'response' => 403 ) );
		}

		if ( ! $order->is_paid() ) {
			wp_die( esc_html__( 'Giftcard PDFs are available after payment.', 'toko-lariso-giftcards' ), '', array( 'response' => 403 ) );
		}

		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || 'yes' !== $item->get_meta( '_tokolariso_is_giftcard', true ) ) {
			wp_die( esc_html__( 'Giftcard order item was not found.', 'toko-lariso-giftcards' ), '', array( 'response' => 404 ) );
		}

		$item_giftcard_id = absint( $item->get_meta( '_tokolariso_giftcard_id', true ) );
		if ( $giftcard_id !== $item_giftcard_id ) {
			wp_die( esc_html__( 'Giftcard order item was not found.', 'toko-lariso-giftcards' ), '', array( 'response' => 404 ) );
		}

		$card = $this->repository->get_by_id( $giftcard_id );
		if ( ! $card ) {
			wp_die( esc_html__( 'Giftcard was not found.', 'toko-lariso-giftcards' ), '', array( 'response' => 404 ) );
		}

		$code = $this->repository->decrypt_card_code( $card );
		$pdf  = $this->build_pdf( $order, $item, $card, $code );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $this->pdf_filename( $card ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Builds a secured PDF download URL.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $item_id Order item id.
	 * @param int      $giftcard_id Giftcard id.
	 * @return string
	 */
	private function build_download_url( WC_Order $order, int $item_id, int $giftcard_id ): string {
		$url = add_query_arg(
			array(
				'tokolariso_giftcard_pdf' => $giftcard_id,
				'order_id'                => $order->get_id(),
				'item_id'                 => $item_id,
				'key'                     => $order->get_order_key(),
			),
			home_url( '/' )
		);

		return wp_nonce_url( $url, $this->nonce_action( $order->get_id(), $item_id ) );
	}

	/**
	 * Checks download permissions.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function current_request_can_download( WC_Order $order ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$user_id = get_current_user_id();
		if ( $user_id && (int) $order->get_user_id() === $user_id ) {
			return true;
		}

		$key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		return $key && hash_equals( $order->get_order_key(), $key );
	}

	/**
	 * Nonce action.
	 *
	 * @param int $order_id Order id.
	 * @param int $item_id Item id.
	 * @return string
	 */
	private function nonce_action( int $order_id, int $item_id ): string {
		return 'tokolariso_giftcard_pdf_' . $order_id . '_' . $item_id;
	}

	/**
	 * Creates a simple one-page PDF.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item Order item.
	 * @param array<string,mixed>   $card Giftcard row.
	 * @param string                $code Plain giftcard code.
	 * @return string
	 */
	private function build_pdf( WC_Order $order, WC_Order_Item_Product $item, array $card, string $code ): string {
		$page_w    = 842;
		$page_h    = 595;
		$objects   = array();
		$font_id   = $this->add_pdf_object( $objects, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
		$image     = $this->jpeg_image_for_card( $card );
		$image_id  = $image ? $this->add_pdf_object( $objects, $this->build_jpeg_object( $image ) ) : 0;
		$resources = '<< /Font << /F1 ' . $font_id . ' 0 R >>';
		if ( $image_id ) {
			$resources .= ' /XObject << /Im1 ' . $image_id . ' 0 R >>';
		}
		$resources .= ' >>';

		$recipient = (string) $item->get_meta( '_tokolariso_giftcard_recipient_name', true );
		$sender    = (string) $item->get_meta( '_tokolariso_giftcard_sender_name', true );
		$message   = (string) $item->get_meta( '_tokolariso_giftcard_message', true );
		$expires   = ! empty( $card['expires_at'] ) ? wc_format_datetime( new WC_DateTime( (string) $card['expires_at'] ) ) : __( 'No expiry date', 'toko-lariso-giftcards' );
		$amount    = $this->format_pdf_price( (float) $card['initial_amount'], (string) $card['currency'] );

		$content = '';
		$content .= $this->pdf_fill_rgb( 0.98, 0.97, 0.95 );
		$content .= $this->pdf_rect( 0, 0, $page_w, $page_h, 'f', $page_h );
		$content .= $this->pdf_fill_rgb( 0.49, 0.29, 0.71 );
		$content .= $this->pdf_rect( 0, 0, $page_w, 54, 'f', $page_h );
		$content .= $this->pdf_text( 36, 35, 22, 'Toko Lariso Cadeaubon', $page_h, 1, 1, 1 );

		if ( $image_id && $image ) {
			$content .= $this->pdf_image( 'Im1', 36, 78, 382, 286, $page_h );
		} else {
			$content .= $this->pdf_fill_rgb( 0.9, 0.86, 0.78 );
			$content .= $this->pdf_rect( 36, 78, 382, 286, 'f', $page_h );
			$content .= $this->pdf_text( 128, 226, 30, 'Toko Lariso Giftcard', $page_h, 0.35, 0.19, 0.08 );
		}

		$content .= $this->pdf_stroke_rgb( 0.49, 0.29, 0.71 );
		$content .= $this->pdf_rect( 36, 78, 382, 400, 'S', $page_h );
		$content .= $this->pdf_text( 128, 405, 18, 'Toko Lariso Cadeaubon', $page_h, 0.05, 0.09, 0.18 );
		$content .= $this->pdf_text( 146, 448, 34, $amount, $page_h, 0.05, 0.09, 0.18 );

		$x = 462;
		$content .= $this->pdf_text( $x, 95, 13, __( 'Receiver name', 'toko-lariso-giftcards' ) . ':', $page_h, 0.49, 0.29, 0.71 );
		$content .= $this->pdf_text( $x, 117, 18, $recipient, $page_h, 0.05, 0.09, 0.18 );
		if ( $sender ) {
			$content .= $this->pdf_text( $x, 152, 13, __( 'Sender name', 'toko-lariso-giftcards' ) . ':', $page_h, 0.49, 0.29, 0.71 );
			$content .= $this->pdf_text( $x, 174, 16, $sender, $page_h, 0.05, 0.09, 0.18 );
		}
		$content .= $this->pdf_text( $x, 214, 13, __( 'Giftcard code', 'toko-lariso-giftcards' ) . ':', $page_h, 0.49, 0.29, 0.71 );
		$content .= $this->pdf_text( $x, 240, 24, $code, $page_h, 0.05, 0.09, 0.18 );
		$content .= $this->pdf_text( $x, 284, 13, __( 'Valid until', 'toko-lariso-giftcards' ) . ':', $page_h, 0.49, 0.29, 0.71 );
		$content .= $this->pdf_text( $x, 306, 15, $expires, $page_h, 0.05, 0.09, 0.18 );

		if ( $message ) {
			$content .= $this->pdf_text( $x, 352, 13, __( 'Greeting/message', 'toko-lariso-giftcards' ) . ':', $page_h, 0.49, 0.29, 0.71 );
			$line_y = 376;
			foreach ( $this->wrap_pdf_text( $message, 44 ) as $line ) {
				$content .= $this->pdf_text( $x, $line_y, 13, $line, $page_h, 0.05, 0.09, 0.18 );
				$line_y += 18;
			}
		}

		$content_id = $this->add_pdf_object( $objects, $this->build_stream_object( $content ) );
		$page_id    = $this->add_pdf_object( $objects, '<< /Type /Page /Parent 0 0 R /MediaBox [0 0 ' . $page_w . ' ' . $page_h . '] /Resources ' . $resources . ' /Contents ' . $content_id . ' 0 R >>' );
		$pages_id   = $this->add_pdf_object( $objects, '<< /Type /Pages /Kids [' . $page_id . ' 0 R] /Count 1 >>' );
		$objects[ $page_id ] = str_replace( '/Parent 0 0 R', '/Parent ' . $pages_id . ' 0 R', $objects[ $page_id ] );
		$catalog_id = $this->add_pdf_object( $objects, '<< /Type /Catalog /Pages ' . $pages_id . ' 0 R >>' );

		return $this->compile_pdf( $objects, $catalog_id );
	}

	/**
	 * Adds an object and returns its id.
	 *
	 * @param array<int,string> $objects Objects.
	 * @param string            $body Object body.
	 * @return int
	 */
	private function add_pdf_object( array &$objects, string $body ): int {
		$id             = count( $objects ) + 1;
		$objects[ $id ] = $body;
		return $id;
	}

	/**
	 * Compiles objects into a PDF file string.
	 *
	 * @param array<int,string> $objects Objects.
	 * @param int               $catalog_id Catalog object id.
	 * @return string
	 */
	private function compile_pdf( array $objects, int $catalog_id ): string {
		$pdf     = "%PDF-1.4\n";
		$offsets = array( 0 );
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= count( $objects ); $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . ' /Root ' . $catalog_id . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

		return $pdf;
	}

	/**
	 * Builds a PDF stream object.
	 *
	 * @param string $stream Stream.
	 * @return string
	 */
	private function build_stream_object( string $stream ): string {
		return "<< /Length " . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
	}

	/**
	 * Gets JPEG image data for the giftcard, if available.
	 *
	 * @param array<string,mixed> $card Giftcard.
	 * @return array<string,mixed>|null
	 */
	private function jpeg_image_for_card( array $card ): ?array {
		$image_id = absint( $card['image_id'] ?? 0 );
		$path     = $image_id ? get_attached_file( $image_id ) : '';
		if ( ! $path || ! is_readable( $path ) ) {
			return null;
		}

		$path = $this->jpeg_path_for_pdf( $path );
		if ( ! $path || ! is_readable( $path ) ) {
			return null;
		}

		$info = getimagesize( $path );
		if ( ! $info || empty( $info['mime'] ) || 'image/jpeg' !== $info['mime'] ) {
			return null;
		}

		return array(
			'width'  => (int) $info[0],
			'height' => (int) $info[1],
			'data'   => (string) file_get_contents( $path ),
		);
	}

	/**
	 * Gets a JPEG path for PDF embedding, converting local images when needed.
	 *
	 * @param string $path Source image path.
	 * @return string
	 */
	private function jpeg_path_for_pdf( string $path ): string {
		$info = getimagesize( $path );
		if ( ! $info || empty( $info['mime'] ) ) {
			return '';
		}

		if ( 'image/jpeg' === $info['mime'] ) {
			return $path;
		}

		$cache_path = $this->pdf_image_cache_path( $path );
		if ( ! $cache_path ) {
			return '';
		}

		if ( is_readable( $cache_path ) ) {
			return $cache_path;
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			Toko_Lariso_Giftcards_Debug::log(
				'pdf_image_conversion_editor_failed',
				array(
					'mime'  => (string) $info['mime'],
					'error' => $editor->get_error_message(),
				)
			);
			return '';
		}

		if ( is_callable( array( $editor, 'set_quality' ) ) ) {
			$editor->set_quality( 90 );
		}

		$result = $editor->save( $cache_path, 'image/jpeg' );
		if ( is_wp_error( $result ) || ! is_readable( $cache_path ) ) {
			Toko_Lariso_Giftcards_Debug::log(
				'pdf_image_conversion_save_failed',
				array(
					'mime'  => (string) $info['mime'],
					'error' => is_wp_error( $result ) ? $result->get_error_message() : 'converted file is not readable',
				)
			);
			return '';
		}

		return $cache_path;
	}

	/**
	 * Builds a stable cache path for converted PDF images.
	 *
	 * @param string $source_path Source image path.
	 * @return string
	 */
	private function pdf_image_cache_path( string $source_path ): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( (string) $uploads['basedir'] ) . 'tokolariso-giftcards/pdf-cache';
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$mtime = filemtime( $source_path );
		$size  = filesize( $source_path );
		$key   = md5( $source_path . '|' . ( false === $mtime ? '' : $mtime ) . '|' . ( false === $size ? '' : $size ) );

		return trailingslashit( $dir ) . $key . '.jpg';
	}

	/**
	 * Builds a JPEG XObject.
	 *
	 * @param array<string,mixed> $image Image.
	 * @return string
	 */
	private function build_jpeg_object( array $image ): string {
		$data = (string) $image['data'];
		return '<< /Type /XObject /Subtype /Image /Width ' . (int) $image['width'] . ' /Height ' . (int) $image['height'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen( $data ) . " >>\nstream\n" . $data . "\nendstream";
	}

	/**
	 * PDF text command.
	 */
	private function pdf_text( float $x, float $y, float $size, string $text, float $page_h, float $r, float $g, float $b ): string {
		return sprintf( "%.3F %.3F %.3F rg\nBT /F1 %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $r, $g, $b, $size, $x, $page_h - $y, $this->escape_pdf_text( $text ) );
	}

	/**
	 * PDF rectangle command.
	 */
	private function pdf_rect( float $x, float $y, float $w, float $h, string $mode, float $page_h ): string {
		return sprintf( "%.2F %.2F %.2F %.2F re %s\n", $x, $page_h - $y - $h, $w, $h, $mode );
	}

	/**
	 * PDF image command.
	 */
	private function pdf_image( string $name, float $x, float $y, float $w, float $h, float $page_h ): string {
		return sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $page_h - $y - $h, $name );
	}

	/**
	 * Fill color command.
	 */
	private function pdf_fill_rgb( float $r, float $g, float $b ): string {
		return sprintf( "%.3F %.3F %.3F rg\n", $r, $g, $b );
	}

	/**
	 * Stroke color command.
	 */
	private function pdf_stroke_rgb( float $r, float $g, float $b ): string {
		return sprintf( "%.3F %.3F %.3F RG\n", $r, $g, $b );
	}

	/**
	 * Escapes text for a PDF literal.
	 */
	private function escape_pdf_text( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( '€', "\r", "\n" ), array( 'EUR', ' ', ' ' ), $text );
		if ( function_exists( 'iconv' ) ) {
			$text = iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );
			$text = false === $text ? '' : $text;
		}

		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}

	/**
	 * Wraps text for PDF output.
	 *
	 * @param string $text Text.
	 * @param int    $width Approximate characters per line.
	 * @return array<int,string>
	 */
	private function wrap_pdf_text( string $text, int $width ): array {
		$lines = array();
		foreach ( preg_split( '/\R+/', $text ) ?: array() as $paragraph ) {
			$wrapped = explode( "\n", wordwrap( trim( (string) $paragraph ), $width, "\n", true ) );
			foreach ( $wrapped as $line ) {
				if ( '' !== trim( $line ) ) {
					$lines[] = $line;
				}
				if ( count( $lines ) >= 5 ) {
					return $lines;
				}
			}
		}

		return $lines;
	}

	/**
	 * Formats an amount for PDF text.
	 */
	private function format_pdf_price( float $amount, string $currency ): string {
		$text = html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( html_entity_decode( '&nbsp;', ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ' ', $text );
		return trim( str_replace( '€', 'EUR', $text ) );
	}

	/**
	 * Creates a download filename.
	 *
	 * @param array<string,mixed> $card Giftcard.
	 * @return string
	 */
	private function pdf_filename( array $card ): string {
		return sanitize_file_name( 'toko-lariso-giftcard-' . (string) ( $card['code_mask'] ?? 'giftcard' ) . '.pdf' );
	}
}
