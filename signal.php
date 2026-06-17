<?php
// Nano Call - signaling for shared PHP hosting. No database, no libraries.
// Clients poll this file; messages wait in small JSON "mailbox" files under
// data/. Audio never touches the server - it flows peer to peer (WebRTC).
//
// Roles:
//   - the OPERATOR console goes online as the business handle (password gated)
//   - VISITORS register a throwaway visitor-* name and call the business
// Branding/labels/subjects live in data/config.json (written by admin.php);
// the admin password hash lives in data/admin.json and is NEVER served out.

header('Content-Type: application/json');
header('Cache-Control: no-store');
// the embed widget reads the public config from other sites - allow it
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// Per-site bootstrap defines the outside-webroot config paths and the gate.
// Missing = this install has not been set up yet; point the caller at install.php.
$__bootstrap = __DIR__ . '/bootstrap.php';
if (!is_file($__bootstrap)) {
    http_response_code(503);
    echo json_encode(['error' => 'not-installed', 'detail' => 'Run install.php to set up Nano Call.']);
    exit;
}
require $__bootstrap;            // defines NANO_CALL_CONFIG_PATH / _ADMIN_PATH / _DATA_DIR / _BOOTSTRAPPED
require __DIR__ . '/licence.php';

// --- TURN relay fallback (full setup in RELAY.md) ---------------------
// Self-hosted coturn using TIME-LIMITED credentials (coturn use-auth-secret).
// Put the SAME secret here as `static-auth-secret` in turnserver.conf. The
// secret never leaves the server: browsers only ever receive a short-lived
// username/password minted from it in the `ice` action below, never the
// secret itself. Fill these in on the live server; keep them blank in the
// committed/public copy so no real credential is ever pushed.
$TURN_HOST   = '';      // 'turn.example.com:3478' or 'IP:PORT' (host:port, no scheme). Blank = relay off.
$TURN_SECRET = '';      // shared secret; MUST match static-auth-secret in coturn
$TURN_TTL    = 3600;    // seconds a minted credential stays valid
$TURN_TCP    = true;    // also advertise the TCP transport URL (turn:...?transport=tcp)
$TURN_TLS    = '';      // 'turn.example.com:443' to also advertise turns:// (strict firewalls); blank = off

// Optional extra relays that issue their own STATIC credentials (Option C
// providers). Leave empty for the ephemeral coturn above. NEVER commit real
// credentials here - fill them in only on the live server.
$TURN_STATIC = [];

$METERED_DOMAIN = '';   // optional managed relay - see RELAY.md
$METERED_KEY    = '';
$RELAY_ONLY     = false; // true = route all audio through the relay (hides IPs)
// ----------------------------------------------------------------------

// a visitor or console stays "reserved" to its browser token this long after
// last seen; visitor-* names are swept much sooner (they are disposable)
$RESERVE_SECONDS = 30 * 86400;

$DATA = NANO_CALL_DATA_DIR;             // transient signaling files (in webroot)
if (!is_dir($DATA)) { mkdir($DATA, 0755, true); }

// settings + licence key (config.json) and the admin password hash (admin.json)
// live OUTSIDE the webroot - paths come from bootstrap.php. The transient
// box-/seen-/ice files stay in $DATA, guard-protected (see licence.php).
$CONFIG_FILE = NANO_CALL_CONFIG_PATH;
$ADMIN_FILE  = NANO_CALL_ADMIN_PATH;

// config the app falls back to before the admin has ever saved anything
function default_config() {
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
function read_config() {
    global $CONFIG_FILE;   // outside webroot - plain JSON, no guard needed
    if (is_file($CONFIG_FILE)) {
        $c = json_decode((string) file_get_contents($CONFIG_FILE), true);
        if (is_array($c)) return array_merge(default_config(), $c);
    }
    return default_config();
}
function read_admin() {
    global $ADMIN_FILE;    // outside webroot - plain JSON, no guard needed
    if (is_file($ADMIN_FILE)) {
        $a = json_decode((string) file_get_contents($ADMIN_FILE), true);
        if (is_array($a)) return $a;
    }
    return [];
}

// a name is only ever a-z 0-9 _ - so it can never escape the data dir
function clean_name($n) {
    $n = strtolower(trim((string) $n));
    return preg_match('/^[a-z0-9_-]{1,40}$/', $n) ? $n : null;
}

function box_path($n)  { global $DATA; return "$DATA/box-$n.php"; }
function seen_path($n) { global $DATA; return "$DATA/seen-$n.php"; }

// presence: you are online if your browser polled in the last 12 seconds
function read_seen($n) {
    $f = seen_path($n);
    if (!is_file($f)) return [0, ''];
    $raw   = nano_call_data_unwrap((string) file_get_contents($f));
    $parts = explode('|', $raw, 2);
    return [(int) $parts[0], $parts[1] ?? ''];
}
function write_seen($n, $token) { return file_put_contents(seen_path($n), nano_call_data_wrap(time() . '|' . $token)) !== false; }
function is_online($n) { [$t, ] = read_seen($n); return (time() - $t) < 12; }

function reply($obj) { echo json_encode($obj); exit; }

// fetch a URL; works whether the host allows allow_url_fopen or only curl
function http_get($url) {
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $out = @file_get_contents($url, false, $ctx);
        if ($out !== false) return $out;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $out = curl_exec($ch);
        curl_close($ch);
        if ($out !== false) return $out;
    }
    return null;
}

// read the mailbox under an exclusive lock; optionally empty it / append to it
function with_box($name, $fn) {
    $fp = fopen(box_path($name), 'c+');
    if (!$fp) reply(['error' => 'storage']);
    flock($fp, LOCK_EX);
    $raw  = nano_call_data_unwrap(stream_get_contents($fp));
    $msgs = $raw ? (json_decode($raw, true) ?: []) : [];
    $out  = $fn($msgs);
    ftruncate($fp, 0);
    rewind($fp);
    if ($out !== null && count($out)) fwrite($fp, nano_call_data_wrap(json_encode($out)));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $msgs;
}

// keep data/ tidy: disposable visitor-* files older than an hour are dropped.
// Runs on a small fraction of requests so it never adds latency to a call.
function sweep_guests() {
    global $DATA;
    if (rand(1, 25) !== 1) return;
    foreach (glob("$DATA/seen-visitor-*.php") ?: [] as $f) {
        if (time() - (int) @filemtime($f) > 3600) {
            @unlink($f);
            @unlink(str_replace('seen-', 'box-', substr($f, 0, -4)) . '.php');
        }
    }
}

$in     = json_decode((string) file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? ($_GET['action'] ?? '');
$token  = preg_replace('/[^a-f0-9-]/', '', (string) ($in['token'] ?? ''));
$me     = clean_name($in['me'] ?? '');

$cfg      = read_config();
$business = clean_name($cfg['business']) ?: 'reception';

// config is public and read cross-origin (GET, no token) by the embed widget
if ($action === 'config') {
    reply(['config' => [
        'business'    => $business,
        'brandName'   => (string) $cfg['brandName'],
        'accent'      => (string) $cfg['accent'],
        'logo'        => (string) $cfg['logo'],
        'greeting'    => (string) $cfg['greeting'],
        'buttonLabel' => (string) $cfg['buttonLabel'],
        'theme'       => (string) $cfg['theme'],
        'position'    => (string) $cfg['position'],
        'poweredBy'   => nano_call_show_powered_by((string) $cfg['site_url'], (string) $cfg['licence_key']),
        'subjects'    => array_values((array) $cfg['subjects']),
        'configured'  => (bool) $cfg['configured'],
        'online'      => is_online($business),   // is the operator console live right now?
    ]]);
}

// everything else is a token-bearing same-origin call
if (!$token) reply(['error' => 'bad-token']);

switch ($action) {

    case 'ice':
        // STUN/TURN servers for this call (relay fallback)
        $servers = [['urls' => 'stun:stun.l.google.com:19302']];

        // self-hosted coturn: mint a short-lived credential from the shared
        // secret (coturn use-auth-secret / TURN REST API). The username is
        // "<expiry-unix-ts>:nano" and the password is base64(HMAC-SHA1(secret,
        // username)). coturn validates it without us storing anything, and the
        // secret itself never reaches the browser - only this expiring pair.
        if ($TURN_HOST && $TURN_SECRET) {
            $user = (time() + (int) $TURN_TTL) . ':nano';
            $pass = base64_encode(hash_hmac('sha1', $user, $TURN_SECRET, true));
            $servers[] = ['urls' => "stun:$TURN_HOST"];
            $servers[] = ['urls' => "turn:$TURN_HOST", 'username' => $user, 'credential' => $pass];
            if ($TURN_TCP) {
                $servers[] = ['urls' => "turn:$TURN_HOST?transport=tcp", 'username' => $user, 'credential' => $pass];
            }
            if ($TURN_TLS) {
                $servers[] = ['urls' => "turns:$TURN_TLS?transport=tcp", 'username' => $user, 'credential' => $pass];
            }
        }

        // extra static-credential relays (Option C providers)
        foreach ($TURN_STATIC as $s) { $servers[] = $s; }

        if ($METERED_DOMAIN && $METERED_KEY) {
            $cache = "$DATA/ice-cache.php";
            $extra = null;
            if (is_file($cache) && (time() - filemtime($cache)) < 3600) {
                $extra = json_decode(nano_call_data_unwrap((string) file_get_contents($cache)), true);
            }
            if (!is_array($extra)) {
                $raw = http_get("https://$METERED_DOMAIN/api/v1/turn/credentials?apiKey=" . urlencode($METERED_KEY));
                $extra = $raw ? json_decode($raw, true) : null;
                if (is_array($extra)) { file_put_contents($cache, nano_call_data_wrap(json_encode($extra))); }
            }
            if (is_array($extra)) {
                foreach ($extra as $s) { if (isset($s['urls'])) $servers[] = $s; }
            }
        }
        reply(['iceServers' => $servers, 'relayOnly' => (bool) $RELAY_ONLY]);

    case 'register-host':
        // ONLY the admin password can put a console online as the business
        // handle - otherwise a stranger could grab it and intercept calls
        if (!$me || $me !== $business) reply(['error' => 'not-host']);
        $admin = read_admin();
        $hash  = $admin['passHash'] ?? '';
        if ($hash === '') reply(['error' => 'no-admin']);   // admin not set up yet
        if (!password_verify((string) ($in['password'] ?? ''), $hash)) {
            reply(['error' => 'bad-password']);
        }
        if (!write_seen($me, $token)) reply(['error' => 'storage']);
        with_box($me, fn($m) => null);
        reply(['registered' => $me]);

    case 'register':
        // visitors only - never allowed to impersonate the business handle
        if (!$me) reply(['error' => 'bad-name']);
        if ($me === $business) reply(['error' => 'reserved']);
        sweep_guests();
        if (!write_seen($me, $token)) reply(['error' => 'storage']);
        with_box($me, fn($m) => null);
        reply(['registered' => $me]);

    case 'poll':
        if (!$me) reply(['error' => 'bad-name']);
        [, $owner] = read_seen($me);
        if ($owner !== '' && $owner !== $token) reply(['error' => 'name-taken']);
        write_seen($me, $token);
        $msgs = with_box($me, fn($m) => null);
        // tell a waiting visitor whether the business console is actually online
        reply(['messages' => $msgs, 'peerOnline' => is_online($business)]);

    case 'send':
        if (!$me) reply(['error' => 'bad-name']);
        $to  = clean_name($in['to'] ?? '');
        $msg = $in['msg'] ?? null;
        if (!$to || !is_array($msg)) reply(['error' => 'bad-send']);
        if (!is_online($to)) reply(['error' => 'unavailable', 'who' => $to]);
        $msg['from'] = $me;                 // subject/note ride along untouched
        with_box($to, function ($m) use ($msg) { $m[] = $msg; return $m; });
        reply(['ok' => true]);
}

reply(['error' => 'bad-action']);
