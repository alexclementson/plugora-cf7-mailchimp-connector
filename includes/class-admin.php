<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Asset loader + admin notices for the CF7 Mailchimp connector.
 */
class Plugora_CF7MC_Admin {

	public static function enqueue( $hook ) {
		// Load the assets on the CF7 form editor + every Plugora CF7MC admin page.
		$is_cf7_editor   = strpos( (string) $hook, 'wpcf7' ) !== false;
		$is_plugora_page = isset( $_GET['page'] ) && (
			$_GET['page'] === Plugora_CF7MC_Settings::PAGE
			|| $_GET['page'] === Plugora_CF7MC_Logs::PAGE_SLUG
			|| $_GET['page'] === 'plugora'
		);
		if ( ! $is_cf7_editor && ! $is_plugora_page ) return;

		wp_enqueue_style(
			'plugora-cf7mc-admin',
			PLUGORA_CF7MC_URL . 'assets/admin.css',
			[],
			PLUGORA_CF7MC_VERSION
		);
		wp_enqueue_script(
			'plugora-cf7mc-admin',
			PLUGORA_CF7MC_URL . 'assets/admin.js',
			[ 'wp-api-fetch' ],
			PLUGORA_CF7MC_VERSION,
			true
		);
		wp_localize_script( 'plugora-cf7mc-admin', 'PlugoraCF7MC', [
			'restRoot'  => esc_url_raw( rest_url() ),
			'ns'        => 'plugora-cf7mc/v1',
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'isPremium' => plugora_cf7mc_is_premium(),
			'i18n'      => [
				'checking'      => __( 'Checking…', 'plugora-cf7-mailchimp' ),
				'connected_to'  => __( 'Connected to', 'plugora-cf7-mailchimp' ),
				'invalid_key'   => __( 'Invalid key — double-check the value from Mailchimp.', 'plugora-cf7-mailchimp' ),
				'no_audiences'  => __( 'No audiences found on your Mailchimp account.', 'plugora-cf7-mailchimp' ),
				'select_aud'    => __( 'Select an audience to load merge fields.', 'plugora-cf7-mailchimp' ),
				'loading'       => __( 'Loading…', 'plugora-cf7-mailchimp' ),
				'pro_required'  => __( 'Premium feature — upgrade to enable.', 'plugora-cf7-mailchimp' ),
			],
		] );
	}

	public static function render_missing_cf7_notice() {
		echo '<div class="notice notice-error"><p>'
			. wp_kses_post( __( '<strong>Plugora CF7 → Mailchimp</strong> requires <a href="https://wordpress.org/plugins/contact-form-7/" target="_blank" rel="noopener">Contact Form 7</a> to be installed and active.', 'plugora-cf7-mailchimp' ) )
			. '</p></div>';
	}

	public static function render_recent_failure_notice() {
		// Only relevant on Plugora screens to avoid noise everywhere else.
		$page = $_GET['page'] ?? '';
		if ( strpos( (string) $page, 'plugora' ) !== 0 && strpos( (string) $page, 'wpcf7' ) !== 0 ) return;

		$count = Plugora_CF7MC_Logs::recent_failures( 60 );
		if ( $count < 1 ) return;
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf(
				/* translators: %d: failure count */
				_n( '%d Mailchimp sync failed in the last hour.', '%d Mailchimp syncs failed in the last hour.', $count, 'plugora-cf7-mailchimp' ),
				$count
			) ),
			esc_url( admin_url( 'admin.php?page=' . Plugora_CF7MC_Logs::PAGE_SLUG . '&status=error' ) ),
			esc_html__( 'View log →', 'plugora-cf7-mailchimp' )
		);
	}
}
