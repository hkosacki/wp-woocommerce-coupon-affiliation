<?php
/**
 * Order list column and order edit meta box for ambassador sales.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for order-level ambassador attribution.
 */
final class WC_Coupon_Affiliation_Order_Admin {

	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'manage_shop_order_posts_columns', array( $this, 'add_legacy_order_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_order_column' ), 10, 2 );

		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_hpos_order_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_order_column' ), 10, 2 );

		add_action( 'add_meta_boxes', array( $this, 'register_order_meta_box' ) );
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_legacy_order_column( array $columns ): array {
		return $this->inject_ambassador_column( $columns );
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_hpos_order_column( array $columns ): array {
		return $this->inject_ambassador_column( $columns );
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	private function inject_ambassador_column( array $columns ): array {
		$new = array();
		$added = false;
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( ! $added && ( 'order_number' === $key || 'order_title' === $key ) ) {
				$new['wcca_ambassador'] = __( 'Ambassador', 'woocommerce-coupon-affiliation' );
				$added = true;
			}
		}
		if ( ! $added ) {
			$new['wcca_ambassador'] = __( 'Ambassador', 'woocommerce-coupon-affiliation' );
		}
		return $new;
	}

	/**
	 * @param string $column  Column id.
	 * @param int    $post_id Post ID.
	 */
	public function render_legacy_order_column( $column, $post_id ): void {
		if ( 'wcca_ambassador' !== $column ) {
			return;
		}

		$order = wc_get_order( absint( $post_id ) );
		if ( ! $order instanceof WC_Order ) {
			echo '&mdash;';
			return;
		}

		$this->render_ambassador_cell( $order );
	}

	/**
	 * @param string       $column Column id.
	 * @param int|\WC_Order $order  Order ID or object (HPOS list table).
	 */
	public function render_hpos_order_column( $column, $order ): void {
		if ( 'wcca_ambassador' !== $column ) {
			return;
		}

		if ( $order instanceof WC_Order ) {
			$this->render_ambassador_cell( $order );
			return;
		}

		$resolved = wc_get_order( $order );
		if ( ! $resolved instanceof WC_Order ) {
			echo '&mdash;';
			return;
		}

		$this->render_ambassador_cell( $resolved );
	}

	private function render_ambassador_cell( WC_Order $order ): void {
		$user_id = absint( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID ) );
		if ( ! $user_id ) {
			echo '&mdash;';
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			echo '&mdash;';
			return;
		}

		echo esc_html( $user->display_name );
	}

	public function register_order_meta_box(): void {
		$screen = $this->get_order_screen_id();

		add_meta_box(
			'wcca_ambassador_sales',
			__( 'Ambassador Sales Data', 'woocommerce-coupon-affiliation' ),
			array( $this, 'render_ambassador_sales_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	private function get_order_screen_id(): string {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}

	/**
	 * @param \WP_Post|\WC_Order $post_or_order Post or order object.
	 */
	public function render_ambassador_sales_meta_box( $post_or_order ): void {
		$order = null;
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} elseif ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
		}

		if ( ! $order instanceof WC_Order ) {
			echo '<p class="description">' . esc_html__( 'Unable to load order data.', 'woocommerce-coupon-affiliation' ) . '</p>';
			return;
		}

		if ( ! $order->get_meta( WC_Coupon_Affiliation_Plugin::META_SALES_ATTRIBUTION_DONE ) ) {
			echo '<p class="description">' . esc_html__( 'Ambassador and commission are recorded when the order is marked Completed.', 'woocommerce-coupon-affiliation' ) . '</p>';
			return;
		}

		$user_id = absint( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID ) );
		$name    = '&mdash;';
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$name = $user->display_name;
			}
		}

		$commission_raw = $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_COMMISSION );
		$commission     = '' !== $commission_raw && null !== $commission_raw ? (float) wc_format_decimal( $commission_raw ) : 0.0;

		echo '<p><strong>' . esc_html__( 'Ambassador', 'woocommerce-coupon-affiliation' ) . '</strong><br />';
		echo esc_html( $name ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Commission', 'woocommerce-coupon-affiliation' ) . '</strong><br />';
		echo wp_kses_post( wc_price( $commission ) ) . '</p>';
	}
}
