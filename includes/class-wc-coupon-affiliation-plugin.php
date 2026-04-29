<?php
/**
 * Core plugin singleton and activation.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wc-coupon-affiliation-order-query.php';
require_once __DIR__ . '/class-wc-coupon-affiliation-mailerlite-signup.php';
require_once dirname( __DIR__ ) . '/admin/class-wc-coupon-affiliation-ambassador-profile.php';
require_once dirname( __DIR__ ) . '/admin/class-wc-coupon-affiliation-user-mailerlite-panel.php';
require_once __DIR__ . '/class-wc-coupon-affiliation-order-tracking.php';
require_once dirname( __DIR__ ) . '/admin/class-wc-coupon-affiliation-order-admin.php';
require_once __DIR__ . '/class-wc-coupon-affiliation-ambassador-dashboard.php';
require_once dirname( __DIR__ ) . '/admin/class-wc-coupon-affiliation-payouts-admin.php';

/**
 * Main plugin bootstrap: loads admin integration when WooCommerce is active.
 */
final class WC_Coupon_Affiliation_Plugin {

	public const ROLE_AMBASSADOR = 'ambassador';

	/**
	 * Comma-separated tokens matching MailerLite signup form name (“through” line).
	 */
	public const META_USER_MAILERLITE_SIGNUP_MATCH = '_ambassador_mailerlite_signup_match';

	/** User meta: commission percent (0–100). Empty means use default at attribution time. */
	public const META_USER_AMBASSADOR_COMMISSION_RATE = '_ambassador_commission_rate';

	/** Default commission percent when user meta is unset. */
	public const DEFAULT_AMBASSADOR_COMMISSION_RATE = 20;

	public const META_ORDER_AMBASSADOR_ID = '_order_ambassador_id';

	public const META_ORDER_AMBASSADOR_COMMISSION = '_order_ambassador_commission';

	/** Snapshot of percent used when order was attributed (for admin display). */
	public const META_ORDER_COMMISSION_RATE_APPLIED = '_order_ambassador_commission_rate_applied';

	/** Bookkeeping: attribution has run for this order (idempotency). */
	public const META_SALES_ATTRIBUTION_DONE = '_wcca_sales_attribution_done';

	/** Admin payout: unpaid | paid | void (lowercase). void = unsuccessful order; commission 0. */
	public const META_ORDER_COMMISSION_PAYOUT_STATUS = '_commission_payout_status';

	public const PAYOUT_STATUS_UNPAID = 'unpaid';

	public const PAYOUT_STATUS_PAID = 'paid';

	public const PAYOUT_STATUS_VOID = 'void';

	public static function is_void_payout_status( $meta ): bool {
		return self::PAYOUT_STATUS_VOID === ( is_string( $meta ) ? $meta : '' );
	}

	public static function is_order_wc_terminal_for_commission( WC_Order $order ): bool {
		return in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true );
	}

	public static function get_order_ambassador_commission_float( WC_Order $order ): float {
		$raw = $order->get_meta( self::META_ORDER_AMBASSADOR_COMMISSION );
		if ( '' === $raw || null === $raw ) {
			return 0.0;
		}
		return (float) wc_format_decimal( $raw );
	}

	/**
	 * Payable unpaid: positive commission, not paid, not void, WC status still successful.
	 */
	public static function counts_toward_unpaid_payable_total( WC_Order $order ): bool {
		if ( self::is_order_wc_terminal_for_commission( $order ) ) {
			return false;
		}
		if ( self::is_void_payout_status( $order->get_meta( self::META_ORDER_COMMISSION_PAYOUT_STATUS ) ) ) {
			return false;
		}
		$meta = $order->get_meta( self::META_ORDER_COMMISSION_PAYOUT_STATUS );
		if ( self::PAYOUT_STATUS_PAID === $meta ) {
			return false;
		}
		return self::get_order_ambassador_commission_float( $order ) > 0.0;
	}

	public static function counts_toward_paid_total( WC_Order $order ): bool {
		if ( self::is_order_wc_terminal_for_commission( $order ) ) {
			return false;
		}
		if ( self::PAYOUT_STATUS_PAID !== $order->get_meta( self::META_ORDER_COMMISSION_PAYOUT_STATUS ) ) {
			return false;
		}
		return self::get_order_ambassador_commission_float( $order ) > 0.0;
	}

	public static function order_blocks_manual_payout_edit( WC_Order $order ): bool {
		return self::is_order_wc_terminal_for_commission( $order )
			|| self::is_void_payout_status( $order->get_meta( self::META_ORDER_COMMISSION_PAYOUT_STATUS ) );
	}

	public static function get_commission_payout_status_label( WC_Order $order ): string {
		$raw = $order->get_meta( self::META_ORDER_COMMISSION_PAYOUT_STATUS );
		if ( self::PAYOUT_STATUS_VOID === $raw ) {
			return __( 'Void', 'woocommerce-coupon-affiliation' );
		}
		if ( self::PAYOUT_STATUS_PAID === $raw ) {
			return __( 'Paid', 'woocommerce-coupon-affiliation' );
		}
		return __( 'Unpaid', 'woocommerce-coupon-affiliation' );
	}

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
	 * Register Ambassador role (same capabilities as Subscriber) and My Account rewrites.
	 */
	public static function activate(): void {
		if ( ! get_role( self::ROLE_AMBASSADOR ) ) {
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

		WC_Coupon_Affiliation_Ambassador_Dashboard::register_rewrite_endpoint();
		flush_rewrite_rules( true );
	}

	private function __construct() {
		new WC_Coupon_Affiliation_Ambassador_Profile();
		new WC_Coupon_Affiliation_User_Mailerlite_Panel();
		new WC_Coupon_Affiliation_Order_Tracking();
		new WC_Coupon_Affiliation_Order_Admin();
		new WC_Coupon_Affiliation_Ambassador_Dashboard();
		new WC_Coupon_Affiliation_Payouts_Admin();

		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );
	}

	/**
	 * @param \WC_Settings_Page[] $settings
	 * @return \WC_Settings_Page[]
	 */
	public function add_settings_page( $settings ) {
		$settings[] = include dirname( __DIR__ ) . '/admin/class-wc-coupon-affiliation-settings.php';
		return $settings;
	}
}
