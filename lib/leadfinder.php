<?php
declare(strict_types=1);

/**
 * Lead discovery via Google Places API (New).
 *
 * Google Maps Platform terms allow storing the place ID only. So:
 *  - Text Search results are used in memory to filter (open, has a website, review range) and are NOT stored,
 *    apart from the place ID and our own search inputs (keyword, "City, ST").
 *  - At research time, Place Details is called by place ID to get the website address, used once to visit
 *    the site, and not stored. Everything saved about a lead comes from the business's own website.
 * Text Search Enterprise and Place Details Enterprise each include 1,000 free calls per month.
 */
const PLACES_SEARCH_FIELDS = 'places.id,places.addressComponents,places.websiteUri,places.rating,places.userRatingCount,places.businessStatus,nextPageToken';
const PLACES_DETAILS_FIELDS = 'id,websiteUri,businessStatus';

function places_search(string $textQuery, ?string $pageToken = null, string $region = 'US'): array {
    $key = (string) setting('places_key');
    if ($key === '') throw new RuntimeException('Google Places API key missing (Admin console → Platform connections).');
    if (month_usage('places') >= (int) setting('places_monthly_cap', 950)) {
        throw new RuntimeException('Monthly Places search cap reached (Admin console → Platform connections).');
    }
    $body = ['textQuery' => $textQuery, 'pageSize' => 20, 'regionCode' => $region, 'languageCode' => 'en'];
    if ($pageToken) $body['pageToken'] = $pageToken;
    $r = http_request('POST', (string) cfg('places_endpoint', 'https://places.googleapis.com/v1/places:searchText'), [
        'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => PLACES_SEARCH_FIELDS,
    ], $body, 30);
    track_usage('places', 1);
    if ($r['status'] !== 200) {
        $msg = $r['json']['error']['message'] ?? ($r['error'] ?: substr($r['body'], 0, 200));
        throw new RuntimeException("Places API error ({$r['status']}): $msg");
    }
    return ['places' => $r['json']['places'] ?? [], 'next' => $r['json']['nextPageToken'] ?? null];
}

/** Website + status for one place, used immediately and never stored. */
function places_details(string $placeId): array {
    $key = (string) setting('places_key');
    if ($key === '') throw new RuntimeException('Google Places API key missing (Admin console → Platform connections).');
    if (month_usage('places_details') >= (int) setting('places_details_cap', 950)) {
        throw new RuntimeException('Monthly Place Details cap reached (Admin console → Platform connections).');
    }
    $base = rtrim((string) cfg('places_details_endpoint', 'https://places.googleapis.com/v1/places/'), '/') . '/';
    $r = http_request('GET', $base . rawurlencode($placeId), ['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => PLACES_DETAILS_FIELDS], null, 20);
    track_usage('places_details', 1);
    if ($r['status'] === 404) return ['website' => null, 'status' => 'NOT_FOUND'];
    if ($r['status'] !== 200) {
        $msg = $r['json']['error']['message'] ?? ($r['error'] ?: substr($r['body'], 0, 200));
        throw new RuntimeException("Place Details error ({$r['status']}): $msg");
    }
    return ['website' => $r['json']['websiteUri'] ?? null, 'status' => $r['json']['businessStatus'] ?? 'OPERATIONAL'];
}

/** Values from a search result used only for filtering, in memory. */
function place_filter_view(array $p): array {
    $country = null;
    foreach ($p['addressComponents'] ?? [] as $c) {
        if (in_array('country', $c['types'] ?? [], true)) $country = strtoupper((string) ($c['shortText'] ?? ''));
    }
    $site = $p['websiteUri'] ?? null;
    return [
        'place_id' => $p['id'] ?? null,
        'status' => $p['businessStatus'] ?? 'OPERATIONAL',
        'country' => $country,
        'has_site' => (bool) $site,
        'domain' => $site ? site_domain($site) : null,
        'rating' => isset($p['rating']) ? (float) $p['rating'] : 0.0,
        'reviews' => (int) ($p['userRatingCount'] ?? 0),
    ];
}

/** "Dallas, TX" → ['Dallas', 'TX'] (our own search input, not Google data). */
function split_location(string $loc): array {
    $parts = array_map('trim', explode(',', $loc));
    return [$parts[0] ?? '', strtoupper($parts[1] ?? '')];
}

function site_domain(string $url): ?string {
    $h = parse_url(strpos($url, '://') === false ? "http://$url" : $url, PHP_URL_HOST);
    if (!$h) return null;
    return strtolower(preg_replace('/^www\./i', '', $h));
}

/** Big platforms / directories that are not the business's own website. */
function is_platform_domain(?string $domain): bool {
    if (!$domain) return true;
    $bad = ['facebook.com', 'instagram.com', 'yelp.com', 'google.com', 'business.site', 'linktr.ee', 'linkedin.com',
        'nextdoor.com', 'angi.com', 'homeadvisor.com', 'thumbtack.com', 'yellowpages.com', 'bbb.org', 'square.site',
        'booksy.com', 'vagaro.com', 'styleseat.com', 'mindbodyonline.com', 'tiktok.com', 'x.com', 'twitter.com',
        'doordash.com', 'ubereats.com', 'grubhub.com', 'opentable.com', 'toasttab.com', 'zocdoc.com', 'g.page'];
    foreach ($bad as $b) if ($domain === $b || str_ends_with($domain, '.' . $b)) return true;
    return false;
}

/** Best-guess IANA timezone from US state / Canadian province. */
function region_timezone(?string $region, ?string $country): string {
    $r = strtoupper((string) $region);
    $map = [
        'America/New_York' => ['CT','DE','DC','FL','GA','ME','MD','MA','MI','NH','NJ','NY','NC','OH','PA','RI','SC','VT','VA','WV','IN','KY','ON','QC'],
        'America/Chicago' => ['AL','AR','IL','IA','KS','LA','MN','MS','MO','NE','ND','OK','SD','TN','TX','WI','MB'],
        'America/Denver' => ['CO','MT','NM','UT','WY','ID','AB','NT'],
        'America/Phoenix' => ['AZ'],
        'America/Los_Angeles' => ['CA','NV','OR','WA','BC','YT'],
        'America/Anchorage' => ['AK'],
        'Pacific/Honolulu' => ['HI'],
        'America/Regina' => ['SK'],
        'America/Halifax' => ['NS','NB','PE'],
        'America/St_Johns' => ['NL'],
        'America/Iqaluit' => ['NU'],
    ];
    // "CA" is both California and Canada's ISO code: region codes are disambiguated by country
    if ($country === 'CA' && $r === 'CA') return 'America/Toronto';
    foreach ($map as $tz => $codes) if (in_array($r, $codes, true)) return $tz;
    return $country === 'CA' ? 'America/Toronto' : 'America/Chicago';
}

/** Guess country (US/CA) from a "City, ST" location string. */
function location_country(string $loc): string {
    $ca = ['ON','QC','BC','AB','MB','SK','NS','NB','NL','PE','YT','NT','NU','CANADA'];
    $parts = array_map('trim', explode(',', strtoupper($loc)));
    foreach ($parts as $p) if (in_array($p, $ca, true)) return 'CA';
    return 'US';
}
