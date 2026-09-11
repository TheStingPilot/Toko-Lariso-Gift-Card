<?php
/**
 * Activation and uninstall routines.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and optionally removes database tables.
 */
class Toko_Lariso_Giftcards_Activator {
	/**
	 * Runs activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		add_option( Toko_Lariso_Giftcards_Settings::OPTION_KEY, ( new Toko_Lariso_Giftcards_Settings() )->defaults(), '', false );
		add_rewrite_endpoint( 'giftcards', EP_ROOT | EP_PAGES );
		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation is intentionally non-destructive.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'tokolariso_giftcards_send_scheduled_email' );
		flush_rewrite_rules( false );
	}

	/**
	 * Runs uninstall tasks only when the explicit delete setting is enabled.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		$settings = get_option( Toko_Lariso_Giftcards_Settings::OPTION_KEY, array() );
		if ( ! is_array( $settings ) || 'yes' !== ( $settings['delete_data_on_uninstall'] ?? 'no' ) ) {
			return;
		}

		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tokolariso_giftcard_ledger" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tokolariso_giftcards" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( Toko_Lariso_Giftcards_Settings::OPTION_KEY );
		delete_option( 'tokolariso_giftcards_rewrite_version' );
	}

	/**
	 * Creates database tables via dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$giftcards       = $wpdb->prefix . 'tokolariso_giftcards';
		$ledger          = $wpdb->prefix . 'tokolariso_giftcard_ledger';

		$sql_giftcards = "CREATE TABLE {$giftcards} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code_hash char(64) NOT NULL,
			code_encrypted longtext NOT NULL,
			code_mask varchar(64) NOT NULL,
			initial_amount decimal(26,8) NOT NULL DEFAULT 0,
			current_balance decimal(26,8) NOT NULL DEFAULT 0,
			currency varchar(10) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			expires_at datetime NULL DEFAULT NULL,
			issued_at datetime NULL DEFAULT NULL,
			purchased_order_id bigint(20) unsigned NULL DEFAULT NULL,
			purchased_order_item_id bigint(20) unsigned NULL DEFAULT NULL,
			recipient_name varchar(200) NULL DEFAULT NULL,
			recipient_email varchar(320) NULL DEFAULT NULL,
			message text NULL,
			image_id bigint(20) unsigned NULL DEFAULT NULL,
			image_url text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code_hash (code_hash),
			KEY status (status),
			KEY expires_at (expires_at),
			KEY purchased_order_id (purchased_order_id),
			KEY recipient_email (recipient_email)
		) {$charset_collate};";

		$sql_ledger = "CREATE TABLE {$ledger} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			giftcard_id bigint(20) unsigned NOT NULL,
			mutation_type varchar(20) NOT NULL,
			amount decimal(26,8) NOT NULL DEFAULT 0,
			balance_before decimal(26,8) NOT NULL DEFAULT 0,
			balance_after decimal(26,8) NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NULL DEFAULT NULL,
			user_id bigint(20) unsigned NULL DEFAULT NULL,
			reason text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY giftcard_id (giftcard_id),
			KEY mutation_type (mutation_type),
			KEY order_id (order_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_giftcards );
		dbDelta( $sql_ledger );
	}
}
