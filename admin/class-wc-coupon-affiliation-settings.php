<?php
/**
 * WooCommerce settings tab for Coupon Affiliation.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WC_Settings_Page', false ) ) {

	/**
	 * Adds a new tab to WooCommerce -> Settings.
	 */
	final class WC_Coupon_Affiliation_Settings extends WC_Settings_Page {

		public function __construct() {
			$this->id    = 'wcca_settings';
			$this->label = __( 'Affiliation', 'woocommerce-coupon-affiliation' );

			parent::__construct();
		}

		/**
		 * Get settings array.
		 *
		 * @return array
		 */
		public function get_settings() {
			$settings = array(
				array(
					'title' => __( 'Mailerlite Integration', 'woocommerce-coupon-affiliation' ),
					'type'  => 'title',
					'desc'  => __( 'Configure Mailerlite API to automatically attribute orders based on a customer\'s Mailerlite groups/tags.', 'woocommerce-coupon-affiliation' ),
					'id'    => 'wcca_mailerlite_options',
				),
				array(
					'title'    => __( 'API Token (v3)', 'woocommerce-coupon-affiliation' ),
					'desc'     => __( 'Your Mailerlite API v3 Token. Leave empty to disable Mailerlite integration.', 'woocommerce-coupon-affiliation' ),
					'id'       => 'wcca_mailerlite_api_token',
					'type'     => 'password',
					'css'      => 'min-width:300px;',
					'default'  => '',
					'desc_tip' => true,
				),
				array(
					'type' => 'sectionend',
					'id'   => 'wcca_mailerlite_options',
				),
			);

			return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings );
		}
	}

	return new WC_Coupon_Affiliation_Settings();
}
