<?php
namespace App\Controllers;
use App\Core\{Auth, Controller};
use Throwable;
final class AuthController extends Controller {
    public function login(): void {
        $error = ''; $email = '';
        try {
            if (current_user()) { redirect('/dashboard'); }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                $identity = hash('sha256', $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
                $ipIdentity = hash('sha256', 'ip|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
                $stmt = db()->prepare('SELECT COALESCE(MAX(attempt_count), 0) FROM (SELECT COUNT(*) AS attempt_count FROM login_attempts WHERE identity_hash IN (?, ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) GROUP BY identity_hash) AS attempts');
                $stmt->execute([$identity, $ipIdentity]);
                if ((int)$stmt->fetchColumn() >= 5) {
                    $error = 'Too many sign-in attempts. Please try again in 15 minutes.';
                } else {
                    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1'); $stmt->execute([$email]); $user = $stmt->fetch();
                    $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
                    if (!$user || !$valid || strlen($password) > 72 || !$user['active']) {
                        $stmt = db()->prepare('INSERT INTO login_attempts (identity_hash, attempted_at) VALUES (?, NOW()), (?, NOW())');
                        $stmt->execute([$identity, $ipIdentity]);
                        $error = 'Email or password is incorrect.';
                    } else {
                        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                        }
                        session_regenerate_id(true); $_SESSION = ['user_id' => $user['id'], 'auth_version'=>(int)$user['session_version'], 'last_activity' => time()];
                        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
                        db()->prepare('DELETE FROM login_attempts WHERE identity_hash = ? OR attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute([$identity]);
                        redirect('/dashboard');
                    }
                }
            }
        } catch (Throwable $exception) { error_log($exception->getMessage()); $error = 'Sign-in is temporarily unavailable. Please contact your administrator.'; }
        $this->view('auth/login', compact('error', 'email'));
    }
    public function logout(): void { Auth::logout(); redirect('/login'); }
}
