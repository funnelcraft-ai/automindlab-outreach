<?php
declare(strict_types=1);
/** Connection definitions, tests and form widgets shared by Connections (workspace) and Platform connections (console). */

function connection_services(): array {
    return [
        'anthropic' => ['secret' => ['anthropic_key'], 'plain' => ['anthropic_model', 'price_anthropic'], 'scope' => 'platform'],
        'openai'    => ['secret' => ['openai_key'], 'plain' => ['openai_model', 'price_openai'], 'scope' => 'platform'],
        'gemini'    => ['secret' => ['gemini_key'], 'plain' => ['gemini_model', 'price_gemini'], 'scope' => 'platform'],
        'places'    => ['secret' => ['places_key'], 'plain' => ['places_monthly_cap', 'places_details_cap'], 'scope' => 'platform'],
        'hunter'    => ['secret' => ['hunter_key'], 'plain' => [], 'scope' => 'platform'],
        'calid'     => ['secret' => [], 'plain' => ['cal_link', 'cal_webhook_secret'], 'check' => ['cal_prefill'], 'scope' => 'workspace'],
    ];
}
function test_service(string $svc): string {
    $t = microtime(true);
    switch ($svc) {
        case 'anthropic': case 'openai': case 'gemini':
            ai_json('You are a connection test.', 'Return {"ok": true}', 60, 'classify', $svc);
            return 'Connected — ' . setting($svc . '_model') . ' answered in ' . round(microtime(true) - $t, 1) . ' s';
        case 'places':
            $r = places_search('plumber in Austin, TX');
            $withSite = count(array_filter($r['places'], fn($p) => !empty($p['websiteUri'])));
            return 'Connected — test search returned ' . count($r['places']) . " businesses ($withSite with websites)";
        case 'hunter':
            $r = http_request('GET', 'https://api.hunter.io/v2/account?api_key=' . rawurlencode((string) setting('hunter_key')), [], null, 20);
            if ($r['status'] !== 200) throw new RuntimeException('Hunter rejected the key (' . $r['status'] . ')');
            $s = $r['json']['data']['requests']['searches'] ?? [];
            return 'Connected — ' . (int) ($s['available'] ?? 0) - (int) ($s['used'] ?? 0) . ' searches left this month';
    }
    throw new RuntimeException('Nothing to test here — send a test booking instead.');
}

/** Save / test / remove one service from a POSTed card. Returns nothing; flashes the outcome. */
function handle_service_post(string $svc, string $op): void {
    $def = connection_services()[$svc] ?? throw new RuntimeException('Unknown service');
    if ($op === 'remove') {
        foreach ($def['secret'] as $k) set_setting($k, '');
        $all = json_decode((string) setting(api_status_key($svc), '{}'), true) ?: [];
        unset($all[$svc]); set_setting(api_status_key($svc), json_encode($all));
        flash('Key removed.');
        return;
    }
    foreach ($def['secret'] as $k) { $v = trim((string) ($_POST[$k] ?? '')); if ($v !== '') set_setting($k, $v); }
    foreach ($def['plain'] as $k) if (isset($_POST[$k])) set_setting($k, trim((string) $_POST[$k]));
    foreach ($def['check'] ?? [] as $k) set_setting($k, isset($_POST[$k]) ? '1' : '0');
    if ($op === 'test') {
        try { $msg = test_service($svc); set_api_status($svc, true, $msg); flash($msg); }
        catch (Throwable $ex) { set_api_status($svc, false, $ex->getMessage()); flash($ex->getMessage(), 'err'); }
    } else flash('Saved.');
}

function conn_chip(string $svc, bool $hasKey): string {
    $st = api_status($svc);
    if (!$hasKey && $svc !== 'calid') return '<span class="chip-status off">' . icon('radio_button_unchecked') . 'Not connected</span>';
    if (!$st) return '<span class="chip-status idle">' . icon('help') . ($svc === 'calid' ? 'No booking received yet' : 'Saved, not tested') . '</span>';
    return $st['ok']
        ? '<span class="chip-status ok" title="' . e($st['msg']) . '">' . icon('check_circle', 'fill') . 'Connected · ' . e(ago($st['at'])) . '</span>'
        : '<span class="chip-status err" title="' . e($st['msg']) . '">' . icon('error', 'fill') . 'Failed · ' . e(ago($st['at'])) . '</span>';
}
function conn_secret_field(string $key, string $label, string $placeholder, string $getUrl = ''): string {
    $saved = (string) setting($key) !== '';
    $hint = $saved ? 'Saved key ending ' . substr((string) setting($key), -4) . ' — paste a new one to replace it' : $placeholder;
    $link = $getUrl ? '<a href="' . e($getUrl) . '" target="_blank" rel="noopener">Get a key' . icon('open_in_new') . '</a>' : '';
    return '<label class="field"><span class="lbl-row"><span>' . e($label) . '</span>' . $link . '</span><span class="secret"><input type="password" name="' . e($key) . '" placeholder="' . e($hint) . '" autocomplete="off" spellcheck="false">'
        . '<button type="button" class="icon-btn" data-reveal aria-label="Show key">' . icon('visibility') . '</button></span></label>';
}
function conn_last_msg(string $svc): string { $st = api_status($svc); return $st ? '<p class="test-msg ' . ($st['ok'] ? 'ok' : 'err') . '">' . e($st['msg']) . '</p>' : ''; }
