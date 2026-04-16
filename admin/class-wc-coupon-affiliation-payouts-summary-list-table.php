<?php
/**
 * Monthly per-ambassador payout summary (WP_List_Table).
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * @phpstan-type SummaryRow array{month:string,ambassador_id:int,ambassador_name:string,total:float,unpaid:float,paid:float,order_count:int}
 */
final class WC_Coupon_Affiliation_Payouts_Summary_List_Table extends WP_List_Table {

	/**
	 * @var list<SummaryRow>
	 */
	private $row_data = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wcca-summary-row',
				'plural'   => 'wcca-summary-rows',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @param list<SummaryRow> $rows Full sorted list (paginated externally).
	 */
	public function set_rows( array $rows ): void {
		$this->row_data = $rows;
	}

	public function get_columns(): array {
		return array(
			'month'             => __( 'Month', 'woocommerce-coupon-affiliation' ),
			'ambassador'        => __( 'Ambassador', 'woocommerce-coupon-affiliation' ),
			'commission_total'  => __( 'Commission total', 'woocommerce-coupon-affiliation' ),
			'unpaid'            => __( 'Unpaid', 'woocommerce-coupon-affiliation' ),
			'paid'              => __( 'Paid', 'woocommerce-coupon-affiliation' ),
			'order_count'       => __( 'Orders', 'woocommerce-coupon-affiliation' ),
			'actions'           => __( 'Actions', 'woocommerce-coupon-affiliation' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items          = $this->row_data;
	}

	/**
	 * @param SummaryRow $item Row.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'month':
				$ts = strtotime( $item['month'] . '-01' );
				return esc_html( $ts ? wp_date( 'F Y', $ts, wp_timezone() ) : $item['month'] );
			case 'ambassador':
				return esc_html( $item['ambassador_name'] );
			case 'commission_total':
				return wp_kses_post( wc_price( $item['total'] ) );
			case 'unpaid':
				return wp_kses_post( wc_price( $item['unpaid'] ) );
			case 'paid':
				return wp_kses_post( wc_price( $item['paid'] ) );
			case 'order_count':
				return esc_html( (string) (int) $item['order_count'] );
			default:
				return '';
		}
	}

	/**
	 * @param SummaryRow $item Row.
	 */
	protected function column_actions( $item ): void {
		$url = add_query_arg(
			array(
				'page'           => WC_Coupon_Affiliation_Payouts_Admin::PAGE_SLUG,
				'view'           => 'transactions',
				'month'          => $item['month'],
				'ambassador_id'  => $item['ambassador_id'],
			),
			admin_url( 'admin.php' )
		);

		$view_tx = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'View transactions', 'woocommerce-coupon-affiliation' )
		);

		$nonce = wp_create_nonce( 'wcca_payout_month_' . $item['month'] . '_' . $item['ambassador_id'] );
		$mark  = add_query_arg(
			array(
				'action'        => 'wcca_mark_month_payout',
				'month'         => $item['month'],
				'ambassador_id' => $item['ambassador_id'],
				'_wpnonce'      => $nonce,
			),
			admin_url( 'admin-post.php' )
		);

		$mark_paid = sprintf(
			'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $mark ),
			esc_js( __( 'Mark all unpaid commissions in this month for this ambassador as paid?', 'woocommerce-coupon-affiliation' ) ),
			esc_html__( 'Mark month paid', 'woocommerce-coupon-affiliation' )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $view_tx . ' | ' . $mark_paid;
	}
}
