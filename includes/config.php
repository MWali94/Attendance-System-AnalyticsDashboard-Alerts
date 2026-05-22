<?php
// ============================================================
//  AttendIQ — Config & Helpers
//  Edit DB_PASS if your MySQL has a password
// ============================================================

define('DB_HOST', 'sql113.infinityfree.com');
define('DB_USER', 'if0_41906588');
define('DB_PASS', '6YHuCjryzRBKh');
define('DB_NAME', 'if0_41906588_attendislb');
define('SITE_NAME', 'AttendIQ');

// ── Force HTTPS on live server ────────────────────────────────
function forceHttps(): void {
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        // Also check for proxy/load balancer forwarded headers
        if (!isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ||
            $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https') {
            $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . $redirect);
            exit;
        }
    }
}

// Call this on every page — redirects HTTP → HTTPS automatically
// Comment out this line if running locally on XAMPP
forceHttps();

// ── Database connection ───────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            die('<h2 style="font-family:sans-serif;color:red;padding:2rem">
                 DB Error: '.$e->getMessage().'<br><br>
                 Make sure MySQL is running and <code>attendance_db</code> exists.</h2>');
        }
    }
    return $pdo;
}

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Secure session settings for HTTPS
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,   // only send cookie over HTTPS
            'httponly' => true,   // prevent JS access to session cookie
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function requireLogin(): void {
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php'); exit;
    }
}

function isAdmin(): bool {
    startSession();
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) { header('Location: dashboard.php'); exit; }
}

function currentUser(): array {
    startSession();
    return [
        'id'   => $_SESSION['user_id']   ?? 0,
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
    ];
}

function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function flashSet(string $type, string $msg): void {
    startSession();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flashGet(): ?array {
    startSession();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function lvClass(int $level): string {
    return match($level) { 3=>'lv3', 4=>'lv4', 5=>'lv5', default=>'' };
}

function lvLabel(int $level): string {
    return match($level) {
        3 => 'Level 3 — Information & Digital Technologies',
        4 => 'Level 4 — Computing',
        5 => 'Level 5 — Computing',
        default => 'Level '.$level
    };
}

function lvCourseCount(int $level): int {
    return match($level) { 3=>7, 4=>11, 5=>8, default=>0 };
}

// Auto-enroll a student into all courses of their level
function autoEnroll(int $studentId, int $level): void {
    db()->prepare("INSERT IGNORE INTO enrollments (student_id, class_id)
                   SELECT ?, id FROM classes WHERE level = ?")
       ->execute([$studentId, $level]);
}
