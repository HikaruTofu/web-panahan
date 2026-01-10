<?php
/**
 * Security Helpers - Web Panahan
 * Centralized security logic for Rate Limiting, CSRF, Sanitization, and Auth Guards.
 */

// --- CSRF PROTECTION ---

/**
 * Generate CSRF token and store it in session
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF hidden input field
 */
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . get_csrf_token() . '">';
}

/**
 * Verify CSRF token from POST request
 */
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            header('HTTP/1.1 403 Forbidden');
            die('CSRF token validation failed.');
        }
    }
}

// --- SANITIZATION & ESCAPING ---

/**
 * Shorthand for htmlspecialchars
 */
function s($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Deep clean input array (trim and strip_tags)
 */
function cleanInput($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = cleanInput($value);
        }
    } else {
        $data = trim(strip_tags($data));
    }
    return $data;
}

// --- RATE LIMITING ---

/**
 * Simple session-based rate limiting
 * @param string $key Unique key for the rate limit (e.g., 'login', 'api_request')
 * @param int $limit Max attempts allowed
 * @param int $window Time window in seconds
 */
function checkRateLimit($key, $limit = 5, $window = 60) {
    $now = time();
    $session_key = "rate_limit_$key";
    
    if (!isset($_SESSION[$session_key])) {
        $_SESSION[$session_key] = [
            'count' => 1,
            'start_time' => $now
        ];
        return true;
    }
    
    $data = &$_SESSION[$session_key];
    
    // Reset if window has passed
    if ($now - $data['start_time'] > $window) {
        $data = [
            'count' => 1,
            'start_time' => $now
        ];
        return true;
    }
    
    // Increment count
    $data['count']++;
    
    if ($data['count'] > $limit) {
        return false;
    }
    
    return true;
}

// --- AUTH & PERMISSION GUARDS ---

/**
 * Enforce authentication (redirect to login if not logged in)
 */
function enforceAuth() {
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('HTTP/1.1 401 Unauthorized');
            echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
            exit;
        }
        session_write_close();
        header('Location: /index.php');
        exit;
    }
}

/**
 * Enforce admin role
 */
function enforceAdmin() {
    enforceAuth();
    if ($_SESSION['role'] !== 'admin') {
        security_log("Unauthorized admin access attempted on " . $_SERVER['REQUEST_URI'], 'CRITICAL');
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['success' => false, 'message' => 'Forbidden: Admin access required.']);
            exit;
        }
        session_write_close();
        header('Location: /views/kegiatan.view.php');
        exit;
    }
}

/**
 * Enforce staff roles (admin, operator, petugas)
 */
function enforceCanInputScore() {
    enforceAuth();
    $allowedRoles = ['admin', 'operator', 'petugas'];
    if (!in_array($_SESSION['role'], $allowedRoles)) {
        security_log("Unauthorized staff access attempted on " . $_SERVER['REQUEST_URI'], 'WARNING');
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['success' => false, 'message' => 'Forbidden: Staff access required.']);
            exit;
        }
        session_write_close();
        header('Location: /views/kegiatan.view.php');
        exit;
    }
}
/**
 * Log security-related events
 */
function security_log($event, $status = 'INFO') {
    $log_dir = dirname(__DIR__) . '/logs';
    $log_ready = true;

    if (!file_exists($log_dir)) {
        if (!@mkdir($log_dir, 0755, true)) {
            $log_ready = false;
        } else {
            @file_put_contents($log_dir . '/.htaccess', "Deny from all");
        }
    }

    $date = date('Y-m-d H:i:s');
    $user = $_SESSION['username'] ?? 'Anonymous';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $message = "[$date] [$status] [User: $user] [IP: $ip] - $event";

    if ($log_ready && is_writable($log_dir)) {
        @error_log($message . PHP_EOL, 3, $log_dir . '/security.log');
    } else {
        // Fallback to standard system error log
        error_log("SECURITY LOG: $message");
    }
}
