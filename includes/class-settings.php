<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Top-level "Plugora" admin menu + Mailchimp settings page (General / License tabs).
 *
 * Stored option (`plugora_cf7mc_settings`):
 *   api_key            string   Mailchimp API key (with -dcXX suffix)
 *   double_optin       bool     Default: ask Mailchimp to send a confirmation email
 *   update_existing    bool     Update fields if the email is already subscribed
 *   debug_log          bool     Verbose logging of every payload
 *   abort_on_error     bool     If Mailchimp fails, also abort CF7's mail send
 */
class Plugora_CF7MC_Settings {
	const OPT_KEY = 'plugora_cf7mc_settings';
	const PAGE    = 'plugora-cf7-mailchimp';

	public static function defaults() {
		return [
			'api_key'         => '',
			'double_optin'    => 0,
			'update_existing' => 0,
			'debug_log'       => 0,
			'abort_on_error'  => 0,
		];
	}

	public static function get( $key = null ) {
		$opts = wp_parse_args( (array) get_option( self::OPT_KEY, [] ), self::defaults() );
		if ( $key === null ) return $opts;
		return $opts[ $key ] ?? null;
	}

	public static function register_menu() {
		// Top-level Plugora bucket — shared by every Plugora plugin once they exist.
		global $admin_page_hooks;
		if ( empty( $admin_page_hooks['plugora'] ) ) {
			add_menu_page(
				'Plugora',
				'Plugora',
				'manage_options',
				'plugora',
				[ __CLASS__, 'render_landing' ],
				'dashicons-screenoptions',
				58
			);
		}
		add_submenu_page(
			'plugora',
			'CF7 Mailchimp',
			'CF7 Mailchimp',
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		register_setting( 'plugora_cf7mc_settings_group', self::OPT_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => self::defaults(),
		] );
	}

	public static function sanitize( $input ) {
		$d = self::defaults();
		if ( ! is_array( $input ) ) return $d;
		return [
			'api_key'         => sanitize_text_field( $input['api_key'] ?? '' ),
			'double_optin'    => empty( $input['double_optin'] )    ? 0 : 1,
			'update_existing' => empty( $input['update_existing'] ) ? 0 : 1,
			'debug_log'       => empty( $input['debug_log'] )       ? 0 : 1,
			'abort_on_error'  => empty( $input['abort_on_error'] )  ? 0 : 1,
		];
	}

	public static function render_landing() {
		echo '<div class="wrap"><h1>Plugora</h1><p>Manage your Plugora plugins below.</p></div>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$base_url = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap plugora-cf7mc-wrap">
			<h1>CF7 → Mailchimp Connector
				<?php echo plugora_cf7mc_is_premium()
					? '<span class="plugora-badge plugora-badge-pro" style="vertical-align:middle;margin-left:8px">PRO</span>'
					: '<span class="plugora-badge plugora-badge-free" style="vertical-align:middle;margin-left:8px">FREE</span>'; ?>
			</h1>
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; ?>">Settings</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Plugora_CF7MC_Logs::PAGE_SLUG ) ); ?>" class="nav-tab">Submission log</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'license', $base_url ) ); ?>" class="nav-tab <?php echo $tab === 'license' ? 'nav-tab-active' : ''; ?>">License</a>
			</h2>
			<?php if ( $tab === 'license' ) Plugora_CF7MC_License::render_panel(); else self::render_general_tab(); ?>
		</div>
		<?php
	}

	private static function render_general_tab() {
		$opts = self::get();
		?>
		<form method="post" action="options.php" class="plugora-cf7mc-form">
			<?php settings_fields( 'plugora_cf7mc_settings_group' ); ?>

			<div class="plugora-card">
				<h2 class="plugora-card-title">Mailchimp connection</h2>
				<p class="plugora-card-sub">Paste your Mailchimp API key to start syncing form submissions. We'll auto-detect the datacenter.</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="plugora_cf7mc_api_key">API key</label></th>
						<td>
							<div class="plugora-key-row">
								<input
									name="<?php echo esc_attr( self::OPT_KEY ); ?>[api_key]"
									id="plugora_cf7mc_api_key"
									type="text"
									value="<?php echo esc_attr( $opts['api_key'] ); ?>"
									class="regular-text code"
									placeholder="abc123def456-us12"
									autocomplete="off"
								/>
								<button type="button" class="button plugora-cf7mc-check-key">Check API key</button>
								<span class="plugora-cf7mc-key-status" aria-live="polite"></span>
							</div>
							<p class="description">
								Find this in Mailchimp under <em>Profile → Extras → API keys</em>.
								<a href="https://us1.admin.mailchimp.com/account/api/" target="_blank" rel="noopener">Where do I get an API key?</a>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="plugora-card">
				<h2 class="plugora-card-title">Default sync behaviour</h2>
				<p class="plugora-card-sub">These apply to every form. You can override them per-form on the CF7 → MailChimp tab.</p>
				<table class="form-table plugora-toggle-table" role="presentation">
					<?php
					self::toggle_row( 'double_optin',    'Double opt-in',          'Send Mailchimp\'s confirmation email before adding the subscriber.' );
					self::toggle_row( 'update_existing', 'Update existing subscribers', 'If the email is already subscribed, refresh their merge fields with the new submission.' );
					self::toggle_row( 'abort_on_error',  'Abort CF7 mail on Mailchimp error', 'Stop CF7 from sending its email if the Mailchimp sync fails. Off by default — admin will see the failure in the submission log.' );
					self::toggle_row( 'debug_log',       'Verbose debug logging',  'Log the full payload sent to Mailchimp. Useful when troubleshooting field mapping.' );
					?>
				</table>
			</div>

			<?php submit_button( 'Save changes' ); ?>
		</form>
		<?php
	}

	private static function toggle_row( $key, $label, $help = '' ) {
		$opts = self::get();
		$on   = ! empty( $opts[ $key ] );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label class="plugora-switch">
					<input type="hidden" name="<?php echo esc_attr( self::OPT_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( self::OPT_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $on ); ?> />
					<span class="plugora-switch-slider"></span>
				</label>
				<?php if ( $help ) : ?><span class="plugora-help" title="<?php echo esc_attr( $help ); ?>">?</span><?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
