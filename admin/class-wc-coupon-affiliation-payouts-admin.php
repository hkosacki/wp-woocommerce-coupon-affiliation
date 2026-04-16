<?php
/**
 * WooCommerce Ambassador Payouts: summary + transactions admin screens.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers submenu, aggregates payout data, and handles admin actions.
 */
final class WC_Coupon_Affiliation_Payouts_Admin {

	public const PAGE_SLUG = 'wcca-ambassador-payouts';

	private const BATCH_SIZE = 200;

	/** Max orders scanned per request (summary + totals). */
	private const SCAN_MAX = 8000;

	/** Max orders loaded for transactions view per month. */
	private const TRANSACTIONS_CAP = 500;

	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_payouts_admin_assets' ) );
		add_action( 'admin_post_wcca_mark_commission_payout', array( $this, 'handle_mark_order_payout' ) );
		add_action( 'admin_post_wcca_mark_month_payout', array( $this, 'handle_mark_month_payout' ) );
	}

	public function enqueue_payouts_admin_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'.wcca-payout-status-void{color:#b32d2e;font-weight:600;}'
		);
	}

	public static function get_payout_status_label( WC_Order $order ): string {
		return WC_Coupon_Affiliation_Plugin::get_commission_payout_status_label( $order );
	}

	public static function is_payout_paid( WC_Order $order ): bool {
		return WC_Coupon_Affiliation_Plugin::PAYOUT_STATUS_PAID === $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_PAYOUT_STATUS );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Ambassador Payouts', 'woocommerce-coupon-affiliation' ),
			__( 'Ambassador Payouts', 'woocommerce-coupon-affiliation' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woocommerce-coupon-affiliation' ) );
		}

		$view = isset( $_GET['view'] ) && 'transactions' === $_GET['view'] ? 'transactions' : 'summary';

		if ( 'transactions' === $view ) {
			$this->render_transactions_view();
			return;
		}

		$this->render_summary_view();
	}

	private function get_filter_month(): string {
		if ( empty( $_GET['filter_month'] ) ) {
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_GET['filter_month'] ) );
		return preg_match( '/^\d{4}-\d{2}$/', $raw ) ? $raw : '';
	}

	private function get_filter_ambassador_id(): int {
		return isset( $_GET['filter_ambassador'] ) ? absint( $_GET['filter_ambassador'] ) : 0;
	}

	/**
	 * Calendar month Y-m from order (completed date preferred).
	 */
	public static function get_order_bucket_month( WC_Order $order ): string {
		$dc = $order->get_date_completed();
		if ( $dc instanceof WC_DateTime ) {
			return wp_date( 'Y-m', $dc->getTimestamp(), wp_timezone() );
		}
		$c = $order->get_date_created();
		if ( $c instanceof WC_DateTime ) {
			return wp_date( 'Y-m', $c->getTimestamp(), wp_timezone() );
		}
		return '';
	}

	/**
	 * @return array{rows:list<array<string,mixed>>,totals:array{unpaid:float,paid:float},scanned:int}
	 */
	private function aggregate_attributed_orders( string $filter_month, int $filter_ambassador_id ): array {
		$buckets = array();
		$totals  = array(
			'unpaid' => 0.0,
			'paid'   => 0.0,
		);
		$scanned = 0;

		$offset = 0;
		while ( $scanned < self::SCAN_MAX ) {
			$orders = wc_get_orders(
				array(
					'limit'      => self::BATCH_SIZE,
					'offset'     => $offset,
					'orderby'    => 'date',
					'order'      => 'DESC',
					'status'     => 'any',
					'meta_query' => array(
						array(
							'key'     => WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID,
							'value'   => 0,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
					),
					'return'     => 'objects',
				)
			);

			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				++$scanned;
				if ( $scanned > self::SCAN_MAX ) {
					break 2;
				}

				$aid = absint( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID ) );
				if ( $aid <= 0 ) {
					continue;
				}

				$ym = self::get_order_bucket_month( $order );
				if ( '' === $ym ) {
					continue;
				}

				$commission = WC_Coupon_Affiliation_Plugin::get_order_ambassador_commission_float( $order );

				$include_in_totals = true;
				if ( $filter_month && $ym !== $filter_month ) {
					$include_in_totals = false;
				}
				if ( $filter_ambassador_id && $aid !== $filter_ambassador_id ) {
					$include_in_totals = false;
				}
				if ( $include_in_totals ) {
					if ( WC_Coupon_Affiliation_Plugin::counts_toward_paid_total( $order ) ) {
						$totals['paid'] += $commission;
					} elseif ( WC_Coupon_Affiliation_Plugin::counts_toward_unpaid_payable_total( $order ) ) {
						$totals['unpaid'] += $commission;
					}
				}

				$include_in_rows = true;
				if ( $filter_month && $ym !== $filter_month ) {
					$include_in_rows = false;
				}
				if ( $filter_ambassador_id && $aid !== $filter_ambassador_id ) {
					$include_in_rows = false;
				}
				if ( ! $include_in_rows ) {
					continue;
				}

				$key = $aid . '|' . $ym;
				if ( ! isset( $buckets[ $key ] ) ) {
					$user = get_userdata( $aid );
					$buckets[ $key ] = array(
						'month'           => $ym,
						'ambassador_id'   => $aid,
						'ambassador_name' => $user ? $user->display_name : (string) $aid,
						'unpaid'          => 0.0,
						'paid'            => 0.0,
						'order_count'     => 0,
					);
				}
				$buckets[ $key ]['order_count']++;
				if ( WC_Coupon_Affiliation_Plugin::counts_toward_paid_total( $order ) ) {
					$buckets[ $key ]['paid'] += $commission;
				} elseif ( WC_Coupon_Affiliation_Plugin::counts_toward_unpaid_payable_total( $order ) ) {
					$buckets[ $key ]['unpaid'] += $commission;
				}
			}

			if ( count( $orders ) < self::BATCH_SIZE ) {
				break;
			}
			$offset += self::BATCH_SIZE;
		}

		$rows = array_values( $buckets );
		usort(
			$rows,
			static function ( $a, $b ) {
				$c = strcmp( $b['month'], $a['month'] );
				if ( 0 !== $c ) {
					return $c;
				}
				return strcasecmp( $a['ambassador_name'], $b['ambassador_name'] );
			}
		);

		return array(
			'rows'    => $rows,
			'totals'  => $totals,
			'scanned' => $scanned,
		);
	}

	/**
	 * @return list<\WC_Order>
	 */
	private function collect_orders_for_month( string $month, int $ambassador_id ): array {
		$out    = array();
		$offset = 0;

		while ( count( $out ) < self::TRANSACTIONS_CAP && $offset < self::SCAN_MAX ) {
			$orders = wc_get_orders(
				array(
					'limit'      => self::BATCH_SIZE,
					'offset'     => $offset,
					'orderby'    => 'date',
					'order'      => 'DESC',
					'status'     => 'any',
					'meta_query' => array(
						array(
							'key'     => WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID,
							'value'   => 0,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
					),
					'return'     => 'objects',
				)
			);

			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$aid = absint( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID ) );
				if ( $aid <= 0 ) {
					continue;
				}
				if ( $ambassador_id && $aid !== $ambassador_id ) {
					continue;
				}
				if ( self::get_order_bucket_month( $order ) !== $month ) {
					continue;
				}
				$out[] = $order;
				if ( count( $out ) >= self::TRANSACTIONS_CAP ) {
					break 2;
				}
			}

			if ( count( $orders ) < self::BATCH_SIZE ) {
				break;
			}
			$offset += self::BATCH_SIZE;
		}

		return $out;
	}

	/**
	 * Mark every unpaid commission paid for orders in the given ambassador + calendar month (full store scan, batched).
	 */
	private function mark_all_unpaid_paid_in_month_ambassador( string $month, int $ambassador_id ): void {
		$offset  = 0;
		$scanned = 0;

		while ( $scanned < self::SCAN_MAX ) {
			$orders = wc_get_orders(
				array(
					'limit'      => self::BATCH_SIZE,
					'offset'     => $offset,
					'orderby'    => 'date',
					'order'      => 'DESC',
					'status'     => 'any',
					'meta_query' => array(
						array(
							'key'     => WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID,
							'value'   => 0,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
					),
					'return'     => 'objects',
				)
			);

			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				++$scanned;
				if ( $scanned > self::SCAN_MAX ) {
					break 2;
				}

				$aid = absint( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID ) );
				if ( $aid !== $ambassador_id ) {
					continue;
				}
				if ( self::get_order_bucket_month( $order ) !== $month ) {
					continue;
				}
				if ( self::is_payout_paid( $order ) ) {
					continue;
				}
				if ( ! WC_Coupon_Affiliation_Plugin::counts_toward_unpaid_payable_total( $order ) ) {
					continue;
				}
				$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_PAYOUT_STATUS, WC_Coupon_Affiliation_Plugin::PAYOUT_STATUS_PAID );
				$order->save();
			}

			if ( count( $orders ) < self::BATCH_SIZE ) {
				break;
			}
			$offset += self::BATCH_SIZE;
		}
	}

	private function render_notices(): void {
		if ( ! empty( $_GET['wcca_updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Payout status updated.', 'woocommerce-coupon-affiliation' ) . '</p></div>';
		}
		if ( ! empty( $_GET['wcca_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not update payout status.', 'woocommerce-coupon-affiliation' ) . '</p></div>';
		}
		if ( ! empty( $_GET['wcca_month_updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Month marked as paid for matching orders.', 'woocommerce-coupon-affiliation' ) . '</p></div>';
		}
	}

	private function render_filter_form_summary( string $filter_month, int $filter_ambassador_id ): void {
		echo '<form method="get" class="wcca-payout-filters" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';

		echo '<label for="wcca-filter-month">' . esc_html__( 'Month', 'woocommerce-coupon-affiliation' ) . '</label> ';
		echo '<select name="filter_month" id="wcca-filter-month">';
		echo '<option value="">' . esc_html__( 'All months', 'woocommerce-coupon-affiliation' ) . '</option>';
		$tz     = wp_timezone();
		$cursor = ( new DateTimeImmutable( 'now', $tz ) )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		for ( $i = 0; $i < 24; $i++ ) {
			$ym = $cursor->format( 'Y-m' );
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $ym ),
				selected( $ym, $filter_month, false ),
				esc_html( wp_date( 'F Y', $cursor->getTimestamp(), $tz ) )
			);
			$cursor = $cursor->modify( '-1 month' );
		}
		echo '</select> ';

		echo '<label for="wcca-filter-amb">' . esc_html__( 'Ambassador', 'woocommerce-coupon-affiliation' ) . '</label> ';
		echo '<select name="filter_ambassador" id="wcca-filter-amb">';
		echo '<option value="0">' . esc_html__( 'All ambassadors', 'woocommerce-coupon-affiliation' ) . '</option>';
		$users = get_users(
			array(
				'role'    => WC_Coupon_Affiliation_Plugin::ROLE_AMBASSADOR,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);
		foreach ( $users as $u ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $u->ID,
				selected( (int) $u->ID, $filter_ambassador_id, false ),
				esc_html( $u->display_name )
			);
		}
		echo '</select> ';

		submit_button( __( 'Filter', 'woocommerce-coupon-affiliation' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function render_summary_view(): void {
		$filter_month          = $this->get_filter_month();
		$filter_ambassador_id  = $this->get_filter_ambassador_id();
		$data                  = $this->aggregate_attributed_orders( $filter_month, $filter_ambassador_id );
		$rows                  = $data['rows'];
		$totals                = $data['totals'];
		$scanned               = $data['scanned'];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Ambassador Payouts', 'woocommerce-coupon-affiliation' ) . '</h1>';

		$this->render_notices();

		echo '<div class="wcca-payout-stats" style="display:flex;gap:2rem;margin:1em 0;">';
		echo '<div><strong>' . esc_html__( 'Total commission payable (filtered)', 'woocommerce-coupon-affiliation' ) . '</strong><br />';
		echo wp_kses_post( wc_price( $totals['unpaid'] ) ) . '</div>';
		echo '<div><strong>' . esc_html__( 'Total paid (filtered)', 'woocommerce-coupon-affiliation' ) . '</strong><br />';
		echo wp_kses_post( wc_price( $totals['paid'] ) ) . '</div>';
		echo '</div>';

		if ( $scanned >= self::SCAN_MAX ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: max scanned orders */
					__( 'Summary is based on the first %d attributed orders only. Contact support to raise the cap for very large catalogs.', 'woocommerce-coupon-affiliation' ),
					self::SCAN_MAX
				)
			);
			echo '</p></div>';
		}

		$this->render_filter_form_summary( $filter_month, $filter_ambassador_id );

		$per_page     = 20;
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total_items  = count( $rows );
		$offset       = ( $current_page - 1 ) * $per_page;
		$paged_rows   = array_slice( $rows, $offset, $per_page );

		$table = new WC_Coupon_Affiliation_Payouts_Summary_List_Table();
		$table->set_rows( $paged_rows );
		$table->prepare_items();
		$table->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);

		echo '<h2 class="screen-reader-text">' . esc_html__( 'Monthly summary', 'woocommerce-coupon-affiliation' ) . '</h2>';
		$table->display();

		echo '</div>';
	}

	private function render_transactions_view(): void {
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Please select a valid month from the summary.', 'woocommerce-coupon-affiliation' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Back to monthly summary', 'woocommerce-coupon-affiliation' ) . '</a></p></div>';
			return;
		}

		$ambassador_id = isset( $_GET['ambassador_id'] ) ? absint( $_GET['ambassador_id'] ) : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Ambassador transactions', 'woocommerce-coupon-affiliation' ) . '</h1>';

		$this->render_notices();

		$back_args = array(
			'page'         => self::PAGE_SLUG,
			'filter_month' => $month,
		);
		if ( $ambassador_id ) {
			$back_args['filter_ambassador'] = $ambassador_id;
		}
		$back = add_query_arg( $back_args, admin_url( 'admin.php' ) );

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to monthly summary', 'woocommerce-coupon-affiliation' ) . '</a></p>';

		$all_orders = $this->collect_orders_for_month( $month, $ambassador_id );
		$total      = count( $all_orders );
		$per_page   = 20;
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$slice      = array_slice( $all_orders, ( $paged - 1 ) * $per_page, $per_page );

		if ( $total >= self::TRANSACTIONS_CAP ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: max orders */
					__( 'Showing at most %d orders for this filter.', 'woocommerce-coupon-affiliation' ),
					self::TRANSACTIONS_CAP
				)
			);
			echo '</p></div>';
		}

		$table = new WC_Coupon_Affiliation_Payouts_Transactions_List_Table();
		$table->set_context( $month, $ambassador_id );
		$table->set_orders( $slice );
		$table->prepare_items();
		$table->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
		$table->display();

		echo '</div>';
	}

	public function handle_mark_order_payout(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'woocommerce-coupon-affiliation' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$req_month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$req_amb   = null;
		if ( isset( $_GET['ambassador_id'] ) ) {
			$req_amb = absint( wp_unslash( $_GET['ambassador_id'] ) );
		}

		if ( ! $order_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wcca_payout_' . $order_id ) ) {
			wp_safe_redirect( $this->redirect_transactions_url( '', 0, 'error' ) );
			exit;
		}

		$allowed = array(
			WC_Coupon_Affiliation_Plugin::PAYOUT_STATUS_PAID,
			WC_Coupon_Affiliation_Plugin::PAYOUT_STATUS_UNPAID,
		);
		if ( ! in_array( $status, $allowed, true ) ) {
			wp_safe_redirect( $this->redirect_transactions_url( '', 0, 'error' ) );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_safe_redirect( $this->redirect_transactions_url( '', 0, 'error' ) );
			exit;
		}

		$aid = absint( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID ) );
		if ( $aid <= 0 ) {
			wp_safe_redirect( $this->redirect_transactions_url( '', 0, 'error' ) );
			exit;
		}

		if ( WC_Coupon_Affiliation_Plugin::order_blocks_manual_payout_edit( $order ) ) {
			$month = preg_match( '/^\d{4}-\d{2}$/', $req_month ) ? $req_month : self::get_order_bucket_month( $order );
			$amb   = null !== $req_amb ? $req_amb : $aid;
			wp_safe_redirect( $this->redirect_transactions_url( $month, $amb, 'error' ) );
			exit;
		}

		$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_PAYOUT_STATUS, $status );
		$order->save();

		$month = preg_match( '/^\d{4}-\d{2}$/', $req_month ) ? $req_month : self::get_order_bucket_month( $order );
		$amb   = null !== $req_amb ? $req_amb : $aid;
		wp_safe_redirect( $this->redirect_transactions_url( $month, $amb, 'updated' ) );
		exit;
	}

	public function handle_mark_month_payout(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'woocommerce-coupon-affiliation' ) );
		}

		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$amb   = isset( $_GET['ambassador_id'] ) ? absint( $_GET['ambassador_id'] ) : 0;

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) || ! $amb ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&wcca_error=1' ) );
			exit;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wcca_payout_month_' . $month . '_' . $amb ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&wcca_error=1' ) );
			exit;
		}

		$this->mark_all_unpaid_paid_in_month_ambassador( $month, $amb );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => self::PAGE_SLUG,
					'wcca_month_updated' => '1',
					'filter_month'       => $month,
					'filter_ambassador'  => $amb,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function redirect_transactions_url( string $month, int $ambassador_id, string $flag ): string {
		if ( '' === $month ) {
			return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&wcca_' . $flag . '=1' );
		}
		return add_query_arg(
			array(
				'page'          => self::PAGE_SLUG,
				'view'          => 'transactions',
				'month'         => $month,
				'ambassador_id' => $ambassador_id,
				'wcca_' . $flag => '1',
			),
			admin_url( 'admin.php' )
		);
	}
}

require_once __DIR__ . '/class-wc-coupon-affiliation-payouts-summary-list-table.php';
require_once __DIR__ . '/class-wc-coupon-affiliation-payouts-transactions-list-table.php';
