<?php
/**
 * Order completion: attribute ambassador from coupons and store commission.
 *
 * Uses the first applied coupon (in order) that has a valid assigned Ambassador.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs once per order when status becomes completed.
 */
final class WC_Coupon_Affiliation_Order_Tracking {

	public function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 10, 2 );
	}

	/**
	 * @param int|\WC_Order $order_id Order ID (WC core) or order if only one arg is passed by a third party.
	 * @param \WC_Order|null $order    Order object (WC core passes this as the second argument).
	 */
	public function on_order_completed( $order_id, $order = null ): void {
		if ( $order instanceof WC_Order ) {
			$id = $order->get_id();
		} elseif ( $order_id instanceof WC_Order ) {
			$id = $order_id->get_id();
		} else {
			$id = absint( $order_id );
		}

		if ( ! $id ) {
			return;
		}

		$this->maybe_attribute_order( $id );
	}

	private function maybe_attribute_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->get_meta( WC_Coupon_Affiliation_Plugin::META_SALES_ATTRIBUTION_DONE ) ) {
			return;
		}

		$ambassador_user_id = $this->resolve_ambassador_from_coupons( $order );

		// Net commission base: subtotal minus order discounts (excludes tax and shipping for typical WC orders).
		$net_base = max( 0.0, (float) $order->get_subtotal() - (float) $order->get_discount_total() );

		$commission = wc_format_decimal( 0 );

		if ( $ambassador_user_id > 0 ) {
			$percent    = $this->get_ambassador_commission_percent( $ambassador_user_id );
			$amount     = $net_base * ( $percent / 100.0 );
			$commission = wc_format_decimal( $amount );

			$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID, $ambassador_user_id );
			$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_COMMISSION, $commission );
			$order->update_meta_data(
				WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_RATE_APPLIED,
				wc_format_decimal( $percent )
			);
			$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_PAYOUT_STATUS, WC_Coupon_Affiliation_Plugin::PAYOUT_STATUS_UNPAID );
		} else {
			$order->delete_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID );
			$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_COMMISSION, wc_format_decimal( 0 ) );
			$order->delete_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_RATE_APPLIED );
			$order->delete_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_COMMISSION_PAYOUT_STATUS );
		}

		$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_SALES_ATTRIBUTION_DONE, '1' );
		$order->save();

		if ( $ambassador_user_id > 0 ) {
			$this->notify_ambassador_commission_email( $order, $ambassador_user_id, $net_base, $commission );
		}
	}

	/**
	 * Notify ambassador by email (first attribution only; caller ensures ambassador > 0).
	 *
	 * @param string $commission_decimal Formatted decimal string stored on the order.
	 */
	private function notify_ambassador_commission_email( WC_Order $order, int $ambassador_user_id, float $net_base, string $commission_decimal ): void {
		$user = get_userdata( $ambassador_user_id );
		if ( ! $user || empty( $user->user_email ) || ! is_email( $user->user_email ) ) {
			error_log(
				sprintf(
					'[WCCA] Commission email skipped: invalid or missing email (order_id=%d, ambassador_user=%d).',
					$order->get_id(),
					$ambassador_user_id
				)
			);
			return;
		}

		$order_ref = $order->get_order_number();
		$subject   = sprintf(
			/* translators: %s: order number */
			__( 'New Commission Earned! (Order #%s)', 'woocommerce-coupon-affiliation' ),
			$order_ref
		);

		$currency = $order->get_currency();
		$net_html = wc_price( $net_base, array( 'currency' => $currency ) );
		$comm_amt = (float) wc_format_decimal( $commission_decimal );
		$comm_html = wc_price( $comm_amt, array( 'currency' => $currency ) );

		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
		$dashboard   = '';
		if ( $account_url ) {
			$dashboard = wc_get_endpoint_url( 'ambassador-stats', '', $account_url );
		}

		$body_lines = array(
			sprintf(
				/* translators: %s: ambassador display name */
				__( 'Hello %s,', 'woocommerce-coupon-affiliation' ),
				$user->display_name
			),
			'',
			sprintf(
				/* translators: %s: order number */
				__( 'Great news! An order (#%s) using your coupon has been completed.', 'woocommerce-coupon-affiliation' ),
				$order_ref
			),
			sprintf(
				/* translators: %s: formatted price (plain text) */
				__( 'Net Order Value: %s', 'woocommerce-coupon-affiliation' ),
				wp_strip_all_tags( $net_html )
			),
			sprintf(
				/* translators: %s: formatted price (plain text) */
				__( 'Your Commission: %s', 'woocommerce-coupon-affiliation' ),
				wp_strip_all_tags( $comm_html )
			),
		);

		if ( $dashboard ) {
			$body_lines[] = '';
			$body_lines[] = sprintf(
				/* translators: %s: URL to ambassador panel */
				__( 'Check your dashboard for details: %s', 'woocommerce-coupon-affiliation' ),
				$dashboard
			);
		} else {
			error_log( '[WCCA] Commission email: my account permalink missing; dashboard URL omitted.' );
		}

		$body    = implode( "\n", $body_lines );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $user->user_email, $subject, $body, $headers );

		if ( $sent ) {
			error_log(
				sprintf(
					'[WCCA] Commission email sent order_id=%d ambassador_user=%d',
					$order->get_id(),
					$ambassador_user_id
				)
			);
		} else {
			error_log(
				sprintf(
					'[WCCA] Commission email FAILED order_id=%d ambassador_user=%d',
					$order->get_id(),
					$ambassador_user_id
				)
			);
		}
	}

	/**
	 * First coupon code with a valid ambassador assignment wins.
	 */
	private function resolve_ambassador_from_coupons( WC_Order $order ): int {
		$codes = $order->get_coupon_codes();
		if ( empty( $codes ) ) {
			return 0;
		}

		foreach ( $codes as $code ) {
			if ( ! is_string( $code ) || '' === $code ) {
				continue;
			}

			$coupon_id = function_exists( 'wc_get_coupon_id_by_code' ) ? absint( wc_get_coupon_id_by_code( $code ) ) : 0;
			if ( ! $coupon_id ) {
				continue;
			}

			$uid = absint( get_post_meta( $coupon_id, WC_Coupon_Affiliation_Plugin::META_ASSIGNED_AMBASSADOR, true ) );
			if ( ! $uid ) {
				continue;
			}

			$user = get_userdata( $uid );
			if ( ! $user || ! in_array( WC_Coupon_Affiliation_Plugin::ROLE_AMBASSADOR, (array) $user->roles, true ) ) {
				continue;
			}

			return $uid;
		}

		return 0;
	}

	/**
	 * Effective commission percent for an ambassador (0–100).
	 */
	private function get_ambassador_commission_percent( int $user_id ): float {
		$raw = get_user_meta( $user_id, WC_Coupon_Affiliation_Plugin::META_USER_AMBASSADOR_COMMISSION_RATE, true );
		if ( '' === $raw || null === $raw ) {
			return (float) WC_Coupon_Affiliation_Plugin::DEFAULT_AMBASSADOR_COMMISSION_RATE;
		}

		$percent = (float) wc_format_decimal( $raw );
		return min( 100.0, max( 0.0, $percent ) );
	}
}
