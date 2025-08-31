<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../database.php';

header('Content-Type: application/json');
$nonce = setSecurityHeaders();

// Rate limiting check
function checkRateLimit($userId) {
    $cacheKey = "claim_attempt_{$userId}";
    $attempts = apcu_fetch($cacheKey, $success);
    
    if (!$success) {
        $attempts = 0;
    }
    
    if ($attempts >= 5) {
        return false; // Too many attempts
    }
    
    apcu_store($cacheKey, $attempts + 1, 300); // 5 minutes
    return true;
}

try {
    $userId = TelegramAuth::requireAuth();
    
    // Check rate limiting
    if (!checkRateLimit($userId)) {
        echo json_encode([
            'success' => false,
            'message' => 'Too many claim attempts. Please wait 5 minutes before trying again.',
            'error_code' => 'RATE_LIMITED'
        ]);
        exit();
    }
    
    $db = Database::getInstance();
    
    // Get user data for additional checks
    $user = $db->getUserByTelegramId($userId);
    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'User not found. Please login again.',
            'error_code' => 'USER_NOT_FOUND'
        ]);
        exit();
    }
    
    // Check if user account is active
    if ($user['status'] !== 'active') {
        echo json_encode([
            'success' => false,
            'message' => 'Your account is not active. Please contact support.',
            'error_code' => 'ACCOUNT_INACTIVE'
        ]);
        exit();
    }
    
    // Check if user can claim credits today
    $canClaim = $db->canClaimDailyCredits($userId);
    
    if (!$canClaim) {
        // Get next claim time for better UX
        $nextClaimTime = $db->getNextClaimTime($userId);
        $timeUntilNext = $nextClaimTime ? date('H:i', $nextClaimTime - time()) : 'tomorrow';
        
        echo json_encode([
            'success' => false,
            'message' => "You have already claimed your daily credits. Next claim available in {$timeUntilNext}.",
            'error_code' => 'ALREADY_CLAIMED',
            'next_claim_time' => $nextClaimTime
        ]);
        exit();
    }
    
    // Calculate credit amount based on user role
    $creditAmount = AppConfig::DAILY_CREDIT_AMOUNT;
    if ($user['role'] === 'premium') {
        $creditAmount = AppConfig::DAILY_CREDIT_AMOUNT * 2; // Premium users get double
    } elseif ($user['role'] === 'admin' || $user['role'] === 'owner') {
        $creditAmount = AppConfig::DAILY_CREDIT_AMOUNT * 3; // Admin/Owner get triple
    }
    
    // Claim the credits with transaction safety
    $result = $db->claimDailyCredits($userId, $creditAmount);
    
    if ($result) {
        // Log the successful claim
        $db->logActivity($userId, 'credit_claim', [
            'amount' => $creditAmount,
            'user_role' => $user['role'],
            'timestamp' => time()
        ]);
        
        // Clear rate limit on successful claim
        apcu_delete("claim_attempt_{$userId}");
        
        echo json_encode([
            'success' => true,
            'message' => "Daily credits claimed successfully! You received {$creditAmount} credits.",
            'credits_awarded' => $creditAmount,
            'new_balance' => $user['credits'] + $creditAmount,
            'bonus_applied' => $user['role'] !== 'free' ? true : false,
            'next_claim_time' => strtotime('+1 day', strtotime('today'))
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to claim credits due to a database error. Please try again.',
            'error_code' => 'DATABASE_ERROR'
        ]);
    }
    
} catch (Exception $e) {
    logError('Credit claim error: ' . $e->getMessage() . ' - User: ' . ($userId ?? 'unknown'));
    
    // Different error messages based on exception type
    $errorMessage = 'System error occurred. Please try again later.';
    $errorCode = 'SYSTEM_ERROR';
    
    if (strpos($e->getMessage(), 'auth') !== false) {
        $errorMessage = 'Authentication failed. Please login again.';
        $errorCode = 'AUTH_ERROR';
    } elseif (strpos($e->getMessage(), 'database') !== false) {
        $errorMessage = 'Database connection error. Please try again in a few moments.';
        $errorCode = 'DB_CONNECTION_ERROR';
    }
    
    echo json_encode([
        'success' => false,
        'message' => $errorMessage,
        'error_code' => $errorCode
    ]);
}
?>
