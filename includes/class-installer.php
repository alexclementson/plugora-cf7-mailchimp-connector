<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Schema installer + version-bump migrator.
 * Mirrors Plugora_Folders_Installer so Plugora plugins all behave the same.
 */
class Plugora_CF7MC_Installer {
	public static function activate() {
		self::install_schema();
		add_option( 'plugora_cf7mc_version', PLUGORA_CF7MC_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'plugora_cf7mc_version' ) !== PLUGORA_CF7MC_VERSION ) {
			self::install_schema();
			update_option( 'plugora_cf7mc_version', PLUGORA_CF7MC_VERSION );
		}
	}

	private static function install_schema() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'plugora_cf7mc_logs';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(190) NOT NULL DEFAULT '',
			audience_id VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			message TEXT NULL,
			payload LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;" );
	}

	public static function deactivate() {
		// Keep data on deactivate; uninstall.php handles destructive cleanup.
	}
}
