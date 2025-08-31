<?php
require_once 'admin_header.php';
require_once 'admin_utils.php';

// Get current user for display
$current_user = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'generate_credits':
            $count = (int)($_POST['count'] ?? 10);
            $credit_amount = (int)($_POST['credit_amount'] ?? 100);
            $expiry_days = (int)($_POST['expiry_days'] ?? 30);
            $type = $_POST['type'] ?? 'standard';
            
            $generated_codes = [];
            for ($i = 0; $i < $count; $i++) {
                $code = generateCreditCode($credit_amount, $expiry_days, $type);
                $generated_codes[] = $code;
            }
            
            $successMessage = "Generated {$count} credit codes worth {$credit_amount} credits each!";
            break;
            
        case 'redeem_code':
            $code = $_POST['code'] ?? '';
            $telegram_id = $_POST['telegram_id'] ?? '';
            
            if (!empty($code) && !empty($telegram_id)) {
                $result = redeemCreditCode($code, $telegram_id);
                if ($result['success']) {
                    $successMessage = "Credit code redeemed successfully! User {$telegram_id} received {$result['credits']} credits";
                } else {
                    $errorMessage = $result['error'];
                }
            } else {
                $errorMessage = "Please provide both code and Telegram ID";
            }
            break;
            
        case 'delete_credit_code':
            $code_id = $_POST['code_id'] ?? '';
            if (!empty($code_id)) {
                $result = deleteCreditCode($code_id);
                if ($result) {
                    $successMessage = "Credit code deleted successfully";
                } else {
                    $errorMessage = "Failed to delete credit code";
                }
            }
            break;
            
        case 'bulk_credit_gift':
            $telegram_ids = array_filter(explode(',', $_POST['telegram_ids'] ?? ''));
            $credit_amount = (int)($_POST['credit_amount'] ?? 50);
            $message = $_POST['message'] ?? '';
            
            if (!empty($telegram_ids) && $credit_amount > 0) {
                $success_count = 0;
                foreach ($telegram_ids as $telegram_id) {
                    $telegram_id = trim($telegram_id);
                    if (is_numeric($telegram_id)) {
                        $result = giftCredits($telegram_id, $credit_amount, $message);
                        if ($result) $success_count++;
                    }
                }
                $successMessage = "Gifted {$credit_amount} credits to {$success_count} users successfully!";
            } else {
                $errorMessage = "Please provide valid Telegram IDs and credit amount";
            }
            break;
    }
}

// Get existing credit codes
$credit_codes = getCreditCodes();
$used_credit_codes = getUsedCreditCodes();

/**
 * Generate a credit code
 */
function generateCreditCode($credit_amount, $expiry_days, $type) {
    global $db;
    
    $code = 'CREDIT-' . strtoupper(generateRandomString(8));
    $expires_at = time() + ($expiry_days * 24 * 60 * 60);
    
    $data = [
        'code' => $code,
        'credit_amount' => $credit_amount,
        'type' => $type,
        'expiry_days' => $expiry_days,
        'expires_at' => $expires_at,
        'created_at' => time(),
        'created_by' => $_SESSION['telegram_id'] ?? 'admin',
        'status' => 'active'
    ];
    
    // Save to database (you'll need to create this table)
    if (method_exists($db, 'insertCreditCode')) {
        $db->insertCreditCode($data);
    }
    
    return $data;
}

/**
 * Redeem a credit code
 */
function redeemCreditCode($code, $telegram_id) {
    global $db;
    
    // Check if code exists and is valid
    $code_data = getCreditCodeByCode($code);
    if (!$code_data) {
        return ['success' => false, 'error' => 'Invalid credit code'];
    }
    
    if ($code_data['status'] !== 'active') {
        return ['success' => false, 'error' => 'Code is not active'];
    }
    
    if ($code_data['expires_at'] < time()) {
        return ['success' => false, 'error' => 'Code has expired'];
    }
    
    // Check if user exists
    $user = $db->getUserByTelegramId($telegram_id);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }
    
    // Check if code was already used by this user
    if (isCreditCodeUsedByUser($code, $telegram_id)) {
        return ['success' => false, 'error' => 'Code already used by this user'];
    }
    
    // Add credits to user
    $current_credits = $user['credits'] ?? 0;
    $new_credits = $current_credits + $code_data['credit_amount'];
    
    if (method_exists($db, 'updateUserCredits')) {
        $db->updateUserCredits($telegram_id, $new_credits);
    }
    
    // Mark code as used
    markCreditCodeAsUsed($code, $telegram_id);
    
    // Send notification to user
    if (function_exists('sendTelegramMessage')) {
        $message = "🎉 Congratulations! You've redeemed a credit code!\n\n";
        $message .= "💰 <b>{$code_data['credit_amount']} credits</b> have been added to your account.\n";
        $message .= "💳 Your new balance: <b>{$new_credits} credits</b>";
        sendTelegramMessage($telegram_id, $message);
    }
    
    return [
        'success' => true, 
        'user' => $user, 
        'code' => $code_data, 
        'credits' => $code_data['credit_amount']
    ];
}

/**
 * Gift credits to a user
 */
function giftCredits($telegram_id, $credit_amount, $message = '') {
    global $db;
    
    // Check if user exists
    $user = $db->getUserByTelegramId($telegram_id);
    if (!$user) {
        return false;
    }
    
    // Add credits to user
    $current_credits = $user['credits'] ?? 0;
    $new_credits = $current_credits + $credit_amount;
    
    if (method_exists($db, 'updateUserCredits')) {
        $db->updateUserCredits($telegram_id, $new_credits);
    }
    
    // Send notification to user
    if (function_exists('sendTelegramMessage')) {
        $gift_message = "🎁 You've received a gift from the admin!\n\n";
        $gift_message .= "💰 <b>{$credit_amount} credits</b> have been added to your account.\n";
        $gift_message .= "💳 Your new balance: <b>{$new_credits} credits</b>";
        
        if (!empty($message)) {
            $gift_message .= "\n\n💬 Message: {$message}";
        }
        
        sendTelegramMessage($telegram_id, $gift_message);
    }
    
    return true;
}

/**
 * Get credit codes from database
 */
function getCreditCodes() {
    global $db;
    
    if (method_exists($db, 'getCreditCodes')) {
        return $db->getCreditCodes();
    }
    
    // Fallback - return sample data
    return [
        [
            'id' => 1,
            'code' => 'CREDIT-ABC12345',
            'credit_amount' => 100,
            'type' => 'standard',
            'expiry_days' => 30,
            'expires_at' => time() + (30 * 24 * 60 * 60),
            'status' => 'active',
            'created_at' => time()
        ]
    ];
}

/**
 * Get used credit codes
 */
function getUsedCreditCodes() {
    global $db;
    
    if (method_exists($db, 'getUsedCreditCodes')) {
        return $db->getUsedCreditCodes();
    }
    
    return [];
}

/**
 * Get credit code by code string
 */
function getCreditCodeByCode($code) {
    global $db;
    
    if (method_exists($db, 'getCreditCodeByCode')) {
        return $db->getCreditCodeByCode($code);
    }
    
    return null;
}

/**
 * Check if credit code was used by specific user
 */
function isCreditCodeUsedByUser($code, $telegram_id) {
    global $db;
    
    if (method_exists($db, 'isCreditCodeUsedByUser')) {
        return $db->isCreditCodeUsedByUser($code, $telegram_id);
    }
    
    return false;
}

/**
 * Mark credit code as used
 */
function markCreditCodeAsUsed($code, $telegram_id) {
    global $db;
    
    if (method_exists($db, 'markCreditCodeAsUsed')) {
        return $db->markCreditCodeAsUsed($code, $telegram_id);
    }
    
    return false;
}

/**
 * Delete credit code
 */
function deleteCreditCode($code_id) {
    global $db;
    
    if (method_exists($db, 'deleteCreditCode')) {
        return $db->deleteCreditCode($code_id);
    }
    
    return false;
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-coin"></i> Credit Generator
                    </h1>
                    <p class="text-muted">Generate and manage credit codes for users</p>
                </div>
                <div>
                    <a href="analytics.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($successMessage)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo $successMessage; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($errorMessage)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo $errorMessage; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Generate Credit Codes -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-plus-circle"></i> Generate Credit Codes
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="generate_credits">
                                
                                <div class="mb-3">
                                    <label for="count" class="form-label">Number of Codes</label>
                                    <input type="number" class="form-control" id="count" name="count" 
                                           value="10" min="1" max="100" required>
                                    <div class="form-text">How many codes to generate (1-100)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="credit_amount" class="form-label">Credit Amount</label>
                                    <input type="number" class="form-control" id="credit_amount" name="credit_amount" 
                                           value="100" min="1" max="10000" required>
                                    <div class="form-text">Credits per code (1-10,000)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="type" class="form-label">Code Type</label>
                                    <select class="form-select" id="type" name="type" required>
                                        <option value="standard">Standard</option>
                                        <option value="bonus">Bonus</option>
                                        <option value="vip">VIP</option>
                                        <option value="event">Event</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="expiry_days" class="form-label">Expiry (Days)</label>
                                    <input type="number" class="form-control" id="expiry_days" name="expiry_days" 
                                           value="30" min="1" max="365" required>
                                    <div class="form-text">How long codes are valid</div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-magic"></i> Generate Codes
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Redeem Code -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-check-circle"></i> Redeem Credit Code
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="redeem_code">
                                
                                <div class="mb-3">
                                    <label for="code" class="form-label">Credit Code</label>
                                    <input type="text" class="form-control" id="code" name="code" 
                                           placeholder="e.g., CREDIT-ABC12345" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="telegram_id" class="form-label">Telegram ID</label>
                                    <input type="text" class="form-control" id="telegram_id" name="telegram_id" 
                                           placeholder="e.g., 7580639195" required>
                                </div>
                                
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> Redeem Code
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulk Credit Gift -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-gift"></i> Bulk Credit Gift
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="bulk_credit_gift">
                        
                        <div class="row">
                            <div class="col-md-4">
                                <label for="telegram_ids" class="form-label">Telegram IDs</label>
                                <textarea class="form-control" id="telegram_ids" name="telegram_ids" rows="4" 
                                          placeholder="Enter Telegram IDs separated by commas&#10;e.g., 7580639195, 1234567890" required></textarea>
                                <div class="form-text">One ID per line or comma-separated</div>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="credit_amount" class="form-label">Credit Amount</label>
                                <input type="number" class="form-control" id="credit_amount" name="credit_amount" 
                                       value="50" min="1" max="10000" required>
                                <div class="form-text">Credits to gift to each user</div>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="message" class="form-label">Gift Message (Optional)</label>
                                <textarea class="form-control" id="message" name="message" rows="4" 
                                          placeholder="Personal message to include with the gift"></textarea>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-warning" 
                                    onclick="return confirm('Are you sure you want to gift credits to all these users?')">
                                <i class="bi bi-gift"></i> Gift Credits to All Users
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Active Credit Codes -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-check"></i> Active Credit Codes
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Credits</th>
                                    <th>Type</th>
                                    <th>Expires</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($credit_codes as $code): ?>
                                <tr>
                                    <td>
                                        <code><?php echo htmlspecialchars($code['code']); ?></code>
                                        <button class="btn btn-sm btn-outline-secondary ms-2" 
                                                onclick="copyToClipboard('<?php echo $code['code']; ?>')">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <span class="badge bg-success"><?php echo $code['credit_amount']; ?> Credits</span>
                                    </td>
                                    <td><?php echo ucfirst($code['type']); ?></td>
                                    <td><?php echo formatDate($code['expires_at']); ?></td>
                                    <td>
                                        <?php if ($code['status'] === 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Used</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_credit_code">
                                            <input type="hidden" name="code_id" value="<?php echo $code['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                    onclick="return confirm('Are you sure you want to delete this code?')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Used Credit Codes -->
            <?php if (!empty($used_credit_codes)): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-clock-history"></i> Used Credit Codes
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Credits</th>
                                    <th>Type</th>
                                    <th>Used By</th>
                                    <th>Used At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($used_credit_codes as $code): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($code['code']); ?></code></td>
                                    <td><?php echo $code['credit_amount']; ?> Credits</td>
                                    <td><?php echo ucfirst($code['type']); ?></td>
                                    <td><?php echo htmlspecialchars($code['used_by']); ?></td>
                                    <td><?php echo formatDate($code['used_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Show success message
        const button = event.target.closest('button');
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="bi bi-check"></i>';
        button.classList.remove('btn-outline-secondary');
        button.classList.add('btn-success');
        
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-secondary');
        }, 2000);
    });
}
</script>

<?php require_once 'admin_footer.php'; ?>
