<?php
/**
 * AJAX handler for Spread Em.
 *
 * Handles the single "save all changes" AJAX request sent by the
 * spreadsheet editor.  Each changed row is validated and written to the
 * WooCommerce product via the WC_Product API, mirroring the fields that
 * the WC CSV importer/exporter uses.
 *
 * @package SpreadEm
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SpreadEm_Ajax
 */
class SpreadEm_Ajax {

	/** @var string AJAX action name. */
	const ACTION = 'spread_em_save';

	/**
	 * Register AJAX hooks (logged-in users only).
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle_save' ] );
	}

	/**
	 * Handle the save request.
	 *
	 * Expects a POST body with:
	 *   nonce   – security nonce
	 *   changes – JSON-encoded array of changed product rows
	 *             Each row must contain at least an "id" key.
	 */
	public static function handle_save(): void {
		// 1. Verify nonce.
		if ( ! check_ajax_referer( 'spread_em_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		// 2. Verify capability.
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit products.', 'spread-em' ) ], 403 );
		}

		// 3. Decode and validate the changes payload.
		$raw_changes = isset( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : '';
		$changes     = json_decode( $raw_changes, true );

		if ( ! is_array( $changes ) || empty( $changes ) ) {
			wp_send_json_error( [ 'message' => __( 'No changes received.', 'spread-em' ) ], 400 );
		}

		// 4. Process each changed row.
		$saved  = [];
		$errors = [];

		foreach ( $changes as $row ) {
			if ( empty( $row['id'] ) || ! is_numeric( $row['id'] ) ) {
				$errors[] = __( 'Row is missing a valid product ID and was skipped.', 'spread-em' );
				continue;
			}

			$product_id = (int) $row['id'];
			$product    = wc_get_product( $product_id );

			if ( ! $product ) {
				/* translators: %d: product ID */
				$errors[] = sprintf( __( 'Product %d not found.', 'spread-em' ), $product_id );
				continue;
			}

			$result = self::apply_row_to_product( $product, $row );

			if ( is_wp_error( $result ) ) {
				/* translators: %1$d: product ID, %2$s: error message */
				$errors[] = sprintf( __( 'Product %1$d: %2$s', 'spread-em' ), $product_id, $result->get_error_message() );
			} else {
				$saved[] = $product_id;
			}
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				[
					'message' => implode( "\n", $errors ),
					'saved'   => $saved,
				],
				422
			);
		}

		wp_send_json_success( [ 'saved' => $saved ] );
	}

	/**
	 * Apply a single changed row's field values to a WC_Product and save it.
	 *
	 * Only fields that exist in the row array are updated; omitted fields are
	 * left unchanged.
	 *
	 * @param \WC_Product           $product The product to update.
	 * @param array<string, mixed>  $row     Associative array of field values.
	 * @return true|\WP_Error
	 */
	private static function apply_row_to_product( \WC_Product $product, array $row ) {
		// --- Sanitise and apply each supported field ---

		if ( array_key_exists( 'name', $row ) ) {
			$product->set_name( sanitize_text_field( $row['name'] ) );
		}

		if ( array_key_exists( 'sku', $row ) ) {
			try {
				$product->set_sku( sanitize_text_field( $row['sku'] ) );
			} catch ( \WC_Data_Exception $e ) {
				return new \WP_Error( 'sku_error', $e->getMessage() );
			}
		}

		if ( array_key_exists( 'status', $row ) ) {
			$allowed_statuses = [ 'publish', 'draft', 'pending', 'private', 'trash' ];
			$status           = sanitize_key( $row['status'] );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$product->set_status( $status );
			}
		}

		if ( array_key_exists( 'catalog_visibility', $row ) ) {
			$allowed = [ 'visible', 'catalog', 'search', 'hidden' ];
			$vis     = sanitize_key( $row['catalog_visibility'] );
			if ( in_array( $vis, $allowed, true ) ) {
				$product->set_catalog_visibility( $vis );
			}
		}

		if ( array_key_exists( 'regular_price', $row ) ) {
			$product->set_regular_price( wc_format_decimal( $row['regular_price'] ) );
		}

		if ( array_key_exists( 'sale_price', $row ) ) {
			$sale = '' === trim( (string) $row['sale_price'] ) ? '' : wc_format_decimal( $row['sale_price'] );
			$product->set_sale_price( $sale );
		}

		if ( array_key_exists( 'tax_status', $row ) ) {
			$allowed = [ 'taxable', 'shipping', 'none' ];
			$ts      = sanitize_key( $row['tax_status'] );
			if ( in_array( $ts, $allowed, true ) ) {
				$product->set_tax_status( $ts );
			}
		}

		if ( array_key_exists( 'tax_class', $row ) ) {
			$product->set_tax_class( sanitize_text_field( $row['tax_class'] ) );
		}

		if ( array_key_exists( 'manage_stock', $row ) ) {
			$product->set_manage_stock( 'yes' === $row['manage_stock'] );
		}

		if ( array_key_exists( 'stock_status', $row ) ) {
			$allowed = [ 'instock', 'outofstock', 'onbackorder' ];
			$ss      = sanitize_key( $row['stock_status'] );
			if ( in_array( $ss, $allowed, true ) ) {
				$product->set_stock_status( $ss );
			}
		}

		if ( array_key_exists( 'stock_quantity', $row ) ) {
			$qty = '' === trim( (string) $row['stock_quantity'] ) ? null : (int) $row['stock_quantity'];
			$product->set_stock_quantity( $qty );
		}

		if ( array_key_exists( 'backorders', $row ) ) {
			$allowed = [ 'no', 'notify', 'yes' ];
			$bo      = sanitize_key( $row['backorders'] );
			if ( in_array( $bo, $allowed, true ) ) {
				$product->set_backorders( $bo );
			}
		}

		if ( array_key_exists( 'weight', $row ) ) {
			$product->set_weight( wc_format_decimal( $row['weight'] ) );
		}

		if ( array_key_exists( 'length', $row ) ) {
			$product->set_length( wc_format_decimal( $row['length'] ) );
		}

		if ( array_key_exists( 'width', $row ) ) {
			$product->set_width( wc_format_decimal( $row['width'] ) );
		}

		if ( array_key_exists( 'height', $row ) ) {
			$product->set_height( wc_format_decimal( $row['height'] ) );
		}

		if ( array_key_exists( 'short_description', $row ) ) {
			$product->set_short_description( wp_kses_post( $row['short_description'] ) );
		}

		if ( array_key_exists( 'description', $row ) ) {
			$product->set_description( wp_kses_post( $row['description'] ) );
		}

		// Persist the product.
		$result = $product->save();

		if ( ! $result ) {
			return new \WP_Error( 'save_failed', __( 'Could not save product.', 'spread-em' ) );
		}

		return true;
	}
}
