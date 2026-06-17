<?php
/**
 * Nano Call - admin (REMOVABLE).
 *
 * Configure the business handle, branding, subjects, theme, site URL and
 * licence key here. Settings + licence key (config.json) and the admin
 * password hash (admin.json) are written to the OUTSIDE-webroot config
 * directory set up by install.php (see bootstrap.php). Once set up you can
 * DELETE this folder from the host to harden the install - the app keeps
 * running off config.json. Re-upload it whenever you want to change settings.
 *
 * First run: create an admin password (this becomes the password the operator
 * console needs to go online, and the password to reach this admin).
 */

declare(strict_types=1);
session_start();

// Per-site bootstrap (one level up, in the phone/ root) defines the outside-
// webroot config paths and the gate. Missing = not installed yet -> installer.
$__bootstrap = __DIR__ . '/../bootstrap.php';
if (!is_file($__bootstrap)) {
    header('Location: ../install.php');
    exit;
}
require $__bootstrap;                   // NANO_CALL_CONFIG_PATH / _ADMIN_PATH / _DATA_DIR / _BOOTSTRAPPED
require __DIR__ . '/../licence.php';    // admin lives in its own folder, one level down

// settings + licence key (config.json) and the admin password hash (admin.json)
// live OUTSIDE the webroot - paths come from bootstrap.php.
$DATA        = NANO_CALL_DATA_DIR;
$CONFIG_FILE = NANO_CALL_CONFIG_PATH;
$ADMIN_FILE  = NANO_CALL_ADMIN_PATH;
if (!is_dir($DATA)) { @mkdir($DATA, 0755, true); }

function default_config(): array {
    return [
        'business'    => 'reception',
        'brandName'   => 'Our Team',
        'accent'      => '#ff4d00',
        'logo'        => '',
        'greeting'    => 'Call us - we are happy to help.',
        'buttonLabel' => 'Call us',
        'theme'       => 'auto',
        'position'    => 'bottom-right',
        'subjects'    => ['General enquiry'],
        'site_url'    => '',
        'licence_key' => '',
        'configured'  => false,
    ];
}
function load_config(): array {
    global $CONFIG_FILE;   // outside webroot - plain JSON
    if (is_file($CONFIG_FILE)) {
        $c = json_decode((string) file_get_contents($CONFIG_FILE), true);
        if (is_array($c)) return array_merge(default_config(), $c);
    }
    return default_config();
}
function load_admin(): array {
    global $ADMIN_FILE;    // outside webroot - plain JSON
    if (is_file($ADMIN_FILE)) {
        $a = json_decode((string) file_get_contents($ADMIN_FILE), true);
        if (is_array($a)) return $a;
    }
    return [];
}
function save_json(string $path, array $data): bool {
    // config/admin live outside the webroot, so plain JSON (no web guard needed)
    @mkdir(dirname($path), 0750, true);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) return false;
    @chmod($tmp, 0640);
    return rename($tmp, $path);
}
function clean_handle(string $n): string {
    $n = strtolower(trim($n));
    return preg_match('/^[a-z0-9_-]{1,40}$/', $n) ? $n : '';
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$admin   = load_admin();
$cfg     = load_config();
$isSetup = ($admin['passHash'] ?? '') !== '';
$loggedIn = !empty($_SESSION['nano_call_admin']);
$notice = '';
$error  = '';

// CSRF
if (empty($_SESSION['nc_csrf'])) { $_SESSION['nc_csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['nc_csrf'];

$action = $_POST['do'] ?? '';
if ($action !== '') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Session expired - please try again.';
        $action = '';
    }
}

// ---------- first-run setup: create the admin password ----------
if ($action === 'setup' && !$isSetup) {
    $p1 = (string) ($_POST['pass'] ?? '');
    $p2 = (string) ($_POST['pass2'] ?? '');
    if (strlen($p1) < 8)      { $error = 'Use a password of at least 8 characters.'; }
    elseif ($p1 !== $p2)      { $error = 'The two passwords do not match.'; }
    else {
        $admin['passHash'] = password_hash($p1, PASSWORD_DEFAULT);
        if (save_json($ADMIN_FILE, $admin)) {
            if (!is_file($CONFIG_FILE)) { save_json($CONFIG_FILE, default_config()); }
            $_SESSION['nano_call_admin'] = true;
            $isSetup = true; $loggedIn = true;
            $notice = 'Admin password created. Now set up your call line below.';
        } else { $error = 'Could not write to the data/ folder - make it writable (755).'; }
    }
}

// ---------- login ----------
if ($action === 'login' && $isSetup && !$loggedIn) {
    if (password_verify((string) ($_POST['pass'] ?? ''), $admin['passHash'])) {
        $_SESSION['nano_call_admin'] = true; $loggedIn = true;
    } else { $error = 'Wrong password.'; }
}

if ($action === 'logout') { $_SESSION['nano_call_admin'] = false; $loggedIn = false; }

// ---------- save settings ----------
if ($action === 'save' && $loggedIn) {
    $business = clean_handle((string) ($_POST['business'] ?? ''));
    if ($business === '') { $error = 'Business handle must be letters, numbers, - or _ (no spaces).'; }
    else {
        $subjects = array_values(array_filter(array_map(
            fn($s) => trim($s),
            preg_split('/\r\n|\r|\n/', (string) ($_POST['subjects'] ?? ''))
        ), fn($s) => $s !== ''));
        if (!$subjects) { $subjects = ['General enquiry']; }

        $theme = in_array($_POST['theme'] ?? '', ['auto', 'light', 'dark'], true) ? $_POST['theme'] : 'auto';
        $pos   = in_array($_POST['position'] ?? '', ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true) ? $_POST['position'] : 'bottom-right';
        $accent = strtolower(trim((string) ($_POST['accent'] ?? '')));
        if ($accent !== '' && $accent[0] !== '#') { $accent = '#' . $accent; }   // tolerate "ff4d00"
        if (!preg_match('/^#[0-9a-f]{6}$/', $accent)) { $accent = '#ff4d00'; }

        $cfg = array_merge($cfg, [
            'business'    => $business,
            'brandName'   => trim((string) ($_POST['brandName'] ?? '')) ?: 'Our Team',
            'accent'      => $accent,
            'logo'        => trim((string) ($_POST['logo'] ?? '')),
            'greeting'    => trim((string) ($_POST['greeting'] ?? '')),
            'buttonLabel' => trim((string) ($_POST['buttonLabel'] ?? '')) ?: 'Call us',
            'theme'       => $theme,
            'position'    => $pos,
            'subjects'    => array_slice($subjects, 0, 12),
            'site_url'    => trim((string) ($_POST['site_url'] ?? '')),
            'licence_key' => trim((string) ($_POST['licence_key'] ?? '')),
            'configured'  => true,
        ]);
        if (save_json($CONFIG_FILE, $cfg)) { $notice = 'Settings saved.'; }
        else { $error = 'Could not write to the data/ folder - make it writable (755).'; }

        // optional password change
        $np = (string) ($_POST['newpass'] ?? '');
        if ($np !== '') {
            if (strlen($np) < 8) { $error = 'New password must be at least 8 characters - other settings were saved.'; }
            else {
                $admin['passHash'] = password_hash($np, PASSWORD_DEFAULT);
                save_json($ADMIN_FILE, $admin);
                $notice = 'Settings and new password saved.';
            }
        }
    }
}

// licence status for the panel
$licStatus = null;
if ($loggedIn) {
    $host = nano_call_licence_canonical_host((string) $cfg['site_url']);
    if ((string) $cfg['licence_key'] !== '') {
        $licStatus = nano_call_licence_inspect((string) $cfg['licence_key'], $host);
    }
}
$showPB = nano_call_show_powered_by((string) $cfg['site_url'], (string) $cfg['licence_key']);

// the embed snippet, derived from site_url if given
$snippetBase = '';
if ((string) $cfg['site_url'] !== '') {
    $snippetBase = rtrim((string) $cfg['site_url'], '/') . '/';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Nano Call - admin</title>
<link rel="stylesheet" href="../css/style.css?v=3" />
<style>
  body{place-items:start center;padding:32px 16px}
  .admin{width:min(640px,96vw);display:grid;gap:18px}
  .admin .card{display:grid;gap:14px}
  .admin h2{font-size:1.15rem;font-weight:600}
  .admin h3{font-size:.95rem;font-weight:600;color:var(--muted);margin-top:4px}
  .field{display:grid;gap:6px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  textarea{background:var(--well);border:1px solid var(--line);border-radius:var(--radius-s);color:var(--ink);font:inherit;padding:12px 14px;width:100%;resize:vertical}
  select{background:var(--well);border:1px solid var(--line);border-radius:var(--radius-s);color:var(--ink);font:inherit;padding:12px 14px;width:100%}
  input[type=color]{padding:4px;height:44px}
  .notice{background:var(--ring);color:var(--accent-text);border-radius:var(--radius-s);padding:10px 14px;font-size:.9rem}
  .err{background:rgba(229,72,77,.12);color:var(--danger);border-radius:var(--radius-s);padding:10px 14px;font-size:.9rem}
  .pill{display:inline-block;font-size:.8rem;padding:3px 10px;border-radius:999px}
  .pill.ok{background:var(--ring);color:var(--accent-text)}
  .pill.no{background:rgba(229,72,77,.14);color:var(--danger)}
  pre{background:var(--well);border:1px solid var(--line);border-radius:var(--radius-s);padding:12px 14px;overflow:auto;font-size:.8rem;white-space:pre-wrap;word-break:break-all}
  .muted{color:var(--muted);font-size:.85rem}
  .bar h1{font-size:1.05rem}
  .hexRow{display:flex;align-items:center;gap:10px}
  .hexSwatch{width:38px;height:38px;border-radius:9px;border:1px solid var(--line);flex:none;background:var(--accent)}
  .hexRow input{flex:1;font-variant-numeric:tabular-nums}
  .hexRgb{font-size:.8rem;color:var(--muted);font-variant-numeric:tabular-nums;white-space:nowrap;flex:none;min-width:108px}
</style>
</head>
<body>
<main class="admin">
  <header class="bar">
    <svg class="mark" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8c1.4 2.7 3.9 5.2 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>
    <h1>Nano Call admin</h1>
    <?php if ($loggedIn): ?>
      <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="linkBtn" name="do" value="logout">Log out</button></form>
    <?php endif; ?>
  </header>

  <?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

  <?php if (!$isSetup): ?>
    <!-- first run -->
    <form class="card" method="post">
      <h2>Create your admin password</h2>
      <p class="muted">This password protects this admin page and is what your operator console needs to go online. Minimum 8 characters. There is no recovery, so store it safely.</p>
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <div class="field"><label>Admin password</label><input type="password" name="pass" autocomplete="new-password"></div>
      <div class="field"><label>Confirm password</label><input type="password" name="pass2" autocomplete="new-password"></div>
      <button class="primary big" name="do" value="setup">Create password</button>
    </form>

  <?php elseif (!$loggedIn): ?>
    <!-- login -->
    <form class="card" method="post">
      <h2>Admin login</h2>
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <div class="field"><label>Admin password</label><input type="password" name="pass" autocomplete="current-password" autofocus></div>
      <button class="primary big" name="do" value="login">Log in</button>
    </form>

  <?php else: ?>
    <!-- settings -->
    <form class="card" method="post">
      <h2>Call line settings</h2>
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <div class="grid2">
        <div class="field"><label>Business handle (internal name)</label><input name="business" value="<?= h((string)$cfg['business']) ?>" placeholder="acme-plumbing"></div>
        <div class="field"><label>Display name (shown to callers)</label><input name="brandName" value="<?= h((string)$cfg['brandName']) ?>" placeholder="Acme Plumbing"></div>
      </div>

      <div class="grid2">
        <div class="field"><label>Button label</label><input name="buttonLabel" value="<?= h((string)$cfg['buttonLabel']) ?>" placeholder="Call us"></div>
        <div class="field"><label>Accent colour (hex)</label>
          <div class="hexRow">
            <span class="hexSwatch" id="hexSwatch"></span>
            <input type="text" name="accent" id="accentHex" value="<?= h((string)$cfg['accent']) ?>" placeholder="#ff4d00" maxlength="7" autocomplete="off" spellcheck="false" inputmode="text">
            <span class="hexRgb" id="hexRgb"></span>
          </div>
        </div>
      </div>

      <div class="field"><label>Greeting (top of the call popup)</label><input name="greeting" value="<?= h((string)$cfg['greeting']) ?>" placeholder="Call us - we are happy to help."></div>
      <div class="field"><label>Logo URL (optional)</label><input name="logo" value="<?= h((string)$cfg['logo']) ?>" placeholder="https://yoursite.com/logo.png"></div>

      <div class="field">
        <label>Call subjects (one per line - the first is pre-selected)</label>
        <textarea name="subjects" rows="4" placeholder="General enquiry&#10;Sales&#10;Support&#10;Booking"><?= h(implode("\n", (array)$cfg['subjects'])) ?></textarea>
      </div>

      <div class="grid2">
        <div class="field"><label>Theme</label>
          <select name="theme">
            <?php foreach (['auto'=>'Follow visitor system','light'=>'Light','dark'=>'Dark'] as $v=>$t): ?>
              <option value="<?= $v ?>"<?= $cfg['theme']===$v?' selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Floating button position</label>
          <select name="position">
            <?php foreach (['bottom-right'=>'Bottom right','bottom-left'=>'Bottom left','top-right'=>'Top right','top-left'=>'Top left'] as $v=>$t): ?>
              <option value="<?= $v ?>"<?= $cfg['position']===$v?' selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <h3>Licence</h3>
      <div class="field">
        <label>Site URL (where Nano Call is installed - binds your licence)</label>
        <input name="site_url" value="<?= h((string)$cfg['site_url']) ?>" placeholder="https://acme-plumbing.co.uk/call/">
      </div>
      <div class="field">
        <label>Licence key (removes the "Powered by" line on this domain)</label>
        <textarea name="licence_key" rows="3" placeholder="paste your base64.base64 licence key"><?= h((string)$cfg['licence_key']) ?></textarea>
        <p class="muted">
          <?php if ($licStatus === null): ?>
            <span class="pill no">No licence</span> The "Powered by Nano Call" line is <?= $showPB ? 'shown' : 'hidden (dev host)' ?>.
          <?php elseif ($licStatus['ok']): ?>
            <span class="pill ok">Licensed</span> Valid for this domain. The "Powered by" line is removed.
          <?php else: ?>
            <span class="pill no">Invalid</span> <?= h((string)$licStatus['reason']) ?>
          <?php endif; ?>
        </p>
      </div>

      <h3>Change admin password (optional)</h3>
      <div class="field"><label>New password (leave blank to keep current)</label><input type="password" name="newpass" autocomplete="new-password"></div>

      <button class="primary big" name="do" value="save">Save settings</button>
    </form>

    <!-- embed snippets -->
    <div class="card">
      <h2>Add the button to a website</h2>
      <?php if ($snippetBase === ''): ?>
        <p class="muted">Set your Site URL above and save to see your exact copy-paste snippets.</p>
      <?php else: ?>
        <h3>Floating button (corner of the page)</h3>
        <pre>&lt;script src="<?= h($snippetBase) ?>js/embed.js" data-nano-call="floating"&gt;&lt;/script&gt;</pre>
        <h3>Inline button (drop where you want it)</h3>
        <pre>&lt;script src="<?= h($snippetBase) ?>js/embed.js"&gt;&lt;/script&gt;
&lt;span data-nano-call-button&gt;&lt;/span&gt;</pre>
      <?php endif; ?>
      <p class="muted">Operator console (keep open to receive calls): <a href="../index.html">open the console</a></p>
    </div>

    <div class="card">
      <h2>Harden this install</h2>
      <p class="muted">Once you are set up, delete <strong>install.php</strong> and the <strong>admin/</strong> folder from the host. The call line keeps working from your config directory above the webroot. Re-upload the admin/ folder any time you want to change settings.</p>
    </div>
  <?php endif; ?>
</main>
<script>
  // live swatch + RGB readout for the accent hex field
  (function () {
    var inp = document.getElementById('accentHex');
    if (!inp) return;
    var sw = document.getElementById('hexSwatch'), rgb = document.getElementById('hexRgb');
    function paint() {
      var v = inp.value.trim();
      if (v && v[0] !== '#') v = '#' + v;
      var m = /^#([0-9a-f]{6})$/i.exec(v);
      if (!m) { rgb.textContent = 'enter a 6-digit hex'; return; }
      var n = parseInt(m[1], 16), r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
      sw.style.background = v;
      rgb.textContent = 'rgb(' + r + ', ' + g + ', ' + b + ')';
    }
    inp.addEventListener('input', paint);
    paint();
  })();
</script>
</body>
</html>
