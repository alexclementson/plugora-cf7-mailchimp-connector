<?php
/**
 * Plugin Name: Plugora CF7 → Mailchimp Connector
 * Description: Sync Contact Form 7 submissions straight into Mailchimp audiences. Per-form mapping, tags, groups, double opt-in, GDPR consent and a polished Plugora admin UI.
 * Version:     1.3.1
 * Author:      Plugora
 * Author URI:  https://plugora.dev
 * License:     GPL-2.0-or-later
 * Text Domain: plugora-cf7-mailchimp
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'PLUGORA_CF7MC_VERSION', '1.3.1' );
define( 'PLUGORA_CF7MC_FILE',      __FILE__ );
define( 'PLUGORA_CF7MC_DIR',       plugin_dir_path( __FILE__ ) );
define( 'PLUGORA_CF7MC_URL',       plugin_dir_url( __FILE__ ) );
define( 'PLUGORA_CF7MC_SLUG',      'plugora-cf7-mailchimp' );
define( 'PLUGORA_CF7MC_API',       'https://kmsqtusutpknswtdzclw.supabase.co/functions/v1/cf7-mailchimp-license-validate' );
define( 'PLUGORA_CF7MC_BUY_URL',   'https://plugora.dev/buy/cf7-mailchimp' );

require_once PLUGORA_CF7MC_DIR . 'includes/class-installer.php';
require_once PLUGORA_CF7MC_DIR . 'includes/class-license.php';
require_once PLUGORA_CF7MC_DIR . 'includes/class-mailchimp.php';
require_once PLUGORA_CF7MC_DIR . 'includes/class-logs.php';
require_once PLUGORA_CF7MC_DIR . 'includes/class-rest.php';
require_once PLUGORA_CF7MC_DIR . 'includes/class-settings.php';
require_once PLUGORA_CF7MC_DIR . 'includes/class-cf7-tab.php';
require_once PLUGORA_CF7MC_DIR . 'includes/class-submit.php';
require_once PLUGORA_CF7MC_DIR . 'includes/class-admin.php';

if ( ! function_exists( 'plugora_cf7mc_is_premium' ) ) {
	/**
	 * Premium gating.
	 *
	 * Premium controls (multi-list per form, tags, interest groups, segment,
	 * conditional logic, Zapier handoff) are ON by default so the plugin
	 * always shows the full Plugora experience. Opt out with:
	 *
	 *   add_filter( 'plugora_cf7mc_is_premium', '__return_false' );
	 *   // or define( 'PLUGORA_CF7MC_FORCE_FREE', true );
	 */
	function plugora_cf7mc_is_premium() {
		if ( defined( 'PLUGORA_CF7MC_FORCE_FREE' ) && PLUGORA_CF7MC_FORCE_FREE ) {
			return false;
		}
		$licensed = class_exists( 'Plugora_CF7MC_License' ) && Plugora_CF7MC_License::is_active();
		return (bool) apply_filters( 'plugora_cf7mc_is_premium', true, $licensed );
	}
}

register_activation_hook( __FILE__, [ 'Plugora_CF7MC_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Plugora_CF7MC_Installer', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'Plugora_CF7MC_Installer', 'maybe_upgrade' ] );

// Wait for CF7 — we softly degrade with an admin notice if it isn't installed.
add_action( 'admin_init', function() {
	if ( ! class_exists( 'WPCF7' ) && ! defined( 'WPCF7_VERSION' ) ) {
		add_action( 'admin_notices', [ 'Plugora_CF7MC_Admin', 'render_missing_cf7_notice' ] );
	}
} );

add_action( 'admin_menu',            [ 'Plugora_CF7MC_Settings', 'register_menu' ] );
add_action( 'admin_menu',            [ 'Plugora_CF7MC_Logs', 'register_menu' ] );
add_action( 'admin_init',            [ 'Plugora_CF7MC_Settings', 'register_settings' ] );
add_action( 'admin_enqueue_scripts', [ 'Plugora_CF7MC_Admin', 'enqueue' ] );
add_action( 'rest_api_init',         [ 'Plugora_CF7MC_REST', 'register_routes' ] );

// CF7 form editor → MailChimp tab + per-form save.
add_filter( 'wpcf7_editor_panels',   [ 'Plugora_CF7MC_CF7_Tab', 'register_panel' ] );
add_action( 'wpcf7_save_contact_form', [ 'Plugora_CF7MC_CF7_Tab', 'save_panel' ], 10, 1 );

// Submission handler — fires before CF7 sends mail so we can sync, log,
// and abort the send only if the admin chose "abort on Mailchimp error".
add_action( 'wpcf7_before_send_mail', [ 'Plugora_CF7MC_Submit', 'handle' ], 10, 3 );

// Admin notice when a form is configured but failing recently.
add_action( 'admin_notices', [ 'Plugora_CF7MC_Admin', 'render_recent_failure_notice' ] );
