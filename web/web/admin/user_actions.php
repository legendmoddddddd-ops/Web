<?php
require_once 'admin_header.php';

if (!isset($_GET['id']) || !isset($_GET['action'])) {
    header('Location: user_management.php');
    exit;
}

$user_id = $_GET['id'];
$action = $_GET['action'];

switch ($action) {
    case 'ban':
        $db->updateUserStatus($user_id, 'banned');
        $db->logAuditAction($_SESSION['user_id'], 'user_banned', $user_id, ['reason' => 'Admin action']);
        break;
    case 'unban':
        $db->updateUserStatus($user_id, 'active');
        $db->logAuditAction($_SESSION['user_id'], 'user_unbanned', $user_id, ['reason' => 'Admin action']);
        break;
    // Future actions like 'force_logout' can be added here
}

// Redirect back to the user management page
header('Location: user_management.php');
exit;

