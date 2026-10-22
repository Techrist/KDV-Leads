<?php
namespace KdvLeads\Database;

use KdvLeads\Config;

defined('ABSPATH') || exit;

final class Schema
{
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'kdv_' . $name;
    }

    /** Create/upgrade tables (idempotent, versioned through kdv_db_version). */
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();
        $leads = self::table('leads');
        $files = self::table('lead_files');
        $queue = self::table('email_queue');
        $events = self::table('lead_events');
        $counters = self::table('counters');
        $tokens = self::table('upload_tokens');

        // dbDelta: two spaces after PRIMARY KEY, one definition per line.
        dbDelta("CREATE TABLE $leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference VARCHAR(32) NOT NULL,
  type VARCHAR(16) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'nouveau',
  submission_key CHAR(36) NOT NULL,
  full_name VARCHAR(120) NOT NULL DEFAULT '',
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(190) NULL,
  postal_code VARCHAR(10) NULL,
  plate VARCHAR(12) NULL,
  vehicle_label VARCHAR(190) NOT NULL DEFAULT '',
  vehicle_year SMALLINT NULL,
  mileage INT NULL,
  sale_timeframe VARCHAR(16) NULL,
  budget_min INT NULL,
  budget_max INT NULL,
  payload LONGTEXT NULL,
  source LONGTEXT NULL,
  data_consent TINYINT(1) NOT NULL DEFAULT 0,
  marketing_consent TINYINT(1) NOT NULL DEFAULT 0,
  consent_text_version VARCHAR(20) NOT NULL DEFAULT '',
  pdf_path VARCHAR(255) NULL,
  pdf_status VARCHAR(16) NOT NULL DEFAULT 'pending',
  ip_hash CHAR(64) NOT NULL DEFAULT '',
  internal_notes LONGTEXT NULL,
  next_callback_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference (reference),
  UNIQUE KEY submission_key (submission_key),
  KEY type_status (type,status),
  KEY phone (phone),
  KEY email (email),
  KEY plate (plate),
  KEY sale_timeframe (sale_timeframe),
  KEY created_at (created_at)
) $c;");

        dbDelta("CREATE TABLE $files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NULL,
  upload_token CHAR(64) NULL,
  kind VARCHAR(16) NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(190) NULL,
  angle VARCHAR(32) NULL,
  position SMALLINT NOT NULL DEFAULT 0,
  mime VARCHAR(64) NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  width SMALLINT UNSIGNED NULL,
  height SMALLINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY lead_id (lead_id),
  KEY upload_token (upload_token)
) $c;");

        dbDelta("CREATE TABLE $queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NULL,
  kind VARCHAR(24) NOT NULL,
  to_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html LONGTEXT NULL,
  body_text LONGTEXT NULL,
  attachments LONGTEXT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_error TEXT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY lead_id (lead_id),
  KEY status_next (status,next_attempt_at)
) $c;");

        dbDelta("CREATE TABLE $events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  event VARCHAR(48) NOT NULL,
  data LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY lead_id (lead_id)
) $c;");

        dbDelta("CREATE TABLE $counters (
  name VARCHAR(48) NOT NULL,
  value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (name)
) $c;");

        dbDelta("CREATE TABLE $tokens (
  token CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  file_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (token),
  KEY expires_at (expires_at)
) $c;");

        update_option('kdv_db_version', Config::DB_VERSION);
    }
}
