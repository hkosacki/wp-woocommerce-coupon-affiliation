<?php
/**
 * WP admin user profile: show MailerLite signup form for the user’s email.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only MailerLite block on Users → Profile / Edit user.
 */
final class WC_Coupon_Affiliation_User_Mailerlite_Panel {

	private const TRANSIENT_PREFIX = 'wcca_ml_user_signup_';

	private const CACHE_TTL = 600;

	private const NONCE_ACTION = 'wcca_ml_profile_refresh';

	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'show_user_profile', array( $this, 'render_panel' ) );
		add_action( 'edit_user_profile', array( $this, 'render_panel' ) );
	}

	/**
	 * @param \WP_User $user User being viewed.
	 */
	public function render_panel( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$api_token = get_option( 'wcca_mailerlite_api_token', '' );
		if ( ! is_string( $api_token ) || '' === trim( $api_token ) ) {
			echo '<h2>' . esc_html__( 'MailerLite', 'woocommerce-coupon-affiliation' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'MailerLite API token is not configured (WooCommerce → Settings → Affiliation).', 'woocommerce-coupon-affiliation' ) . '</p>';
			return;
		}

		$email = trim( (string) $user->user_email );
		if ( '' === $email || ! is_email( $email ) ) {
			echo '<h2>' . esc_html__( 'MailerLite', 'woocommerce-coupon-affiliation' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'This user has no valid email address; MailerLite lookup is skipped.', 'woocommerce-coupon-affiliation' ) . '</p>';
			return;
		}

		$bust = false;
		if ( isset( $_GET['wcca_ml_refresh'], $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION . '_' . $user->ID ) ) {
			$bust = true;
			delete_transient( $this->cache_key( $user->ID, $email ) );
		}

		$cached = $bust ? false : get_transient( $this->cache_key( $user->ID, $email ) );
		if ( false !== $cached && is_array( $cached ) ) {
			$this->render_result_table( $cached, $user->ID, $email, true );
			return;
		}

		$result = WC_Coupon_Affiliation_Mailerlite_Signup::get_signup_form_for_email( $api_token, $email, $bust );

		$store = array(
			'ok'        => ! empty( $result['ok'] ),
			'form_id'   => isset( $result['form_id'] ) ? (string) $result['form_id'] : '',
			'form_name' => isset( $result['form_name'] ) ? (string) $result['form_name'] : '',
			'code'      => isset( $result['code'] ) ? (string) $result['code'] : '',
			'message'   => isset( $result['message'] ) ? (string) $result['message'] : '',
		);

		set_transient( $this->cache_key( $user->ID, $email ), $store, self::CACHE_TTL );

		$this->render_result_table( $store, $user->ID, $email, false );
	}

	/**
	 * @param array<string, mixed> $store Cached or fresh result.
	 */
	private function render_result_table( array $store, int $user_id, string $email, bool $from_cache ): void {
		echo '<h2>' . esc_html__( 'MailerLite signup', 'woocommerce-coupon-affiliation' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Lookup email', 'woocommerce-coupon-affiliation' ) . '</th><td>' . esc_html( $email ) . '</td></tr>';

		if ( ! empty( $store['ok'] ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Signup form (through)', 'woocommerce-coupon-affiliation' ) . '</th><td>';
			echo esc_html( '' !== $store['form_name'] ? $store['form_name'] : '—' );
			if ( '' !== $store['form_id'] ) {
				echo '<br /><span class="description">' . esc_html__( 'Form ID:', 'woocommerce-coupon-affiliation' ) . ' ' . esc_html( $store['form_id'] ) . '</span>';
			}
			echo '</td></tr>';
		} else {
			$msg = '' !== $store['message']
				? $store['message']
				: __( 'Could not resolve signup form.', 'woocommerce-coupon-affiliation' );
			echo '<tr><th scope="row">' . esc_html__( 'Status', 'woocommerce-coupon-affiliation' ) . '</th><td>' . esc_html( $msg );
			if ( '' !== $store['code'] ) {
				echo ' <span class="description">(' . esc_html( $store['code'] ) . ')</span>';
			}
			echo '</td></tr>';
		}

		if ( $from_cache ) {
			echo '<tr><th scope="row"></th><td><span class="description">' . esc_html__( 'Showing cached result.', 'woocommerce-coupon-affiliation' ) . '</span></td></tr>';
		}

		$refresh = wp_nonce_url(
			add_query_arg( 'wcca_ml_refresh', '1', get_edit_user_link( $user_id ) ),
			self::NONCE_ACTION . '_' . $user_id,
			'_wpnonce'
		);

		echo '<tr><th scope="row"></th><td><a class="button" href="' . esc_url( $refresh ) . '">' . esc_html__( 'Refresh from MailerLite', 'woocommerce-coupon-affiliation' ) . '</a></td></tr>';

		echo '</tbody></table>';
	}

	private function cache_key( int $user_id, string $email ): string {
		return self::TRANSIENT_PREFIX . $user_id . '_' . md5( strtolower( $email ) );
	}
}
