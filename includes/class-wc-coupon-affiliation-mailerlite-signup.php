<?php
/**
 * MailerLite: resolve subscriber signup form (“through” / form name) for an email.
 *
 * @package WooCommerce_Coupon_Affiliation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared MailerLite API helpers for attribution and admin verification.
 */
final class WC_Coupon_Affiliation_Mailerlite_Signup {

	private const FORMS_CACHE_KEY = 'wcca_mailerlite_forms_index_v1';

	private const FORMS_CACHE_TTL = 3600;

	private const MAX_FORM_TYPES_PAGES = 200;

	private const MAX_FORMS_TO_SCAN = 80;

	private const MAX_SUBSCRIBER_PAGES = 20;

	private const SUBSCRIBERS_LIMIT = 100;

	/**
	 * Clear cached form index (e.g. after structural changes in MailerLite).
	 */
	public static function bust_forms_cache(): void {
		delete_transient( self::FORMS_CACHE_KEY );
	}

	/**
	 * Resolve which ambassador (if any) should receive attribution for this email.
	 *
	 * @param string $api_token MailerLite v3 bearer token.
	 * @param string $email     Normalized billing email.
	 * @return int Ambassador user ID or 0.
	 */
	public static function resolve_ambassador_user_id( string $api_token, string $email ): int {
		$result = self::get_signup_form_for_email( $api_token, $email, false );
		if ( empty( $result['ok'] ) || empty( $result['form_name'] ) ) {
			return 0;
		}

		$form_name = (string) $result['form_name'];

		$user_ids = get_users(
			array(
				'role'    => WC_Coupon_Affiliation_Plugin::ROLE_AMBASSADOR,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$matched = array();
		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			$raw = get_user_meta( $uid, WC_Coupon_Affiliation_Plugin::META_USER_MAILERLITE_SIGNUP_MATCH, true );
			if ( empty( $raw ) || ! is_string( $raw ) ) {
				continue;
			}
			$tokens = array_map( 'trim', explode( ',', $raw ) );
			foreach ( $tokens as $token ) {
				if ( self::token_matches_form_name( $token, $form_name ) ) {
					$matched[] = $uid;
					break;
				}
			}
		}

		if ( empty( $matched ) ) {
			return 0;
		}

		if ( count( $matched ) > 1 ) {
			error_log(
				sprintf(
					'[WCCA] Multiple ambassadors match MailerLite signup form "%s" for email; using lowest user ID among: %s',
					$form_name,
					implode( ',', array_map( 'strval', $matched ) )
				)
			);
		}

		return (int) min( $matched );
	}

	/**
	 * @param string $token Plain match token from ambassador profile.
	 * @param string $form_name Resolved MailerLite form display name.
	 */
	public static function token_matches_form_name( string $token, string $form_name ): bool {
		$t = trim( $token );
		if ( '' === $t ) {
			return false;
		}
		if ( 0 === strcasecmp( $form_name, $t ) ) {
			return true;
		}
		return false !== stripos( $form_name, $t );
	}

	/**
	 * Find signup form id + name for a subscriber email.
	 *
	 * @param string $api_token           Bearer token.
	 * @param string $email               Subscriber email.
	 * @param bool   $bust_forms_transient When true, refetch form index instead of using transient.
	 * @return array{ok:bool, form_id:string, form_name:string, code:string, message:string}
	 */
	public static function get_signup_form_for_email( string $api_token, string $email, bool $bust_forms_transient = false ): array {
		$empty = array(
			'ok'        => false,
			'form_id'   => '',
			'form_name' => '',
			'code'      => '',
			'message'   => '',
		);

		$email = trim( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			$empty['code']    = 'invalid_email';
			$empty['message'] = 'Invalid email.';
			return $empty;
		}

		$encoded = rawurlencode( $email );
		$url     = 'https://connect.mailerlite.com/api/subscribers/' . $encoded;

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$empty['code']    = 'http_error';
			$empty['message'] = $response->get_error_message();
			return $empty;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			$empty['code']    = 'not_found';
			$empty['message'] = __( 'No subscriber with this email in MailerLite.', 'woocommerce-coupon-affiliation' );
			return $empty;
		}

		if ( 200 !== $status ) {
			$empty['code']    = 'api_error';
			$empty['message'] = sprintf(
				/* translators: %d: HTTP status code */
				__( 'MailerLite API returned HTTP %d.', 'woocommerce-coupon-affiliation' ),
				(int) $status
			);
			return $empty;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			$empty['code']    = 'parse_error';
			$empty['message'] = __( 'Unexpected MailerLite response.', 'woocommerce-coupon-affiliation' );
			return $empty;
		}

		$subscriber = $data['data'];
		$extracted  = self::extract_signup_form_from_subscriber_payload( $subscriber );
		if ( null !== $extracted && ( $extracted['form_name'] !== '' || $extracted['form_id'] !== '' ) ) {
			$form_id   = $extracted['form_id'];
			$form_name = $extracted['form_name'];
			if ( '' === $form_name && '' !== $form_id ) {
				foreach ( self::get_cached_form_index( $api_token, false ) as $f ) {
					if ( isset( $f['id'] ) && (string) $f['id'] === $form_id ) {
						$form_name = isset( $f['name'] ) ? (string) $f['name'] : '';
						break;
					}
				}
			}
			return array(
				'ok'        => true,
				'form_id'   => $form_id,
				'form_name' => $form_name,
				'code'      => 'subscriber_payload',
				'message'   => '',
			);
		}

		$forms = self::get_cached_form_index( $api_token, $bust_forms_transient );
		if ( empty( $forms ) ) {
			$empty['code']    = 'no_forms';
			$empty['message'] = __( 'Could not load MailerLite forms list.', 'woocommerce-coupon-affiliation' );
			return $empty;
		}

		$slice = array_slice( $forms, 0, self::MAX_FORMS_TO_SCAN );
		foreach ( array( 'active', 'unconfirmed' ) as $ml_status ) {
			foreach ( $slice as $form ) {
				$form_id = isset( $form['id'] ) ? (string) $form['id'] : '';
				if ( '' === $form_id ) {
					continue;
				}
				if ( self::email_in_form_subscribers( $api_token, $form_id, $email, $ml_status ) ) {
					return array(
						'ok'        => true,
						'form_id'   => $form_id,
						'form_name' => isset( $form['name'] ) ? (string) $form['name'] : '',
						'code'      => 'form_subscribers',
						'message'   => '',
					);
				}
			}
		}

		$empty['code']    = 'form_not_resolved';
		$empty['message'] = __( 'Subscriber exists, but signup form could not be determined (caps or MailerLite data).', 'woocommerce-coupon-affiliation' );
		return $empty;
	}

	/**
	 * Try to read form id/name from subscriber JSON if MailerLite exposes it.
	 *
	 * @param array<string, mixed> $subscriber Decoded `data` object.
	 * @return array{form_id:string, form_name:string}|null
	 */
	private static function extract_signup_form_from_subscriber_payload( array $subscriber ): ?array {
		$candidate_keys = array(
			'subscription_form',
			'signup_form',
			'subscribed_through',
			'subscribed_through_form',
			'form',
		);

		foreach ( $candidate_keys as $key ) {
			if ( empty( $subscriber[ $key ] ) || ! is_array( $subscriber[ $key ] ) ) {
				continue;
			}
			$block = $subscriber[ $key ];
			$id    = isset( $block['id'] ) ? (string) $block['id'] : '';
			$name  = isset( $block['name'] ) ? (string) $block['name'] : '';
			if ( '' !== $id || '' !== $name ) {
				return array(
					'form_id'   => $id,
					'form_name' => $name,
				);
			}
		}

		return null;
	}

	/**
	 * @return list<array{id:string, name:string}>
	 */
	private static function get_cached_form_index( string $api_token, bool $bust ): array {
		if ( ! $bust ) {
			$cached = get_transient( self::FORMS_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$all = array();
		foreach ( array( 'popup', 'embedded', 'promotion' ) as $type ) {
			$page = 1;
			while ( $page <= self::MAX_FORM_TYPES_PAGES ) {
				$url = sprintf(
					'https://connect.mailerlite.com/api/forms/%s?page=%d&limit=100',
					rawurlencode( $type ),
					$page
				);

				$response = wp_remote_get(
					$url,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $api_token,
							'Accept'        => 'application/json',
						),
						'timeout' => 15,
					)
				);

				if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
					break;
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! is_array( $body ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
					break;
				}

				foreach ( $body['data'] as $row ) {
					if ( ! is_array( $row ) || empty( $row['id'] ) ) {
						continue;
					}
					$all[] = array(
						'id'   => (string) $row['id'],
						'name' => isset( $row['name'] ) ? (string) $row['name'] : '',
					);
				}

				$last = isset( $body['meta']['last_page'] ) ? (int) $body['meta']['last_page'] : $page;
				if ( $page >= $last ) {
					break;
				}
				++$page;
			}
		}

		set_transient( self::FORMS_CACHE_KEY, $all, self::FORMS_CACHE_TTL );
		return $all;
	}

	private static function email_in_form_subscribers( string $api_token, string $form_id, string $email, string $status ): bool {
		$want = strtolower( trim( $email ) );
		$cursor = null;
		$pages  = 0;

		while ( $pages < self::MAX_SUBSCRIBER_PAGES ) {
			$q = array(
				'limit'          => self::SUBSCRIBERS_LIMIT,
				'filter[status]' => $status,
			);
			if ( null !== $cursor ) {
				$q['cursor'] = $cursor;
			}

			$url = add_query_arg(
				$q,
				sprintf( 'https://connect.mailerlite.com/api/forms/%s/subscribers', rawurlencode( $form_id ) )
			);

			$response = wp_remote_get(
				$url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_token,
						'Accept'        => 'application/json',
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				break;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
				break;
			}

			foreach ( $body['data'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['email'] ) ) {
					continue;
				}
				if ( strtolower( trim( (string) $row['email'] ) ) === $want ) {
					return true;
				}
			}

			$next = isset( $body['meta']['next_cursor'] ) ? (string) $body['meta']['next_cursor'] : '';
			if ( '' === $next ) {
				break;
			}
			$cursor = $next;
			++$pages;
		}

		return false;
	}
}
