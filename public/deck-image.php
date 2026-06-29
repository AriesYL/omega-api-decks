<?php

require(__DIR__ . '/../vendor/autoload.php');

/*
 * Deck image + cover, persisted to Supabase Storage (SwissYGO #84 / Part 1).
 *
 *   GET /deck-image?token=<REQUEST_TOKEN>&list=<deck>   (any /imageify deck param:
 *   list | ydke | omega | ydk | names | json ; optional &quality=)
 *   → { "success": true, "data": { "url": <deck image>, "cover_url": <art|null>, "cached": <bool> } }
 *
 * - url:       the YGOPro-style deck image (rendered via /imageify) at deck-images/<sha256(deck)>.jpg
 * - cover_url: the HIGH-QUALITY cropped ARTWORK (no borders) of the FIRST main-deck card
 *              at deck-images/covers/<passcode>.jpg, sourced from CARD_ART_URL (ygoprodeck
 *              cards_cropped). Best-effort: null if unavailable — never fails the deck image.
 * Both are idempotent by key (existing object → reused, no re-render / re-upload). The
 * Supabase service key lives ONLY here, never on the SwissYGO host.
 *
 *   DELETE /deck-image?token=<REQUEST_TOKEN>&url=<public object url>   (SwissYGO #117)
 *   → { "success": true, "data": { "deleted": <bool> } }
 *
 * Garbage-collects an orphaned blob: the SwissYGO host calls this after deleting a saved
 * deck whose image/cover is no longer referenced by ANY remaining row (reference count 0).
 * Pass a stored public URL verbatim (deck image OR cover); we validate it points inside our
 * own bucket and delete that object. Idempotent — a missing object still returns success.
 */

Http::allow_methods('GET', 'DELETE');
Http::check_token('token', 'REQUEST_TOKEN');

$supabase_url = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabase_key = (string) getenv('SUPABASE_SERVICE_KEY');
$bucket       = getenv('SUPABASE_BUCKET') ?: 'deck-images';
if ($supabase_url === '' || $supabase_key === '')
    Http::fail('storage is not configured (set SUPABASE_URL and SUPABASE_SERVICE_KEY)', Http::INTERNAL_SERVER_ERROR);

// ---- DELETE: remove one orphaned object from storage (SwissYGO #117) ----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $target = (string) Http::get_query_parameter('url', false, '');
    $prefix = "$supabase_url/storage/v1/object/public/";
    if ($target === '' || strncmp($target, $prefix, strlen($prefix)) !== 0)
        Http::fail('url must be a public object URL in this storage', Http::BAD_REQUEST);
    $object_path = substr($target, strlen($prefix));            // "<bucket>/<key…>"
    $bucket_prefix = rawurlencode($bucket) . '/';
    if (strncmp($object_path, $bucket_prefix, strlen($bucket_prefix)) !== 0)
        Http::fail('url is not in the deck-images bucket', Http::BAD_REQUEST);
    $deleted = supabase_delete($supabase_url, $supabase_key, $object_path);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => ['deleted' => $deleted]]);
    exit;
}

$port = getenv('PORT') ?: '80';
$qs   = $_SERVER['QUERY_STRING'] ?? '';   // token + deck + optional quality — forwarded to the loopbacks

$deck_input = null;
foreach (['list', 'ydke', 'omega', 'ydk', 'names', 'json'] as $param) {
    $value = Http::get_query_parameter($param, false, null);
    if ($value !== null && $value !== '') { $deck_input = trim($value); break; }
}
if ($deck_input === null)
    Http::fail('no deck provided (use ?list=<deck> or a format-specific parameter)');

// ---- 1) Deck image: deck-images/<sha256(deck)>.jpg ----
$hash     = hash('sha256', $deck_input);
$deck_obj = rawurlencode($bucket) . '/' . $hash . '.jpg';
$deck_url = "$supabase_url/storage/v1/object/public/$deck_obj";
$cached   = storage_object_exists($deck_url);
if (!$cached) {
    list($png, $code, $ctype) = http_get("http://127.0.0.1:$port/imageify?$qs", 120);
    if ($png === false || $code !== 200 || strpos((string) $ctype, 'image/') !== 0)
        Http::fail('failed to render deck image' . ($code ? " (imageify returned $code)" : ''), Http::INTERNAL_SERVER_ERROR);
    if (!supabase_upload($supabase_url, $supabase_key, $deck_obj, $png, $ctype ?: 'image/jpeg'))
        Http::fail('failed to store deck image', Http::INTERNAL_SERVER_ERROR);
}

// ---- 2) Cover: cropped art of a card → deck-images/covers/<passcode>.jpg ----
// The caller can pick the cover card with ?cover=<passcode> (the UI lets the user
// choose from the deck's cards); otherwise it defaults to the first main-deck card.
// Best-effort: any failure leaves cover_url null without failing the deck image.
$cover_url   = null;
$cover_param = Http::get_query_parameter('cover', false, null);
$cover_code  = ($cover_param !== null && is_numeric($cover_param))
    ? (int) $cover_param
    : first_main_code("http://127.0.0.1:$port/parse?$qs");
if ($cover_code !== null) {
    $cover_obj = rawurlencode($bucket) . '/covers/' . $cover_code . '.jpg';
    $candidate = "$supabase_url/storage/v1/object/public/$cover_obj";
    if (storage_object_exists($candidate)) {
        $cover_url = $candidate;
    } else {
        $art_base = rtrim(getenv('CARD_ART_URL') ?: 'https://images.ygoprodeck.com/images/cards_cropped', '/');
        list($art, $acode, $actype) = http_get("$art_base/$cover_code.jpg", 60);
        if ($art !== false && $acode === 200 && strpos((string) $actype, 'image/') === 0
            && supabase_upload($supabase_url, $supabase_key, $cover_obj, $art, $actype)) {
            $cover_url = $candidate;
        }
    }
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'data' => ['url' => $deck_url, 'cover_url' => $cover_url, 'cached' => $cached]]);
exit;


function http_get(string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true]);
    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$body, $code, $ctype];
}

function storage_object_exists(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code === 200;
}

function supabase_upload(string $base, string $key, string $object_path, string $bytes, string $ctype): bool
{
    $ch = curl_init("$base/storage/v1/object/$object_path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $bytes,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'apikey: ' . $key,
            'Content-Type: ' . $ctype,
            'x-upsert: true',
            'Cache-Control: max-age=31536000, immutable',
        ],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function supabase_delete(string $base, string $key, string $object_path): bool
{
    $ch = curl_init("$base/storage/v1/object/$object_path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'apikey: ' . $key,
        ],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

// First main-deck passcode via the local /parse (works for any input format).
function first_main_code(string $parse_url): ?int
{
    list($body, $code) = http_get($parse_url, 30);
    if ($code !== 200 || !$body) return null;
    $json = json_decode($body, true);
    $main = $json['data']['decks']['main'] ?? null;
    if (is_array($main) && count($main) > 0 && is_numeric($main[0])) return (int) $main[0];
    return null;
}
