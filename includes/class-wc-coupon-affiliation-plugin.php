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

/**
 * Main plugin bootstrap: loads admin integration when WooCommerce is active.
 */
final class WC_Coupon_Affiliation_Plugin {

	public const META_ASSIGNED_AMBASSADOR = '_assigned_ambassador_id';

	public const ROLE_AMBASSADOR = 'ambassador';

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
	}
}
