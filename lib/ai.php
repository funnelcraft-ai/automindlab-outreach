<?php
declare(strict_types=1);

/**
 * Switchable LLM layer. ai_json() returns a decoded array or throws.
 * Providers: anthropic (Claude), openai, gemini, mock (offline testing).
 */
function ai_provider(): string { return (string) setting('ai_provider', 'anthropic'); }

function ai_budget_left(): float {
    return (float) setting('ai_monthly_budget', 8) - month_usage('ai:', 'cost_usd');
}

function ai_cost(string $provider, int $in, int $out): float {
    $p = explode('/', (string) setting('price_' . $provider, '0/0'));
    return ($in / 1e6) * (float) ($p[0] ?? 0) + ($out / 1e6) * (float) ($p[1] ?? 0);
}

function ai_json(string $system, string $user, int $maxTokens = 2500, ?string $mockKind = null, ?string $provider = null): array {
    $provider = $provider ?? ai_provider();
    if ($provider !== 'mock' && ai_budget_left() <= 0) {
        throw new RuntimeException('Monthly platform AI budget reached — a platform admin can raise it in Admin console → Platform connections.');
    }
    if ($provider !== 'mock' && tid() && ($t = tenant()) && (float) $t['quota_ai_usd'] > 0 && month_usage('ai:', 'cost_usd', tid()) >= (float) $t['quota_ai_usd']) {
        throw new RuntimeException('This workspace has used its monthly AI budget — a platform admin can raise its quota.');
    }
    $system .= "\n\nRespond with ONE valid JSON object only. No markdown, no code fences, no commentary.";
    [$text, $in, $out] = match ($provider) {
        'anthropic' => ai_anthropic($system, $user, $maxTokens),
        'openai'    => ai_openai($system, $user, $maxTokens),
        'gemini'    => ai_gemini($system, $user, $maxTokens),
        'mock'      => [ai_mock($mockKind ?? 'draft', $user), 0, 0],
        default     => throw new RuntimeException("Unknown AI provider: $provider"),
    };
    $cost = $provider === 'mock' ? 0.0 : ai_cost($provider, $in, $out);
    track_usage('ai:' . $provider, 1, $in, $out, $cost);
    $data = extract_json($text);
    if (!is_array($data)) throw new RuntimeException('AI returned invalid JSON: ' . mb_substr($text, 0, 200));
    return $data;
}

function extract_json(string $text) {
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
    $d = json_decode($text, true);
    if (is_array($d)) return $d;
    $s = strpos($text, '{'); $e = strrpos($text, '}');
    if ($s !== false && $e !== false && $e > $s) return json_decode(substr($text, $s, $e - $s + 1), true);
    return null;
}

function ai_fail(string $who, array $r): RuntimeException {
    $msg = $r['json']['error']['message'] ?? ($r['error'] ?: mb_substr($r['body'], 0, 200));
    return new RuntimeException("$who API error ({$r['status']}): $msg");
}

function ai_anthropic(string $system, string $user, int $max): array {
    $key = (string) setting('anthropic_key');
    if ($key === '') throw new RuntimeException('Anthropic API key missing (Admin console → Platform connections).');
    $r = http_request('POST', 'https://api.anthropic.com/v1/messages', [
        'x-api-key' => $key, 'anthropic-version' => '2023-06-01',
    ], [
        'model' => setting('anthropic_model'), 'max_tokens' => $max, 'system' => $system,
        'messages' => [['role' => 'user', 'content' => $user]],
    ], 90);
    if ($r['status'] !== 200) throw ai_fail('Anthropic', $r);
    $text = '';
    foreach ($r['json']['content'] ?? [] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    return [$text, (int) ($r['json']['usage']['input_tokens'] ?? 0), (int) ($r['json']['usage']['output_tokens'] ?? 0)];
}

function ai_openai(string $system, string $user, int $max): array {
    $key = (string) setting('openai_key');
    if ($key === '') throw new RuntimeException('OpenAI API key missing (Admin console → Platform connections).');
    $model = (string) setting('openai_model');
    // reasoning models spend hidden tokens before answering
    $limit = preg_match('/^(gpt-5|o\d)/', $model) ? max($max * 3, 6000) : $max;
    $r = http_request('POST', 'https://api.openai.com/v1/chat/completions', [
        'Authorization' => 'Bearer ' . $key,
    ], [
        'model' => $model, 'max_completion_tokens' => $limit,
        'response_format' => ['type' => 'json_object'],
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
    ], 120);
    if ($r['status'] !== 200) throw ai_fail('OpenAI', $r);
    return [(string) ($r['json']['choices'][0]['message']['content'] ?? ''),
        (int) ($r['json']['usage']['prompt_tokens'] ?? 0), (int) ($r['json']['usage']['completion_tokens'] ?? 0)];
}

function ai_gemini(string $system, string $user, int $max): array {
    $key = (string) setting('gemini_key');
    if ($key === '') throw new RuntimeException('Gemini API key missing (Admin console → Platform connections).');
    $model = rawurlencode((string) setting('gemini_model'));
    $r = http_request('POST', "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent", [
        'x-goog-api-key' => $key,
    ], [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => max($max * 3, 6000)],
    ], 120);
    if ($r['status'] !== 200) throw ai_fail('Gemini', $r);
    $text = '';
    foreach ($r['json']['candidates'][0]['content']['parts'] ?? [] as $p) $text .= $p['text'] ?? '';
    $u = $r['json']['usageMetadata'] ?? [];
    return [$text, (int) ($u['promptTokenCount'] ?? 0), (int) (($u['candidatesTokenCount'] ?? 0) + ($u['thoughtsTokenCount'] ?? 0))];
}

/** Offline provider so the whole pipeline can be dry-run without any API key. */
function ai_mock(string $kind, string $user): string {
    if ($kind === 'classify') {
        $low = strtolower($user);
        $cat = str_contains($low, 'unsubscribe') || str_contains($low, 'remove me') ? 'unsubscribe'
            : (str_contains($low, 'not interested') ? 'not_interested'
            : (str_contains($low, 'out of office') ? 'out_of_office' : 'interested'));
        return json_encode(['category' => $cat, 'summary' => 'Mock classification', 'suggested_reply' =>
            $cat === 'interested' ? "Great to hear! Here's my calendar: {{cal_link}}" : '']);
    }
    preg_match('/Business name(?: \(from its website\))?: (.+)/', $user, $m);
    $biz = trim($m[1] ?? 'your business');
    $li = ['connection_note' => "Hi {{first_name}}, came across $biz while looking at local businesses and liked what I saw on your site. Would be glad to connect.",
        'message' => "Thanks for connecting, {{first_name}}. I run automation projects for local service businesses. Noticed $biz books by phone only; an AI text-back can answer missed calls in seconds and book the job. Would that be useful for you?",
        'followup' => "Quick follow-up: happy to show you how the missed-call text-back works in 15 minutes: {{cal_link}}"];
    if ($kind === 'linkedin') return json_encode(['linkedin' => $li]);
    return json_encode([
        'fit_score' => 78, 'fit_reason' => 'Mock: local owner-operated business with visible lead-capture gaps.',
        'contact_first_name' => null,
        'observations' => ['No online booking on the site', 'Phone is the only contact route after hours'],
        'automation_ideas' => ['Missed-call text-back', 'AI receptionist for after-hours calls'],
        'emails' => [
            ['step' => 1, 'subject' => "quick question about $biz", 'body' => "Hi there,\n\nNoticed $biz takes bookings by phone only. When a call is missed after hours, that customer usually calls the next company on Google.\n\nWe set up an AI text-back that replies within seconds and books the job for you.\n\nWorth a look?"],
            ['step' => 2, 'subject' => '', 'body' => "Hi again,\n\nHere's a 15-minute slot if easier: {{cal_link}}"],
            ['step' => 3, 'subject' => '', 'body' => "One more idea: an automatic review request after every job.\n\nHappy to show you: {{cal_link}}"],
            ['step' => 4, 'subject' => '', 'body' => "I'll close the loop here. If missed calls ever become a headache, my calendar is open: {{cal_link}}"],
        ],
    ] + (str_contains($user, 'ALSO WRITE LINKEDIN COPY') ? ['linkedin' => $li] : []));
}
