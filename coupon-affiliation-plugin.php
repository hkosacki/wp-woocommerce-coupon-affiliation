<?php
/**
 * Plugin Name: WooCommerce Coupon Affiliation
 * Description: System śledzenia sprzedaży dla ambasadorów na podstawie kodów rabatowych.
 * Version: 0.0.1
 * Author: HubLabs
 * Text Domain: woocommerce-coupon-affiliation
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-wc-coupon-affiliation-plugin.php';

register_activation_hook( __FILE__, array( 'WC_Coupon_Affiliation_Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			WC_Coupon_Affiliation_Plugin::get_instance();
		}
	},
	20
);
