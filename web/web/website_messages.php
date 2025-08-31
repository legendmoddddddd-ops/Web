<?php
/**
 * Website Messages Component
 * This file displays active broadcast messages to users
 * Include this in your main pages to show website messages
 */

function getWebsiteMessagesForUsers() {
    try {
        $messages_file = 'data/website_messages.json';
        
        if (!file_exists($messages_file)) {
            return [];
        }
        
        $messages = json_decode(file_get_contents($messages_file), true) ?: [];
        
        // Filter active messages and sort by priority
        $active_messages = array_filter($messages, function($msg) {
            return $msg['status'] === 'active' && $msg['expires_at'] > time();
        });
        
        // Sort by priority (high, normal, low)
        $priority_order = ['high' => 3, 'normal' => 2, 'low' => 1];
        usort($active_messages, function($a, $b) use ($priority_order) {
            $a_priority = $priority_order[$a['priority']] ?? 1;
            $b_priority = $priority_order[$b['priority']] ?? 1;
            return $b_priority - $a_priority;
        });
        
        return $active_messages;
        
    } catch (Exception $e) {
        return [];
    }
}

function renderWebsiteMessages() {
    $messages = getWebsiteMessagesForUsers();
    
    if (empty($messages)) {
        return '';
    }
    
    $html = '<div class="website-messages-container mb-4">';
    
    foreach ($messages as $msg) {
        $priority_class = getPriorityClass($msg['priority']);
        $priority_icon = getPriorityIcon($msg['priority']);
        
        $html .= '
        <div class="alert alert-' . $priority_class . ' alert-dismissible fade show website-message" role="alert">
            <div class="d-flex align-items-start">
                <div class="me-3">
                    <i class="bi ' . $priority_icon . ' fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="alert-heading mb-0">
                            <i class="bi bi-megaphone"></i> Website Announcement
                        </h6>
                        <small class="text-muted">
                            ' . date('M j, g:i A', $msg['created_at']) . '
                        </small>
                    </div>
                    <p class="mb-2">' . htmlspecialchars($msg['message']) . '</p>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <code>' . $msg['message_id'] . '</code>
                        </small>
                        <span class="badge bg-' . $priority_class . '">
                            ' . ucfirst($msg['priority']) . ' Priority
                        </span>
                    </div>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

function getPriorityClass($priority) {
    switch ($priority) {
        case 'high':
            return 'danger';
        case 'normal':
            return 'primary';
        case 'low':
            return 'info';
        default:
            return 'secondary';
    }
}

function getPriorityIcon($priority) {
    switch ($priority) {
        case 'high':
            return 'bi-exclamation-triangle-fill';
        case 'normal':
            return 'bi-info-circle-fill';
        case 'low':
            return 'bi-info-circle';
        default:
            return 'bi-info-circle';
    }
}

// Add CSS for website messages
function getWebsiteMessagesCSS() {
    return '
    <style>
    .website-messages-container {
        position: relative;
    }
    
    .website-message {
        border-left: 4px solid;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        margin-bottom: 1rem;
    }
    
    .website-message.alert-danger {
        border-left-color: #dc3545;
        background: linear-gradient(135deg, #fff5f5 0%, #ffe6e6 100%);
    }
    
    .website-message.alert-primary {
        border-left-color: #0d6efd;
        background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%);
    }
    
    .website-message.alert-info {
        border-left-color: #0dcaf0;
        background: linear-gradient(135deg, #f0fdff 0%, #e6faff 100%);
    }
    
    .website-message .alert-heading {
        color: #495057;
        font-weight: 600;
    }
    
    .website-message code {
        background: rgba(0,0,0,0.1);
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.85em;
    }
    
    .website-message .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    @media (max-width: 768px) {
        .website-message {
            margin-bottom: 0.75rem;
        }
        
        .website-message .d-flex {
            flex-direction: column;
        }
        
        .website-message .me-3 {
            margin-right: 0 !important;
            margin-bottom: 0.5rem;
        }
    }
    </style>';
}

// Add JavaScript for website messages
function getWebsiteMessagesJS() {
    return '
    <script>
    // Auto-hide website messages after 30 seconds (optional)
    setTimeout(() => {
        const messages = document.querySelectorAll(".website-message");
        messages.forEach((message, index) => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(message);
                bsAlert.close();
            }, index * 2000); // Stagger the hiding
        });
    }, 30000);
    
    // Store dismissed messages in localStorage to avoid showing them again in the same session
    document.querySelectorAll(".website-message .btn-close").forEach(button => {
        button.addEventListener("click", function() {
            const message = this.closest(".website-message");
            const messageId = message.querySelector("code").textContent;
            
            // Store dismissed message
            const dismissed = JSON.parse(localStorage.getItem("dismissedMessages") || "[]");
            if (!dismissed.includes(messageId)) {
                dismissed.push(messageId);
                localStorage.setItem("dismissedMessages", JSON.stringify(dismissed));
            }
        });
    });
    
    // Hide messages that were already dismissed
    document.addEventListener("DOMContentLoaded", function() {
        const dismissed = JSON.parse(localStorage.getItem("dismissedMessages") || "[]");
        dismissed.forEach(messageId => {
            const message = document.querySelector(`code:contains("${messageId}")`);
            if (message) {
                const alert = message.closest(".website-message");
                if (alert) {
                    alert.style.display = "none";
                }
            }
        });
    });
    </script>';
}

// Function to include website messages in any page
function includeWebsiteMessages() {
    echo getWebsiteMessagesCSS();
    echo renderWebsiteMessages();
    echo getWebsiteMessagesJS();
}

// Function to get messages as array (for custom rendering)
function getWebsiteMessagesArray() {
    return getWebsiteMessagesForUsers();
}

// Function to check if there are active messages
function hasActiveWebsiteMessages() {
    $messages = getWebsiteMessagesForUsers();
    return !empty($messages);
}
?>
