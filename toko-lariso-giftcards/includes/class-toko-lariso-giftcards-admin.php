<?php
/**
 * Admin screens.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin management UI.
 */
class Toko_Lariso_Giftcards_Admin {
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
	 * Email service.
	 *
	 * @var Toko_Lariso_Giftcards_Email
	 */
	private Toko_Lariso_Giftcards_Email $email;

	/**
	 * Constructor.
	 *
	 * @param Toko_Lariso_Giftcards_Settings   $settings Settings.
	 * @param Toko_Lariso_Giftcards_Repository $repository Repository.
	 * @param Toko_Lariso_Giftcards_Email      $email Email.
	 */
	public function __construct( Toko_Lariso_Giftcards_Settings $settings, Toko_Lariso_Giftcards_Repository $repository, Toko_Lariso_Giftcards_Email $email ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->email      = $email;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_posts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Adds WooCommerce submenu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Toko Lariso Giftcards', 'toko-lariso-giftcards' ),
			__( 'Toko Lariso Giftcards', 'toko-lariso-giftcards' ),
			'manage_woocommerce',
			'tokolariso-giftcards',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues admin CSS.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'tokolariso-giftcards' ) ) {
			return;
		}

		wp_enqueue_style( 'tokolariso-giftcards-admin', TOKO_LARISO_GIFTCARDS_URL . 'assets/css/admin.css', array(), TOKO_LARISO_GIFTCARDS_VERSION );
	}

	/**
	 * Handles admin POST actions.
	 *
	 * @return void
	 */
	public function handle_posts(): void {
		if ( empty( $_POST['tokolariso_giftcards_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage giftcards.', 'toko-lariso-giftcards' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['tokolariso_giftcards_action'] ) );

		try {
			if ( 'save_settings' === $action ) {
				check_admin_referer( 'tokolariso_giftcards_save_settings' );
				$this->settings->save( wp_unslash( $_POST['settings'] ?? array() ) );
				$this->redirect_notice( 'settings_saved' );
			}

			$giftcard_id = absint( $_POST['giftcard_id'] ?? 0 );

			if ( 'update_card' === $action ) {
				check_admin_referer( 'tokolariso_giftcards_update_card_' . $giftcard_id );
				$status     = sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) );
				$expires_at = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );
				if ( ! in_array( $status, array( 'active', 'used', 'expired', 'blocked' ), true ) ) {
					$status = 'active';
				}
				$this->repository->update_card(
					$giftcard_id,
					array(
						'status'     => $status,
						'expires_at' => $expires_at ? gmdate( 'Y-m-d H:i:s', strtotime( $expires_at . ' 23:59:59' ) ) : null,
					)
				);
				$this->redirect_notice( 'card_updated', $giftcard_id );
			}

			if ( 'adjust_card' === $action ) {
				check_admin_referer( 'tokolariso_giftcards_adjust_card_' . $giftcard_id );
				$amount = isset( $_POST['adjustment_amount'] ) ? wc_clean( wp_unslash( $_POST['adjustment_amount'] ) ) : 0;
				$reason = sanitize_textarea_field( wp_unslash( $_POST['adjustment_reason'] ?? '' ) );
				if ( '' === $reason ) {
					throw new InvalidArgumentException( __( 'A reason is required for manual adjustments.', 'toko-lariso-giftcards' ) );
				}
				$this->repository->adjust( $giftcard_id, (float) $amount, $reason );
				$this->redirect_notice( 'card_adjusted', $giftcard_id );
			}

			if ( 'resend_email' === $action ) {
				check_admin_referer( 'tokolariso_giftcards_resend_email_' . $giftcard_id );
				$card = $this->repository->get_by_id( $giftcard_id );
				if ( ! $card || ! $this->email->send_giftcard( $card ) ) {
					throw new RuntimeException( __( 'Giftcard email could not be resent.', 'toko-lariso-giftcards' ) );
				}
				$this->redirect_notice( 'email_resent', $giftcard_id );
			}
		} catch ( Throwable $exception ) {
			$this->redirect_notice( 'error', $giftcard_id ?? 0, $exception->getMessage() );
		}
	}

	/**
	 * Renders admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage giftcards.', 'toko-lariso-giftcards' ) );
		}

		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'giftcards' ) );
		echo '<div class="wrap tokolariso-giftcards-admin">';
		echo '<h1>' . esc_html__( 'Toko Lariso Giftcards', 'toko-lariso-giftcards' ) . '</h1>';
		echo '<p class="description tokolariso-giftcards-version">' . esc_html__( 'Version', 'toko-lariso-giftcards' ) . ' ' . esc_html( TOKO_LARISO_GIFTCARDS_VERSION ) . '</p>';
		$this->render_notices();
		$this->render_tabs( $tab );

		if ( 'settings' === $tab ) {
			$this->render_settings();
		} elseif ( ! empty( $_GET['giftcard_id'] ) ) {
			$this->render_card_detail( absint( $_GET['giftcard_id'] ) );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Renders tabs.
	 *
	 * @param string $active Active tab.
	 * @return void
	 */
	private function render_tabs( string $active ): void {
		$tabs = array(
			'giftcards' => __( 'Giftcards', 'toko-lariso-giftcards' ),
			'settings'  => __( 'Settings', 'toko-lariso-giftcards' ),
		);

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a class="nav-tab %s" href="%s">%s</a>',
				$active === $key ? 'nav-tab-active' : '',
				esc_url( admin_url( 'admin.php?page=tokolariso-giftcards&tab=' . $key ) ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Renders notices based on redirect query args.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		$notice = sanitize_key( wp_unslash( $_GET['tokolariso_notice'] ?? '' ) );
		if ( ! $notice ) {
			return;
		}

		$messages = array(
			'settings_saved' => __( 'Settings saved.', 'toko-lariso-giftcards' ),
			'card_updated'   => __( 'Giftcard updated.', 'toko-lariso-giftcards' ),
			'card_adjusted'  => __( 'Giftcard balance adjusted.', 'toko-lariso-giftcards' ),
			'email_resent'   => __( 'Giftcard email resent.', 'toko-lariso-giftcards' ),
		);

		if ( 'error' === $notice ) {
			$message = sanitize_text_field( wp_unslash( $_GET['message'] ?? __( 'Action failed.', 'toko-lariso-giftcards' ) ) );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}

		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	/**
	 * Renders giftcard list.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$cards  = $this->repository->search( $search );
		?>
		<form method="get" class="tokolariso-search">
			<input type="hidden" name="page" value="tokolariso-giftcards" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search code, recipient, or order ID', 'toko-lariso-giftcards' ); ?>" />
			<?php submit_button( __( 'Search', 'toko-lariso-giftcards' ), 'secondary', '', false ); ?>
		</form>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Initial balance', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Current balance', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Status', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Purchase order', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Recipient', 'toko-lariso-giftcards' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $cards ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No giftcards found.', 'toko-lariso-giftcards' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $cards as $card ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=tokolariso-giftcards&giftcard_id=' . (int) $card['id'] ) ); ?>"><?php echo esc_html( (string) $card['code_mask'] ); ?></a></td>
						<td><?php echo wp_kses_post( wc_price( (float) $card['initial_amount'], array( 'currency' => (string) $card['currency'] ) ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( (float) $card['current_balance'], array( 'currency' => (string) $card['currency'] ) ) ); ?></td>
						<td><?php echo esc_html( (string) $card['status'] ); ?></td>
						<td><?php echo esc_html( $card['expires_at'] ? wc_format_datetime( new WC_DateTime( (string) $card['expires_at'] ) ) : '-' ); ?></td>
						<td><?php $this->order_link( (int) ( $card['purchased_order_id'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) $card['recipient_email'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders card detail.
	 *
	 * @param int $giftcard_id Giftcard id.
	 * @return void
	 */
	private function render_card_detail( int $giftcard_id ): void {
		$card = $this->repository->get_by_id( $giftcard_id );
		if ( ! $card ) {
			echo '<p>' . esc_html__( 'Giftcard not found.', 'toko-lariso-giftcards' ) . '</p>';
			return;
		}

		$ledger = $this->repository->ledger_for_card( $giftcard_id );
		$code   = $this->repository->decrypt_card_code( $card );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=tokolariso-giftcards' ) ); ?>">&larr; <?php esc_html_e( 'Back to giftcards', 'toko-lariso-giftcards' ); ?></a></p>
		<div class="tokolariso-admin-grid">
			<section>
				<h2><?php echo esc_html( (string) $card['code_mask'] ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Full code', 'toko-lariso-giftcards' ); ?></th>
						<td>
							<?php if ( '' !== $code ) : ?>
								<input type="text" class="regular-text code" value="<?php echo esc_attr( $code ); ?>" readonly onclick="this.select();" />
								<p class="description"><?php esc_html_e( 'Admin-only full code for testing and manual recipient support. Keep this code private.', 'toko-lariso-giftcards' ); ?></p>
							<?php else : ?>
								<em><?php esc_html_e( 'Full code could not be decrypted.', 'toko-lariso-giftcards' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr><th><?php esc_html_e( 'Initial balance', 'toko-lariso-giftcards' ); ?></th><td><?php echo wp_kses_post( wc_price( (float) $card['initial_amount'], array( 'currency' => (string) $card['currency'] ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Current balance', 'toko-lariso-giftcards' ); ?></th><td><?php echo wp_kses_post( wc_price( (float) $card['current_balance'], array( 'currency' => (string) $card['currency'] ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Purchase order', 'toko-lariso-giftcards' ); ?></th><td><?php $this->order_link( (int) ( $card['purchased_order_id'] ?? 0 ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Recipient', 'toko-lariso-giftcards' ); ?></th><td><?php echo esc_html( (string) $card['recipient_name'] . ' <' . (string) $card['recipient_email'] . '>' ); ?></td></tr>
					<?php if ( ! empty( $card['image_url'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Selected image', 'toko-lariso-giftcards' ); ?></th><td><img class="tokolariso-card-thumb" src="<?php echo esc_url( (string) $card['image_url'] ); ?>" alt="" /></td></tr>
					<?php endif; ?>
				</table>
			</section>

			<section>
				<h2><?php esc_html_e( 'Status and expiry', 'toko-lariso-giftcards' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'tokolariso_giftcards_update_card_' . $giftcard_id ); ?>
					<input type="hidden" name="tokolariso_giftcards_action" value="update_card" />
					<input type="hidden" name="giftcard_id" value="<?php echo esc_attr( $giftcard_id ); ?>" />
					<p>
						<label for="tokolariso_status"><?php esc_html_e( 'Status', 'toko-lariso-giftcards' ); ?></label>
						<select id="tokolariso_status" name="status">
							<?php foreach ( array( 'active', 'used', 'expired', 'blocked' ) as $status ) : ?>
								<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $status, $card['status'] ); ?>><?php echo esc_html( $status ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="tokolariso_expires_at"><?php esc_html_e( 'Expiry date', 'toko-lariso-giftcards' ); ?></label>
						<input type="date" id="tokolariso_expires_at" name="expires_at" value="<?php echo esc_attr( $card['expires_at'] ? gmdate( 'Y-m-d', strtotime( (string) $card['expires_at'] ) ) : '' ); ?>" />
					</p>
					<?php submit_button( __( 'Update giftcard', 'toko-lariso-giftcards' ) ); ?>
				</form>

				<h2><?php esc_html_e( 'Manual correction', 'toko-lariso-giftcards' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'tokolariso_giftcards_adjust_card_' . $giftcard_id ); ?>
					<input type="hidden" name="tokolariso_giftcards_action" value="adjust_card" />
					<input type="hidden" name="giftcard_id" value="<?php echo esc_attr( $giftcard_id ); ?>" />
					<p><input type="number" step="0.01" name="adjustment_amount" placeholder="<?php esc_attr_e( 'Positive or negative amount', 'toko-lariso-giftcards' ); ?>" required /></p>
					<p><textarea name="adjustment_reason" rows="3" placeholder="<?php esc_attr_e( 'Reason', 'toko-lariso-giftcards' ); ?>" required></textarea></p>
					<?php submit_button( __( 'Apply correction', 'toko-lariso-giftcards' ), 'secondary' ); ?>
				</form>

				<h2><?php esc_html_e( 'Email', 'toko-lariso-giftcards' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'tokolariso_giftcards_resend_email_' . $giftcard_id ); ?>
					<input type="hidden" name="tokolariso_giftcards_action" value="resend_email" />
					<input type="hidden" name="giftcard_id" value="<?php echo esc_attr( $giftcard_id ); ?>" />
					<?php submit_button( __( 'Resend giftcard email', 'toko-lariso-giftcards' ), 'secondary' ); ?>
				</form>
			</section>
		</div>

		<h2><?php esc_html_e( 'Ledger', 'toko-lariso-giftcards' ); ?></h2>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Type', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Before', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'After', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Order', 'toko-lariso-giftcards' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'toko-lariso-giftcards' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ledger as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( wc_format_datetime( new WC_DateTime( (string) $entry['created_at'] ) ) ); ?></td>
						<td><?php echo esc_html( (string) $entry['mutation_type'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( (float) $entry['amount'], array( 'currency' => (string) $card['currency'] ) ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( (float) $entry['balance_before'], array( 'currency' => (string) $card['currency'] ) ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( (float) $entry['balance_after'], array( 'currency' => (string) $card['currency'] ) ) ); ?></td>
						<td><?php $this->order_link( (int) ( $entry['order_id'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) $entry['reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders settings.
	 *
	 * @return void
	 */
	private function render_settings(): void {
		$settings = $this->settings->all();
		?>
		<form method="post" class="tokolariso-settings-form">
			<?php wp_nonce_field( 'tokolariso_giftcards_save_settings' ); ?>
			<input type="hidden" name="tokolariso_giftcards_action" value="save_settings" />
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="expiry_days"><?php esc_html_e( 'Default validity', 'toko-lariso-giftcards' ); ?></label></th>
					<td><input id="expiry_days" name="settings[expiry_days]" type="number" min="1" value="<?php echo esc_attr( (string) $settings['expiry_days'] ); ?>" /> <?php esc_html_e( 'days', 'toko-lariso-giftcards' ); ?></td>
				</tr>
				<tr>
					<th><label for="fixed_amounts"><?php esc_html_e( 'Fixed amounts', 'toko-lariso-giftcards' ); ?></label></th>
					<td><input id="fixed_amounts" name="settings[fixed_amounts]" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['fixed_amounts'] ); ?>" /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Custom amount', 'toko-lariso-giftcards' ); ?></th>
					<td><label><input name="settings[allow_custom_amount]" type="checkbox" value="1" <?php checked( 'yes', $settings['allow_custom_amount'] ); ?> /> <?php esc_html_e( 'Allow customers to enter their own amount', 'toko-lariso-giftcards' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Multiple giftcards', 'toko-lariso-giftcards' ); ?></th>
					<td><label><input name="settings[allow_multiple_giftcards]" type="checkbox" value="1" <?php checked( 'yes', $settings['allow_multiple_giftcards'] ); ?> /> <?php esc_html_e( 'Allow more than one giftcard per cart', 'toko-lariso-giftcards' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="refund_behavior"><?php esc_html_e( 'Refund behavior', 'toko-lariso-giftcards' ); ?></label></th>
					<td>
						<select id="refund_behavior" name="settings[refund_behavior]">
							<option value="manual" <?php selected( 'manual', $settings['refund_behavior'] ); ?>><?php esc_html_e( 'Manual review only', 'toko-lariso-giftcards' ); ?></option>
							<option value="restore_full_refund" <?php selected( 'restore_full_refund', $settings['refund_behavior'] ); ?>><?php esc_html_e( 'Restore giftcard credit on full order refund', 'toko-lariso-giftcards' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Debug logging', 'toko-lariso-giftcards' ); ?></th>
					<td>
						<label><input name="settings[debug_logging]" type="checkbox" value="1" <?php checked( 'yes', $settings['debug_logging'] ); ?> /> <?php esc_html_e( 'Write giftcard checkout diagnostics to WooCommerce logs', 'toko-lariso-giftcards' ); ?></label>
						<p class="description"><?php esc_html_e( 'Use only while testing. Full giftcard codes are redacted; masked codes, order IDs, and amounts may be logged.', 'toko-lariso-giftcards' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Uninstall', 'toko-lariso-giftcards' ); ?></th>
					<td><label><input name="settings[delete_data_on_uninstall]" type="checkbox" value="1" <?php checked( 'yes', $settings['delete_data_on_uninstall'] ); ?> /> <?php esc_html_e( 'Delete giftcard tables and settings when the plugin is uninstalled', 'toko-lariso-giftcards' ); ?></label></td>
				</tr>
				<tr><th colspan="2"><h2><?php esc_html_e( 'Email template basics', 'toko-lariso-giftcards' ); ?></h2></th></tr>
				<tr>
					<th><label for="email_subject"><?php esc_html_e( 'Subject', 'toko-lariso-giftcards' ); ?></label></th>
					<td><input id="email_subject" name="settings[email_subject]" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['email_subject'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="email_heading"><?php esc_html_e( 'Heading', 'toko-lariso-giftcards' ); ?></label></th>
					<td><input id="email_heading" name="settings[email_heading]" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['email_heading'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="email_intro"><?php esc_html_e( 'Intro', 'toko-lariso-giftcards' ); ?></label></th>
					<td><textarea id="email_intro" name="settings[email_intro]" rows="3" class="large-text"><?php echo esc_textarea( (string) $settings['email_intro'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="email_button_label"><?php esc_html_e( 'Button label', 'toko-lariso-giftcards' ); ?></label></th>
					<td><input id="email_button_label" name="settings[email_button_label]" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['email_button_label'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="email_shop_url"><?php esc_html_e( 'Shop URL', 'toko-lariso-giftcards' ); ?></label></th>
					<td><input id="email_shop_url" name="settings[email_shop_url]" type="url" class="regular-text" value="<?php echo esc_url( (string) $settings['email_shop_url'] ); ?>" /></td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Available placeholders: {recipient_name}, {code}, {amount}, {balance}, {expires_at}, {message}.', 'toko-lariso-giftcards' ); ?></p>
			<?php submit_button( __( 'Save settings', 'toko-lariso-giftcards' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Outputs an admin order link.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	private function order_link( int $order_id ): void {
		if ( $order_id <= 0 ) {
			echo '-';
			return;
		}

		$url = function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
			: get_edit_post_link( $order_id );

		printf( '<a href="%s">#%d</a>', esc_url( (string) $url ), $order_id );
	}

	/**
	 * Redirects with notice query args.
	 *
	 * @param string $notice Notice key.
	 * @param int    $giftcard_id Giftcard id.
	 * @param string $message Error message.
	 * @return void
	 */
	private function redirect_notice( string $notice, int $giftcard_id = 0, string $message = '' ): void {
		$args = array(
			'page'               => 'tokolariso-giftcards',
			'tokolariso_notice'  => $notice,
		);
		if ( $giftcard_id ) {
			$args['giftcard_id'] = $giftcard_id;
		}
		if ( $message ) {
			$args['message'] = rawurlencode( $message );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
