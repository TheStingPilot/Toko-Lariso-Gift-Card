<?php
/**
 * My Account giftcard balance checker.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a customer-facing balance lookup without exposing full codes.
 */
class Toko_Lariso_Giftcards_My_Account {
	private const ENDPOINT               = 'giftcards';
	private const REWRITE_VERSION_OPTION = 'tokolariso_giftcards_rewrite_version';

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
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite_rules' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );
		add_shortcode( 'tokolariso_giftcard_balance', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Enqueues minimal customer-facing account styles.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'tokolariso-giftcards-my-account',
			TOKO_LARISO_GIFTCARDS_URL . 'assets/css/my-account.css',
			array(),
			TOKO_LARISO_GIFTCARDS_VERSION
		);
	}

	/**
	 * Registers the My Account endpoint.
	 *
	 * @return void
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flushes rewrite rules once per plugin version.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::REWRITE_VERSION_OPTION ) === TOKO_LARISO_GIFTCARDS_VERSION ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, TOKO_LARISO_GIFTCARDS_VERSION, false );
	}

	/**
	 * Adds query vars.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Adds menu item to WooCommerce My Account.
	 *
	 * @param array<string,string> $items Menu items.
	 * @return array<string,string>
	 */
	public function add_menu_item( array $items ): array {
		$updated = array();

		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$updated[ self::ENDPOINT ] = __( 'Giftcards', 'toko-lariso-giftcards' );
			}
			$updated[ $key ] = $label;
		}

		if ( ! isset( $updated[ self::ENDPOINT ] ) ) {
			$updated[ self::ENDPOINT ] = __( 'Giftcards', 'toko-lariso-giftcards' );
		}

		return $updated;
	}

	/**
	 * Renders endpoint content.
	 *
	 * @return void
	 */
	public function render_endpoint(): void {
		echo $this->render_balance_checker(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Shortcode callback.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		return $this->render_balance_checker();
	}

	/**
	 * Renders balance checker form and result.
	 *
	 * @return string
	 */
	private function render_balance_checker(): string {
		$result        = null;
		$error         = '';
		$result_notice = '';
		$code          = '';

		if ( isset( $_POST['tokolariso_giftcard_balance_action'] ) ) {
			$nonce = isset( $_POST['tokolariso_giftcard_balance_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_balance_nonce'] ) ) : '';
			$code  = isset( $_POST['tokolariso_giftcard_balance_code'] ) ? sanitize_text_field( wp_unslash( $_POST['tokolariso_giftcard_balance_code'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'tokolariso_giftcard_balance' ) ) {
				$error = __( 'Security check failed. Please try again.', 'toko-lariso-giftcards' );
			} elseif ( '' === trim( $code ) ) {
				$error = __( 'Please enter a giftcard code.', 'toko-lariso-giftcards' );
			} else {
				$result = $this->lookup_card( $code );
				if ( ! $result ) {
					$error = __( 'Giftcard code was not found or cannot be checked.', 'toko-lariso-giftcards' );
				} elseif ( 'expired' === (string) $result['status'] ) {
					$result_notice = __( 'This giftcard has been expired and can no longer be used.', 'toko-lariso-giftcards' );
				} elseif ( (float) $result['current_balance'] <= 0 ) {
					$result_notice = __( 'The full balance of this giftcard has already been used.', 'toko-lariso-giftcards' );
				}
			}
		}

		ob_start();
		?>
		<div class="tokolariso-giftcard-balance-checker">
			<h3><?php esc_html_e( 'Check giftcard balance', 'toko-lariso-giftcards' ); ?></h3>
			<form method="post" class="tokolariso-giftcard-balance-form">
				<?php wp_nonce_field( 'tokolariso_giftcard_balance', 'tokolariso_giftcard_balance_nonce' ); ?>
				<input type="hidden" name="tokolariso_giftcard_balance_action" value="check" />
				<p class="form-row form-row-wide">
					<label for="tokolariso-giftcard-balance-code"><?php esc_html_e( 'Giftcard code', 'toko-lariso-giftcards' ); ?></label>
					<input
						type="text"
						id="tokolariso-giftcard-balance-code"
						name="tokolariso_giftcard_balance_code"
						value="<?php echo esc_attr( $code ); ?>"
						autocomplete="off"
					/>
				</p>
				<p>
					<button type="submit" class="button wp-element-button"><?php esc_html_e( 'Check balance', 'toko-lariso-giftcards' ); ?></button>
				</p>
			</form>

			<?php if ( $error ) : ?>
				<div class="woocommerce-error" role="alert"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<?php if ( $result_notice ) : ?>
				<div class="woocommerce-info" role="status"><?php echo esc_html( $result_notice ); ?></div>
			<?php endif; ?>

			<?php if ( is_array( $result ) ) : ?>
				<table class="shop_table shop_table_responsive tokolariso-giftcard-balance-result">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Code', 'toko-lariso-giftcards' ); ?></th>
							<td><?php echo esc_html( (string) $result['code_mask'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Current balance', 'toko-lariso-giftcards' ); ?></th>
							<td><?php echo wp_kses_post( wc_price( (float) $result['current_balance'], array( 'currency' => (string) $result['currency'] ) ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', 'toko-lariso-giftcards' ); ?></th>
							<td><?php echo esc_html( $this->status_label( (string) $result['status'] ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Expiry date', 'toko-lariso-giftcards' ); ?></th>
							<td><?php echo esc_html( $this->format_expiry( $result['expires_at'] ?? null ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<?php $this->render_activity_table( $result ); ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Looks up a giftcard for customer display.
	 *
	 * @param string $code Giftcard code.
	 * @return array<string,mixed>|null
	 */
	private function lookup_card( string $code ): ?array {
		$card = $this->repository->get_by_code( $code );
		if ( ! $card ) {
			return null;
		}

		if ( $this->repository->is_expired_by_date( $card ) ) {
			$this->repository->expire( (int) $card['id'] );
			$card = $this->repository->get_by_id( (int) $card['id'] );
		}

		return $card;
	}

	/**
	 * Renders customer-facing giftcard activity.
	 *
	 * @param array<string,mixed> $card Giftcard row.
	 * @return void
	 */
	private function render_activity_table( array $card ): void {
		$activity = $this->customer_activity_for_card( (int) $card['id'] );
		?>
		<h3><?php esc_html_e( 'Spending history', 'toko-lariso-giftcards' ); ?></h3>

		<?php if ( ! $activity ) : ?>
			<p><?php esc_html_e( 'No spending has been recorded for this giftcard yet.', 'toko-lariso-giftcards' ); ?></p>
			<?php
			return;
		endif;
		?>

		<table class="woocommerce-table woocommerce-table--giftcard-activity shop_table shop_table_responsive my_account_orders account-orders-table tokolariso-giftcard-activity">
			<thead>
				<tr>
					<th scope="col" class="woocommerce-table__header woocommerce-table__header--date"><?php esc_html_e( 'Date', 'toko-lariso-giftcards' ); ?></th>
					<th scope="col" class="woocommerce-table__header woocommerce-table__header--type"><?php esc_html_e( 'Type', 'toko-lariso-giftcards' ); ?></th>
					<th scope="col" class="woocommerce-table__header woocommerce-table__header--reference"><?php esc_html_e( 'Reference', 'toko-lariso-giftcards' ); ?></th>
					<th scope="col" class="woocommerce-table__header woocommerce-table__header--amount tokolariso-giftcard-amount-column"><?php esc_html_e( 'Amount', 'toko-lariso-giftcards' ); ?></th>
					<th scope="col" class="woocommerce-table__header woocommerce-table__header--balance tokolariso-giftcard-amount-column"><?php esc_html_e( 'Balance after', 'toko-lariso-giftcards' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $activity as $entry ) : ?>
					<tr>
						<td class="woocommerce-table__cell woocommerce-table__cell--date" data-title="<?php esc_attr_e( 'Date', 'toko-lariso-giftcards' ); ?>"><?php echo wp_kses_post( $this->format_activity_datetime( $entry['created_at'] ?? '' ) ); ?></td>
						<td class="woocommerce-table__cell woocommerce-table__cell--type" data-title="<?php esc_attr_e( 'Type', 'toko-lariso-giftcards' ); ?>"><?php echo esc_html( $this->activity_label( (string) ( $entry['mutation_type'] ?? '' ) ) ); ?></td>
						<td class="woocommerce-table__cell woocommerce-table__cell--reference" data-title="<?php esc_attr_e( 'Reference', 'toko-lariso-giftcards' ); ?>"><?php echo esc_html( $this->activity_reference( $entry ) ); ?></td>
						<td class="woocommerce-table__cell woocommerce-table__cell--amount tokolariso-giftcard-money-cell" data-title="<?php esc_attr_e( 'Amount', 'toko-lariso-giftcards' ); ?>"><?php echo wp_kses_post( $this->format_activity_amount( (float) ( $entry['amount'] ?? 0 ), (string) $card['currency'] ) ); ?></td>
						<td class="woocommerce-table__cell woocommerce-table__cell--balance tokolariso-giftcard-money-cell" data-title="<?php esc_attr_e( 'Balance after', 'toko-lariso-giftcards' ); ?>"><?php echo wp_kses_post( $this->format_activity_amount( (float) ( $entry['balance_after'] ?? 0 ), (string) $card['currency'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Gets customer-facing activity rows.
	 *
	 * @param int $giftcard_id Giftcard id.
	 * @return array<int,array<string,mixed>>
	 */
	private function customer_activity_for_card( int $giftcard_id ): array {
		return array_values(
			array_filter(
				$this->repository->ledger_for_card( $giftcard_id ),
				static fn( array $entry ): bool => in_array(
					(string) ( $entry['mutation_type'] ?? '' ),
					array( 'redeemed', 'refunded', 'adjusted', 'expired' ),
					true
				)
			)
		);
	}

	/**
	 * Formats activity type.
	 *
	 * @param string $type Mutation type.
	 * @return string
	 */
	private function activity_label( string $type ): string {
		return match ( $type ) {
			'redeemed' => __( 'Spent', 'toko-lariso-giftcards' ),
			'refunded' => __( 'Refunded', 'toko-lariso-giftcards' ),
			'adjusted' => __( 'Adjusted', 'toko-lariso-giftcards' ),
			'expired' => __( 'Expired', 'toko-lariso-giftcards' ),
			default => __( 'Activity', 'toko-lariso-giftcards' ),
		};
	}

	/**
	 * Formats activity reference.
	 *
	 * @param array<string,mixed> $entry Ledger entry.
	 * @return string
	 */
	private function activity_reference( array $entry ): string {
		$order_id = (int) ( $entry['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return '-';
		}

		return sprintf(
			/* translators: %d: order id */
			__( 'Order #%d', 'toko-lariso-giftcards' ),
			$order_id
		);
	}

	/**
	 * Formats activity money as a non-wrapping amount.
	 *
	 * @param float  $amount Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_activity_amount( float $amount, string $currency ): string {
		$formatted = wc_price( abs( $amount ), array( 'currency' => $currency ) );
		if ( $amount < 0 ) {
			$formatted = '- ' . $formatted;
		}

		return '<span class="tokolariso-giftcard-money">' . $formatted . '</span>';
	}

	/**
	 * Formats activity date and time using WooCommerce display settings.
	 *
	 * @param mixed $created_at Created date.
	 * @return string
	 */
	private function format_activity_datetime( mixed $created_at ): string {
		$timestamp = strtotime( (string) $created_at );
		if ( ! $timestamp ) {
			return '-';
		}

		$date_format = function_exists( 'wc_date_format' ) ? wc_date_format() : get_option( 'date_format' );
		$time_format = function_exists( 'wc_time_format' ) ? wc_time_format() : get_option( 'time_format' );

		return sprintf(
			'<span class="tokolariso-giftcard-activity-date">%s</span><br><span class="tokolariso-giftcard-activity-time">%s</span>',
			esc_html( wp_date( $date_format, $timestamp ) ),
			esc_html( wp_date( $time_format, $timestamp ) )
		);
	}

	/**
	 * Formats a giftcard status for customers.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_label( string $status ): string {
		return match ( $status ) {
			'active' => __( 'Active', 'toko-lariso-giftcards' ),
			'used' => __( 'Used', 'toko-lariso-giftcards' ),
			'expired' => __( 'Expired', 'toko-lariso-giftcards' ),
			'blocked' => __( 'Blocked', 'toko-lariso-giftcards' ),
			default => __( 'Unknown', 'toko-lariso-giftcards' ),
		};
	}

	/**
	 * Formats expiry for customer display.
	 *
	 * @param mixed $expires_at Expiry date.
	 * @return string
	 */
	private function format_expiry( mixed $expires_at ): string {
		if ( empty( $expires_at ) ) {
			return __( 'No expiry date', 'toko-lariso-giftcards' );
		}

		$timestamp = strtotime( (string) $expires_at );
		if ( ! $timestamp ) {
			return __( 'No expiry date', 'toko-lariso-giftcards' );
		}

		return wp_date( get_option( 'date_format' ), $timestamp );
	}
}
