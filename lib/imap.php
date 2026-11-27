<?php
declare(strict_types=1);

/**
 * Minimal IMAP client (no php-imap extension needed — it is removed in PHP 8.4).
 * Enough to read new messages from INBOX for reply + bounce detection.
 */
final class MiniImap {
    private $fp;
    private int $tag = 0;

    public function __construct(string $host, int $port, bool $ssl = true, int $timeout = 20) {
        assert_public_mail_host($host);
        if (!$ssl && !local_testing()) throw new DomainException('IMAP must use SSL/TLS (port 993); plain connections would send the password unencrypted.');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $this->fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $err, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) throw new RuntimeException("IMAP connect failed: $err");
        stream_set_timeout($this->fp, $timeout);
        $greet = fgets($this->fp);
        if ($greet === false || stripos($greet, '* OK') !== 0) throw new RuntimeException('IMAP bad greeting');
    }

    private static function quote(string $s): string { return '"' . addcslashes($s, "\"\\") . '"'; }

    /** Send a command; return [untagged chunks (with literals inlined), final status line]. */
    public function cmd(string $command): array {
        $t = 'A' . (++$this->tag);
        fwrite($this->fp, "$t $command\r\n");
        $chunks = []; $cur = '';
        while (true) {
            $line = fgets($this->fp);
            if ($line === false) throw new RuntimeException('IMAP connection lost');
            if (str_starts_with($line, '* ') && $cur !== '') { $chunks[] = $cur; $cur = ''; }
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $cur .= $line;
                $len = (int) $m[1]; $lit = '';
                while (strlen($lit) < $len) {
                    $part = fread($this->fp, $len - strlen($lit));
                    if ($part === false || $part === '') throw new RuntimeException('IMAP literal read failed');
                    $lit .= $part;
                }
                $cur .= $lit;
                continue;
            }
            if (str_starts_with($line, "$t ")) {
                if ($cur !== '') $chunks[] = $cur;
                if (!preg_match("/^$t OK/i", $line)) throw new RuntimeException('IMAP: ' . trim(substr($line, strlen($t) + 1)));
                return [$chunks, $line];
            }
            $cur .= $line;
        }
    }

    public function login(string $user, string $pass): void { $this->cmd('LOGIN ' . self::quote($user) . ' ' . self::quote($pass)); }

    /** @return int UIDVALIDITY-independent count; selects mailbox */
    public function select(string $box = 'INBOX'): void { $this->cmd('SELECT ' . self::quote($box)); }

    /** UIDs greater than $afterUid (or received in the last 3 days on first run). */
    public function newUids(int $afterUid): array {
        if ($afterUid > 0) {
            [$chunks] = $this->cmd('UID SEARCH UID ' . ($afterUid + 1) . ':*');
        } else {
            [$chunks] = $this->cmd('UID SEARCH SINCE ' . gmdate('d-M-Y', time() - 3 * 86400));
        }
        $uids = [];
        foreach ($chunks as $c) if (preg_match('/^\* SEARCH([\d ]*)/', $c, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $u) if ($u !== '' && (int) $u > $afterUid) $uids[] = (int) $u;
        }
        sort($uids);
        return $uids;
    }

    /** Raw RFC822 message (first 60KB), without setting \Seen. */
    public function fetchRaw(int $uid): string {
        [$chunks] = $this->cmd("UID FETCH $uid (BODY.PEEK[]<0.60000>)");
        foreach ($chunks as $c) {
            if (preg_match('/\{(\d+)\}\r\n/', $c, $m, PREG_OFFSET_CAPTURE)) {
                return substr($c, $m[0][1] + strlen($m[0][0]), (int) $m[1][0]);
            }
        }
        return '';
    }

    public function logout(): void { try { $this->cmd('LOGOUT'); } catch (Throwable $e) {} if ($this->fp) fclose($this->fp); }
}

/* ---------- lightweight MIME parsing ---------- */
function mime_split(string $raw): array {
    $raw = str_replace("\r\n", "\n", $raw);
    $pos = strpos($raw, "\n\n");
    return $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
}
function mime_headers(string $head): array {
    $head = preg_replace("/\n[ \t]+/", ' ', $head);
    $h = [];
    foreach (explode("\n", $head) as $line) {
        if (!str_contains($line, ':')) continue;
        [$k, $v] = explode(':', $line, 2);
        $k = strtolower(trim($k));
        $h[$k] = isset($h[$k]) ? $h[$k] . "\n" . trim($v) : trim($v);
    }
    return $h;
}
function mime_decode_header(string $v): string {
    return function_exists('iconv_mime_decode') ? (string) @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $v;
}
function mime_body_text(array $headers, string $body, int $depth = 0): string {
    $ct = strtolower($headers['content-type'] ?? 'text/plain');
    if ($depth < 5 && str_starts_with($ct, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'], $m)) {
        $parts = explode('--' . $m[1], $body);
        $html = '';
        foreach ($parts as $p) {
            $p = ltrim($p, "\n");
            if ($p === '' || str_starts_with($p, '--')) continue;
            [$ph, $pb] = mime_split($p);
            $phd = mime_headers($ph);
            $pct = strtolower($phd['content-type'] ?? 'text/plain');
            if (str_starts_with($pct, 'multipart/')) { $t = mime_body_text($phd, $pb, $depth + 1); if ($t !== '') return $t; }
            elseif (str_starts_with($pct, 'text/plain')) return mime_transfer_decode($phd, $pb);
            elseif (str_starts_with($pct, 'text/html') && $html === '') $html = mime_transfer_decode($phd, $pb);
            elseif (str_starts_with($pct, 'message/delivery-status')) $html .= "\n" . $pb;
        }
        return trim(html_entity_decode(strip_tags($html)));
    }
    $t = mime_transfer_decode($headers, $body);
    return str_starts_with($ct, 'text/html') ? trim(html_entity_decode(strip_tags($t))) : $t;
}
function mime_transfer_decode(array $h, string $body): string {
    $enc = strtolower($h['content-transfer-encoding'] ?? '');
    if ($enc === 'base64') $body = (string) base64_decode(preg_replace('/\s+/', '', $body));
    elseif ($enc === 'quoted-printable') $body = quoted_printable_decode($body);
    if (preg_match('/charset="?([\w\-]+)/i', $h['content-type'] ?? '', $m) && strtolower($m[1]) !== 'utf-8') {
        $conv = @mb_convert_encoding($body, 'UTF-8', $m[1]);
        if ($conv !== false) $body = $conv;
    }
    return $body;
}
/** Strip quoted history ("On ... wrote:", "> " lines) to keep only the new reply. */
function strip_quoted(string $text): string {
    $text = str_replace("\r\n", "\n", $text);
    $cut = preg_split('/\n(On .{5,200}wrote:|-{2,}\s*Original Message|From: .+\nSent: |_{5,})/i', $text)[0] ?? $text;
    $lines = array_filter(explode("\n", $cut), fn($l) => !str_starts_with(ltrim($l), '>'));
    return trim(implode("\n", $lines));
}
function parse_address(string $v): array {
    $v = mime_decode_header($v);
    if (preg_match('/<([^>]+)>/', $v, $m)) return [strtolower(trim($m[1])), trim(trim(str_replace($m[0], '', $v)), '" ')];
    return [strtolower(trim($v)), ''];
}
