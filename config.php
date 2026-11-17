<?php
/**
 * Website Configuration (Hostinger Compatible)
 * Upload the contents of the `website/` folder into Hostinger's `public_html/`.
 */

// 1. Timezone (Matches your local +08:00 timezone)
date_default_timezone_set('Asia/Manila');

// 2. Security API Key (Leave empty '' to allow open access, or set a secret string)
// If you set this, put the same key in your CLI config.json on each PC.
define('API_SECRET_KEY', '');

// 3. Online Status Threshold in seconds (Default: 180 seconds = 3 minutes)
// If a PC hasn't sent a heartbeat within this time, the website marks it as Offline.
define('ONLINE_THRESHOLD_SECONDS', 180);

// 4. Database Driver: 'sqlite' (Zero-config default, works on Hostinger) or 'mysql'
define('DB_DRIVER', 'sqlite');

// SQLite File Path (Automatically created inside website/data/)
define('SQLITE_DB_PATH', __DIR__ . '/data/uptime.db');

// Optional: Hostinger MySQL Settings (Only used if DB_DRIVER is changed to 'mysql')
define('MYSQL_HOST', 'localhost');
define('MYSQL_DB',   'u123456789_uptime');
define('MYSQL_USER', 'u123456789_admin');
define('MYSQL_PASS', 'your_mysql_password');
