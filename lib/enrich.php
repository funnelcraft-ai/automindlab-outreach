<?php
declare(strict_types=1);

/**
 * Website enrichment: fetch homepage + contact/about pages, find a published email,
 * detect lead-handling signals (booking, chat, forms, tracking) and build a text excerpt for the AI.
 */
function enrich_website(string $url, bool $allowHunter = true): array {
    $pages = [];
    $home = fetch_page($url);
    if (!$home) return ['ok' => false, 'error' => 'Website did not load'];
    $pages[$home['url']] = $home['html'];

    // find likely contact/about pages from links, plus common paths
    $links = extract_links($home['html'], $home['url']);
    $wanted = [];
    foreach ($links as $l) {
        if (preg_match('~/(contact|contact-us|about|about-us|our-team|team|book|schedule|appointments?)(/|$|\.)~i', parse_url($l, PHP_URL_PATH) ?? '')) $wanted[] = $l;
    }
    $root = rtrim(preg_replace('~^(https?://[^/]+).*$~i', '$1', $home['url']), '/');
    foreach (['/contact', '/contact-us', '/about'] as $p) $wanted[] = $root . $p;
    $wanted = array_slice(array_values(array_unique($wanted)), 0, 4);
    foreach ($wanted as $w) {
        if (count($pages) >= 4 || isset($pages[$w])) continue;
        $pg = fetch_page($w, 12);
        if ($pg && !isset($pages[$pg['url']])) $pages[$pg['url']] = $pg['html'];
    }

    $domain = site_domain($home['url']);
    $found = [];
    foreach ($pages as $pUrl => $html) foreach (find_emails($html) as $em) $found[$em] ??= $pUrl;
    $best = pick_best_email(array_keys($found), (string) $domain);
    $allHtml = implode("\n", $pages);
    $noSolicit = (bool) preg_match('/(no|do not send)\s+(unsolicited|solicitation|marketing|sales)|no soliciting/i', strip_tags($allHtml));

    $result = [
        'ok' => true,
        'final_url' => $home['url'],
        'email' => $best,
        'email_source' => $best ? $found[$best] : null,
        'all_emails' => array_slice(array_keys($found), 0, 8),
        'no_solicit_notice' => $noSolicit,
        'signals' => detect_signals($allHtml, $home['html']),
        'excerpt' => build_excerpt($pages),
        'business' => extract_business($pages, $home['url']),
        'linkedin' => li_from_html($allHtml),    // only a link the business itself publishes
    ];

    if (!$best && $allowHunter && setting('hunter_key') && $domain) {
        $h = hunter_lookup($domain);
        if ($h) { $result['email'] = $h; $result['email_source'] = 'hunter.io'; }
    }
    return $result;
}

function fetch_page(string $url, int $timeout = 15): ?array {
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    $r = safe_get($url, $timeout);
    if ((!$r || $r['status'] === 0) && str_starts_with($url, 'https://')) $r = safe_get('http://' . substr($url, 8), $timeout); // broken SSL on some small-business sites
    if (!$r || $r['status'] >= 400 || $r['status'] === 0 || $r['body'] === '') return null;
    return ['url' => $r['url'], 'html' => mb_substr($r['body'], 0, 600000)];
}

/**
 * GET an untrusted URL safely: http/https only, every redirect hop re-checked, the host's IP is resolved once,
 * checked to be public and then pinned (no DNS-rebinding), response capped at 1.5 MB.
 */
function safe_get(string $url, int $timeout = 15): ?array {
    for ($hop = 0; $hop < 6; $hop++) {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = resolve_public_ip($host);
        if ($ip === null) return null;
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROXY => '', CURLOPT_RESOLVE => ["$host:$port:$ip"],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OutreachResearchBot/1.2)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en'],
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > 1500000 ? 0 : strlen($chunk);   // stop oversized pages
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($status >= 300 && $status < 400 && $location !== '') { $url = $location; continue; }
        return ['status' => $status, 'body' => $body, 'url' => $url];
    }
    return null;
}

/** Resolve a hostname to one IPv4 address, only if every address it resolves to is public. */
function resolve_public_ip(string $host): ?string {
    $local = local_testing();
    $host = trim($host, '[]');
    if (strtolower($host) === 'localhost') return $local ? '127.0.0.1' : null;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return null;
    foreach ($ips as $ip) {
        if (!$local && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    }
    $v4 = array_values(array_filter($ips, fn($i) => filter_var($i, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
    return $v4[0] ?? null;
}

function url_is_public(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    return $host ? resolve_public_ip((string) $host) !== null : false;
}

/** Business name, phone and address as published on the business's own website (schema.org data, meta tags, title). */
function extract_business(array $pages, string $finalUrl): array {
    $out = ['name' => null, 'phone' => null, 'address' => null, 'city' => null, 'region' => null];
    $domain = (string) site_domain($finalUrl);
    $orgs = [];
    foreach ($pages as $html) {
        if (!preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) continue;
        foreach ($m[1] as $json) {
            $d = json_decode(html_entity_decode(trim($json)), true);
            if (is_array($d)) collect_ld_orgs($d, $orgs);
        }
    }
    usort($orgs, fn($a, $b) => (int) !empty($b['address']) <=> (int) !empty($a['address']));
    foreach ($orgs as $o) {
        $out['name'] ??= is_string($o['name'] ?? null) ? trim($o['name']) : null;
        $out['phone'] ??= is_string($o['telephone'] ?? null) ? trim($o['telephone']) : null;
        $a = $o['address'] ?? null;
        if (isset($a[0]) && is_array($a[0])) $a = $a[0];
        if (is_array($a) && !$out['address']) {
            $out['address'] = trim(implode(', ', array_filter([$a['streetAddress'] ?? '', $a['addressLocality'] ?? '', trim(($a['addressRegion'] ?? '') . ' ' . ($a['postalCode'] ?? ''))], fn($x) => is_string($x) && trim($x) !== '')));
            $out['city'] = is_string($a['addressLocality'] ?? null) ? trim($a['addressLocality']) : null;
            $out['region'] = is_string($a['addressRegion'] ?? null) && strlen(trim($a['addressRegion'])) <= 3 ? strtoupper(trim($a['addressRegion'])) : null;
        } elseif (is_string($a) && !$out['address']) $out['address'] = trim($a);
    }
    $home = (string) reset($pages);
    if (!$out['name'] && preg_match('~<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)~i', $home, $m)) $out['name'] = trim(html_entity_decode($m[1]));
    if (!$out['name'] && preg_match('~<title[^>]*>(.*?)</title>~is', $home, $m)) $out['name'] = name_from_title(html_entity_decode(trim($m[1])), $domain);
    if (!$out['name'] && $domain) $out['name'] = ucwords(str_replace(['-', '_'], ' ', explode('.', $domain)[0]));
    if (!$out['phone']) {
        foreach ($pages as $html) if (preg_match('~href=["\']tel:([+0-9().\s-]{7,20})["\']~i', $html, $m)) { $out['phone'] = trim($m[1]); break; }
    }
    $out['name'] = $out['name'] ? mb_substr(preg_replace('/\s+/', ' ', strip_tags($out['name'])), 0, 120) : null;
    return $out;
}

function collect_ld_orgs(array $d, array &$orgs, int $depth = 0): void {
    if ($depth > 6) return;
    if (isset($d['@graph']) && is_array($d['@graph'])) foreach ($d['@graph'] as $g) if (is_array($g)) collect_ld_orgs($g, $orgs, $depth + 1);
    $type = $d['@type'] ?? null;
    $types = is_array($type) ? $type : [$type];
    $skip = ['WebSite', 'WebPage', 'BreadcrumbList', 'ImageObject', 'Person', 'Article', 'BlogPosting', 'SiteNavigationElement', 'SearchAction', 'Review', 'Offer', 'Product', 'FAQPage', 'Question', 'Answer'];
    if ($type && !array_intersect($types, $skip) && isset($d['name']) && (isset($d['address']) || isset($d['telephone']) || in_array('Organization', $types, true))) $orgs[] = $d;
    foreach ($d as $v) if (is_array($v) && array_is_list($v)) foreach ($v as $item) if (is_array($item)) collect_ld_orgs($item, $orgs, $depth + 1);
}

/** Pick the brand part of a page title like "AC Repair Dallas | CoolAir HVAC". */
function name_from_title(string $title, string $domain): ?string {
    $segs = array_values(array_filter(array_map('trim', preg_split('/\s+[|–—:·-]\s+|\s*\|\s*/u', $title)), fn($x) => $x !== '' && !preg_match('/^(home|homepage|welcome|contact( us)?|about( us)?)$/i', $x)));
    if (!$segs) return null;
    $base = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0] ?? ''));
    foreach ($segs as $seg) {
        $flat = preg_replace('/[^a-z0-9]/', '', strtolower($seg));
        if ($base !== '' && $flat !== '' && (str_contains($flat, $base) || str_contains($base, substr($flat, 0, max(4, (int) (strlen($flat) * .6)))))) return $seg;
    }
    return count($segs) > 1 ? end($segs) : $segs[0];
}

function extract_links(string $html, string $base): array {
    preg_match_all('~<a\s[^>]*href\s*=\s*["\']([^"\'#]+)["\']~i', $html, $m);
    $host = parse_url($base, PHP_URL_HOST);
    $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
    $out = [];
    foreach ($m[1] as $href) {
        $href = html_entity_decode(trim($href));
        if (preg_match('~^(mailto|tel|javascript):~i', $href)) continue;
        if (str_starts_with($href, '//')) $href = "$scheme:$href";
        elseif (str_starts_with($href, '/')) $href = "$scheme://$host$href";
        elseif (!preg_match('~^https?://~i', $href)) $href = rtrim($base, '/') . '/' . $href;
        if (parse_url($href, PHP_URL_HOST) === $host) $out[] = strtok($href, '?');
    }
    return array_values(array_unique($out));
}

function find_emails(string $html): array {
    $emails = [];
    // Cloudflare email obfuscation
    if (preg_match_all('~data-cfemail="([0-9a-f]+)"~i', $html, $cf)) foreach ($cf[1] as $hex) $emails[] = cf_decode($hex);
    if (preg_match_all('~/cdn-cgi/l/email-protection#([0-9a-f]+)~i', $html, $cf2)) foreach ($cf2[1] as $hex) $emails[] = cf_decode($hex);
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/\s*[\[\(\{]\s*at\s*[\]\)\}]\s*/i', '@', $text);
    $text = preg_replace('/\s*[\[\(\{]\s*dot\s*[\]\)\}]\s*/i', '.', $text);
    preg_match_all('/mailto:([^"\'?>\s]+)/i', $text, $mm);
    preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}/i', $text, $rm);
    foreach (array_merge($mm[1], $rm[0]) as $em) $emails[] = rawurldecode($em);
    $clean = [];
    foreach ($emails as $em) {
        $em = strtolower(trim($em, " .,;:<>()[]\"'"));
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
        if (preg_match('/\.(png|jpe?g|gif|webp|svg|css|js)$/', $em)) continue;
        if (preg_match('/(sentry|wixpress|example\.|domain\.com|email\.com|yourdomain|godaddy|wordpress|squarespace|schema\.org|cloudflare|noreply|no-reply|donotreply|privacy@|abuse@|webmaster@|postmaster@)/', $em)) continue;
        $clean[$em] = true;
    }
    return array_keys($clean);
}

function cf_decode(string $hex): string {
    $k = hexdec(substr($hex, 0, 2)); $out = '';
    for ($i = 2; $i < strlen($hex); $i += 2) $out .= chr(hexdec(substr($hex, $i, 2)) ^ $k);
    return $out;
}

/** Prefer: person-like address on own domain > generic on own domain > free-mail. Skip other domains. */
function pick_best_email(array $emails, string $domain): ?string {
    $best = null; $bestScore = -1;
    $free = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'live.com', 'msn.com', 'comcast.net', 'att.net', 'sbcglobal.net', 'shaw.ca', 'rogers.com', 'bell.net', 'telus.net'];
    foreach ($emails as $em) {
        [$local, $edom] = explode('@', $em, 2);
        $own = $domain && ($edom === $domain || str_ends_with($edom, '.' . $domain) || str_ends_with($domain, '.' . $edom));
        if (!$own && !in_array($edom, $free, true)) continue;
        $score = $own ? 50 : 20;
        if (preg_match('/^(owner|founder|ceo|president|manager|gm|office|admin|service|appointments?|booking|bookings|sales|hello|info|contact|team|support|frontdesk)\b/', $local)) $score += 10;
        if (preg_match('/^[a-z]+(\.[a-z]+)?$/', $local) && !preg_match('/^(info|contact|hello|office|admin|sales|support|service|team|mail|careers|jobs|hr|billing|accounts?)$/', $local)) $score += 15; // looks like a person
        if (preg_match('/^(careers|jobs|hr|billing|accounts?|invoices?|marketing|press|media)$/', $local)) $score -= 30;
        if ($score > $bestScore) { $best = $em; $bestScore = $score; }
    }
    return $best;
}

function detect_signals(string $all, string $home): array {
    $h = strtolower($all);
    $has = fn(array $needles) => (bool) array_filter($needles, fn($n) => str_contains($h, $n));
    $year = null;
    if (preg_match_all('/(?:©|&copy;|copyright)\s*(?:\d{4}\s*[-–]\s*)?(\d{4})/i', $all, $y)) $year = max(array_map('intval', $y[1]));
    $cms = 'unknown';
    foreach (['wordpress' => 'wp-content', 'wix' => 'wix.com', 'squarespace' => 'squarespace', 'godaddy' => 'godaddy', 'shopify' => 'cdn.shopify', 'webflow' => 'webflow', 'duda' => 'dudamobile', 'weebly' => 'weebly'] as $name => $n) {
        if (str_contains($h, $n)) { $cms = $name; break; }
    }
    return [
        'online_booking' => $has(['calendly', 'housecallpro', 'servicetitan', 'jobber', 'booksy', 'vagaro', 'mindbody', 'zocdoc', 'opentable', 'resy.com', 'squareup.com/appointments', 'acuityscheduling', 'schedulicity', 'setmore', 'nexhealth', 'localmed', 'book online', 'book now', 'schedule online', 'online booking', 'online scheduling']),
        'live_chat' => $has(['intercom', 'drift.com', 'tawk.to', 'livechatinc', 'tidio', 'podium', 'birdeye', 'crisp.chat', 'zendesk', 'hubspot-messages', 'smartsupp', 'olark', 'gorgias', 'webchat']),
        'contact_form' => str_contains($h, '<form') && $has(['contact', 'message', 'quote', 'estimate']),
        'quote_form' => $has(['free estimate', 'free quote', 'get a quote', 'request a quote', 'request estimate']),
        'meta_pixel' => $has(['fbq(', 'connect.facebook.net']),
        'google_analytics' => $has(['gtag(', 'googletagmanager', 'google-analytics']),
        'reviews_widget' => $has(['elfsight', 'trustindex', 'birdeye', 'nicejob', 'grade.us', 'reviewsonmywebsite']),
        'text_us' => $has(['text us', 'sms:', 'text-us']),
        'after_hours_or_24_7' => $has(['24/7', '24 hours', 'emergency service', 'after hours', 'after-hours']),
        'mobile_ready' => str_contains(strtolower($home), 'name="viewport"'),
        'copyright_year' => $year,
        'cms' => $cms,
    ];
}

function build_excerpt(array $pages): string {
    $out = [];
    foreach ($pages as $url => $html) {
        $title = preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? trim(html_entity_decode($m[1])) : '';
        $desc = preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)~i', $html, $m) ? html_entity_decode($m[1]) : '';
        $body = preg_replace('~<(script|style|noscript|svg|iframe|head)[^>]*>.*?</\1>~is', ' ', $html);
        $body = preg_replace('~<(br|p|div|li|h[1-6]|tr)[^>]*>~i', "\n", $body);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace("/[ \t\r\f\v]+/u", ' ', $text);
        $text = preg_replace("/\n\s*\n+/u", "\n", $text);
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $out[] = "### PAGE $path\nTitle: $title\nMeta: $desc\n" . mb_substr(trim($text), 0, count($out) ? 1500 : 3500);
    }
    return mb_substr(implode("\n\n", $out), 0, 7000);
}

function hunter_lookup(string $domain): ?string {
    $key = (string) setting('hunter_key');
    $r = http_request('GET', 'https://api.hunter.io/v2/domain-search?limit=5&domain=' . rawurlencode($domain) . '&api_key=' . rawurlencode($key), [], null, 20);
    track_usage('hunter', 1);
    if ($r['status'] !== 200) return null;
    $best = null; $bestConf = 0;
    foreach ($r['json']['data']['emails'] ?? [] as $em) {
        $conf = (int) ($em['confidence'] ?? 0) + (($em['type'] ?? '') === 'personal' ? 10 : 0)
            + (preg_match('/owner|founder|ceo|president|manager/i', (string) ($em['position'] ?? '')) ? 20 : 0);
        if ($conf > $bestConf && ($em['verification']['status'] ?? '') !== 'invalid') { $best = strtolower($em['value']); $bestConf = $conf; }
    }
    return $best;
}

/** Cheap deliverability check: does the email's domain accept mail? */
function email_domain_ok(string $email): bool {
    $d = substr(strrchr($email, '@') ?: '', 1);
    if ($d === '') return false;
    if (local_testing()) return true;
    if (function_exists('checkdnsrr')) return checkdnsrr($d, 'MX') || checkdnsrr($d, 'A');
    return true;
}
