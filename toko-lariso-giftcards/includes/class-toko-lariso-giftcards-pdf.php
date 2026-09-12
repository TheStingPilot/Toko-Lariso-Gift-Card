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
	 * Constructor.
	 *
	 * @param Toko_Lariso_Giftcards_Settings   $settings Settings.
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
		$logo      = $this->jpeg_image_for_attachment_id( absint( $this->settings->get( 'pdf_logo_image_id', 0 ) ) );
		$image_id  = $image ? $this->add_pdf_object( $objects, $this->build_jpeg_object( $image ) ) : 0;
		$logo_id   = $logo ? $this->add_pdf_object( $objects, $this->build_jpeg_object( $logo ) ) : 0;
		$resources = '<< /Font << /F1 ' . $font_id . ' 0 R >>';
		if ( $image_id || $logo_id ) {
			$resources .= ' /XObject <<';
			if ( $image_id ) {
				$resources .= ' /Im1 ' . $image_id . ' 0 R';
			}
			if ( $logo_id ) {
				$resources .= ' /Logo ' . $logo_id . ' 0 R';
			}
			$resources .= ' >>';
		}
		$resources .= ' >>';

		$recipient = (string) $item->get_meta( '_tokolariso_giftcard_recipient_name', true );
		$sender    = (string) $item->get_meta( '_tokolariso_giftcard_sender_name', true );
		$message   = (string) $item->get_meta( '_tokolariso_giftcard_message', true );
		$expires   = ! empty( $card['expires_at'] ) ? wc_format_datetime( new WC_DateTime( (string) $card['expires_at'] ) ) : __( 'No expiry date', 'toko-lariso-giftcards' );
		$amount    = $this->format_pdf_price( (float) $card['initial_amount'], (string) $card['currency'] );
		$redeem_url = $this->redemption_url( $code );
		$qr_matrix  = $this->qr_matrix_for_text( $redeem_url );
		$header_rgb = $this->pdf_rgb_from_hex( (string) $this->settings->get( 'pdf_header_color', '#7d49b4' ), array( 0.49, 0.29, 0.71 ) );

		$content = '';
		$content .= $this->pdf_fill_rgb( 0.98, 0.97, 0.95 );
		$content .= $this->pdf_rect( 0, 0, $page_w, $page_h, 'f', $page_h );
		$content .= $this->pdf_fill_rgb( $header_rgb[0], $header_rgb[1], $header_rgb[2] );
		$content .= $this->pdf_rect( 0, 0, $page_w, 54, 'f', $page_h );
		if ( $logo_id && $logo ) {
			$content .= $this->pdf_image_fit( 'Logo', 34, 8, 112, 38, $logo, $page_h );
			$content .= $this->pdf_text( 166, 35, 22, 'Toko Lariso Cadeaubon', $page_h, 1, 1, 1 );
		} else {
			$content .= $this->pdf_text( 36, 35, 22, 'Toko Lariso Cadeaubon', $page_h, 1, 1, 1 );
		}

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
		$content .= $this->pdf_text( $x, 348, 13, __( 'Webshop link', 'toko-lariso-giftcards' ) . ':', $page_h, 0.49, 0.29, 0.71 );
		$link_y = 370;
		foreach ( array_slice( $this->wrap_pdf_text( $this->display_url_for_pdf( $redeem_url ), 31 ), 0, 5 ) as $line ) {
			$content .= $this->pdf_text( $x, $link_y, 9, $line, $page_h, 0.05, 0.09, 0.18 );
			$link_y += 12;
		}

		if ( $qr_matrix ) {
			$content .= $this->pdf_qr( $qr_matrix, 700, 344, 96, $page_h );
			$content .= $this->pdf_text( 684, 455, 10, __( 'Scan to use this giftcard', 'toko-lariso-giftcards' ), $page_h, 0.05, 0.09, 0.18 );
		}

		if ( $message ) {
			$content .= $this->pdf_text( $x, 482, 13, __( 'Greeting/message', 'toko-lariso-giftcards' ) . ':', $page_h, 0.49, 0.29, 0.71 );
			$line_y = 506;
			foreach ( array_slice( $this->wrap_pdf_text( $message, 44 ), 0, 3 ) as $line ) {
				$content .= $this->pdf_text( $x, $line_y, 13, $line, $page_h, 0.05, 0.09, 0.18 );
				$line_y += 18;
			}
		}

		$content_id    = $this->add_pdf_object( $objects, $this->build_stream_object( $content ) );
		$annotation_id = $this->add_pdf_object( $objects, $this->build_link_annotation( $redeem_url, 456, 342, 805, 462, $page_h ) );
		$page_id       = $this->add_pdf_object( $objects, '<< /Type /Page /Parent 0 0 R /MediaBox [0 0 ' . $page_w . ' ' . $page_h . '] /Resources ' . $resources . ' /Annots [' . $annotation_id . ' 0 R] /Contents ' . $content_id . ' 0 R >>' );
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
	 * Builds the URL that applies this giftcard code to the shopper session.
	 *
	 * @param string $code Plain giftcard code.
	 * @return string
	 */
	private function redemption_url( string $code ): string {
		$base = (string) $this->settings->get( 'giftcard_redeem_url', '' );
		if ( '' === trim( $base ) ) {
			$base = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );
		}

		return esc_url_raw( add_query_arg( 'tokolariso_giftcard', $code, $base ) );
	}

	/**
	 * Shortens a URL for readable PDF text while the QR keeps the full URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function display_url_for_pdf( string $url ): string {
		return untrailingslashit( $url );
	}

	/**
	 * Builds a clickable PDF URI annotation.
	 *
	 * @param string $url URL.
	 * @param float  $x1 Left.
	 * @param float  $y1 Top.
	 * @param float  $x2 Right.
	 * @param float  $y2 Bottom.
	 * @param float  $page_h Page height.
	 * @return string
	 */
	private function build_link_annotation( string $url, float $x1, float $y1, float $x2, float $y2, float $page_h ): string {
		$rect = sprintf( '[%.2F %.2F %.2F %.2F]', $x1, $page_h - $y2, $x2, $page_h - $y1 );
		return '<< /Type /Annot /Subtype /Link /Rect ' . $rect . ' /Border [0 0 0] /A << /S /URI /URI (' . $this->escape_pdf_text( $url ) . ') >> >>';
	}

	/**
	 * Converts a hex color to PDF RGB floats.
	 *
	 * @param string             $hex Hex color.
	 * @param array<int,float>   $fallback Fallback RGB floats.
	 * @return array<int,float>
	 */
	private function pdf_rgb_from_hex( string $hex, array $fallback ): array {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return $fallback;
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ) / 255,
			hexdec( substr( $hex, 2, 2 ) ) / 255,
			hexdec( substr( $hex, 4, 2 ) ) / 255,
		);
	}

	/**
	 * Gets JPEG image data for the giftcard, if available.
	 *
	 * @param array<string,mixed> $card Giftcard.
	 * @return array<string,mixed>|null
	 */
	private function jpeg_image_for_card( array $card ): ?array {
		$image_id = absint( $card['image_id'] ?? 0 );
		return $this->jpeg_image_for_attachment_id( $image_id );
	}

	/**
	 * Gets JPEG image data for an attachment, if available.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|null
	 */
	private function jpeg_image_for_attachment_id( int $attachment_id ): ?array {
		$path = $attachment_id ? get_attached_file( $attachment_id ) : '';
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
	 * PDF image command fitted inside a box without stretching.
	 *
	 * @param string              $name XObject name.
	 * @param float               $x Box x.
	 * @param float               $y Box y.
	 * @param float               $box_w Box width.
	 * @param float               $box_h Box height.
	 * @param array<string,mixed> $image Image metadata.
	 * @param float               $page_h Page height.
	 * @return string
	 */
	private function pdf_image_fit( string $name, float $x, float $y, float $box_w, float $box_h, array $image, float $page_h ): string {
		$width  = max( 1, (int) ( $image['width'] ?? 1 ) );
		$height = max( 1, (int) ( $image['height'] ?? 1 ) );
		$scale  = min( $box_w / $width, $box_h / $height );
		$w      = $width * $scale;
		$h      = $height * $scale;

		return $this->pdf_image( $name, $x + ( ( $box_w - $w ) / 2 ), $y + ( ( $box_h - $h ) / 2 ), $w, $h, $page_h );
	}

	/**
	 * Draws a QR matrix as vector rectangles.
	 *
	 * @param array<int,array<int,bool>> $matrix QR matrix.
	 * @param float                      $x X position.
	 * @param float                      $y Y position.
	 * @param float                      $size Printed size.
	 * @param float                      $page_h Page height.
	 * @return string
	 */
	private function pdf_qr( array $matrix, float $x, float $y, float $size, float $page_h ): string {
		$count  = count( $matrix );
		$module = $size / max( 1, $count );
		$out    = $this->pdf_fill_rgb( 1, 1, 1 );
		$out   .= $this->pdf_rect( $x - 4, $y - 4, $size + 8, $size + 8, 'f', $page_h );
		$out   .= $this->pdf_fill_rgb( 0.02, 0.02, 0.02 );

		foreach ( $matrix as $row => $cols ) {
			foreach ( $cols as $col => $dark ) {
				if ( $dark ) {
					$out .= $this->pdf_rect( $x + ( $col * $module ), $y + ( $row * $module ), $module + 0.02, $module + 0.02, 'f', $page_h );
				}
			}
		}

		return $out;
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
	 * Generates a fixed Version 6-L QR matrix for giftcard redeem URLs.
	 *
	 * @param string $text QR payload.
	 * @return array<int,array<int,bool>>|null
	 */
	private function qr_matrix_for_text( string $text ): ?array {
		$bytes = array_values( unpack( 'C*', $text ) ?: array() );
		if ( count( $bytes ) > 134 ) {
			Toko_Lariso_Giftcards_Debug::log( 'pdf_qr_payload_too_long', array( 'length' => count( $bytes ) ) );
			return null;
		}

		$data_codewords = $this->qr_data_codewords( $bytes );
		$blocks         = array( array_slice( $data_codewords, 0, 68 ), array_slice( $data_codewords, 68, 68 ) );
		$ecc_blocks     = array( $this->qr_ecc_codewords( $blocks[0], 18 ), $this->qr_ecc_codewords( $blocks[1], 18 ) );
		$codewords      = array();

		for ( $i = 0; $i < 68; $i++ ) {
			$codewords[] = $blocks[0][ $i ];
			$codewords[] = $blocks[1][ $i ];
		}
		for ( $i = 0; $i < 18; $i++ ) {
			$codewords[] = $ecc_blocks[0][ $i ];
			$codewords[] = $ecc_blocks[1][ $i ];
		}

		return $this->qr_build_matrix( $codewords );
	}

	/**
	 * Encodes bytes as QR byte-mode data codewords for Version 6-L.
	 *
	 * @param array<int,int> $bytes Payload bytes.
	 * @return array<int,int>
	 */
	private function qr_data_codewords( array $bytes ): array {
		$bits = array();
		foreach ( array( 0, 1, 0, 0 ) as $bit ) {
			$bits[] = $bit;
		}
		for ( $i = 7; $i >= 0; $i-- ) {
			$bits[] = ( count( $bytes ) >> $i ) & 1;
		}
		foreach ( $bytes as $byte ) {
			for ( $i = 7; $i >= 0; $i-- ) {
				$bits[] = ( $byte >> $i ) & 1;
			}
		}

		$capacity_bits = 136 * 8;
		$terminator    = min( 4, $capacity_bits - count( $bits ) );
		for ( $i = 0; $i < $terminator; $i++ ) {
			$bits[] = 0;
		}
		while ( count( $bits ) % 8 ) {
			$bits[] = 0;
		}

		$codewords = array();
		foreach ( array_chunk( $bits, 8 ) as $chunk ) {
			$value = 0;
			foreach ( $chunk as $bit ) {
				$value = ( $value << 1 ) | $bit;
			}
			$codewords[] = $value;
		}
		foreach ( array( 0xec, 0x11 ) as $pad ) {
			if ( count( $codewords ) >= 136 ) {
				break;
			}
			$codewords[] = $pad;
			if ( count( $codewords ) < 136 ) {
				$codewords[] = 0x11 === $pad ? 0xec : 0x11;
			}
		}
		while ( count( $codewords ) < 136 ) {
			$codewords[] = count( $codewords ) % 2 ? 0x11 : 0xec;
		}

		return array_slice( $codewords, 0, 136 );
	}

	/**
	 * Builds Reed-Solomon error correction codewords.
	 *
	 * @param array<int,int> $data Data codewords.
	 * @param int            $degree Number of ECC codewords.
	 * @return array<int,int>
	 */
	private function qr_ecc_codewords( array $data, int $degree ): array {
		$generator = array( 1 );
		for ( $i = 0; $i < $degree; $i++ ) {
			$next = array_fill( 0, count( $generator ) + 1, 0 );
			foreach ( $generator as $j => $coef ) {
				$next[ $j ]     ^= $this->qr_gf_multiply( $coef, 1 );
				$next[ $j + 1 ] ^= $this->qr_gf_multiply( $coef, $this->qr_gf_exp( $i ) );
			}
			$generator = $next;
		}

		$remainder = array_fill( 0, $degree, 0 );
		foreach ( $data as $byte ) {
			$factor = $byte ^ $remainder[0];
			array_shift( $remainder );
			$remainder[] = 0;
			for ( $i = 0; $i < $degree; $i++ ) {
				$remainder[ $i ] ^= $this->qr_gf_multiply( $generator[ $i + 1 ], $factor );
			}
		}

		return $remainder;
	}

	/**
	 * Builds the QR matrix with fixed mask 0 and Version 6 function patterns.
	 *
	 * @param array<int,int> $codewords Data plus ECC codewords.
	 * @return array<int,array<int,bool>>
	 */
	private function qr_build_matrix( array $codewords ): array {
		$size     = 41;
		$matrix   = array_fill( 0, $size, array_fill( 0, $size, false ) );
		$reserved = array_fill( 0, $size, array_fill( 0, $size, false ) );

		$this->qr_add_finder( $matrix, $reserved, 0, 0 );
		$this->qr_add_finder( $matrix, $reserved, $size - 7, 0 );
		$this->qr_add_finder( $matrix, $reserved, 0, $size - 7 );
		$this->qr_add_alignment( $matrix, $reserved, 34, 34 );

		for ( $i = 8; $i < $size - 8; $i++ ) {
			$dark = 0 === $i % 2;
			$this->qr_set( $matrix, $reserved, $i, 6, $dark, true );
			$this->qr_set( $matrix, $reserved, 6, $i, $dark, true );
		}
		$this->qr_reserve_format( $reserved );
		$this->qr_set( $matrix, $reserved, 8, 33, true, true );

		$bits = array();
		foreach ( $codewords as $byte ) {
			for ( $i = 7; $i >= 0; $i-- ) {
				$bits[] = ( $byte >> $i ) & 1;
			}
		}

		$bit_index = 0;
		$upward    = true;
		for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right--;
			}
			for ( $vert = 0; $vert < $size; $vert++ ) {
				$row = $upward ? $size - 1 - $vert : $vert;
				for ( $j = 0; $j < 2; $j++ ) {
					$col = $right - $j;
					if ( $reserved[ $row ][ $col ] ) {
						continue;
					}

					$dark = isset( $bits[ $bit_index ] ) && 1 === $bits[ $bit_index ];
					$dark = $dark !== ( 0 === ( ( $row + $col ) % 2 ) );
					$this->qr_set( $matrix, $reserved, $col, $row, $dark, true );
					$bit_index++;
				}
			}
			$upward = ! $upward;
		}

		$this->qr_add_format_bits( $matrix, $reserved, 0x77c4 );
		return $matrix;
	}

	/**
	 * Adds a finder pattern with separator.
	 */
	private function qr_add_finder( array &$matrix, array &$reserved, int $x, int $y ): void {
		for ( $dy = -1; $dy <= 7; $dy++ ) {
			for ( $dx = -1; $dx <= 7; $dx++ ) {
				$xx = $x + $dx;
				$yy = $y + $dy;
				if ( $xx < 0 || $yy < 0 || $xx >= 41 || $yy >= 41 ) {
					continue;
				}
				$dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6 && ( 0 === $dx || 6 === $dx || 0 === $dy || 6 === $dy || ( $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4 ) );
				$this->qr_set( $matrix, $reserved, $xx, $yy, $dark, true );
			}
		}
	}

	/**
	 * Adds an alignment pattern.
	 */
	private function qr_add_alignment( array &$matrix, array &$reserved, int $cx, int $cy ): void {
		for ( $dy = -2; $dy <= 2; $dy++ ) {
			for ( $dx = -2; $dx <= 2; $dx++ ) {
				$dark = 2 === max( abs( $dx ), abs( $dy ) ) || ( 0 === $dx && 0 === $dy );
				$this->qr_set( $matrix, $reserved, $cx + $dx, $cy + $dy, $dark, true );
			}
		}
	}

	/**
	 * Reserves format bit coordinates.
	 */
	private function qr_reserve_format( array &$reserved ): void {
		for ( $i = 0; $i < 9; $i++ ) {
			if ( 6 !== $i ) {
				$reserved[8][ $i ] = true;
				$reserved[ $i ][8] = true;
			}
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$reserved[40 - $i][8] = true;
			$reserved[8][40 - $i] = true;
		}
	}

	/**
	 * Adds format bits for level L, mask 0.
	 */
	private function qr_add_format_bits( array &$matrix, array &$reserved, int $format ): void {
		$bits = array();
		for ( $i = 14; $i >= 0; $i-- ) {
			$bits[] = 1 === ( ( $format >> $i ) & 1 );
		}

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->qr_set( $matrix, $reserved, $i, 8, $bits[ $i ], true );
		}
		$this->qr_set( $matrix, $reserved, 7, 8, $bits[6], true );
		$this->qr_set( $matrix, $reserved, 8, 8, $bits[7], true );
		$this->qr_set( $matrix, $reserved, 8, 7, $bits[8], true );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->qr_set( $matrix, $reserved, 8, 14 - $i, $bits[ $i ], true );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->qr_set( $matrix, $reserved, 8, 40 - $i, $bits[ $i ], true );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->qr_set( $matrix, $reserved, 26 + $i, 8, $bits[ $i ], true );
		}
	}

	/**
	 * Sets a QR module.
	 */
	private function qr_set( array &$matrix, array &$reserved, int $x, int $y, bool $dark, bool $reserve ): void {
		if ( $x < 0 || $y < 0 || $x >= 41 || $y >= 41 ) {
			return;
		}

		$matrix[ $y ][ $x ] = $dark;
		if ( $reserve ) {
			$reserved[ $y ][ $x ] = true;
		}
	}

	/**
	 * Galois-field exponent.
	 */
	private function qr_gf_exp( int $power ): int {
		$tables = $this->qr_gf_tables();
		return $tables['exp'][ $power % 255 ];
	}

	/**
	 * Galois-field multiply.
	 */
	private function qr_gf_multiply( int $a, int $b ): int {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}

		$tables = $this->qr_gf_tables();
		return $tables['exp'][ ( $tables['log'][ $a ] + $tables['log'][ $b ] ) % 255 ];
	}

	/**
	 * Galois-field lookup tables for QR Reed-Solomon.
	 *
	 * @return array<string,array<int,int>>
	 */
	private function qr_gf_tables(): array {
		static $tables = null;
		if ( null !== $tables ) {
			return $tables;
		}

		$exp = array_fill( 0, 512, 0 );
		$log = array_fill( 0, 256, 0 );
		$x   = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			$exp[ $i ] = $x;
			$log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11d;
			}
		}
		for ( $i = 255; $i < 512; $i++ ) {
			$exp[ $i ] = $exp[ $i - 255 ];
		}

		$tables = array(
			'exp' => $exp,
			'log' => $log,
		);
		return $tables;
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
