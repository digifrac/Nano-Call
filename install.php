<?php
/**
 * Nano Call - first-time web installer.
 *
 * Lives at /phone/install.php. Detects whether the install is already
 * configured (bootstrap.php exists). If not, it creates the outside-webroot
 * config directory (for config.json + admin.json), writes bootstrap.php with
 * the absolute path, and hands off to the admin setup wizard.
 *
 * Delete this file after install (same pattern as the admin/ folder). It
 * refuses to run once bootstrap.php exists, so a forgotten install.php cannot
 * reconfigure a live line.
 */

$phone_dir = __DIR__;
$bootstrap = $phone_dir . '/bootstrap.php';
$self_file = __FILE__;

$self_url  = $_SERVER['SCRIPT_NAME'] ?? 'install.php';
$base_url  = rtrim(str_replace('\\', '/', dirname($self_url)), '/');
$admin_url = $base_url . '/admin/';

// Never run with trailing path info; bounce to the clean script URL.
if (!empty($_SERVER['PATH_INFO'])) {
    header('Location: ' . $self_url, true, 302);
    exit;
}

/**
 * Pick a sensible default for the outside-webroot config directory: one level
 * above DOCUMENT_ROOT, with a per-host slug so two installs under the same
 * parent never share one config (admin hash + licence key).
 */
function nano_install_default_cfg_dir(string $phone_dir): string
{
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $host), '-');
    $name = 'nano-phone-config' . ($slug !== '' ? '-' . $slug : '');
    $docroot   = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/') : '';
    $phone_norm = rtrim(str_replace('\\', '/', $phone_dir), '/');
    if ($docroot !== '' && (str_starts_with($phone_norm, $docroot . '/') || $phone_norm === $docroot)) {
        return dirname($docroot) . DIRECTORY_SEPARATOR . $name;   // safely above the webroot
    }
    return dirname($phone_norm) . DIRECTORY_SEPARATOR . $name;
}

$default_cfg_dir = nano_install_default_cfg_dir($phone_dir);

/** True if $path lives inside DOCUMENT_ROOT (i.e. would be web-accessible). */
function nano_install_is_inside_docroot(string $path): bool
{
    $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/') : '';
    if ($docroot === '') return false;
    $p = rtrim(str_replace('\\', '/', $path), '/');
    return $p === $docroot || str_starts_with($p, $docroot . '/');
}

function nano_install_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function nano_install_page(string $title, string $body): void
{
    $t = nano_install_h($title);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . $t . ' - Nano Call install</title><style>'
       . 'body{font-family:system-ui,-apple-system,sans-serif;max-width:42em;margin:2em auto;padding:0 1em;color:#1f2328;line-height:1.55}'
       . 'h1{font-size:1.5em;margin:0 0 1em}h2{font-size:1.1em;margin:1.5em 0 .5em}'
       . 'code,pre{background:#f6f8fa;border-radius:4px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.92em}'
       . 'code{padding:.1em .35em}pre{padding:.75em 1em;overflow:auto}'
       . 'label{display:block;margin:1em 0;font-weight:500}'
       . 'input[type=text]{display:block;width:100%;padding:.55em .7em;font:inherit;border:1px solid #d0d7de;border-radius:4px;margin-top:.35em}'
       . '.btn{display:inline-block;padding:.6em 1.2em;background:#1f6feb;color:#fff;border:1px solid #1f6feb;border-radius:4px;text-decoration:none;cursor:pointer;font:inherit}'
       . '.btn:hover{background:#0a4fc4}.btn-secondary{background:#fff;color:#1f2328;border-color:#d0d7de}'
       . '.danger{background:#ffebe9;border:1px solid #82071e;color:#82071e;padding:1em;border-radius:4px;margin:1em 0}'
       . '.success{background:#dafbe1;border:1px solid #1a7f37;color:#1a7f37;padding:1em;border-radius:4px;margin:1em 0}'
       . '.warning{background:#fff8c5;border:1px solid #9a6700;color:#7d4e00;padding:1em;border-radius:4px;margin:1em 0}'
       . '.meta{color:#57606a;font-size:.85em}'
       . '</style></head><body><h1>Nano Call install: ' . $t . '</h1>' . $body
       . '<p class="meta">Delete install.php from /phone/ after a successful install.</p></body></html>';
}

$delete_form = '<form method="post" action="' . nano_install_h($self_url) . '" style="display:inline">'
    . '<input type="hidden" name="action" value="delete">'
    . '<button class="btn btn-secondary" type="submit" onclick="return confirm(\'Delete install.php now?\')">Delete install.php</button></form>';

/* ---- self-delete (POST action=delete) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (@unlink($self_file)) {
        nano_install_page('install.php removed',
            '<div class="success"><p><strong>install.php deleted.</strong></p></div>'
            . (file_exists($bootstrap) ? '<p><a class="btn" href="' . nano_install_h($admin_url) . '">Go to admin</a></p>' : ''));
    } else {
        nano_install_page('cannot delete',
            '<div class="danger"><p>PHP could not delete <code>install.php</code> on this host. Remove it manually via your file manager / SFTP / <code>rm ' . nano_install_h($self_file) . '</code>.</p></div>');
    }
    exit;
}

/* ---- already configured? bail ---------------------------------------- */
if (file_exists($bootstrap)) {
    nano_install_page('already configured',
        '<div class="warning"><p><strong>This install is already configured.</strong> <code>bootstrap.php</code> exists; re-running the installer would overwrite the live configuration.</p></div>'
        . '<p>To reconfigure from scratch, delete <code>bootstrap.php</code> AND the config directory it points at, then reload.</p>'
        . '<p><a class="btn" href="' . nano_install_h($admin_url) . '">Go to admin</a> ' . $delete_form . '</p>');
    exit;
}

/* ---- POST: try to install -------------------------------------------- */
$errors  = [];
$cfg_dir = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg_dir = rtrim(str_replace('\\', '/', trim((string) ($_POST['cfg_dir'] ?? ''))), '/');

    if ($cfg_dir === '') {
        $errors[] = 'Config directory path is required.';
    } elseif (str_contains($cfg_dir, '..')) {
        $errors[] = 'Path may not contain "..".';
    } elseif (str_starts_with($cfg_dir, $phone_dir . '/') || $cfg_dir === $phone_dir) {
        $errors[] = 'Config directory must be OUTSIDE the phone/ directory (that is the point).';
    } elseif (nano_install_is_inside_docroot($cfg_dir)) {
        $errors[] = 'Config directory <code>' . nano_install_h($cfg_dir) . '</code> is inside the webroot (<code>' . nano_install_h((string) $_SERVER['DOCUMENT_ROOT']) . '</code>) and would be web-accessible. Use a path ABOVE the webroot, e.g. <code>' . nano_install_h(dirname((string) $_SERVER['DOCUMENT_ROOT']) . '/nano-phone-config') . '</code>.';
    } elseif (is_file($cfg_dir . '/config.json')) {
        $errors[] = 'A <code>config.json</code> already exists in <code>' . nano_install_h($cfg_dir) . '</code> - that belongs to another install. Choose a different, empty config directory for THIS line.';
    } else {
        if (!is_dir($cfg_dir) && !@mkdir($cfg_dir, 0750, true)) {
            $errors[] = 'Could not create <code>' . nano_install_h($cfg_dir) . '</code>. PHP likely lacks permission on its parent. Create it manually, <code>chmod 750</code>, then reload.';
        }
        if (empty($errors)) {
            $test = $cfg_dir . '/.write-test';
            if (@file_put_contents($test, 'ok') === false) {
                $errors[] = 'Directory <code>' . nano_install_h($cfg_dir) . '</code> exists but PHP cannot write to it. Fix ownership/permissions (e.g. <code>chmod 750</code>) and reload.';
            } else {
                @unlink($test);
                @chmod($cfg_dir, 0750);
            }
        }
        if (empty($errors)) {
            $cfg_dir_php = var_export($cfg_dir, true);
            $bootstrap_contents = "<?php\n"
                . "// Generated by install.php on " . gmdate('Y-m-d\TH:i:s\Z') . " UTC.\n"
                . "// Edit the path below if you ever move the config directory.\n\n"
                . "\$cfg_dir = " . $cfg_dir_php . ";\n\n"
                . "define('NANO_CALL_CONFIG_PATH', \$cfg_dir . '/config.json');\n"
                . "define('NANO_CALL_ADMIN_PATH',  \$cfg_dir . '/admin.json');\n"
                . "define('NANO_CALL_DATA_DIR',    __DIR__ . '/data');\n\n"
                . "define('NANO_CALL_BOOTSTRAPPED', true);\n";
            if (@file_put_contents($bootstrap, $bootstrap_contents) === false) {
                $errors[] = 'Could not write <code>bootstrap.php</code> in the phone/ directory. Check that PHP can write to <code>' . nano_install_h($phone_dir) . '</code>.';
            } else {
                @chmod($bootstrap, 0640);
            }
        }
        if (empty($errors)) {
            nano_install_page('install complete',
                '<div class="success"><p><strong>Installed.</strong> <code>bootstrap.php</code> is in place and points at <code>' . nano_install_h($cfg_dir) . '</code>.</p></div>'
                . '<h2>Next step</h2>'
                . '<p><a class="btn" href="' . nano_install_h($admin_url) . '">Open the admin setup</a></p>'
                . '<p>The admin page creates the operator password and your call settings. <strong>Do not delete install.php yet</strong> - finish setup first.</p>'
                . '<h2>What just happened</h2><ul>'
                . '<li>Created <code>' . nano_install_h($cfg_dir) . '</code> (mode 0750) for outside-webroot config.</li>'
                . '<li>Wrote <code>bootstrap.php</code> in phone/ pointing at it.</li>'
                . '<li>No <code>config.json</code> yet - that is written when you complete admin setup.</li>'
                . '</ul>');
            exit;
        }
    }
}

/* ---- GET (or POST with errors): show the form ------------------------ */
if ($cfg_dir === '') $cfg_dir = $default_cfg_dir;
$err_html = '';
if ($errors) {
    $err_html = '<div class="danger"><ul><li>' . implode('</li><li>', $errors) . '</li></ul></div>';
}
nano_install_page('set up Nano Call',
    $err_html
    . '<p>Nano Call keeps its <strong>config, operator password and licence key in a directory OUTSIDE the webroot</strong>, so they are never web-reachable. Confirm where that directory should be:</p>'
    . '<form method="post" action="' . nano_install_h($self_url) . '">'
    . '<label>Outside-webroot config directory (absolute path)'
    . '<input type="text" name="cfg_dir" value="' . nano_install_h($cfg_dir) . '"></label>'
    . '<p class="meta">Default is one level above your webroot. It must be outside <code>' . nano_install_h((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) . '</code>.</p>'
    . '<p><button class="btn" type="submit">Create config directory</button></p>'
    . '</form>');
