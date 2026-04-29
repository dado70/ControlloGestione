<?php

declare(strict_types=1);

class Auth
{
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        if (self::isLoggedIn() && isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
                self::logout();
                return;
            }
            $_SESSION['last_activity'] = time();
        }
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['utente_id']) && $_SESSION['utente_id'] > 0;
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . APP_URL . '/public/login.php');
            exit;
        }
    }

    public static function requireRole(string ...$ruoli): void
    {
        self::requireLogin();
        if (!in_array($_SESSION['ruolo'] ?? '', $ruoli, true)) {
            http_response_code(403);
            require BASE_DIR . '/public/403.php';
            exit;
        }
    }

    public static function login(string $username, string $password): bool
    {
        if (self::isLockedOut($username)) {
            return false;
        }

        $utente = Database::fetchOne(
            'SELECT id, username, password_hash, ruolo, attivo FROM utenti WHERE username = ?',
            [$username]
        );

        if (!$utente || !$utente['attivo'] || !password_verify($password, $utente['password_hash'])) {
            self::recordFailedAttempt($username);
            return false;
        }

        self::clearLoginAttempts($username);

        $_SESSION['utente_id']  = $utente['id'];
        $_SESSION['username']   = $utente['username'];
        $_SESSION['ruolo']      = $utente['ruolo'];
        $_SESSION['last_activity'] = time();

        // Carica l'azienda predefinita per l'utente
        $azienda = Database::fetchOne(
            'SELECT id_azienda FROM utenti_aziende WHERE id_utente = ? LIMIT 1',
            [$utente['id']]
        );
        $_SESSION['id_azienda'] = $azienda['id_azienda'] ?? null;

        self::auditLog('login', 'Accesso effettuato');
        return true;
    }

    public static function logout(): void
    {
        if (self::isLoggedIn()) {
            self::auditLog('logout', 'Disconnessione');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    public static function getUser(): array
    {
        return [
            'id'         => $_SESSION['utente_id'] ?? 0,
            'username'   => $_SESSION['username'] ?? '',
            'ruolo'      => $_SESSION['ruolo'] ?? '',
            'id_azienda' => $_SESSION['id_azienda'] ?? null,
        ];
    }

    public static function setAzienda(int $idAzienda): bool
    {
        if (!self::isLoggedIn()) {
            return false;
        }

        $ruolo = $_SESSION['ruolo'];
        if ($ruolo === 'superadmin') {
            $_SESSION['id_azienda'] = $idAzienda;
            return true;
        }

        $ok = Database::fetchOne(
            'SELECT id FROM utenti_aziende WHERE id_utente = ? AND id_azienda = ?',
            [$_SESSION['utente_id'], $idAzienda]
        );
        if ($ok) {
            $_SESSION['id_azienda'] = $idAzienda;
            return true;
        }
        return false;
    }

    public static function getIdAzienda(): ?int
    {
        return $_SESSION['id_azienda'] ?? null;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die('Token CSRF non valido.');
        }
    }

    private static function isLockedOut(string $username): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $result = Database::fetchOne(
            'SELECT COUNT(*) as tentativi FROM login_attempts
             WHERE (username = ? OR ip = ?) AND creato_il > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$username, $ip, LOGIN_LOCKOUT_TIME]
        );
        return ($result['tentativi'] ?? 0) >= MAX_LOGIN_ATTEMPTS;
    }

    private static function recordFailedAttempt(string $username): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        Database::query(
            'INSERT INTO login_attempts (username, ip) VALUES (?, ?)',
            [$username, $ip]
        );
    }

    private static function clearLoginAttempts(string $username): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        Database::query(
            'DELETE FROM login_attempts WHERE username = ? OR ip = ?',
            [$username, $ip]
        );
    }

    public static function auditLog(string $azione, string $dettaglio = ''): void
    {
        try {
            Database::query(
                'INSERT INTO audit_log (id_utente, username, azione, dettaglio, ip) VALUES (?, ?, ?, ?, ?)',
                [
                    $_SESSION['utente_id'] ?? null,
                    $_SESSION['username']  ?? 'sistema',
                    $azione,
                    $dettaglio,
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                ]
            );
        } catch (Throwable) {
            // Non bloccare l'app per un errore di log
        }
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function isSuperadmin(): bool
    {
        return ($_SESSION['ruolo'] ?? '') === 'superadmin';
    }

    public static function isAdmin(): bool
    {
        return in_array($_SESSION['ruolo'] ?? '', ['superadmin', 'admin'], true);
    }

    public static function canWrite(): bool
    {
        return in_array($_SESSION['ruolo'] ?? '', ['superadmin', 'admin', 'operatore'], true);
    }
}
