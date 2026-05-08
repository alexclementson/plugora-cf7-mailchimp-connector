<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Plugora CF7 Mailchimp — license storage + Plugora gateway validation.
 * Mirrors the Folders plugin license module exactly so the Plugora
 * back-office (subscriptions, downloads, update checks) treats every
 * plugin the same way.
 */
class Plugora_CF7MC_License {
	const OPT_KEY   = 'plugora_cf7mc_license_key';
	const OPT_STATE = 'plugora_cf7mc_license_state';
	const TRANSIENT = 'plugora_cf7mc_license_recheck';

	public static function is_active() {
		$state = get_option( self::OPT_STATE, [] );
		if ( empty( $state ) || empty( $state['valid'] ) ) return false;
		if ( ! empty( $state['expires_at'] ) && strtotime( $state['expires_at'] ) < time() ) return false;
		return true;
	}

	public static function get_key() {
		return (string) get_option( self::OPT_KEY, '' );
	}

	public static function get_state() {
		return (array) get_option( self::OPT_STATE, [] );
	}

	public static function activate( $key ) {
		$key    = trim( (string) $key );
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $key === '' ) {
			update_option( self::OPT_KEY, '' );
			update_option( self::OPT_STATE, [] );
			return [ 'valid' => false, 'error' => 'empty_key' ];
		}

		$response = wp_remote_post( PLUGORA_CF7MC_API, [
			'timeout' => 12,
			'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'    => wp_json_encode( [
				'key'         => $key,
				'domain'      => $domain,
				'plugin_slug' => 'cf7-mailchimp-connector',
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'valid' => false, 'error' => 'network', 'message' => $response->get_error_message() ];
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return [ 'valid' => false, 'error' => 'bad_response' ];
		}
		if ( empty( $body['valid'] ) ) {
			update_option( self::OPT_KEY, $key );
			update_option( self::OPT_STATE, [
				'valid'      => false,
				'error'      => $body['error'] ?? 'invalid',
				'checked_at' => time(),
			] );
			return $body;
		}

		$lic = $body['license'] ?? [];
		update_option( self::OPT_KEY, $key );
		update_option( self::OPT_STATE, [
			'valid'             => true,
			'edition'           => $body['edition'] ?? 'premium',
			'type'              => $lic['type'] ?? null,
			'expires_at'        => $lic['expires_at'] ?? null,
			'max_sites'         => $lic['max_sites'] ?? 1,
			'activated_domains' => $lic['activated_domains'] ?? [],
			'checked_at'        => time(),
		] );
		set_transient( self::TRANSIENT, 1, DAY_IN_SECONDS );
		return $body;
	}

	public static function deactivate() {
		update_option( self::OPT_KEY, '' );
		update_option( self::OPT_STATE, [] );
		delete_transient( self::TRANSIENT );
	}

	public static function render_panel() {
		$message = '';
		if ( isset( $_POST['plugora_cf7mc_license_nonce'] ) && wp_verify_nonce( $_POST['plugora_cf7mc_license_nonce'], 'plugora_cf7mc_license' ) ) {
			if ( isset( $_POST['plugora_deactivate'] ) ) {
				self::deactivate();
				$message = '<div class="notice notice-success"><p>License removed. Premium features disabled.</p></div>';
			} else {
				$key    = sanitize_text_field( wp_unslash( $_POST['plugora_cf7mc_license_key'] ?? '' ) );
				$result = self::activate( $key );
				if ( ! empty( $result['valid'] ) ) {
					$message = '<div class="notice notice-success"><p>✓ License active — premium features unlocked.</p></div>';
				} else {
					$err = esc_html( $result['error'] ?? 'invalid' );
					$message = '<div class="notice notice-error"><p>License check failed: ' . $err . '</p></div>';
				}
			}
		}

		$key     = self::get_key();
		$state   = self::get_state();
		$active  = self::is_active();
		$buy_url = esc_url( PLUGORA_CF7MC_BUY_URL );
		?>
		<div class="plugora-license-wrap">
			<?php echo $message; ?>

			<div class="plugora-license-grid">
				<div class="plugora-license-card">
					<h2><?php echo $active ? '<span class="plugora-badge plugora-badge-pro">PRO</span> Premium active' : '<span class="plugora-badge plugora-badge-free">FREE</span> Free edition'; ?></h2>
					<p class="description">
						<?php if ( $active ) : ?>
							All premium features are unlocked on this site.
							<?php if ( ! empty( $state['expires_at'] ) ) echo ' Renews on <strong>' . esc_html( $state['expires_at'] ) . '</strong>.'; ?>
						<?php else : ?>
							Enter a license key below to unlock multiple lists per form, tags, interest groups, segments and full submission log retention.
						<?php endif; ?>
					</p>

					<form method="post">
						<?php wp_nonce_field( 'plugora_cf7mc_license', 'plugora_cf7mc_license_nonce' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="plugora_cf7mc_license_key">License key</label></th>
								<td>
									<input name="plugora_cf7mc_license_key" id="plugora_cf7mc_license_key" type="text"
										value="<?php echo esc_attr( $key ); ?>"
										class="regular-text code" placeholder="PLG-XXXX-XXXX-XXXX" autocomplete="off" />
									<p class="description">You'll get this by email after purchase.</p>
								</td>
							</tr>
						</table>
						<p class="submit">
							<button class="button button-primary" type="submit">
								<?php echo $active ? 'Re-check license' : 'Activate license'; ?>
							</button>
							<?php if ( $active ) : ?>
								<button class="button" type="submit" name="plugora_deactivate" value="1">Deactivate on this site</button>
							<?php endif; ?>
							<?php if ( ! $active ) : ?>
								<a class="button button-secondary plugora-buy-btn" href="<?php echo $buy_url; ?>" target="_blank" rel="noopener">
									Purchase a license &rarr;
								</a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<aside class="plugora-license-card plugora-upsell">
					<h3>Premium features</h3>
					<ul class="plugora-feature-list">
						<li><span class="dashicons dashicons-yes-alt"></span> Multiple audiences per form</li>
						<li><span class="dashicons dashicons-yes-alt"></span> Tags, interest groups &amp; segments</li>
						<li><span class="dashicons dashicons-yes-alt"></span> Update-existing subscriber strategy</li>
						<li><span class="dashicons dashicons-yes-alt"></span> Per-form retry queue + log retention</li>
					</ul>
					<?php if ( ! $active ) : ?>
						<a class="button button-primary button-hero" href="<?php echo $buy_url; ?>" target="_blank" rel="noopener">Upgrade to Pro</a>
					<?php endif; ?>
				</aside>
			</div>
		</div>
		<?php
	}
}
