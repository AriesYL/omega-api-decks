<?php

require(__DIR__ . '/../vendor/autoload.php');

/*
 * Deck image WITH persistent storage (SwissYGO #84 / Part 1).
 *
 * Renders the deck image via the local /imageify endpoint, stores it in Supabase
 * Storage under a deterministic key derived from the deck (sha256), and returns the
 * public URL as JSON. IDEMPOTENT: if the object already exists it returns its URL
 * without re-rendering or re-uploading. The Supabase service key lives ONLY here
 * (this external API), never on the SwissYGO host.
 *
 * GET /deck-image?token=<REQUEST_TOKEN>&list=<deck>   (any /imageify-supported deck
 * param works: list | ydke | omega | ydk | names | json; optional &quality=)
 * → { "success": true, "data": { "url": "<public url>", "cached": <bool> } }
 */

Http::allow_method('GET');
Http::check_token('token', 'REQUEST_TOKEN');

$supabase_url = rtrim((string) getenv('SUPABASE_URL'), '/');
$supabase_key = (string) getenv('SUPABASE_SERVICE_KEY');
$bucket       = getenv('SUPABASE_BUCKET') ?: 'deck-images';
if ($supabase_url === '' || $supabase_key === '')
    Http::fail('storage is not configured (set SUPABASE_URL and SUPABASE_SERVICE_KEY)', Http::INTERNAL_SERVER_ERROR);

// Deterministic key from the deck the caller sent. We hash the raw deck string (the
// SwissYGO host sends a stable representation), so re-submitting the same deck reuses
// the stored image. Accept any of /imageify's deck parameters.
$deck_input = null;
foreach (['list', 'ydke', 'omega', 'ydk', 'names', 'json'] as $param) {
    $value = Http::get_query_parameter($param, false, null);
    if ($value !== null && $value !== '') { $deck_input = trim($value); break; }
}
if ($deck_input === null)
    Http::fail('no deck provided (use ?list=<deck> or a format-specific parameter)');

$hash        = hash('sha256', $deck_input);
$object_path = rawurlencode($bucket) . '/' . $hash . '.jpg';   // omega outputs JPEG; hash is hex → path-safe
$public_url  = "$supabase_url/storage/v1/object/public/$object_path";

// 1) Already stored? → return it (idempotent: no render, no upload).
if (storage_object_exists($public_url))
    deck_image_respond($public_url, true);

// 2) Render via the local /imageify (loopback), forwarding the same query
//    (token + deck + optional quality). Apache listens on $PORT (see entrypoint).
$port     = getenv('PORT') ?: '80';
$loop_url = "http://127.0.0.1:$port/imageify?" . ($_SERVER['QUERY_STRING'] ?? '');
$ch = curl_init($loop_url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
$png       = curl_exec($ch);
$img_code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$img_ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);
if ($png === false || $img_code !== 200 || strpos($img_ctype, 'image/') !== 0)
    Http::fail('failed to render deck image' . ($img_code ? " (imageify returned $img_code)" : ''), Http::INTERNAL_SERVER_ERROR);

// 3) Upload to Supabase Storage. `x-upsert: true` keeps a concurrent re-create
//    idempotent (returns 200 instead of 409 if it raced into existence).
$upload_url = "$supabase_url/storage/v1/object/$object_path";
$ch = curl_init($upload_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $png,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $supabase_key,
        'apikey: ' . $supabase_key,
        'Content-Type: ' . ($img_ctype ?: 'image/jpeg'),
        'x-upsert: true',
        'Cache-Control: max-age=31536000, immutable',
    ],
]);
$up_body = curl_exec($ch);
$up_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($up_code < 200 || $up_code >= 300)
    Http::fail("failed to store image (supabase returned $up_code): " . substr((string) $up_body, 0, 200), Http::INTERNAL_SERVER_ERROR);

deck_image_respond($public_url, false);


function storage_object_exists(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code === 200;
}

function deck_image_respond(string $url, bool $cached): void
{
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => ['url' => $url, 'cached' => $cached]]);
    exit;
}
