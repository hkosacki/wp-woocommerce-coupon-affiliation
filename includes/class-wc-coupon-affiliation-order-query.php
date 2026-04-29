<?php
/**
 * Order list queries compatible with HPOS and legacy post-based order storage.
 *
 * WooCommerce 9.2+ triggers doing_it_wrong when meta_query is passed to wc_get_orders()
 * while the CPT datastore is active; HPOS supports meta_query. This helper uses WP_Query
 * on shop_order for the legacy path.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wc_get_orders-style queries with meta_query on both datastores.
 */
final class WC_Coupon_Affiliation_Order_Query {

	public static function custom_orders_table_usage_is_enabled(): bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Like wc_get_orders(), but safe when meta_query is set and orders use post storage.
	 *
	 * @param array<string,mixed> $args Same as wc_get_orders; meta_query optional. Supports return objects (default) or ids.
	 * @return list<int|\WC_Order>
	 */
	public static function wc_get_orders_with_meta_query_compat( array $args ): array {
		$return = isset( $args['return'] ) ? $args['return'] : 'objects';
		$has_meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) && array() !== $args['meta_query'];

		if ( ! $has_meta_query || self::custom_orders_table_usage_is_enabled() ) {
			$result = wc_get_orders( $args );
			if ( ! is_array( $result ) ) {
				return array();
			}
			return $result;
		}

		return self::legacy_get_orders_via_shop_order_posts( $args, $return );
	}

	/**
	 * @param array<string,mixed> $args
	 * @param string              $return objects|ids
	 * @return list<int|\WC_Order>
	 */
	private static function legacy_get_orders_via_shop_order_posts( array $args, string $return ): array {
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$order  = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$orderby = isset( $args['orderby'] ) && is_string( $args['orderby'] ) ? $args['orderby'] : 'date';
		$status  = $args['status'] ?? 'any';
		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

		$wp_query_args = array(
			'post_type'      => 'shop_order',
			'post_status'    => self::order_status_arg_to_post_statuses( $status ),
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'offset'         => $offset,
			'orderby'        => $orderby,
			'order'          => $order,
			'meta_query'     => $meta_query,
			'fields'         => 'ids',
		);

		$query = new WP_Query( $wp_query_args );
		if ( 'ids' === $return ) {
			return array_map( 'intval', $query->posts );
		}

		$orders = array();
		foreach ( $query->posts as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof WC_Order ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	/**
	 * @param string|array<int|string,string> $status wc_get_orders status (any, completed, or list of slugs without wc- prefix).
	 * @return string|array<int,string>
	 */
	private static function order_status_arg_to_post_statuses( $status ) {
		if ( 'any' === $status ) {
			return array_keys( wc_get_order_statuses() );
		}
		if ( is_array( $status ) ) {
			$out = array();
			foreach ( $status as $slug ) {
				if ( ! is_string( $slug ) || '' === $slug ) {
					continue;
				}
				$s     = str_replace( 'wc-', '', $slug );
				$out[] = 'wc-' . $s;
			}
			return ! empty( $out ) ? $out : array_keys( wc_get_order_statuses() );
		}
		if ( is_string( $status ) && '' !== $status ) {
			$s = str_replace( 'wc-', '', $status );
			return array( 'wc-' . $s );
		}
		return array_keys( wc_get_order_statuses() );
	}
}
