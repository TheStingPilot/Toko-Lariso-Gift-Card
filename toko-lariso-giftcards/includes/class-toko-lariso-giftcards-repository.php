<?php
/**
 * Giftcard repository.
 *
 * @package TokoLarisoGiftcards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access and balance mutation logic.
 */
class Toko_Lariso_Giftcards_Repository {
	/**
	 * Giftcard table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tokolariso_giftcards';
	}

	/**
	 * Ledger table name.
	 *
	 * @return string
	 */
	public function ledger_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tokolariso_giftcard_ledger';
	}

	/**
	 * Normalizes a code for hashing and lookup.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public function normalize_code( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $code ) ?? '' );
	}

	/**
	 * Hashes a normalized giftcard code.
	 *
	 * @param string $code Raw or normalized code.
	 * @return string
	 */
	public function hash_code( string $code ): string {
		return hash_hmac( 'sha256', $this->normalize_code( $code ), wp_salt( 'auth' ) );
	}

	/**
	 * Generates a unique display code.
	 *
	 * @return string
	 */
	public function generate_unique_code(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$raw = 'TLG';
			for ( $i = 0; $i < 12; $i++ ) {
				$raw .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}

			$formatted = trim( chunk_split( $raw, 4, '-' ), '-' );
			if ( null === $this->get_by_code( $formatted ) ) {
				return $formatted;
			}
		}

		throw new RuntimeException( 'Unable to generate a unique giftcard code.' );
	}

	/**
	 * Masks a code for display.
	 *
	 * @param string $code Raw or normalized code.
	 * @return string
	 */
	public function mask_code( string $code ): string {
		$normalized = $this->normalize_code( $code );
		$last       = substr( $normalized, -4 );
		return 'TLG-****-****-' . $last;
	}

	/**
	 * Creates a giftcard and its issued ledger entry.
	 *
	 * @param array<string,mixed> $args Giftcard data.
	 * @return array<string,mixed>
	 */
	public function create_giftcard( array $args ): array {
		global $wpdb;

		$code     = (string) ( $args['code'] ?? $this->generate_unique_code() );
		$amount   = $this->normalize_amount( $args['amount'] ?? 0 );
		$currency = sanitize_text_field( (string) ( $args['currency'] ?? get_woocommerce_currency() ) );
		$now      = current_time( 'mysql', true );

		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Giftcard amount must be greater than zero.' );
		}

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'code_hash'               => $this->hash_code( $code ),
				'code_encrypted'          => $this->encrypt_code( $code ),
				'code_mask'               => $this->mask_code( $code ),
				'initial_amount'          => $amount,
				'current_balance'         => $amount,
				'currency'                => $currency,
				'status'                  => sanitize_key( (string) ( $args['status'] ?? 'active' ) ),
				'expires_at'              => $args['expires_at'] ?? null,
				'issued_at'               => $now,
				'purchased_order_id'      => isset( $args['purchased_order_id'] ) ? absint( $args['purchased_order_id'] ) : null,
				'purchased_order_item_id' => isset( $args['purchased_order_item_id'] ) ? absint( $args['purchased_order_item_id'] ) : null,
				'recipient_name'          => isset( $args['recipient_name'] ) ? sanitize_text_field( (string) $args['recipient_name'] ) : null,
				'recipient_email'         => isset( $args['recipient_email'] ) ? sanitize_email( (string) $args['recipient_email'] ) : null,
				'message'                 => isset( $args['message'] ) ? sanitize_textarea_field( (string) $args['message'] ) : null,
				'image_id'                => isset( $args['image_id'] ) ? absint( $args['image_id'] ) : null,
				'image_url'               => isset( $args['image_url'] ) ? esc_url_raw( (string) $args['image_url'] ) : null,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Giftcard could not be created.' );
		}

		$giftcard_id = (int) $wpdb->insert_id;
		$this->insert_ledger( $giftcard_id, 'issued', $amount, 0.0, $amount, isset( $args['purchased_order_id'] ) ? absint( $args['purchased_order_id'] ) : null, __( 'Giftcard issued after successful payment.', 'toko-lariso-giftcards' ) );

		$giftcard                = $this->get_by_id( $giftcard_id );
		$giftcard['plain_code'] = $code;

		return $giftcard;
	}

	/**
	 * Gets a giftcard by id.
	 *
	 * @param int $id Giftcard id.
	 * @return array<string,mixed>|null
	 */
	public function get_by_id( int $id ): ?array {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate_row( $row ) : null;
	}

	/**
	 * Gets a giftcard by exact code.
	 *
	 * @param string $code Raw code.
	 * @return array<string,mixed>|null
	 */
	public function get_by_code( string $code ): ?array {
		global $wpdb;
		$table = $this->table();
		$hash  = $this->hash_code( $code );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code_hash = %s", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate_row( $row ) : null;
	}

	/**
	 * Searches giftcards for admin.
	 *
	 * @param string $term Search term.
	 * @param int    $limit Result limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function search( string $term = '', int $limit = 50 ): array {
		global $wpdb;

		$table = $this->table();
		$limit = max( 1, min( 200, $limit ) );
		$term  = trim( $term );

		if ( '' === $term ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$hash = $this->hash_code( $term );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE code_hash = %s
						OR code_mask LIKE %s
						OR recipient_email LIKE %s
						OR recipient_name LIKE %s
						OR purchased_order_id = %d
					ORDER BY id DESC
					LIMIT %d",
					$hash,
					$like,
					$like,
					$like,
					absint( $term ),
					$limit
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return array_map( array( $this, 'hydrate_row' ), $rows ?: array() );
	}

	/**
	 * Gets ledger entries for a giftcard.
	 *
	 * @param int $giftcard_id Giftcard id.
	 * @return array<int,array<string,mixed>>
	 */
	public function ledger_for_card( int $giftcard_id ): array {
		global $wpdb;
		$table = $this->ledger_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE giftcard_id = %d ORDER BY id DESC", $giftcard_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		) ?: array();
	}

	/**
	 * Updates card metadata.
	 *
	 * @param int                 $giftcard_id Giftcard id.
	 * @param array<string,mixed> $data Data to update.
	 * @return bool
	 */
	public function update_card( int $giftcard_id, array $data ): bool {
		global $wpdb;

		$allowed = array( 'status', 'expires_at', 'recipient_name', 'recipient_email', 'message' );
		$update  = array( 'updated_at' => current_time( 'mysql', true ) );
		$formats = array( '%s' );

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$update[ $key ] = null === $data[ $key ] ? null : (string) $data[ $key ];
				$formats[]     = '%s';
			}
		}

		return false !== $wpdb->update( $this->table(), $update, array( 'id' => $giftcard_id ), $formats, array( '%d' ) );
	}

	/**
	 * Redeems credit with an atomic balance update.
	 *
	 * @param int         $giftcard_id Giftcard id.
	 * @param float|int   $amount Amount to redeem.
	 * @param int|null    $order_id Order id.
	 * @param string|null $reason Reason.
	 * @return array<string,mixed>
	 */
	public function redeem( int $giftcard_id, float|int $amount, ?int $order_id, ?string $reason = null ): array {
		$amount = $this->normalize_amount( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Redeem amount must be greater than zero.' );
		}

		return $this->mutate_balance( $giftcard_id, -1 * $amount, 'redeemed', $order_id, $reason ?: __( 'Redeemed against order.', 'toko-lariso-giftcards' ) );
	}

	/**
	 * Credits giftcard balance.
	 *
	 * @param int         $giftcard_id Giftcard id.
	 * @param float|int   $amount Amount to credit.
	 * @param string      $type Ledger type.
	 * @param int|null    $order_id Order id.
	 * @param string|null $reason Reason.
	 * @return array<string,mixed>
	 */
	public function credit( int $giftcard_id, float|int $amount, string $type = 'refunded', ?int $order_id = null, ?string $reason = null ): array {
		$amount = $this->normalize_amount( $amount );
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Credit amount must be greater than zero.' );
		}

		return $this->mutate_balance( $giftcard_id, $amount, $type, $order_id, $reason );
	}

	/**
	 * Applies an admin adjustment.
	 *
	 * @param int         $giftcard_id Giftcard id.
	 * @param float|int   $delta Signed adjustment.
	 * @param string|null $reason Reason.
	 * @return array<string,mixed>
	 */
	public function adjust( int $giftcard_id, float|int $delta, ?string $reason ): array {
		$delta = $this->normalize_amount( $delta );
		if ( 0.0 === $delta ) {
			throw new InvalidArgumentException( 'Adjustment amount cannot be zero.' );
		}

		return $this->mutate_balance( $giftcard_id, $delta, 'adjusted', null, $reason );
	}

	/**
	 * Expires a giftcard and moves remaining balance to an expired ledger entry.
	 *
	 * @param int $giftcard_id Giftcard id.
	 * @return void
	 */
	public function expire( int $giftcard_id ): void {
		$card = $this->get_by_id( $giftcard_id );
		if ( ! $card || 'expired' === $card['status'] ) {
			return;
		}

		$balance = (float) $card['current_balance'];
		if ( $balance > 0 ) {
			$this->mutate_balance( $giftcard_id, -1 * $balance, 'expired', null, __( 'Giftcard expired.', 'toko-lariso-giftcards' ) );
		}

		$this->update_card( $giftcard_id, array( 'status' => 'expired' ) );
	}

	/**
	 * Returns true when a card is expired by date.
	 *
	 * @param array<string,mixed> $card Giftcard row.
	 * @return bool
	 */
	public function is_expired_by_date( array $card ): bool {
		if ( empty( $card['expires_at'] ) ) {
			return false;
		}

		return strtotime( (string) $card['expires_at'] ) < time();
	}

	/**
	 * Decrypts the full giftcard code for admin-only resend flows.
	 *
	 * @param array<string,mixed> $card Giftcard row.
	 * @return string
	 */
	public function decrypt_card_code( array $card ): string {
		return $this->decrypt_code( (string) ( $card['code_encrypted'] ?? '' ) );
	}

	/**
	 * Formats an amount for storage.
	 *
	 * @param mixed $amount Amount.
	 * @return float
	 */
	public function normalize_amount( mixed $amount ): float {
		return (float) wc_format_decimal( $amount, wc_get_price_decimals() );
	}

	/**
	 * Runs a balance mutation inside a transaction.
	 *
	 * @param int         $giftcard_id Giftcard id.
	 * @param float       $delta Signed delta.
	 * @param string      $type Ledger type.
	 * @param int|null    $order_id Order id.
	 * @param string|null $reason Reason.
	 * @return array<string,mixed>
	 */
	private function mutate_balance( int $giftcard_id, float $delta, string $type, ?int $order_id, ?string $reason ): array {
		global $wpdb;

		$table = $this->table();
		$now   = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );

		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $giftcard_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				throw new RuntimeException( 'Giftcard not found.' );
			}

			$card = $this->hydrate_row( $row );
			if ( 'redeemed' === $type && $order_id ) {
				$ledger_table = $this->ledger_table();
				$existing     = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id FROM {$ledger_table} WHERE giftcard_id = %d AND order_id = %d AND mutation_type = 'redeemed' LIMIT 1",
						$giftcard_id,
						$order_id
					),
					ARRAY_A
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $existing ) {
					$wpdb->query( 'COMMIT' );
					$card              = $this->get_by_id( $giftcard_id );
					$card['ledger_id'] = (int) $existing['id'];
					return $card;
				}
			}

			if ( 'redeemed' === $type && ( 'active' !== $card['status'] || $this->is_expired_by_date( $card ) ) ) {
				throw new RuntimeException( 'Giftcard is not active.' );
			}

			$before = (float) $card['current_balance'];
			$after  = $this->normalize_amount( $before + $delta );

			if ( $after < 0 ) {
				throw new RuntimeException( 'Giftcard balance is too low.' );
			}

			$status = (string) $card['status'];
			if ( 'redeemed' === $type && 0.0 === $after ) {
				$status = 'used';
			} elseif ( in_array( $type, array( 'refunded', 'adjusted' ), true ) && $after > 0 && 'used' === $status ) {
				$status = 'active';
			}

			$updated = $wpdb->update(
				$table,
				array(
					'current_balance' => $after,
					'status'          => $status,
					'updated_at'      => $now,
				),
				array( 'id' => $giftcard_id ),
				array( '%f', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				throw new RuntimeException( 'Giftcard balance could not be updated.' );
			}

			$ledger_id = $this->insert_ledger( $giftcard_id, $type, $delta, $before, $after, $order_id, $reason );
			$wpdb->query( 'COMMIT' );

			$card              = $this->get_by_id( $giftcard_id );
			$card['ledger_id'] = $ledger_id;

			return $card;
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	/**
	 * Inserts a ledger row.
	 *
	 * @param int         $giftcard_id Giftcard id.
	 * @param string      $type Mutation type.
	 * @param float       $amount Signed amount.
	 * @param float       $before Balance before.
	 * @param float       $after Balance after.
	 * @param int|null    $order_id Order id.
	 * @param string|null $reason Reason.
	 * @return int
	 */
	private function insert_ledger( int $giftcard_id, string $type, float $amount, float $before, float $after, ?int $order_id, ?string $reason ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->ledger_table(),
			array(
				'giftcard_id'    => $giftcard_id,
				'mutation_type'  => sanitize_key( $type ),
				'amount'         => $amount,
				'balance_before' => $before,
				'balance_after'  => $after,
				'order_id'       => $order_id,
				'user_id'        => get_current_user_id() ?: null,
				'reason'         => $reason ? sanitize_textarea_field( $reason ) : null,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%f', '%f', '%f', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Ledger entry could not be created.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Hydrates numeric fields.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function hydrate_row( array $row ): array {
		$row['id']                       = (int) $row['id'];
		$row['initial_amount']           = (float) $row['initial_amount'];
		$row['current_balance']          = (float) $row['current_balance'];
		$row['purchased_order_id']       = $row['purchased_order_id'] ? (int) $row['purchased_order_id'] : null;
		$row['purchased_order_item_id']  = $row['purchased_order_item_id'] ? (int) $row['purchased_order_item_id'] : null;
		$row['image_id']                 = $row['image_id'] ? (int) $row['image_id'] : null;
		return $row;
	}

	/**
	 * Encrypts full codes at rest.
	 *
	 * @param string $code Plain code.
	 * @return string
	 */
	private function encrypt_code( string $code ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . base64_encode( $code );
		}

		$key = hash( 'sha256', wp_salt( 'secure_auth' ), true );
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $code, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return base64_encode( $iv . ( false === $enc ? '' : $enc ) );
	}

	/**
	 * Decrypts full codes.
	 *
	 * @param string $payload Encrypted payload.
	 * @return string
	 */
	private function decrypt_code( string $payload ): string {
		if ( str_starts_with( $payload, 'plain:' ) ) {
			return (string) base64_decode( substr( $payload, 6 ), true );
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( $payload, true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$key       = hash( 'sha256', wp_salt( 'secure_auth' ), true );
		$iv        = substr( $raw, 0, 16 );
		$encrypted = substr( $raw, 16 );
		$code      = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return false === $code ? '' : $code;
	}
}
