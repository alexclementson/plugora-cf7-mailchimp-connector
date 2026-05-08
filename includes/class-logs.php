<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Submission log storage + admin viewer.
 *
 * Free edition keeps the last 50 entries; premium keeps everything until
 * the admin clears it manually.
 */
class Plugora_CF7MC_Logs {
	const PAGE_SLUG = 'plugora-cf7mc-logs';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'plugora_cf7mc_logs';
	}

	/**
	 * Insert a log row, then trim free-tier rows beyond the 50-entry cap.
	 *
	 * $payload is anything serialisable (typically the merge_fields we sent).
	 */
	public static function record( $form_id, $email, $audience_id, $status, $http_code, $message, $payload = null ) {
		global $wpdb;
		$wpdb->insert( self::table(), [
			'form_id'     => (int) $form_id,
			'email'       => substr( (string) $email, 0, 190 ),
			'audience_id' => substr( (string) $audience_id, 0, 64 ),
			'status'      => substr( (string) $status, 0, 20 ),
			'http_code'   => (int) $http_code,
			'message'     => $message ? wp_kses_post( $message ) : null,
			'payload'     => $payload ? wp_json_encode( $payload ) : null,
		], [ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ] );

		if ( ! plugora_cf7mc_is_premium() ) {
			$cap   = 50;
			$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
			if ( $total > $cap ) {
				$cutoff = (int) $wpdb->get_var( $wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1 OFFSET %d',
					$cap
				) );
				if ( $cutoff ) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE id <= %d', $cutoff ) );
			}
		}
	}

	public static function recent_failures( $minutes = 60 ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)",
			(int) $minutes
		) );
	}

	public static function register_menu() {
		add_submenu_page(
			'plugora-cf7-mailchimp',          // parent registered by Settings
			'Submission log',
			'Submission log',
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		// Clear-log action.
		if (
			isset( $_POST['plugora_cf7mc_clear_logs'], $_POST['plugora_cf7mc_logs_nonce'] ) &&
			wp_verify_nonce( $_POST['plugora_cf7mc_logs_nonce'], 'plugora_cf7mc_clear_logs' )
		) {
			global $wpdb;
			$wpdb->query( 'TRUNCATE TABLE ' . self::table() );
			echo '<div class="notice notice-success is-dismissible"><p>Submission log cleared.</p></div>';
		}

		$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$where = '';
		$args  = [];
		if ( in_array( $status_filter, [ 'success', 'error', 'skipped' ], true ) ) {
			$where  = 'WHERE status = %s';
			$args[] = $status_filter;
		}

		global $wpdb;
		$sql  = "SELECT * FROM " . self::table() . " {$where} ORDER BY id DESC LIMIT 200";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

		?>
		<div class="wrap plugora-cf7mc-logs-wrap">
			<h1>Submission log</h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="<?php echo $status_filter === '' ? 'current' : ''; ?>">All</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'success', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>" class="<?php echo $status_filter === 'success' ? 'current' : ''; ?>">Success</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'error', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>" class="<?php echo $status_filter === 'error' ? 'current' : ''; ?>">Errors</a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'skipped', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>" class="<?php echo $status_filter === 'skipped' ? 'current' : ''; ?>">Skipped</a></li>
			</ul>

			<form method="post" style="float:right;margin-top:-32px">
				<?php wp_nonce_field( 'plugora_cf7mc_clear_logs', 'plugora_cf7mc_logs_nonce' ); ?>
				<button type="submit" name="plugora_cf7mc_clear_logs" value="1" class="button"
					onclick="return confirm('Clear every log entry?');">Clear log</button>
			</form>

			<table class="wp-list-table widefat striped plugora-cf7mc-log-table">
				<thead>
					<tr>
						<th style="width:140px">When</th>
						<th style="width:80px">Status</th>
						<th>Form</th>
						<th>Email</th>
						<th>Audience</th>
						<th>Detail</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6" class="plugora-empty-cell">No log entries yet — submit a form to see Mailchimp activity here.</td></tr>
					<?php else : foreach ( $rows as $r ) :
						$badge_class = 'plugora-status-' . $r->status;
						$form        = get_post( (int) $r->form_id );
						?>
						<tr>
							<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $r->created_at ) ); ?></td>
							<td><span class="plugora-status-pill <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $r->status ); ?></span></td>
							<td><?php echo $form ? esc_html( $form->post_title ) : '#' . (int) $r->form_id; ?></td>
							<td><?php echo esc_html( $r->email ); ?></td>
							<td><code><?php echo esc_html( $r->audience_id ); ?></code></td>
							<td><?php echo esc_html( $r->message ?? '' ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
			<p class="description">
				<?php if ( plugora_cf7mc_is_premium() ) : ?>
					Premium: full log retention. Showing the most recent 200 entries.
				<?php else : ?>
					Free edition keeps the last <strong>50 entries</strong>. <a href="<?php echo esc_url( admin_url( 'admin.php?page=plugora-cf7-mailchimp&tab=license' ) ); ?>">Upgrade</a> for full log retention.
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
