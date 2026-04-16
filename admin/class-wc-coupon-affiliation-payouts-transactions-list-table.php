<?php
/**
 * Per-order payout transactions for a month (WP_List_Table).
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class WC_Coupon_Affiliation_Payouts_Transactions_List_Table extends WP_List_Table {

	/**
	 * @var list<\WC_Order>
	 */
	private $orders = array();

	private string $context_month = '';

	private int $context_ambassador_id = 0;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wcca-payout-order',
				'plural'   => 'wcca-payout-orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @param list<\WC_Order> $orders Orders for current page.
	 */
	public function set_orders( array $orders ): void {
		$this->orders = $orders;
	}

	public function set_context( string $month, int $ambassador_id ): void {
		$this->context_month           = $month;
		$this->context_ambassador_id   = $ambassador_id;
	}

	public function get_columns(): array {
		return array(
			'order'     => __( 'Order', 'woocommerce-coupon-affiliation' ),
			'date'      => __( 'Date', 'woocommerce-coupon-affiliation' ),
			'ambassador' => __( 'Ambassador', 'woocommerce-coupon-affiliation' ),
			'commission' => __( 'Commission', 'woocommerce-coupon-affiliation' ),
			'status'    => __( 'Payout status', 'woocommerce-coupon-affiliation' ),
			'actions'   => __( 'Actions', 'woocommerce-coupon-affiliation' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items          = $this->orders;
	}

	/**
	 * @param \WC_Order $order Order.
	 */
	protected function column_default( $order, $column_name ): string {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$aid = absint( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID ) );

		switch ( $column_name ) {
			case 'order':
				$url = $order->get_edit_order_url();
				return sprintf(
					'<a href="%s">#%s</a>',
					esc_url( $url ),
					esc_html( $order->get_order_number() )
				);
			case 'date':
				$dc = $order->get_date_completed();
				if ( ! $dc instanceof WC_DateTime ) {
					$dc = $order->get_date_created();
				}
				return $dc instanceof WC_DateTime
					? esc_html( $dc->date_i18n( wc_date_format() . ' ' . wc_time_format() ) )
					: '&mdash;';
			case 'ambassador':
				$user = get_userdata( $aid );
				return $user ? esc_html( $user->display_name ) : '&mdash;';
			case 'commission':
				$raw = $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_COMMISSION );
				$amt = (float) wc_format_decimal( $raw );
				return wp_kses_post( wc_price( $amt, array( 'currency' => $order->get_currency() ) ) );
			case 'status':
				return esc_html( WC_Coupon_Affiliation_Payouts_Admin::get_payout_status_label( $order ) );
			default:
				return '';
		}
	}

	/**
	 * @param \WC_Order $order Order.
	 */
	protected function column_actions( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$oid   = $order->get_id();
		$nonce = wp_create_nonce( 'wcca_payout_' . $oid );

		$ctx = array(
			'month'         => $this->context_month,
			'ambassador_id' => $this->context_ambassador_id,
		);

		$paid_url = add_query_arg(
			array_merge(
				array(
					'action'   => 'wcca_mark_commission_payout',
					'order_id' => $oid,
					'status'   => WC_Coupon_Affiliation_Plugin::PAYOUT_STATUS_PAID,
					'_wpnonce' => $nonce,
				),
				$ctx
			),
			admin_url( 'admin-post.php' )
		);

		$unpaid_url = add_query_arg(
			array_merge(
				array(
					'action'   => 'wcca_mark_commission_payout',
					'order_id' => $oid,
					'status'   => WC_Coupon_Affiliation_Plugin::PAYOUT_STATUS_UNPAID,
					'_wpnonce' => $nonce,
				),
				$ctx
			),
			admin_url( 'admin-post.php' )
		);

		$links = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $paid_url ),
				esc_html__( 'Mark as Paid', 'woocommerce-coupon-affiliation' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $unpaid_url ),
				esc_html__( 'Mark as Unpaid', 'woocommerce-coupon-affiliation' )
			),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( ' | ', $links );
	}
}
