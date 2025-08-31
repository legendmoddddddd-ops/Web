<?php
// Utility functions for date/time handling and common operations

/**
 * Safe date formatting that handles both MongoDB UTCDateTime and Unix timestamps
 */
function formatDate($dateValue, $format = 'M d, Y') {
    if (empty($dateValue)) {
        return date($format);
    }
    
    // Handle MongoDB UTCDateTime object
    if (is_object($dateValue) && method_exists($dateValue, 'toDateTime')) {
        return $dateValue->toDateTime()->format($format);
    }
    
    // Handle Unix timestamp (integer)
    if (is_numeric($dateValue)) {
        return date($format, $dateValue);
    }
    
    // Handle string dates
    if (is_string($dateValue)) {
        $timestamp = strtotime($dateValue);
        if ($timestamp !== false) {
            return date($format, $timestamp);
        }
    }
    
    // Fallback to current date
    return date($format);
}

/**
 * Safe time ago formatting
 */
function timeAgo($dateValue) {
    $timestamp = null;
    
    // Handle MongoDB UTCDateTime object
    if (is_object($dateValue) && method_exists($dateValue, 'toDateTime')) {
        $timestamp = $dateValue->toDateTime()->getTimestamp();
    }
    // Handle Unix timestamp
    elseif (is_numeric($dateValue)) {
        $timestamp = $dateValue;
    }
    // Handle string dates
    elseif (is_string($dateValue)) {
        $timestamp = strtotime($dateValue);
    }
    
    if ($timestamp === null || $timestamp === false) {
        return 'Unknown';
    }
    
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return formatDate($dateValue, 'M d, Y');
    }
}

/**
 * Send Telegram notification (static function)
 */
function sendTelegramNotification($message, $chatId = null) {
    if ($chatId === null) {
        $chatId = TelegramConfig::CHAT_ID;
    }
    
    $url = "https://api.telegram.org/bot" . TelegramConfig::BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

/**
 * Format user display name safely
 */
function formatUserName($user) {
    if (isset($user['display_name']) && !empty($user['display_name'])) {
        return htmlspecialchars($user['display_name']);
    }
    
    if (isset($user['username']) && !empty($user['username'])) {
        return '@' . htmlspecialchars($user['username']);
    }
    
    return 'User #' . ($user['telegram_id'] ?? 'Unknown');
}

/**
 * Safe number formatting
 */
function formatNumber($number) {
    if (!is_numeric($number)) {
        return '0';
    }
    return number_format($number);
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Luhn algorithm check for card numbers
 */
function luhnCheck($number) {
    $number = preg_replace('/\D+/', '', (string)$number);
    if ($number === '' || strlen($number) < 12) return false;
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int)$number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    return ($sum % 10) === 0;
}

/**
 * Detect card brand from number using IIN ranges
 */
function detectCardBrand($number) {
    $number = preg_replace('/\D+/', '', (string)$number);
    if (preg_match('/^4\d{12}(\d{3})?(\d{3})?$/', $number)) return 'Visa';
    if (preg_match('/^(5[1-5]\d{14}|2(2[2-9]|[3-6]\d|7[01])\d{12}|2720\d{12})$/', $number)) return 'Mastercard';
    if (preg_match('/^3[47]\d{13}$/', $number)) return 'American Express';
    if (preg_match('/^6(?:011|5\d{2})\d{12}$/', $number)) return 'Discover';
    if (preg_match('/^(?:2131|1800|35\d{3})\d{11}$/', $number)) return 'JCB';
    if (preg_match('/^3(?:0[0-5]|[68]\d)\d{11}$/', $number)) return 'Diners Club';
    if (preg_match('/^220[0-4]\d{12}$/', $number)) return 'MIR';
    if (preg_match('/^(62|81)\d{14,17}$/', $number)) return 'UnionPay';
    if (preg_match('/^(50|5[6-9]|6[0-9])\d{10,17}$/', $number)) return 'Maestro';
    return 'Unknown';
}
?>
