<?php
/**
 * Core plugin singleton and activation.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/admin/class-wc-coupon-affiliation-admin.php';
require_once __DIR__ . '/class-wc-coupon-affiliation-order-tracking.php';
require_once dirname( __DIR__ ) . '/admin/class-wc-coupon-affiliation-order-admin.php';

/**
 * Main plugin bootstrap: loads admin integration when WooCommerce is active.
 */
final class WC_Coupon_Affiliation_Plugin {

	public const META_ASSIGNED_AMBASSADOR = '_assigned_ambassador_id';

	public const ROLE_AMBASSADOR = 'ambassador';

	/** @var float Flat commission rate (10%). */
	public const COMMISSION_RATE = 0.1;

	public const META_ORDER_AMBASSADOR_ID = '_order_ambassador_id';

	public const META_ORDER_AMBASSADOR_COMMISSION = '_order_ambassador_commission';

	/** Bookkeeping: attribution has run for this order (idempotency). */
	public const META_SALES_ATTRIBUTION_DONE = '_wcca_sales_attribution_done';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register Ambassador role (same capabilities as Subscriber).
	 */
	public static function activate(): void {
		if ( get_role( self::ROLE_AMBASSADOR ) ) {
			return;
		}

		$subscriber = get_role( 'subscriber' );
		$caps       = ( $subscriber && is_array( $subscriber->capabilities ) )
			? $subscriber->capabilities
			: array( 'read' => true );

		add_role(
			self::ROLE_AMBASSADOR,
			__( 'Ambassador', 'woocommerce-coupon-affiliation' ),
			$caps
		);
	}

	private function __construct() {
		new WC_Coupon_Affiliation_Admin();
		new WC_Coupon_Affiliation_Order_Tracking();
		new WC_Coupon_Affiliation_Order_Admin();
	}
}
