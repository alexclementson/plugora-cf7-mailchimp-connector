<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Per-form Mailchimp settings panel inside the CF7 form editor.
 *
 * Persisted as post meta on the wpcf7_contact_form post:
 *   _plugora_cf7mc_enabled        bool
 *   _plugora_cf7mc_audience_id    string  Mailchimp list id
 *   _plugora_cf7mc_email_field    string  CF7 mail-tag (e.g. "your-email")
 *   _plugora_cf7mc_name_field     string  CF7 mail-tag (e.g. "your-name")
 *   _plugora_cf7mc_field_map      array   merge_tag => cf7_mail_tag
 *   _plugora_cf7mc_consent_field  string  CF7 mail-tag of the GDPR consent checkbox
 *   _plugora_cf7mc_double_optin   string  '', '1', '0' (per-form override; '' = inherit)
 *   _plugora_cf7mc_update_existing string '', '1', '0'
 *   _plugora_cf7mc_tags           string  comma-separated list (premium)
 *   _plugora_cf7mc_interests      array   interest_id => bool (premium)
 */
class Plugora_CF7MC_CF7_Tab {

	/** Add the "MailChimp" panel to the CF7 form editor tab list. */
	public static function register_panel( $panels ) {
		$panels['plugora-cf7mc-panel'] = [
			'title'    => __( 'MailChimp', 'plugora-cf7-mailchimp' ),
			'callback' => [ __CLASS__, 'render_panel' ],
		];
		return $panels;
	}

	/**
	 * Render the per-form panel. CF7 hands us the WPCF7_ContactForm.
	 * The panel is heavily JS-enhanced (audience switcher, field mapping,
	 * tags) but every control degrades to a normal HTML form so values
	 * round-trip even with JS disabled.
	 */
	public static function render_panel( $form ) {
		$post_id = $form->id();
		$cfg     = self::read_config( $post_id );
		$is_pro  = plugora_cf7mc_is_premium();
		$mail_tags = self::get_form_mail_tags( $form );
		?>
		<div class="plugora-cf7mc-panel" data-form-id="<?php echo (int) $post_id; ?>">
			<h2><?php esc_html_e( 'Mailchimp Integration', 'plugora-cf7-mailchimp' ); ?></h2>

			<?php wp_nonce_field( 'plugora_cf7mc_save_panel', 'plugora_cf7mc_panel_nonce' ); ?>

			<div class="plugora-cf7mc-row">
				<label class="plugora-cf7mc-toggle">
					<input type="checkbox" name="plugora_cf7mc[enabled]" value="1" <?php checked( $cfg['enabled'] ); ?> />
					<span><?php esc_html_e( 'Send submissions to Mailchimp', 'plugora-cf7-mailchimp' ); ?></span>
				</label>
				<p class="description">
					<?php
					$settings_url = esc_url( admin_url( 'admin.php?page=' . Plugora_CF7MC_Settings::PAGE ) );
					echo wp_kses_post( sprintf(
						/* translators: %s: settings link */
						__( 'Make sure your API key is set under <a href="%s">Plugora → CF7 Mailchimp</a>.', 'plugora-cf7-mailchimp' ),
						$settings_url
					) );
					?>
				</p>
			</div>

			<table class="form-table plugora-cf7mc-table" role="presentation">
				<tr>
					<th><label for="plugora_cf7mc_audience"><?php esc_html_e( 'Audience', 'plugora-cf7-mailchimp' ); ?></label></th>
					<td>
						<select name="plugora_cf7mc[audience_id]" id="plugora_cf7mc_audience" class="plugora-cf7mc-audience">
							<option value=""><?php esc_html_e( '— Select a list —', 'plugora-cf7-mailchimp' ); ?></option>
							<?php if ( $cfg['audience_id'] ) : ?>
								<option value="<?php echo esc_attr( $cfg['audience_id'] ); ?>" selected><?php echo esc_html( $cfg['audience_id'] ); ?></option>
							<?php endif; ?>
						</select>
						<button type="button" class="button plugora-cf7mc-refresh">
							<span class="dashicons dashicons-update" style="font-size:16px;line-height:1.4;width:16px;height:16px;vertical-align:text-bottom;"></span>
							<?php esc_html_e( 'Refresh', 'plugora-cf7-mailchimp' ); ?>
						</button>
						<span class="plugora-cf7mc-audience-status"></span>
					</td>
				</tr>

				<tr>
					<th><label for="plugora_cf7mc_email_field"><?php esc_html_e( 'Email field', 'plugora-cf7-mailchimp' ); ?> *</label></th>
					<td>
						<?php self::render_mail_tag_select( 'email_field', $cfg['email_field'], $mail_tags, true ); ?>
						<p class="description"><?php esc_html_e( 'CF7 field that contains the subscriber email. We pre-select the most likely match.', 'plugora-cf7-mailchimp' ); ?></p>
					</td>
				</tr>

				<tr>
					<th><label for="plugora_cf7mc_name_field"><?php esc_html_e( 'Name field', 'plugora-cf7-mailchimp' ); ?></label></th>
					<td>
						<?php self::render_mail_tag_select( 'name_field', $cfg['name_field'], $mail_tags, false ); ?>
						<p class="description"><?php esc_html_e( 'Optional — used as FNAME if your audience has no separate first/last fields.', 'plugora-cf7-mailchimp' ); ?></p>
					</td>
				</tr>

				<tr>
					<th><label><?php esc_html_e( 'Merge fields', 'plugora-cf7-mailchimp' ); ?></label></th>
					<td>
						<div class="plugora-cf7mc-fieldmap" data-current="<?php echo esc_attr( wp_json_encode( $cfg['field_map'] ) ); ?>">
							<p class="description"><?php esc_html_e( 'Pick an audience above to load its merge fields. We auto-suggest matches based on your CF7 field names.', 'plugora-cf7-mailchimp' ); ?></p>
						</div>
					</td>
				</tr>

				<tr>
					<th><label for="plugora_cf7mc_consent"><?php esc_html_e( 'GDPR consent field', 'plugora-cf7-mailchimp' ); ?></label></th>
					<td>
						<?php self::render_mail_tag_select( 'consent_field', $cfg['consent_field'], $mail_tags, false ); ?>
						<p class="description"><?php esc_html_e( 'If set, the subscriber will only be sent to Mailchimp when this checkbox is ticked.', 'plugora-cf7-mailchimp' ); ?></p>
					</td>
				</tr>

				<tr>
					<th><?php esc_html_e( 'Double opt-in', 'plugora-cf7-mailchimp' ); ?></th>
					<td>
						<select name="plugora_cf7mc[double_optin]">
							<option value=""  <?php selected( $cfg['double_optin'], '' ); ?>><?php esc_html_e( 'Use site default', 'plugora-cf7-mailchimp' ); ?></option>
							<option value="1" <?php selected( $cfg['double_optin'], '1' ); ?>><?php esc_html_e( 'On — Mailchimp sends a confirmation email', 'plugora-cf7-mailchimp' ); ?></option>
							<option value="0" <?php selected( $cfg['double_optin'], '0' ); ?>><?php esc_html_e( 'Off — subscribe immediately', 'plugora-cf7-mailchimp' ); ?></option>
						</select>
					</td>
				</tr>

				<tr>
					<th><?php esc_html_e( 'Update existing subscribers', 'plugora-cf7-mailchimp' ); ?></th>
					<td>
						<select name="plugora_cf7mc[update_existing]">
							<option value=""  <?php selected( $cfg['update_existing'], '' ); ?>><?php esc_html_e( 'Use site default', 'plugora-cf7-mailchimp' ); ?></option>
							<option value="1" <?php selected( $cfg['update_existing'], '1' ); ?>><?php esc_html_e( 'Yes — refresh fields on resubmission', 'plugora-cf7-mailchimp' ); ?></option>
							<option value="0" <?php selected( $cfg['update_existing'], '0' ); ?>><?php esc_html_e( 'No — only add brand-new emails', 'plugora-cf7-mailchimp' ); ?></option>
						</select>
					</td>
				</tr>

				<tr class="plugora-cf7mc-pro-row">
					<th>
						<?php esc_html_e( 'Tags', 'plugora-cf7-mailchimp' ); ?>
						<?php if ( ! $is_pro ) : ?><span class="plugora-pro-pill">PRO</span><?php endif; ?>
					</th>
					<td>
						<input type="text" name="plugora_cf7mc[tags]" value="<?php echo esc_attr( $cfg['tags'] ); ?>"
							class="regular-text" placeholder="newsletter, lead-form, gated-download" <?php disabled( ! $is_pro ); ?> />
						<p class="description"><?php esc_html_e( 'Comma-separated tags applied to every new subscriber from this form.', 'plugora-cf7-mailchimp' ); ?></p>
					</td>
				</tr>

				<tr class="plugora-cf7mc-pro-row">
					<th>
						<?php esc_html_e( 'Interest groups', 'plugora-cf7-mailchimp' ); ?>
						<?php if ( ! $is_pro ) : ?><span class="plugora-pro-pill">PRO</span><?php endif; ?>
					</th>
					<td>
						<div class="plugora-cf7mc-groups" data-current="<?php echo esc_attr( wp_json_encode( $cfg['interests'] ) ); ?>" data-pro="<?php echo $is_pro ? '1' : '0'; ?>">
							<p class="description"><?php esc_html_e( 'Loaded from Mailchimp once you select an audience.', 'plugora-cf7-mailchimp' ); ?></p>
						</div>
					</td>
				</tr>
			</table>

			<p class="plugora-cf7mc-footnote">
				<?php
				echo wp_kses_post( sprintf(
					/* translators: %s: link */
					__( 'Mailchimp integration is provided by <a href="%s" target="_blank" rel="noopener">Plugora</a>.', 'plugora-cf7-mailchimp' ),
					esc_url( 'https://plugora.dev' )
				) );
				?>
			</p>
		</div>
		<?php
	}

	private static function render_mail_tag_select( $field, $current, $mail_tags, $required ) {
		$id   = 'plugora_cf7mc_' . $field;
		$name = 'plugora_cf7mc[' . $field . ']';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="plugora-cf7mc-mailtag">';
		echo '<option value="">' . esc_html__( '— Select form field —', 'plugora-cf7-mailchimp' ) . '</option>';
		foreach ( $mail_tags as $tag ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $tag ),
				selected( $current, $tag, false )
			);
		}
		echo '</select>';
		if ( $required ) echo '<span class="plugora-cf7mc-required">*</span>';
	}

	/** Pull the list of [tag] field names from the current form's body. */
	private static function get_form_mail_tags( $form ) {
		$tags = [];
		if ( method_exists( $form, 'scan_form_tags' ) ) {
			foreach ( $form->scan_form_tags() as $t ) {
				if ( ! empty( $t->name ) ) $tags[ $t->name ] = $t->name;
			}
		}
		return array_values( $tags );
	}

	/** Persist panel values when CF7 saves the form. */
	public static function save_panel( $form ) {
		if ( ! isset( $_POST['plugora_cf7mc_panel_nonce'] ) ) return;
		if ( ! wp_verify_nonce( $_POST['plugora_cf7mc_panel_nonce'], 'plugora_cf7mc_save_panel' ) ) return;
		if ( ! current_user_can( 'wpcf7_edit_contact_form', $form->id() ) ) return;

		$raw = $_POST['plugora_cf7mc'] ?? [];
		if ( ! is_array( $raw ) ) return;

		$post_id = $form->id();
		$cfg = [
			'enabled'         => ! empty( $raw['enabled'] ) ? 1 : 0,
			'audience_id'     => sanitize_text_field( $raw['audience_id']   ?? '' ),
			'email_field'     => sanitize_key( $raw['email_field']          ?? '' ),
			'name_field'      => sanitize_key( $raw['name_field']           ?? '' ),
			'consent_field'   => sanitize_key( $raw['consent_field']        ?? '' ),
			'double_optin'    => self::sanitize_tristate( $raw['double_optin']    ?? '' ),
			'update_existing' => self::sanitize_tristate( $raw['update_existing'] ?? '' ),
			'tags'            => sanitize_text_field( $raw['tags']          ?? '' ),
			'field_map'       => self::sanitize_field_map( $raw['field_map'] ?? [] ),
			'interests'       => self::sanitize_interests( $raw['interests'] ?? [] ),
		];
		foreach ( $cfg as $k => $v ) {
			update_post_meta( $post_id, '_plugora_cf7mc_' . $k, $v );
		}
	}

	private static function sanitize_tristate( $v ) {
		return in_array( $v, [ '0', '1' ], true ) ? $v : '';
	}
	private static function sanitize_field_map( $raw ) {
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $merge_tag => $cf7_tag ) {
			$mt = strtoupper( preg_replace( '/[^A-Z0-9_]/i', '', (string) $merge_tag ) );
			$ct = sanitize_key( (string) $cf7_tag );
			if ( $mt && $ct ) $out[ $mt ] = $ct;
		}
		return $out;
	}
	private static function sanitize_interests( $raw ) {
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $iid => $on ) {
			$iid = sanitize_text_field( (string) $iid );
			if ( $iid !== '' ) $out[ $iid ] = ! empty( $on );
		}
		return $out;
	}

	/** Public reader used by the submission handler too. */
	public static function read_config( $post_id ) {
		$keys = [
			'enabled', 'audience_id', 'email_field', 'name_field', 'consent_field',
			'double_optin', 'update_existing', 'tags', 'field_map', 'interests',
		];
		$out = [];
		foreach ( $keys as $k ) {
			$out[ $k ] = get_post_meta( $post_id, '_plugora_cf7mc_' . $k, true );
		}
		// Defaults / coercion.
		$out['enabled']      = ! empty( $out['enabled'] );
		$out['field_map']    = is_array( $out['field_map'] ) ? $out['field_map'] : [];
		$out['interests']    = is_array( $out['interests'] ) ? $out['interests'] : [];
		foreach ( [ 'audience_id','email_field','name_field','consent_field','tags','double_optin','update_existing' ] as $s ) {
			$out[ $s ] = (string) $out[ $s ];
		}
		return $out;
	}
}
