<?php
/**
 * Nano Call - licence verification.
 *
 * Included by signal.php and admin.php. Verifies customer licence keys
 * against the embedded Digital Fracture public key using Ed25519. Mirrors
 * the Nano CMS / Nano Cart pattern; only the expected `product` field in
 * the payload differs ("nano-call").
 *
 * No network calls. No phone-home. All verification is local. The generator
 * and signing key live in the private nano-licence-tools repo. A valid
 * per-domain licence suppresses the "Powered by Nano Call" attribution.
 */

if (!defined('NANO_CALL_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

/*
 * --- Data-file protection -------------------------------------------------
 * Everything under data/ (admin password hash, presence tokens, in-flight
 * signaling, cached relay creds) is private. data/.htaccess denies direct
 * access, but .htaccess only works on Apache/LiteSpeed - nginx ignores it, and
 * a host can disable AllowOverride. So every data file is also (a) named .php
 * and (b) written with a PHP guard as its first line. If the webserver ever
 * serves one directly it runs the guard - 403, empty body - instead of handing
 * out the contents. The guard is stripped again on read. Belt and braces.
 */
const NANO_CALL_DATA_GUARD = "<?php http_response_code(403); exit; ?>\n";

/** Prefix the guard before writing a file under data/. */
function nano_call_data_wrap(string $contents): string {
    return NANO_CALL_DATA_GUARD . $contents;
}

/** Strip the guard back off on read; tolerates legacy unguarded files. */
function nano_call_data_unwrap(string $raw): string {
    if (strncmp($raw, '<?php', 5) === 0) {
        $nl = strpos($raw, "\n");
        return $nl === false ? '' : substr($raw, $nl + 1);
    }
    return $raw;
}

/**
 * Read a guarded data file. If only a legacy unguarded copy exists (older
 * .json/.txt name), migrate it: rewrite it guarded under the new path and
 * delete the exposed original, so the upgrade needs no manual file shuffling.
 * Returns the stored string (guard stripped), or null if nothing is stored.
 */
function nano_call_data_load(string $path, ?string $legacy = null): ?string {
    if (is_file($path)) {
        return nano_call_data_unwrap((string) file_get_contents($path));
    }
    if ($legacy !== null && is_file($legacy)) {
        $raw = (string) file_get_contents($legacy);
        if (@file_put_contents($path, nano_call_data_wrap($raw)) !== false) {
            @chmod($path, 0640);
            @unlink($legacy);                 // remove the unprotected original
        }
        return $raw;
    }
    return null;
}

/**
 * Digital Fracture master public key (Ed25519, base64). Shared across the
 * whole Nano suite; only the signed payload's `product` field decides which
 * product a licence is valid for.
 *
 * Safe to ship in MIT-licensed code: this is the verification half of the
 * keypair. Only the matching private key (held offline in nano-licence-tools)
 * can mint a valid licence. The `_V1` suffix leaves room for rotation.
 */
const NANO_CALL_LICENCE_PUBKEY_V1 = 'OW0ZWPowsYFF4Hv49r8Kc8OcM31COddoOk5j1UVCWfY=';

/**
 * The host a licence is bound to, derived from the admin-set `site_url`
 * (where Nano Call is installed) - NEVER from $_SERVER['HTTP_HOST'], which
 * is attacker-controlled and would allow a Host-spoof bypass.
 *
 * Returns '' on any failure; callers treat '' as "show the attribution".
 */
function nano_call_licence_canonical_host(string $site_url): string
{
    $site_url = trim($site_url);
    if ($site_url === '') return '';

    $parts = parse_url($site_url);
    $host  = $parts['host'] ?? null;
    if (!is_string($host) || $host === '') return '';
    $host = strtolower($host);

    // keep a non-default port so dev-host detection still sees the marker
    $port = isset($parts['port']) ? (int) $parts['port'] : 0;
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $default_port = $scheme === 'http' ? 80 : ($scheme === 'https' ? 443 : 0);
    if ($port > 0 && $port !== $default_port) {
        return $host . ':' . $port;
    }
    return $host;
}

/**
 * Hosts that bypass the licence check (no attribution shown):
 *   localhost / 127.0.0.1 / ::1, anything with a port, *.test, *.local.
 */
function nano_call_is_dev_host(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '') return true;
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '[::1]') {
        return true;
    }
    if (strpos($host, ':') !== false) return true;
    foreach (['.test', '.local'] as $suffix) {
        if (substr($host, -strlen($suffix)) === $suffix) return true;
    }
    return false;
}

/**
 * Detailed verification: ['ok' => bool, 'reason' => ?string, 'payload' => ?array].
 * `payload` is populated whenever the licence parses, so the admin can show
 * "your licence covers X, this site runs on Y" without re-decoding.
 */
function nano_call_licence_inspect(string $licence_key, string $current_host): array
{
    $licence_key = trim($licence_key);
    if ($licence_key === '') {
        return ['ok' => false, 'reason' => 'No licence key set.', 'payload' => null];
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return ['ok' => false, 'reason' => 'libsodium is not available on this host.', 'payload' => null];
    }
    if (substr_count($licence_key, '.') !== 1) {
        return ['ok' => false, 'reason' => 'Malformed licence key (expected base64.base64).', 'payload' => null];
    }

    [$payload_b64, $signature_b64] = explode('.', $licence_key, 2);
    $payload_json = base64_decode($payload_b64, true);
    $signature    = base64_decode($signature_b64, true);
    if ($payload_json === false || $signature === false) {
        return ['ok' => false, 'reason' => 'Licence key contains invalid base64.', 'payload' => null];
    }
    if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return ['ok' => false, 'reason' => 'Signature length is wrong.', 'payload' => null];
    }

    $payload = json_decode($payload_json, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'reason' => 'Licence payload is not valid JSON.', 'payload' => null];
    }

    foreach (['product', 'domain', 'tier', 'licence_id', 'issued'] as $field) {
        if (!array_key_exists($field, $payload)) {
            return ['ok' => false, 'reason' => "Licence payload missing field '$field'.", 'payload' => $payload];
        }
    }

    if ((string) $payload['product'] !== 'nano-call') {
        $other = (string) $payload['product'];
        return ['ok' => false, 'reason' => "Licence is for product '$other', not nano-call.", 'payload' => $payload];
    }

    $pubkey = base64_decode(NANO_CALL_LICENCE_PUBKEY_V1, true);
    if ($pubkey === false || strlen($pubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return ['ok' => false, 'reason' => 'Embedded public key is malformed.', 'payload' => $payload];
    }

    // Verify against the RAW decoded payload bytes (what the signer signed).
    // Re-encoding the parsed array would risk a key-order/whitespace change
    // that invalidates a genuine signature.
    try {
        $sig_ok = sodium_crypto_sign_verify_detached($signature, $payload_json, $pubkey);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'Signature verification raised an error.', 'payload' => $payload];
    }
    if (!$sig_ok) {
        return ['ok' => false, 'reason' => 'Signature does not match the embedded public key.', 'payload' => $payload];
    }

    // Wildcard '*' only honoured for agency-unlimited. www. is stripped so a
    // licence for example.com also covers www.example.com.
    $licence_domain = strtolower((string) $payload['domain']);
    $check_host     = strtolower(trim($current_host));
    if (str_starts_with($check_host, 'www.')) {
        $check_host = substr($check_host, 4);
    }
    $tier = (string) $payload['tier'];
    if ($licence_domain === '*' && $tier === 'agency-unlimited') {
        // wildcard pass
    } elseif ($licence_domain !== $check_host) {
        return [
            'ok' => false,
            'reason' => "Licence covers '$licence_domain', site runs on '$check_host'.",
            'payload' => $payload,
        ];
    }

    $expires = $payload['expires'] ?? null;
    if ($expires !== null && $expires !== '') {
        $ts = strtotime((string) $expires);
        if ($ts === false) {
            return ['ok' => false, 'reason' => "Licence has an unparseable expiry value: $expires.", 'payload' => $payload];
        }
        if ($ts < time()) {
            return ['ok' => false, 'reason' => "Licence expired on $expires.", 'payload' => $payload];
        }
    }

    return ['ok' => true, 'reason' => null, 'payload' => $payload];
}

function nano_call_verify_licence(string $licence_key, string $current_host): bool
{
    return nano_call_licence_inspect($licence_key, $current_host)['ok'];
}

/**
 * Should the "Powered by Nano Call" attribution be shown?
 *
 *   - dev host                -> false (no badge while testing)
 *   - valid licence for host  -> false (paid: badge removed)
 *   - everything else         -> true  (fail safe to showing it, incl. a
 *                                        misconfigured/empty site_url)
 */
function nano_call_show_powered_by(string $site_url, string $licence_key): bool
{
    $host = nano_call_licence_canonical_host($site_url);
    if ($host === '') return true;                       // misconfigured: show it
    if (nano_call_is_dev_host($host)) return false;      // local/testing: hide it
    if ($licence_key !== '' && nano_call_verify_licence($licence_key, $host)) {
        return false;                                    // licensed: hide it
    }
    return true;                                         // unlicensed: show it
}
