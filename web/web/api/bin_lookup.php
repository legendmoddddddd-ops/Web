<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils.php';

header('Content-Type: application/json');

// Simple IP-based rate limiting using filesystem
function check_ip_rate_limit($key, $limit = 30, $window = 60) {
    $dir = __DIR__ . '/../data/rate_limit/';
    if (!is_dir($dir)) {@mkdir($dir, 0755, true);}    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = $dir . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key . '_' . $ip) . '.json';
    $now = time();
    $list = [];
    if (file_exists($file)) {
        $list = json_decode(@file_get_contents($file), true) ?: [];
        // prune old
        $list = array_values(array_filter($list, function($t) use ($now, $window) { return ($now - (int)$t) < $window; }));
    }
    if (count($list) >= $limit) {
        return false;
    }
    $list[] = $now;
    @file_put_contents($file, json_encode($list));
    return true;
}

function http_get_json($url, $headers = []) {
    // Prefer cURL if available
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err || $code >= 400) { return null; }
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }
    // Fallback to file_get_contents
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'header' => implode("\r\n", $headers)
        ]
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) { return null; }
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function respond($ok, $payload) {
    echo json_encode($ok ? ['success' => true, 'data' => $payload] : ['success' => false, 'error' => $payload]);
    exit;
}

$bin = isset($_GET['bin']) ? preg_replace('/\D+/', '', $_GET['bin']) : '';
if ($bin === '' || strlen($bin) < 6 || strlen($bin) > 8) {
    respond(false, 'Invalid BIN. Provide 6-8 digits.');
}

if (!check_ip_rate_limit('bin_lookup', 30, 60)) {
    respond(false, 'Rate limit exceeded. Try again later.');
}

// Cache file
$cacheDir = __DIR__ . '/../data/cache/';
if (!is_dir($cacheDir)) {@mkdir($cacheDir, 0755, true);}    
$cacheFile = $cacheDir . 'bin_' . $bin . '.json';
$cacheTtl = 7 * 24 * 3600; // 7 days

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $cached = json_decode(@file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        respond(true, $cached);
    }
}

// Query external BIN service
$resp = http_get_json('https://lookup.binlist.net/' . $bin, ['Accept: application/json']);
if (!$resp) {
    // Try alternate public API
    $resp = http_get_json('https://bins.antipublic.cc/bins/' . $bin, []);
}
if (!$resp) {
    respond(false, 'BIN lookup service unavailable.');
}

// Normalize response
$data = [
    'bin' => $bin,
    'scheme' => $resp['scheme'] ?? ($resp['brand'] ?? null),
    'brand' => $resp['brand'] ?? ($resp['type'] ?? null),
    'type' => $resp['type'] ?? ($resp['card_type'] ?? null),
    'level' => $resp['prepaid'] ?? ($resp['category'] ?? null),
    'bank_name' => $resp['bank']['name'] ?? ($resp['bank'] ?? null),
    'bank_url' => $resp['bank']['url'] ?? ($resp['website'] ?? null),
    'bank_phone' => $resp['bank']['phone'] ?? ($resp['phone'] ?? null),
    'country_name' => $resp['country']['name'] ?? ($resp['country_name'] ?? null),
    'country_code' => $resp['country']['alpha2'] ?? ($resp['country'] ?? null),
    'country_emoji' => $resp['country']['emoji'] ?? null,
    'currency' => $resp['country']['currency'] ?? ($resp['currency'] ?? null)
];

@file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
respond(true, $data);
?>

