<?php
namespace App\Core;
final class Auth {
    public static function start(): void {
        $config = require BASE_PATH . '/config/app.php';
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; worker-src 'self'; manifest-src 'self'; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        header('Cache-Control: no-store');
        ini_set('session.use_strict_mode', '1');
        session_name('spare_session');
        session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
        session_start();
        if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $config['session_idle_timeout']) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = time();
    }
    public static function user(): ?array {
        if (empty($_SESSION['user_id'])) return null;
        $stmt = db()->prepare('SELECT id, shop_id, name, email, role, session_version FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user=$stmt->fetch();
        if(!$user || (int)($_SESSION['auth_version']??1)!==(int)$user['session_version']) {
            unset($_SESSION['user_id'],$_SESSION['auth_version']);return null;
        }
        $_SESSION['auth_version']=(int)$user['session_version'];unset($user['session_version']);return $user;
    }
    public static function logout(): void {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time()-42000, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }
}
