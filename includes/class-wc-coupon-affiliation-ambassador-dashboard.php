<?php
/**
 * Ambassador My Account endpoint: stats and recent attributed orders by month.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers ambassador-stats endpoint, menu item, and dashboard UI.
 */
final class WC_Coupon_Affiliation_Ambassador_Dashboard {

	public const ENDPOINT_SLUG = 'ambassador-stats';

	public const MONTH_QUERY_VAR = 'wcca_month';

	private const MONTHS_BACK = 24;

	/** Cap wc_get_orders rows before PHP month filter (large ambassador histories may need a custom index later). */
	private const QUERY_HARD_LIMIT = 3000;

	public function __construct() {
		add_action( 'init', array( self::class, 'register_rewrite_endpoint' ), 11 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu_items' ), 40 );
		add_action( 'woocommerce_account_' . self::ENDPOINT_SLUG . '_endpoint', array( $this, 'render_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Register My Account rewrite endpoint (also call from plugin activation before flush).
	 */
	public static function register_rewrite_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT_SLUG, EP_ROOT | EP_PAGES );
	}

	/**
	 * @param array<string, string> $items Menu slug => label.
	 * @return array<string, string>
	 */
	public function account_menu_items( array $items ): array {
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::ENDPOINT_SLUG ] = __( 'Ambassador Panel', 'woocommerce-coupon-affiliation' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::ENDPOINT_SLUG ] ) ) {
			$new[ self::ENDPOINT_SLUG ] = __( 'Ambassador Panel', 'woocommerce-coupon-affiliation' );
		}

		if ( ! $this->current_user_is_ambassador() ) {
			unset( $new[ self::ENDPOINT_SLUG ] );
		}

		return $new;
	}

	public function enqueue_styles(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		if ( false === get_query_var( self::ENDPOINT_SLUG, false ) ) {
			return;
		}

		$css_path = dirname( __DIR__ ) . '/assets/css/ambassador-dashboard.css';
		$href      = plugins_url( 'assets/css/ambassador-dashboard.css', dirname( __DIR__ ) . '/coupon-affiliation-plugin.php' );
		wp_enqueue_style(
			'wcca-ambassador-dashboard',
			$href,
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : '0.0.1'
		);
	}

	public function render_endpoint(): void {
		if ( ! $this->current_user_is_ambassador() ) {
			wc_add_notice( __( 'You do not have access to this page.', 'woocommerce-coupon-affiliation' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'dashboard' ) );
			exit;
		}

		$user_id = get_current_user_id();
		$month   = $this->get_selected_month_ym();

		$this->render_month_form( $month );

		$orders = $this->query_orders_for_month( $user_id, $month );
		$this->render_summary( $orders );
		$this->render_table( $orders );
	}

	private function current_user_is_ambassador(): bool {
		$user = wp_get_current_user();
		return $user && in_array( WC_Coupon_Affiliation_Plugin::ROLE_AMBASSADOR, (array) $user->roles, true );
	}

	/**
	 * Selected calendar month Y-m in site timezone (validated).
	 */
	private function get_selected_month_ym(): string {
		$tz = wp_timezone();

		if ( isset( $_GET[ self::MONTH_QUERY_VAR ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_GET[ self::MONTH_QUERY_VAR ] ) );
			if ( preg_match( '/^(\d{4})-(\d{2})$/', $raw, $m ) ) {
				$y = (int) $m[1];
				$mo = (int) $m[2];
				if ( $y >= 1970 && $y <= 2100 && $mo >= 1 && $mo <= 12 && checkdate( $mo, 1, $y ) ) {
					return sprintf( '%04d-%02d', $y, $mo );
				}
			}
		}

		return wp_date( 'Y-m', time(), $tz );
	}

	/**
	 * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}
	 */
	private function get_month_bounds( string $ym ): array {
		$tz = wp_timezone();
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ym . '-01', $tz );
		if ( ! $start ) {
			$start = ( new \DateTimeImmutable( 'now', $tz ) )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		}
		$end = $start->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		return array( 'start' => $start, 'end' => $end );
	}

	/**
	 * @return list<\WC_Order>
	 */
	private function query_orders_for_month( int $user_id, string $ym ): array {
		$bounds = $this->get_month_bounds( $ym );
		$start  = $bounds['start'];
		$end    = $bounds['end'];

		$t_start = $start->getTimestamp();
		$t_end   = $end->getTimestamp();

		$args = array(
			'limit'      => self::QUERY_HARD_LIMIT,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => array( 'completed', 'refunded', 'cancelled', 'failed' ),
			'meta_query' => array(
				array(
					'key'   => WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID,
					'value' => (string) $user_id,
				),
			),
			'return'     => 'objects',
		);

		$orders = WC_Coupon_Affiliation_Order_Query::wc_get_orders_with_meta_query_compat( $args );
		if ( ! is_array( $orders ) ) {
			return array();
		}

		/** @var list<\WC_Order> $filtered */
		$filtered = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$dc = $order->get_date_completed();
			$ts = null;
			if ( $dc instanceof WC_DateTime ) {
				$ts = $dc->getTimestamp();
			} else {
				$created = $order->get_date_created();
				if ( $created instanceof WC_DateTime ) {
					$ts = $created->getTimestamp();
				}
			}
			if ( null !== $ts && $ts >= $t_start && $ts <= $t_end ) {
				$filtered[] = $order;
			}
		}

		usort(
			$filtered,
			static function ( $a, $b ): int {
				if ( ! $a instanceof WC_Order || ! $b instanceof WC_Order ) {
					return 0;
				}
				$da = $a->get_date_completed();
				$db = $b->get_date_completed();
				$ta = $da instanceof WC_DateTime ? $da->getTimestamp() : 0;
				$tb = $db instanceof WC_DateTime ? $db->getTimestamp() : 0;
				if ( $ta === $tb ) {
					return 0;
				}
				// Newest completed first.
				return ( $ta > $tb ) ? -1 : 1;
			}
		);

		return $filtered;
	}

	/**
	 * Non-payable: void payout meta, cancelled/refunded/failed, or full refund (partial refunds do not zero commission).
	 */
	private function is_order_nonpayable( WC_Order $order ): bool {
		if ( WC_Coupon_Affiliation_Plugin::is_void_payout_status( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_PAYOUT_STATUS ) ) ) {
			return true;
		}

		$status = $order->get_status();
		if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			return true;
		}

		$total    = (float) $order->get_total();
		$refunded = (float) $order->get_total_refunded();
		if ( $total <= 0 ) {
			return false;
		}

		return $refunded >= ( $total - 0.01 );
	}

	private function get_order_net_base( WC_Order $order ): float {
		$net = (float) $order->get_subtotal() - (float) $order->get_discount_total();
		return max( 0.0, $net );
	}

	private function get_stored_commission( WC_Order $order ): float {
		$raw = $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_COMMISSION );
		if ( '' === $raw || null === $raw ) {
			return 0.0;
		}
		return (float) wc_format_decimal( $raw );
	}

	private function get_effective_commission( WC_Order $order ): float {
		if ( $this->is_order_nonpayable( $order ) ) {
			return 0.0;
		}
		return $this->get_stored_commission( $order );
	}

	/**
	 * @param list<\WC_Order> $orders
	 */
	private function render_month_form( string $current_ym ): void {
		$tz     = wp_timezone();
		$action = wc_get_account_endpoint_url( self::ENDPOINT_SLUG );

		echo '<div class="wcca-ambassador-dashboard">';
		echo '<form method="get" class="wcca-month-form" action="' . esc_url( $action ) . '">';
		echo '<label for="wcca-month-select" class="wcca-month-form__label">';
		echo esc_html__( 'Month', 'woocommerce-coupon-affiliation' );
		echo '</label> ';
		echo '<select name="' . esc_attr( self::MONTH_QUERY_VAR ) . '" id="wcca-month-select" class="wcca-month-form__select" onchange="this.form.submit()">';

		$cursor = ( new \DateTimeImmutable( 'now', $tz ) )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		for ( $i = 0; $i < self::MONTHS_BACK; $i++ ) {
			$ym = $cursor->format( 'Y-m' );
			$label = wp_date( 'F Y', $cursor->getTimestamp(), $tz );
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $ym ),
				selected( $ym, $current_ym, false ),
				esc_html( $label )
			);
			$cursor = $cursor->modify( '-1 month' );
		}

		echo '</select>';
		echo '</form>';
	}

	/**
	 * @param list<\WC_Order> $orders
	 */
	private function render_summary( array $orders ): void {
		$payable = 0.0;
		$net_sum = 0.0;
		foreach ( $orders as $order ) {
			$payable += $this->get_effective_commission( $order );
			$net_sum += $this->get_order_net_base( $order );
		}
		$count = count( $orders );

		echo '<div class="wcca-summary">';
		echo '<div class="wcca-summary__item">';
		echo '<span class="wcca-summary__label">' . esc_html__( 'Total commission (payable)', 'woocommerce-coupon-affiliation' ) . '</span>';
		echo '<span class="wcca-summary__value">' . wp_kses_post( wc_price( $payable ) ) . '</span>';
		echo '</div>';
		echo '<div class="wcca-summary__item">';
		echo '<span class="wcca-summary__label">' . esc_html__( 'Orders in month', 'woocommerce-coupon-affiliation' ) . '</span>';
		echo '<span class="wcca-summary__value">' . esc_html( (string) $count ) . '</span>';
		echo '</div>';
		echo '<div class="wcca-summary__item">';
		echo '<span class="wcca-summary__label">' . esc_html__( 'Total net sales', 'woocommerce-coupon-affiliation' ) . '</span>';
		echo '<span class="wcca-summary__value">' . wp_kses_post( wc_price( $net_sum ) ) . '</span>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param list<\WC_Order> $orders
	 */
	private function render_table( array $orders ): void {
		$slice = array_slice( $orders, 0, 10 );

		echo '<h3>' . esc_html__( 'Recent orders', 'woocommerce-coupon-affiliation' ) . '</h3>';
		echo '<table class="shop_table shop_table_responsive wcca-orders-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Order number', 'woocommerce-coupon-affiliation' ) . '</th>';
		echo '<th>' . esc_html__( 'Order date / time', 'woocommerce-coupon-affiliation' ) . '</th>';
		echo '<th>' . esc_html__( 'Net value', 'woocommerce-coupon-affiliation' ) . '</th>';
		echo '<th>' . esc_html__( 'Commission', 'woocommerce-coupon-affiliation' ) . '</th>';
		echo '<th>' . esc_html__( 'Payout status', 'woocommerce-coupon-affiliation' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $slice ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No orders for this month.', 'woocommerce-coupon-affiliation' ) . '</td></tr>';
		} else {
			foreach ( $slice as $order ) {
				$this->render_table_row( $order );
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_table_row( WC_Order $order ): void {
		$num   = $order->get_order_number();
		$dcomp = $order->get_date_completed();
		if ( $dcomp instanceof WC_DateTime ) {
			$dt = $dcomp->date_i18n( wc_date_format() . ' ' . wc_time_format() );
		} else {
			$dt = $order->get_date_created() instanceof WC_DateTime
				? $order->get_date_created()->date_i18n( wc_date_format() . ' ' . wc_time_format() )
				: '';
		}

		$net  = $this->get_order_net_base( $order );
		$eff  = $this->get_effective_commission( $order );
		$rate = $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_RATE_APPLIED );

		$commission_html = wp_kses_post( wc_price( $eff ) );
		if ( '' !== $rate && null !== $rate ) {
			$commission_html .= '<br /><span class="wcca-commission-rate">(' . esc_html( wc_format_decimal( (float) $rate ) ) . '%)</span>';
		}

		$nonpay = $this->is_order_nonpayable( $order );
		if ( WC_Coupon_Affiliation_Plugin::is_void_payout_status( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_PAYOUT_STATUS ) ) ) {
			$payout_label = WC_Coupon_Affiliation_Plugin::get_commission_payout_status_label( $order );
		} elseif ( $nonpay ) {
			$payout_label = __( 'Not payable', 'woocommerce-coupon-affiliation' );
		} else {
			$payout_label = WC_Coupon_Affiliation_Plugin::get_commission_payout_status_label( $order );
		}
		$payout_cell = $nonpay
			? '<span class="wcca-payout-void">' . esc_html( $payout_label ) . '</span>'
			: esc_html( $payout_label );

		echo '<tr>';
		echo '<td data-title="' . esc_attr__( 'Order number', 'woocommerce-coupon-affiliation' ) . '">' . esc_html( $num ) . '</td>';
		echo '<td data-title="' . esc_attr__( 'Order date / time', 'woocommerce-coupon-affiliation' ) . '">' . esc_html( $dt ) . '</td>';
		echo '<td data-title="' . esc_attr__( 'Net value', 'woocommerce-coupon-affiliation' ) . '">' . wp_kses_post( wc_price( $net ) ) . '</td>';
		echo '<td data-title="' . esc_attr__( 'Commission', 'woocommerce-coupon-affiliation' ) . '">' . $commission_html . '</td>';
		echo '<td data-title="' . esc_attr__( 'Payout status', 'woocommerce-coupon-affiliation' ) . '">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $payout_cell built from escaped parts.
		echo $payout_cell;
		echo '</td>';
		echo '</tr>';
	}
}
