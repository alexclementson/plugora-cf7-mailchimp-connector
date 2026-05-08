<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST endpoints used by the admin UI for live API-key validation,
 * audience selection and merge-field discovery. All routes require
 * `manage_options` and a valid REST nonce — no public exposure.
 */
class Plugora_CF7MC_REST {
	const NS = 'plugora-cf7mc/v1';

	public static function register_routes() {
		register_rest_route( self::NS, '/validate', [
			'methods'             => 'POST',
			'permission_callback' => [ __CLASS__, 'can_manage' ],
			'callback'            => [ __CLASS__, 'validate_key' ],
			'args' => [
				'api_key' => [ 'type' => 'string', 'required' => true ],
			],
		] );
		register_rest_route( self::NS, '/audiences', [
			'methods'             => 'GET',
			'permission_callback' => [ __CLASS__, 'can_manage' ],
			'callback'            => [ __CLASS__, 'audiences' ],
		] );
		register_rest_route( self::NS, '/audiences/(?P<list_id>[a-zA-Z0-9_-]+)/fields', [
			'methods'             => 'GET',
			'permission_callback' => [ __CLASS__, 'can_manage' ],
			'callback'            => [ __CLASS__, 'audience_fields' ],
		] );
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	private static function api_key() {
		$opts = get_option( 'plugora_cf7mc_settings', [] );
		return isset( $opts['api_key'] ) ? trim( (string) $opts['api_key'] ) : '';
	}

	public static function validate_key( WP_REST_Request $r ) {
		$key = sanitize_text_field( (string) $r->get_param( 'api_key' ) );
		if ( $key === '' ) {
			return new WP_Error( 'empty', 'API key required', [ 'status' => 400 ] );
		}
		if ( ! Plugora_CF7MC_Mailchimp::datacenter( $key ) ) {
			return new WP_REST_Response( [
				'ok'    => false,
				'error' => 'Invalid key format — expected something like "abc123…-us12".',
			], 200 );
		}
		$res = Plugora_CF7MC_Mailchimp::ping( $key );
		return new WP_REST_Response( [
			'ok'           => $res['ok'],
			'status'       => $res['status'],
			'datacenter'   => Plugora_CF7MC_Mailchimp::datacenter( $key ),
			'account_name' => $res['data']['account_name'] ?? null,
			'error'        => $res['error'],
		], 200 );
	}

	public static function audiences( WP_REST_Request $r ) {
		$key = self::api_key();
		if ( $key === '' ) return new WP_Error( 'no_key', 'No API key configured', [ 'status' => 400 ] );
		$force = (bool) $r->get_param( 'force' );
		$res   = Plugora_CF7MC_Mailchimp::audiences( $key, $force );
		if ( ! $res['ok'] ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $res['error'] ], 200 );
		}
		$lists = array_map( function( $l ) {
			return [
				'id'      => $l['id'],
				'name'    => $l['name'],
				'members' => $l['stats']['member_count'] ?? 0,
			];
		}, $res['data']['lists'] ?? [] );
		return new WP_REST_Response( [ 'ok' => true, 'audiences' => $lists ], 200 );
	}

	public static function audience_fields( WP_REST_Request $r ) {
		$key = self::api_key();
		if ( $key === '' ) return new WP_Error( 'no_key', 'No API key configured', [ 'status' => 400 ] );
		$list_id = sanitize_text_field( $r['list_id'] );
		$mf      = Plugora_CF7MC_Mailchimp::merge_fields( $key, $list_id );
		$ic      = Plugora_CF7MC_Mailchimp::interest_categories( $key, $list_id );

		$fields = [];
		if ( $mf['ok'] ) {
			foreach ( $mf['data']['merge_fields'] ?? [] as $f ) {
				$fields[] = [
					'tag'      => $f['tag'],
					'name'     => $f['name'],
					'type'     => $f['type'],
					'required' => ! empty( $f['required'] ),
				];
			}
		}
		$groups = [];
		if ( $ic['ok'] ) {
			foreach ( $ic['data']['categories'] ?? [] as $cat ) {
				$groups[] = [
					'id'   => $cat['id'],
					'name' => $cat['title'],
					'type' => $cat['type'],
				];
			}
		}
		return new WP_REST_Response( [
			'ok'           => $mf['ok'],
			'merge_fields' => $fields,
			'groups'       => $groups,
			'error'        => $mf['ok'] ? null : $mf['error'],
		], 200 );
	}
}
