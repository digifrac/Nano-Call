<?php
/**
 * Nano Call - per-site bootstrap.
 *
 * Copy this file to bootstrap.php and edit the path below for your install
 * (or let install.php do it for you). bootstrap.php itself is gitignored and
 * SHOULD be excluded from any production backup that ends up in a public repo.
 *
 * Loaded by signal.php and admin/index.php at the top, before anything else.
 */

/**
 * Outside-webroot config directory. Holds config.json (settings + licence
 * key) and admin.json (bcrypt operator password hash). MUST NOT be
 * web-accessible - that is the whole point.
 *
 * Recommended layout: one level above the webroot.
 *   /var/www/example.com/public_html/phone/    <- webroot, contains this file
 *   /var/www/example.com/phone-config/          <- contains config.json
 */
$cfg_dir = '/path/to/phone-config';

define('NANO_CALL_CONFIG_PATH', $cfg_dir . '/config.json');
define('NANO_CALL_ADMIN_PATH',  $cfg_dir . '/admin.json');

/**
 * In-webroot directory for transient signaling files (call mailboxes,
 * presence, cached relay creds). These are short-lived and need to be fast to
 * write, so they stay in the webroot - protected by data/.htaccess plus a
 * per-file PHP guard. Override only if you have split the layout.
 */
define('NANO_CALL_DATA_DIR', __DIR__ . '/data');

/* Required gate: signal.php and licence.php refuse to run without it. */
define('NANO_CALL_BOOTSTRAPPED', true);
