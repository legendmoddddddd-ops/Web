<?php
// Check if user ID is provided before including header
if (!isset($_GET['id'])) {
    header('Location: user_management.php');
    exit;
}

require_once 'admin_header.php';
require_once 'admin_utils.php';

// Get current user for display
$current_user = getCurrentUser();

$user_id = $_GET['id'];
$user = $db->getUserByTelegramId($user_id);

if (!$user) {
    header('Location: user_management.php');
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (int)$_POST['amount'];
    $action = $_POST['action'];

    if ($action === 'add') {
        $db->addCredits($user_id, $amount);
        $message = "<div class='alert alert-success'>Successfully added {$amount} credits.</div>";
    } elseif ($action === 'remove') {
        $current_credits = $user['credits'];
        $new_credits = $current_credits - $amount;
        if ($new_credits < 0) {
            $message = "<div class='alert alert-danger'>Cannot remove more credits than the user has.</div>";
        } else {
            if ($db->updateUserCredits($user_id, $new_credits)) {
                // Log the audit action
                $db->logAuditAction($_SESSION['user_id'], 'credit_adjusted', $user_id, [
                    'action' => $action,
                    'amount' => $amount,
                    'old_credits' => $current_credits,
                    'new_credits' => $new_credits
                ]);
                $message = "<div class='alert alert-success'>Credits updated successfully! User now has " . number_format($new_credits) . " credits.</div>";
            } else {
                $message = "<div class='alert alert-danger'>Failed to update credits. Please try again.</div>";
            }
        }
    }
    // Refresh user data
    $user = $db->getUserByTelegramId($user_id);
}

?>
<div class="content">
    <h1>Adjust Credits for <?php echo htmlspecialchars($user['display_name']); ?></h1>
    <a href="user_management.php" class="btn btn-secondary mb-3">Back to User List</a>

    <?php echo $message; ?>

    <div class="card">
        <div class="card-header">Current Balance: <?php echo number_format($user['credits']); ?> Credits</div>
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label for="amount" class="form-label">Amount</label>
                    <input type="number" class="form-control" id="amount" name="amount" required min="1">
                </div>
                <div class="mb-3">
                    <label for="action" class="form-label">Action</label>
                    <select class="form-select" id="action" name="action">
                        <option value="add">Add Credits</option>
                        <option value="remove">Remove Credits</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Submit</button>
            </form>
        </div>
    </div>
</div>
<?php require_once 'admin_footer.php'; ?>
