<?php
require_once 'config.php';
require_once 'database.php';

// Production error handling - disable display, enable logging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

class TelegramAuth {
    
    public static function verifyTelegramAuth($authData) {
        // Log the received data for debugging
        logError('Telegram auth data received', $authData);
        
        if (!isset($authData['hash']) || empty($authData['hash'])) {
            logError('No hash provided in auth data');
            return false;
        }
        
        $checkHash = $authData['hash'];
        unset($authData['hash']);
        
        // Remove empty values and sort
        $dataCheckArr = [];
        foreach ($authData as $key => $value) {
            if ($value !== '' && $value !== null) {
                $dataCheckArr[] = $key . '=' . $value;
            }
        }
        sort($dataCheckArr);
        
        $dataCheckString = implode("\n", $dataCheckArr);
        logError('Data check string', ['string' => $dataCheckString]);
        
        // Create secret key from bot token
        $secretKey = hash('sha256', TelegramConfig::BOT_TOKEN, true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);
        
        logError('Hash comparison', ['calculated' => $hash, 'received' => $checkHash]);
        
        if (!hash_equals($hash, $checkHash)) {
            logError('Hash verification failed');
            return false;
        }
        
        // Check if auth data is not too old (within 86400 seconds = 24 hours)
        if (!isset($authData['auth_date']) || (time() - $authData['auth_date']) > 86400) {
            logError('Auth data too old or missing auth_date');
            return false;
        }
        
        logError('Telegram auth verification successful');
        return true;
    }
    
    public static function handleTelegramLogin($authData) {
        logError('Handling Telegram login', $authData);
        
        if (!self::verifyTelegramAuth($authData)) {
            return ['success' => false, 'error' => 'Invalid authentication data. Please try again.'];
        }
        
        try {
            // Initialize secure session
            initSecureSession();
            
            $db = Database::getInstance();
            $user = $db->getUserByTelegramId($authData['id']);
            
            if ($user) {
                // Check if user is banned
                if (isset($user['status']) && $user['status'] === 'banned') {
                    logError('Banned user login attempt', ['telegram_id' => $authData['id']]);
                    return ['success' => false, 'error' => 'Your account has been suspended. Please contact support.'];
                }
                
                logError('Updating existing user login', ['telegram_id' => $authData['id']]);
                $db->updateUserLastLogin($authData['id']);
            } else {
                logError('Creating new user', ['telegram_id' => $authData['id']]);
                $user = $db->createUser($authData);
            }
            
            // Store user data in session
            $_SESSION['user_id'] = $user['telegram_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['display_name'] = $user['display_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['credits'] = $user['credits'];
            $_SESSION['last_login'] = time();
            
            // Send login notification to Telegram channel
            self::sendLoginNotification($user);
            
            // Set session data
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            // Update presence
            $db->updatePresence($authData['id']);
            
            logError('Login successful', ['user_id' => $authData['id']]);
            return ['success' => true, 'user' => $user];
            
        } catch (Exception $e) {
            logError('Login error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return ['success' => false, 'error' => 'System error occurred. Please try again later.'];
        }
    }
    
    private static function sendLoginNotification($userData) {
        require_once 'utils.php';
        
        $message = "🔐 *New Login Alert*\n\n";
        $message .= "👤 **User:** " . formatUserName($userData) . "\n";
        $message .= "🆔 **ID:** " . $userData['telegram_id'] . "\n";
        $message .= "👑 **Role:** " . ucfirst($userData['role']) . "\n";
        $message .= "💰 **Credits:** " . formatNumber($userData['credits']) . "\n";
        $message .= "🕐 **Time:** " . date('Y-m-d H:i:s') . "\n";
        $message .= "🌐 **Domain:** " . AppConfig::DOMAIN;
        
        sendTelegramNotification($message);
    }
    
    public static function requireAuth() {
        initSecureSession();
        
        if (!isset($_SESSION['user_id'])) {
            // Store the current page for redirect after login
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header('Location: login.php');
            exit();
        }
        
        // Check session timeout
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > AppConfig::SESSION_TIMEOUT) {
            session_destroy();
            header('Location: login.php?timeout=1');
            exit();
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return $_SESSION['user_id'];
    }
    
    public static function getCurrentUser() {
        initSecureSession();
        
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        try {
            $db = Database::getInstance();
            return $db->getUserByTelegramId($_SESSION['user_id']);
        } catch (Exception $e) {
            logError('Error getting current user: ' . $e->getMessage());
            return null;
        }
    }
    
    public static function logout() {
        initSecureSession();
        
        // Clear all session data
        $_SESSION = [];
        
        // Delete the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        
        session_destroy();
        header('Location: login.php?logged_out=1');
        exit();
    }
    
    public static function checkMembership($telegramId) {
        // This would typically check if user is member of required Telegram channel
        // For now, we'll assume all authenticated users have membership
        return true;
    }
    
    // CSRF Protection
    public static function generateCSRFToken() {
        initSecureSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function validateCSRFToken($token) {
        initSecureSession();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    // Rate limiting
    public static function checkRateLimit($action, $limit = 5, $window = 300) {
        initSecureSession();
        $key = 'rate_limit_' . $action;
        $now = time();
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        
        // Clean old entries
        $_SESSION[$key] = array_filter($_SESSION[$key], function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
        
        if (count($_SESSION[$key]) >= $limit) {
            return false;
        }
        
        $_SESSION[$key][] = $now;
        return true;
    }
}
?>
