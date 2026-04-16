<?php
/**
 * WooCommerce coupon admin UI and list table column.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coupon edit screen field and coupons list column for assigned ambassador.
 */
final class WC_Coupon_Affiliation_Admin {

	public function __construct() {
		add_action( 'woocommerce_coupon_options', array( $this, 'render_assigned_ambassador_field' ) );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_assigned_ambassador' ), 10, 2 );
		add_filter( 'manage_shop_coupon_posts_columns', array( $this, 'add_coupon_list_column' ) );
		add_action( 'manage_shop_coupon_posts_custom_column', array( $this, 'render_coupon_list_column' ), 10, 2 );
	}

	/**
	 * General tab: searchable select of users with Ambassador role.
	 */
	public function render_assigned_ambassador_field(): void {
		global $post;

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$users = get_users(
			array(
				'role'    => WC_Coupon_Affiliation_Plugin::ROLE_AMBASSADOR,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		$options = array(
			'' => __( '— None —', 'woocommerce-coupon-affiliation' ),
		);

		foreach ( $users as $user ) {
			$options[ (string) $user->ID ] = $user->display_name;
		}

		$current = absint( get_post_meta( $post->ID, WC_Coupon_Affiliation_Plugin::META_ASSIGNED_AMBASSADOR, true ) );

		woocommerce_wp_select(
			array(
				'id'          => WC_Coupon_Affiliation_Plugin::META_ASSIGNED_AMBASSADOR,
				'label'       => __( 'Assigned Ambassador', 'woocommerce-coupon-affiliation' ),
				'options'     => $options,
				'value'       => $current > 0 ? (string) $current : '',
				'class'       => 'wc-enhanced-select',
				'description' => '',
			)
		);
	}

	/**
	 * Persist _assigned_ambassador_id; validate user is an Ambassador.
	 *
	 * @param int        $post_id Coupon post ID.
	 * @param \WC_Coupon $coupon  Coupon object (unused; WC passes it before persisting).
	 */
	public function save_assigned_ambassador( $post_id, $coupon ): void {
		// $coupon is provided by WooCommerce; assignment is stored as post meta only.
		unset( $coupon );

		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$meta_key = WC_Coupon_Affiliation_Plugin::META_ASSIGNED_AMBASSADOR;
		if ( ! isset( $_POST[ $meta_key ] ) ) {
			return;
		}

		$raw = sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) );
		$uid = absint( $raw );

		if ( $uid <= 0 ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		$user = get_userdata( $uid );
		if ( ! $user || ! in_array( WC_Coupon_Affiliation_Plugin::ROLE_AMBASSADOR, (array) $user->roles, true ) ) {
			return;
		}

		update_post_meta( $post_id, $meta_key, $uid );
	}

	/**
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_coupon_list_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'code' === $key ) {
				$new['ambassador'] = __( 'Ambassador', 'woocommerce-coupon-affiliation' );
			}
		}
		if ( ! isset( $new['ambassador'] ) ) {
			$new['ambassador'] = __( 'Ambassador', 'woocommerce-coupon-affiliation' );
		}
		return $new;
	}

	/**
	 * @param string $column  Column key.
	 * @param mixed  $post_id Coupon post ID.
	 */
	public function render_coupon_list_column( $column, $post_id ): void {
		if ( 'ambassador' !== $column ) {
			return;
		}

		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		$user_id = absint( get_post_meta( $post_id, WC_Coupon_Affiliation_Plugin::META_ASSIGNED_AMBASSADOR, true ) );
		if ( ! $user_id ) {
			echo '&mdash;';
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			echo '&mdash;';
			return;
		}

		echo esc_html( $user->display_name );
	}
}
