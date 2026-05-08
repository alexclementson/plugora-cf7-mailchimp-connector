<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin Mailchimp REST client — wraps wp_remote_* with the
 * datacenter-suffix routing Mailchimp requires.
 *
 * Mailchimp API keys are formatted as `<hex>-<dc>` (e.g. `abc123-us12`).
 * The datacenter portion ("us12") becomes the API host: `us12.api.mailchimp.com`.
 *
 * All helpers return associative arrays with `ok`, `status`, `data`, `error`
 * so callers can give the user actionable error messages without having to
 * decode HTTP plumbing themselves.
 */
class Plugora_CF7MC_Mailchimp {

	/**
	 * Detect the datacenter from the API key suffix.
	 * Returns null for malformed keys.
	 */
	public static function datacenter( $api_key ) {
		$api_key = (string) $api_key;
		if ( ! preg_match( '/-([a-z]+\d+)$/i', $api_key, $m ) ) return null;
		return strtolower( $m[1] );
	}

	public static function base_url( $api_key ) {
		$dc = self::datacenter( $api_key );
		if ( ! $dc ) return null;
		return "https://{$dc}.api.mailchimp.com/3.0";
	}

	/**
	 * Low-level request. Always uses HTTP basic auth with `anystring:<key>`.
	 */
	public static function request( $api_key, $method, $path, $body = null ) {
		$base = self::base_url( $api_key );
		if ( ! $base ) {
			return [ 'ok' => false, 'status' => 0, 'error' => 'invalid_key_format' ];
		}
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 12,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( 'plugora:' . $api_key ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'Plugora-CF7-Mailchimp/' . PLUGORA_CF7MC_VERSION . '; ' . home_url(),
			],
		];
		if ( $body !== null ) $args['body'] = wp_json_encode( $body );

		$response = wp_remote_request( $base . $path, $args );
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'status' => 0, 'error' => $response->get_error_message() ];
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		$ok     = $status >= 200 && $status < 300;
		return [
			'ok'     => $ok,
			'status' => $status,
			'data'   => is_array( $data ) ? $data : null,
			'error'  => $ok ? null : ( $data['detail'] ?? $data['title'] ?? 'http_' . $status ),
		];
	}

	/** Validate by hitting `/ping` — cheap, returns the account name. */
	public static function ping( $api_key ) {
		return self::request( $api_key, 'GET', '/ping' );
	}

	/** List audiences (Mailchimp calls them "lists"). Cached for 5 min. */
	public static function audiences( $api_key, $force = false ) {
		$cache_key = 'plugora_cf7mc_audiences_' . md5( $api_key );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) return $cached;
		}
		$res = self::request( $api_key, 'GET', '/lists?count=100&fields=lists.id,lists.name,lists.stats.member_count' );
		if ( $res['ok'] ) {
			set_transient( $cache_key, $res, 5 * MINUTE_IN_SECONDS );
		}
		return $res;
	}

	public static function merge_fields( $api_key, $list_id ) {
		return self::request( $api_key, 'GET', '/lists/' . rawurlencode( $list_id ) . '/merge-fields?count=100' );
	}

	public static function interest_categories( $api_key, $list_id ) {
		return self::request( $api_key, 'GET', '/lists/' . rawurlencode( $list_id ) . '/interest-categories?count=100' );
	}

	public static function tags( $api_key, $list_id ) {
		return self::request( $api_key, 'GET', '/lists/' . rawurlencode( $list_id ) . '/segments?type=static&count=100' );
	}

	/**
	 * Subscribe (or update) a member. Uses the idempotent PUT endpoint with
	 * the email's MD5 as the resource id — that way a re-submission is a
	 * harmless update, not a duplicate.
	 */
	public static function upsert_member( $api_key, $list_id, $email, $merge_fields = [], $opts = [] ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return [ 'ok' => false, 'status' => 0, 'error' => 'invalid_email' ];
		}
		$hash = md5( $email );

		$status     = ! empty( $opts['double_optin'] ) ? 'pending' : 'subscribed';
		$update_existing = ! empty( $opts['update_existing'] );

		$body = [
			'email_address' => $email,
			'status_if_new' => $status,
		];
		if ( $update_existing ) {
			$body['status'] = $status;
		}
		if ( ! empty( $merge_fields ) ) {
			$body['merge_fields'] = $merge_fields;
		}
		if ( ! empty( $opts['interests'] ) && is_array( $opts['interests'] ) ) {
			$body['interests'] = $opts['interests'];
		}
		if ( ! empty( $opts['language'] ) ) {
			$body['language'] = sanitize_text_field( $opts['language'] );
		}
		if ( ! empty( $opts['marketing_permissions'] ) && is_array( $opts['marketing_permissions'] ) ) {
			$body['marketing_permissions'] = $opts['marketing_permissions'];
		}

		$res = self::request( $api_key, 'PUT', "/lists/{$list_id}/members/{$hash}", $body );

		// Apply tags after upsert (Mailchimp wants them as a separate call).
		if ( $res['ok'] && ! empty( $opts['tags'] ) && is_array( $opts['tags'] ) ) {
			$tags = array_map( function( $t ) {
				return [ 'name' => (string) $t, 'status' => 'active' ];
			}, $opts['tags'] );
			self::request( $api_key, 'POST', "/lists/{$list_id}/members/{$hash}/tags", [ 'tags' => $tags ] );
		}
		return $res;
	}
}
