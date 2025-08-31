<?php
require_once 'config.php';
require_once 'auth.php';

// Check if we're behind ngrok proxy
$isNgrok = strpos($_SERVER['HTTP_HOST'], 'ngrok') !== false;
$protocol = ($isNgrok && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 
           (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');

$nonce = setSecurityHeaders();

// Handle different login states
$error = '';
$success = '';

if (isset($_GET['timeout'])) {
    $error = 'Your session has expired. Please log in again.';
} elseif (isset($_GET['logged_out'])) {
    $success = 'You have been logged out successfully.';
}

// Handle Telegram auth callback
if (isset($_GET['id']) && isset($_GET['hash'])) {
    // Rate limiting for login attempts
    if (!TelegramAuth::checkRateLimit('login', 10, 300)) {
        $error = 'Too many login attempts. Please wait 5 minutes and try again.';
    } else {
        $authResult = TelegramAuth::handleTelegramLogin($_GET);
        if ($authResult['success']) {
            // Check for redirect after login
            $redirectUrl = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirectUrl);
            exit();
        } else {
            $error = $authResult['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LEGEND CHECKER: Secure Sign-in</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/enhanced.css">
    <style>
        :root {
            --bg-primary: #0a0a0a;
            --bg-secondary: #1a1a1a;
            --bg-card: #2a2a2a;
            --text-primary: #ffffff;
            --text-secondary: #b0b0b0;
            --accent-blue: #1da1f2;
            --accent-green: #00d4aa;
            --accent-purple: #8b5cf6;
            --accent-orange: #f59e0b;
            --border-color: #3a3a3a;
            --shadow-glow: rgba(29, 161, 242, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(29, 161, 242, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(139, 92, 246, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(0, 212, 170, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .login-container {
            background: var(--bg-card);
            border-radius: 24px;
            padding: 48px;
            width: 100%;
            max-width: 480px;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 1;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 32px;
            color: white;
            font-weight: 700;
        }

        .app-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .app-subtitle {
            color: var(--text-secondary);
            font-size: 16px;
            font-weight: 400;
        }

        .login-form {
            margin-top: 40px;
        }

        .form-group {
            margin-bottom: 32px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 16px;
            text-align: center;
        }

        .telegram-login-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 24px;
        }

        .telegram-login-button {
            background: linear-gradient(135deg, #0088cc, #00a0e6);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 16px 32px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0, 136, 204, 0.3);
        }

        .telegram-login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 136, 204, 0.4);
        }

        .telegram-login-button i {
            font-size: 20px;
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 32px 0;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-color);
        }

        .divider span {
            padding: 0 16px;
        }

        .features-list {
            list-style: none;
            margin-top: 32px;
        }

        .features-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .features-list i {
            color: var(--accent-green);
            width: 16px;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            text-align: center;
            animation: slideIn 0.3s ease-out;
        }
        
        .success-message {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            text-align: center;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .security-notice {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            padding: 16px;
            border-radius: 12px;
            margin-top: 24px;
            font-size: 12px;
            text-align: center;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 12px;
        }

        .footer a {
            color: var(--accent-blue);
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 640px) {
            .login-container {
                padding: 32px 24px;
                border-radius: 16px;
            }

            .app-title {
                font-size: 28px;
            }

            .telegram-login-button {
                padding: 14px 28px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-section">
            <div class="logo">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1 class="app-title">LEGEND CHECKER</h1>
            <p class="app-subtitle">Secure Sign-in</p>
        </div>

        <?php if (!empty($success)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="login-form">
            <div class="form-group">
                <label class="form-label">Sign in with your Telegram account</label>
                <div class="telegram-login-wrapper">
                    <script async src="https://telegram.org/js/telegram-widget.js?22" 
                            data-telegram-login="<?php echo TelegramConfig::botName(); ?>" 
                            data-size="large" 
                            data-auth-url="<?php echo $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>" 
                            data-request-access="write"
                            data-radius="10">
                    </script>
                    <noscript>
                        <div class="error-message">
                            <i class="fas fa-exclamation-triangle"></i>
                            JavaScript is required for Telegram authentication. Please enable JavaScript and refresh the page.
                        </div>
                    </noscript>
                </div>
            </div>

            <div class="divider">
                <span>Secure & Fast Access</span>
            </div>

            <ul class="features-list">
                <li>
                    <i class="fas fa-check"></i>
                    <span>Secure Telegram authentication</span>
                </li>
                <li>
                    <i class="fas fa-check"></i>
                    <span>Personal dashboard with stats</span>
                </li>
                <li>
                    <i class="fas fa-check"></i>
                    <span>Daily credit rewards</span>
                </li>
                <li>
                    <i class="fas fa-check"></i>
                    <span>Advanced card & site checking tools</span>
                </li>
                <li>
                    <i class="fas fa-check"></i>
                    <span>Real-time leaderboards</span>
                </li>
            </ul>

            <div class="security-notice">
                <i class="fas fa-lock"></i>
                Your data is protected with end-to-end encryption and secure authentication protocols.
            </div>
        </div>

        <div class="footer">
            <p>Powered by <a href="https://t.me/<?php echo str_replace('@', '', TelegramConfig::botName()); ?>" target="_blank"><?php echo TelegramConfig::botName(); ?></a></p>
            <p>© 2025 LEGEND CHECKER. All rights reserved.</p>
        </div>
    </div>
<script src="assets/js/main.js"></script>
</body>
</html>
