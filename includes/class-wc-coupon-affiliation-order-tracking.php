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

		$total   = (float) $order->get_total();
		$rate    = WC_Coupon_Affiliation_Plugin::COMMISSION_RATE;
		$amount  = $ambassador_user_id > 0 ? ( $total * $rate ) : 0.0;
		$commission = wc_format_decimal( $amount );

		if ( $ambassador_user_id > 0 ) {
			$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID, $ambassador_user_id );
			$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_COMMISSION, $commission );
		} else {
			$order->delete_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_ID );
			$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_ORDER_AMBASSADOR_COMMISSION, wc_format_decimal( 0 ) );
		}

		$order->update_meta_data( WC_Coupon_Affiliation_Plugin::META_SALES_ATTRIBUTION_DONE, '1' );
		$order->save();
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
}
