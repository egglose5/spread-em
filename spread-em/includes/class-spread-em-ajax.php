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
		$save_state_id = sanitize_key( wp_generate_uuid4() );
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

			// Snapshot the current field values before applying the change.
			$old_snapshot = self::snapshot_product( $product, $row );

			$result = self::apply_row_to_product( $product, $row );

			if ( is_wp_error( $result ) ) {
				/* translators: %1$d: product ID, %2$s: error message */
				$errors[] = sprintf( __( 'Product %1$d: %2$s', 'spread-em' ), $product_id, $result->get_error_message() );
			} else {
				$saved[] = $product_id;

				// Log each field that actually changed.
				if ( class_exists( 'SpreadEm_Logger' ) ) {
					$user_id      = get_current_user_id();
					$saved_product = wc_get_product( $product_id );

					if ( $saved_product ) {
						$new_snapshot = self::snapshot_product( $saved_product, $row );

						foreach ( $old_snapshot as $field => $old_value ) {
							$new_value = isset( $new_snapshot[ $field ] ) ? $new_snapshot[ $field ] : '';
							if ( $old_value !== $new_value ) {
								SpreadEm_Logger::log_change( $user_id, $product_id, $field, $old_value, $new_value, $save_state_id );
							}
						}
					}
				}
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

		$parent_ids = self::get_context_parent_ids( $saved );
		$products   = [];

		if ( class_exists( 'SpreadEm_Admin' ) && method_exists( 'SpreadEm_Admin', 'get_products_for_editor' ) ) {
			$products = SpreadEm_Admin::get_products_for_editor( $parent_ids );
		}

		wp_send_json_success(
			[
				'saved'          => $saved,
				'products'       => $products,
				'save_state_id'  => $save_state_id,
			]
		);
	}

	/**
	 * Resolve parent product IDs for a full post-save refresh.
	 *
	 * @param array<int> $saved Saved product IDs from this request.
	 * @return array<int>
	 */
	private static function get_context_parent_ids( array $saved ): array {
		$context_parent_ids = [];

		if ( isset( $_POST['context_parent_ids'] ) ) {
			$raw = wp_unslash( $_POST['context_parent_ids'] );
			$ids = json_decode( $raw, true );

			if ( is_array( $ids ) ) {
				$context_parent_ids = array_map( 'absint', $ids );
			}
		}

		if ( empty( $context_parent_ids ) ) {
			foreach ( $saved as $id ) {
				$product = wc_get_product( (int) $id );
				if ( ! $product ) {
					continue;
				}

				if ( $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ) {
					$context_parent_ids[] = (int) $product->get_parent_id();
				} else {
					$context_parent_ids[] = (int) $product->get_id();
				}
			}
		}

		$context_parent_ids = array_values( array_unique( array_filter( $context_parent_ids ) ) );

		return $context_parent_ids;
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
	public static function apply_row_to_product( \WC_Product $product, array $row ) {
		$is_variation = $product->is_type( 'variation' );

		// Name and catalog visibility are inherited by variations – skip them.
		if ( ! $is_variation && array_key_exists( 'name', $row ) ) {
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

		if ( ! $is_variation && array_key_exists( 'catalog_visibility', $row ) ) {
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

		if ( ! $is_variation && array_key_exists( 'categories', $row ) ) {
			$cats = self::parse_list_values( (string) $row['categories'] );
			wp_set_post_terms( $product->get_id(), $cats, 'product_cat', false );
		}

		if ( ! $is_variation && array_key_exists( 'tags', $row ) ) {
			$tags = self::parse_list_values( (string) $row['tags'] );
			wp_set_post_terms( $product->get_id(), $tags, 'product_tag', false );
		}

		if ( array_key_exists( 'attributes', $row ) ) {
			self::apply_attributes_value( $product, (string) $row['attributes'] );
		}

		$meta_result = self::apply_meta_column_values( $product->get_id(), $row );
		if ( is_wp_error( $meta_result ) ) {
			return $meta_result;
		}

		// Persist the product.
		$result = $product->save();

		if ( ! $result ) {
			return new \WP_Error( 'save_failed', __( 'Could not save product.', 'spread-em' ) );
		}

		return true;
	}

	/**
	 * Apply dynamic meta column values from a row payload.
	 *
	 * @param int                 $product_id Product post ID.
	 * @param array<string,mixed> $row        Changed row payload.
	 * @return true|\WP_Error
	 */
	private static function apply_meta_column_values( int $product_id, array $row ) {
		foreach ( $row as $key => $value ) {
			$column_key = (string) $key;
			$meta_key   = class_exists( 'SpreadEm_Admin' )
				? SpreadEm_Admin::get_meta_key_from_column_key( $column_key )
				: null;

			if ( null === $meta_key ) {
				continue;
			}

			if ( is_protected_meta( $meta_key, 'post' ) ) {
				continue;
			}

			$raw_value = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
			$raw_value = trim( (string) $raw_value );

			if ( '' === $raw_value ) {
				delete_post_meta( $product_id, $meta_key );
				continue;
			}

			$parsed_value = self::parse_meta_cell_value( $raw_value );
			update_post_meta( $product_id, $meta_key, $parsed_value );
		}

		return true;
	}

	/**
	 * Parse a meta cell string back to native PHP value when JSON is supplied.
	 *
	 * @param string $value Cell value.
	 * @return mixed
	 */
	private static function parse_meta_cell_value( string $value ) {
		$first = substr( ltrim( $value ), 0, 1 );

		if ( '{' === $first || '[' === $first ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}

		return $value;
	}

	/**
	 * Parse comma-separated values to an array of sanitized strings.
	 *
	 * @param string $value Raw input.
	 * @return array<int, string>
	 */
	private static function parse_list_values( string $value ): array {
		$parts = array_map( 'trim', explode( ',', $value ) );
		$parts = array_filter( $parts, static function ( $part ) {
			return '' !== $part;
		} );

		return array_values( array_map( 'sanitize_text_field', $parts ) );
	}

	/**
	 * Apply attributes from the spreadsheet string format.
	 *
	 * Accepted format: "Size: S, M, L | Color: Red, Blue".
	 * For variations, the first value for each attribute is used as the combo.
	 *
	 * @param \WC_Product $product Product to update.
	 * @param string      $raw     Raw attributes string.
	 */
	private static function apply_attributes_value( \WC_Product $product, string $raw ): void {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			if ( ! $product->is_type( 'variation' ) ) {
				$product->set_attributes( [] );
			} else {
				$product->set_attributes( [] );
			}
			return;
		}

		$pairs = array_map( 'trim', explode( '|', $raw ) );

		if ( $product->is_type( 'variation' ) ) {
			$variation_attrs = [];

			foreach ( $pairs as $pair ) {
				if ( '' === $pair || false === strpos( $pair, ':' ) ) {
					continue;
				}

				[ $label, $values_raw ] = array_map( 'trim', explode( ':', $pair, 2 ) );
				$values = self::parse_list_values( $values_raw );

				if ( '' === $label || empty( $values ) ) {
					continue;
				}

				$taxonomy = self::resolve_attribute_taxonomy_by_label( $label );
				$value    = $values[0];

				if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
					$term = get_term_by( 'name', $value, $taxonomy );
					if ( ! $term instanceof \WP_Term ) {
						$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
					}
					$variation_attrs[ $taxonomy ] = $term instanceof \WP_Term ? $term->slug : sanitize_title( $value );
				} else {
					$variation_attrs[ sanitize_title( $label ) ] = sanitize_text_field( $value );
				}
			}

			$product->set_attributes( $variation_attrs );
			return;
		}

		$attributes = [];
		$position   = 0;

		foreach ( $pairs as $pair ) {
			if ( '' === $pair || false === strpos( $pair, ':' ) ) {
				continue;
			}

			[ $label, $values_raw ] = array_map( 'trim', explode( ':', $pair, 2 ) );
			$values = self::parse_list_values( $values_raw );

			if ( '' === $label || empty( $values ) ) {
				continue;
			}

			$taxonomy = self::resolve_attribute_taxonomy_by_label( $label );
			$attr     = new \WC_Product_Attribute();

			if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
				$term_ids = [];
				foreach ( $values as $value ) {
					$term = get_term_by( 'name', $value, $taxonomy );
					if ( ! $term instanceof \WP_Term ) {
						$inserted = wp_insert_term( $value, $taxonomy );
						if ( ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) ) {
							$term_ids[] = (int) $inserted['term_id'];
						}
					} else {
						$term_ids[] = (int) $term->term_id;
					}
				}

				if ( empty( $term_ids ) ) {
					continue;
				}

				$attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attr->set_name( $taxonomy );
				$attr->set_options( $term_ids );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$attr->set_position( $position++ );
			} else {
				$attr->set_id( 0 );
				$attr->set_name( sanitize_text_field( $label ) );
				$attr->set_options( array_map( 'sanitize_text_field', $values ) );
				$attr->set_visible( true );
				$attr->set_variation( false );
				$attr->set_position( $position++ );
			}

			$attributes[] = $attr;
		}

		$product->set_attributes( $attributes );
	}

	/**
	 * Resolve a global attribute taxonomy name from a human label.
	 *
	 * @param string $label Human-readable attribute label.
	 * @return string|null e.g. pa_color or null.
	 */
	private static function resolve_attribute_taxonomy_by_label( string $label ): ?string {
		$label = sanitize_text_field( $label );
		$slug  = sanitize_title( $label );

		if ( taxonomy_exists( 'pa_' . $slug ) ) {
			return 'pa_' . $slug;
		}

		$taxonomies = wc_get_attribute_taxonomies();

		if ( ! is_array( $taxonomies ) ) {
			return null;
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! isset( $taxonomy->attribute_name ) || ! isset( $taxonomy->attribute_label ) ) {
				continue;
			}

			if (
				0 === strcasecmp( $taxonomy->attribute_label, $label ) ||
				0 === strcasecmp( $taxonomy->attribute_name, $slug )
			) {
				return 'pa_' . $taxonomy->attribute_name;
			}
		}

		return null;
	}

	/**
	 * Snapshot the current values of every field present in a row payload.
	 *
	 * The 'id' key is excluded; all other keys in $row are read from the
	 * product and returned as a field-name → string-value map.
	 *
	 * @param \WC_Product          $product Current product object.
	 * @param array<string, mixed> $row     Row payload (field keys to snapshot).
	 * @return array<string, string>
	 */
	private static function snapshot_product( \WC_Product $product, array $row ): array {
		$snapshot = [];

		foreach ( array_keys( $row ) as $field ) {
			if ( 'id' === $field ) {
				continue;
			}

			$snapshot[ $field ] = self::get_product_field_value( $product, $field );
		}

		return $snapshot;
	}

	/**
	 * Read the current string value of a single editable field from a product.
	 *
	 * @param \WC_Product $product
	 * @param string      $field   Column key (e.g. "regular_price", "meta::custom_key").
	 * @return string
	 */
	private static function get_product_field_value( \WC_Product $product, string $field ): string {
		switch ( $field ) {
			case 'name':
				return (string) $product->get_name();
			case 'sku':
				return (string) $product->get_sku();
			case 'status':
				return (string) $product->get_status();
			case 'catalog_visibility':
				return (string) $product->get_catalog_visibility();
			case 'regular_price':
				return (string) $product->get_regular_price();
			case 'sale_price':
				return (string) $product->get_sale_price();
			case 'tax_status':
				return (string) $product->get_tax_status();
			case 'tax_class':
				return (string) $product->get_tax_class();
			case 'manage_stock':
				return $product->get_manage_stock() ? 'yes' : 'no';
			case 'stock_status':
				return (string) $product->get_stock_status();
			case 'stock_quantity':
				$qty = $product->get_stock_quantity();
				return null === $qty ? '' : (string) $qty;
			case 'backorders':
				return (string) $product->get_backorders();
			case 'weight':
				return (string) $product->get_weight();
			case 'length':
				return (string) $product->get_length();
			case 'width':
				return (string) $product->get_width();
			case 'height':
				return (string) $product->get_height();
			case 'short_description':
				return (string) $product->get_short_description();
			case 'description':
				return (string) $product->get_description();
			case 'categories':
				return wp_strip_all_tags( wc_get_product_category_list( $product->get_id(), ', ' ) );
			case 'tags':
				$tags = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] );
				return is_array( $tags ) ? implode( ', ', $tags ) : '';
			case 'attributes':
				return class_exists( 'SpreadEm_Admin' )
					? SpreadEm_Admin::get_product_attributes_summary( $product )
					: '';
			default:
				$meta_key = class_exists( 'SpreadEm_Admin' )
					? SpreadEm_Admin::get_meta_key_from_column_key( $field )
					: null;

				if ( null !== $meta_key ) {
					$value = get_post_meta( $product->get_id(), $meta_key, true );

					if ( is_array( $value ) || is_object( $value ) ) {
						$encoded = wp_json_encode( $value );
						return is_string( $encoded ) ? $encoded : '';
					}

					return null !== $value ? (string) $value : '';
				}

				return '';
		}
	}
}
