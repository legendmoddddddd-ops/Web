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
        $chatId = TelegramConfig::chatId();
    }
    
    $url = "https://api.telegram.org/bot" . TelegramConfig::botToken() . "/sendMessage";
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
?>
