<?php
/**
 * Ambassador-only user profile field: commission rate (%).
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves _ambassador_commission_rate for users with the ambassador role.
 */
final class WC_Coupon_Affiliation_Ambassador_Profile {

	private const POST_FIELD = 'wcca_ambassador_commission_rate';

	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'show_user_profile', array( $this, 'render_commission_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_commission_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_commission_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_commission_field' ) );
	}

	private function user_is_ambassador( WP_User $user ): bool {
		return in_array( WC_Coupon_Affiliation_Plugin::ROLE_AMBASSADOR, (array) $user->roles, true );
	}

	/**
	 * @param \WP_User $user User being edited.
	 */
	public function render_commission_field( WP_User $user ): void {
		if ( ! $this->user_is_ambassador( $user ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$raw   = get_user_meta( $user->ID, WC_Coupon_Affiliation_Plugin::META_USER_AMBASSADOR_COMMISSION_RATE, true );
		$value = ( '' === $raw || null === $raw )
			? (string) WC_Coupon_Affiliation_Plugin::DEFAULT_AMBASSADOR_COMMISSION_RATE
			: (string) wc_format_decimal( (float) $raw );

		wp_nonce_field( 'wcca_save_ambassador_commission_' . $user->ID, 'wcca_ambassador_commission_nonce' );
		?>
		<h2><?php esc_html_e( 'Ambassador', 'woocommerce-coupon-affiliation' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( self::POST_FIELD ); ?>">
						<?php esc_html_e( 'Commission Rate (%)', 'woocommerce-coupon-affiliation' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						name="<?php echo esc_attr( self::POST_FIELD ); ?>"
						id="<?php echo esc_attr( self::POST_FIELD ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="small-text"
						min="0"
						max="100"
						step="0.01"
					/>
					<p class="description">
						<?php esc_html_e( 'Default is 20% if left empty on save. Commission is calculated from order subtotal minus discounts (excludes tax and shipping).', 'woocommerce-coupon-affiliation' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param int $user_id User ID.
	 */
	public function save_commission_field( int $user_id ): void {
		if ( ! isset( $_POST['wcca_ambassador_commission_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcca_ambassador_commission_nonce'] ) ), 'wcca_save_ambassador_commission_' . $user_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! $this->user_is_ambassador( $user ) ) {
			return;
		}

		$posted = isset( $_POST[ self::POST_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::POST_FIELD ] ) ) : '';
		if ( '' === $posted ) {
			$percent = (float) WC_Coupon_Affiliation_Plugin::DEFAULT_AMBASSADOR_COMMISSION_RATE;
		} else {
			$percent = (float) wc_format_decimal( $posted );
		}

		$percent = min( 100.0, max( 0.0, $percent ) );
		update_user_meta( $user_id, WC_Coupon_Affiliation_Plugin::META_USER_AMBASSADOR_COMMISSION_RATE, wc_format_decimal( $percent ) );
	}
}
