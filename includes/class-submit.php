<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CF7 submission → Mailchimp bridge.
 *
 * Hooks `wpcf7_before_send_mail` so we can read posted_data, sync to
 * Mailchimp, log the result, and (optionally) abort the email send if
 * the admin requested abort-on-error.
 */
class Plugora_CF7MC_Submit {

	public static function handle( $contact_form, &$abort, $submission ) {
		if ( ! $contact_form || ! $submission ) return;

		$form_id = method_exists( $contact_form, 'id' ) ? (int) $contact_form->id() : 0;
		$cfg     = Plugora_CF7MC_CF7_Tab::read_config( $form_id );

		// Form-level disabled, skip silently.
		if ( empty( $cfg['enabled'] ) || empty( $cfg['audience_id'] ) || empty( $cfg['email_field'] ) ) return;

		$site = Plugora_CF7MC_Settings::get();
		$key  = trim( (string) $site['api_key'] );
		if ( $key === '' ) {
			Plugora_CF7MC_Logs::record( $form_id, '', $cfg['audience_id'], 'skipped', 0, 'No API key configured' );
			return;
		}

		$posted = $submission->get_posted_data();
		if ( ! is_array( $posted ) ) $posted = [];

		// GDPR consent — bail out cleanly when the box was left unchecked.
		if ( $cfg['consent_field'] !== '' ) {
			$consent = $posted[ $cfg['consent_field'] ] ?? '';
			if ( ! self::is_truthy( $consent ) ) {
				Plugora_CF7MC_Logs::record( $form_id, '', $cfg['audience_id'], 'skipped', 0, 'GDPR consent not given' );
				return;
			}
		}

		$email = self::flatten( $posted[ $cfg['email_field'] ] ?? '' );
		if ( ! is_email( $email ) ) {
			Plugora_CF7MC_Logs::record( $form_id, $email, $cfg['audience_id'], 'error', 0, 'Posted email is invalid' );
			if ( ! empty( $site['abort_on_error'] ) ) $abort = true;
			return;
		}

		// Build merge fields: NAME from name_field (split into FNAME/LNAME),
		// then anything explicitly mapped on the form.
		$merge = [];
		if ( $cfg['name_field'] !== '' ) {
			$name  = self::flatten( $posted[ $cfg['name_field'] ] ?? '' );
			$parts = preg_split( '/\s+/', trim( $name ), 2 );
			if ( ! empty( $parts[0] ) ) $merge['FNAME'] = $parts[0];
			if ( ! empty( $parts[1] ) ) $merge['LNAME'] = $parts[1];
		}
		foreach ( (array) $cfg['field_map'] as $mc_tag => $cf7_tag ) {
			$val = self::flatten( $posted[ $cf7_tag ] ?? '' );
			if ( $val !== '' ) $merge[ $mc_tag ] = $val;
		}

		$opts = [
			'double_optin'    => self::resolve( $cfg['double_optin'],    $site['double_optin'] ),
			'update_existing' => self::resolve( $cfg['update_existing'], $site['update_existing'] ),
		];
		if ( plugora_cf7mc_is_premium() ) {
			if ( $cfg['tags'] !== '' ) {
				$opts['tags'] = array_filter( array_map( 'trim', explode( ',', $cfg['tags'] ) ) );
			}
			if ( ! empty( $cfg['interests'] ) ) {
				$opts['interests'] = $cfg['interests'];
			}
		}

		$res = Plugora_CF7MC_Mailchimp::upsert_member(
			$key,
			$cfg['audience_id'],
			$email,
			$merge,
			$opts
		);

		$status  = $res['ok'] ? 'success' : 'error';
		$message = $res['ok']
			? 'Subscribed (' . ( $opts['double_optin'] ? 'pending' : 'active' ) . ')'
			: ( $res['error'] ?? 'Mailchimp request failed' );

		$payload = ! empty( $site['debug_log'] )
			? [ 'merge_fields' => $merge, 'opts' => $opts ]
			: null;

		Plugora_CF7MC_Logs::record(
			$form_id, $email, $cfg['audience_id'], $status,
			(int) $res['status'], $message, $payload
		);

		if ( ! $res['ok'] && ! empty( $site['abort_on_error'] ) ) {
			$abort = true;
		}
	}

	/** Treat checkbox/select arrays as truthy if any value is set. */
	private static function is_truthy( $v ) {
		if ( is_array( $v ) ) return ! empty( array_filter( $v, fn( $x ) => $x !== '' && $x !== '0' ) );
		return $v !== '' && $v !== '0' && $v !== false && $v !== null;
	}

	/** CF7 sometimes hands us arrays for repeating fields — collapse safely. */
	private static function flatten( $v ) {
		if ( is_array( $v ) ) return implode( ', ', array_map( 'sanitize_text_field', $v ) );
		return sanitize_text_field( (string) $v );
	}

	/** Per-form override beats site default; '' means inherit. */
	private static function resolve( $form_value, $site_default ) {
		if ( $form_value === '1' ) return true;
		if ( $form_value === '0' ) return false;
		return ! empty( $site_default );
	}
}
