<?php
declare(strict_types=1);

/* Time-based one-time passwords (RFC 6238) for authenticator apps: Google Authenticator, Microsoft Authenticator, 1Password, Authy. */

function base32_encode_str(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function base32_decode_str(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) { $i = strpos($alpha, $c); if ($i === false) return ''; $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
function totp_new_secret(): string { return base32_encode_str(random_bytes(20)); }

function totp_code(string $secretB32, int $step): string {
    $key = base32_decode_str($secretB32);
    $h = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Accepts the current code or one 30-second step either side; rejects a step already used in this session. */
function totp_verify(string $secretB32, string $code, int $lastUsedStep = 0): bool {
    if ($secretB32 === '' || !preg_match('/^\d{6}$/', $code)) return false;
    $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $step = $now + $d;
        if ($step <= $lastUsedStep) continue;
        if (hash_equals(totp_code($secretB32, $step), $code)) {
            if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['last_totp_step'] = $step;
            return true;
        }
    }
    return false;
}

function totp_uri(string $secretB32, string $email): string {
    $issuer = (string) setting('company_name', 'Outreach') . ' Outreach';
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secretB32
        . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/** Ten single-use recovery codes; only their hashes are stored. */
function new_recovery_codes(): array {
    $codes = [];
    for ($i = 0; $i < 10; $i++) $codes[] = substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4);
    return $codes;
}
function hash_recovery_codes(array $codes): string {
    return json_encode(array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $codes));
}
/** Returns how many codes remain if one matched (and consumes it), or null. */
function use_recovery_code(array $user, string $code): ?int {
    $code = strtolower(trim($code));
    if (!preg_match('/^[0-9a-f]{4}-?[0-9a-f]{4}$/', $code)) return null;
    if (!str_contains($code, '-')) $code = substr($code, 0, 4) . '-' . substr($code, 4);
    $list = json_decode((string) $user['recovery_codes'], true) ?: [];
    foreach ($list as $i => $h) {
        if (password_verify($code, $h)) {
            unset($list[$i]);
            update('users', ['recovery_codes' => json_encode(array_values($list))], 'id = ?', [$user['id']]);
            return count($list);
        }
    }
    return null;
}
