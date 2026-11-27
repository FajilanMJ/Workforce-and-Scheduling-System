<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/../models/UserModel.php')) {
    require_once __DIR__ . '/../models/UserModel.php';
}

function auth_set_flash(string $type, string $message): void
{
    if (function_exists('set_flash_message')) {
        set_flash_message($type, $message);
        return;
    }

    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function auth_redirect(string $url): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (function_exists('redirect')) {
        redirect($url);
    }

    header('Location: ' . $url);
    exit;
}

function auth_resolve_connection(?mysqli $conn): mysqli
{
    if ($conn instanceof mysqli) {
        return $conn;
    }

    require_once __DIR__ . '/../config/database.php';

    if (isset($conn) && $conn instanceof mysqli) {
        return $conn;
    }
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        return $GLOBALS['conn'];
    }
    if (function_exists('get_db_connection')) {
        $db = get_db_connection();
        if ($db instanceof mysqli) {
            return $db;
        }
    }

    throw new RuntimeException('Database connection is not initialized.');
}

function auth_lookup_user_by_email(mysqli $conn, string $email): ?array
{
    if (function_exists('user_find_by_email')) {
        $found = user_find_by_email($conn, $email);
        if (is_array($found)) {
            return $found;
        }
    }

    $stmt = $conn->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

function auth_establish_session(array $user): void
{
    if (function_exists('login_user')) {
        login_user($user);
    } else {
        session_regenerate_id(true);
    }

    $userId = (int) ($user['id'] ?? 0);
    $employeeId = (string) ($user['employee_id'] ?? '');
    $fullName = (string) ($user['full_name'] ?? '');
    $email = (string) ($user['email'] ?? '');
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $position = (string) ($user['position'] ?? '');
    $profilePic = (string) ($user['profile_picture'] ?? '');
    $mustChange = (int) ($user['must_change_password'] ?? 0);

    $_SESSION['user_id'] = $userId;
    $_SESSION['employee_id'] = $employeeId;
    $_SESSION['full_name'] = $fullName;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;
    $_SESSION['position'] = $position;
    $_SESSION['profile_picture'] = $profilePic;
    $_SESSION['must_change_password'] = $mustChange;
    $_SESSION['logged_in'] = true;
    $_SESSION['last_activity'] = time();

    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        $_SESSION['user'] = [];
    }

    $_SESSION['user'] = array_merge($_SESSION['user'], [
        'id' => $userId,
        'employee_id' => $employeeId,
        'full_name' => $fullName,
        'email' => $email,
        'role' => $role,
        'position' => $position,
        'profile_picture' => $profilePic,
        'must_change_password' => $mustChange,
    ]);
}

function auth_log_successful_login(mysqli $conn, int $userId, string $ipAddress): void
{
    if (function_exists('user_record_login')) {
        user_record_login($conn, $userId, $ipAddress);
        return;
    }

    $safeIp = substr($ipAddress, 0, 45);
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, description, ip_address)
         VALUES (?, 'login', 'users', ?, 'User signed in.', ?)"
    );
    if ($stmt) {
        $entityId = (string) $userId;
        $stmt->bind_param('iss', $userId, $entityId, $safeIp);
        $stmt->execute();
        $stmt->close();
    }
}

function auth_submit_password_help(mysqli $conn, string $email, string $employeeId, string $message): void
{
    if (function_exists('user_create_password_assistance_request')) {
        user_create_password_assistance_request($conn, $email, $employeeId, $message);
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO password_assistance_requests (submitted_email, submitted_employee_id, message) VALUES (?, ?, ?)'
    );
    if ($stmt) {
        $empParam = $employeeId !== '' ? $employeeId : null;
        $msgParam = $message !== '' ? $message : null;
        $stmt->bind_param('sss', $email, $empParam, $msgParam);
        $stmt->execute();
        $stmt->close();
    }
}

function handle_login_page(?mysqli $conn = null, string $baseUrl = '/Scheduling_System'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $conn = auth_resolve_connection($conn);

    // Redirect already signed-in users using the project's session check.
    $activeUser = function_exists('current_user')
        ? current_user()
        : ($_SESSION['user'] ?? ((!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) ? $_SESSION : null));

    if (is_array($activeUser) && !empty($activeUser['role'])) {
        $existingRole = strtolower(trim((string) $activeUser['role']));
        if ($existingRole === 'admin') {
            auth_redirect($baseUrl . '/admin/dashboard.php');
        }
        if ($existingRole === 'staff') {
            auth_redirect($baseUrl . '/staff/dashboard.php');
        }
    }

    $csrfToken = function_exists('generate_csrf_token')
        ? generate_csrf_token()
        : ($_SESSION['csrf_token'] ??= bin2hex(random_bytes(32)));

    $rawFlash = function_exists('get_flash_message') ? get_flash_message() : ($_SESSION['flash_message'] ?? null);
    if (!function_exists('get_flash_message')) {
        unset($_SESSION['flash_message']);
    }
    $flashMessage = is_array($rawFlash) && !empty($rawFlash['message']) ? $rawFlash : null;

    $errors = [];
    $errorField = ''; // 'email', 'password', or 'both'
    $fieldError = '';
    $oldEmail = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        $validCsrf = function_exists('verify_csrf_token')
            ? verify_csrf_token($submittedToken)
            : ($csrfToken !== '' && hash_equals($csrfToken, $submittedToken));

        if (!$validCsrf) {
            $errors[] = 'Security token expired. Please refresh and try again.';
        } else {
            $formAction = (string) ($_POST['form_action'] ?? 'login');

            if ($formAction === 'staff_password_help') {
                $helpEmail = strtolower(trim((string) ($_POST['help_email'] ?? '')));
                $helpEmpId = trim((string) ($_POST['help_employee_id'] ?? ''));
                $helpMsg = trim((string) ($_POST['help_message'] ?? ''));

                if (!filter_var($helpEmail, FILTER_VALIDATE_EMAIL)) {
                    auth_set_flash('error', 'Please enter a valid registered email address.');
                } else {
                    auth_submit_password_help($conn, $helpEmail, $helpEmpId, $helpMsg);
                    auth_set_flash('success', 'Your password assistance request has been sent to the administrator.');
                }

                auth_redirect($baseUrl . '/login.php');
            }

            // Standard login validation specifying which field is incorrect.
            $oldEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');

            if ($oldEmail === '' && $password === '') {
                $errors[] = 'Please enter your email address and password.';
                $errorField = 'both';
            } elseif ($oldEmail === '') {
                $fieldError = 'Please enter your email address.';
                $errors[] = $fieldError;
                $errorField = 'email';
            } elseif (!filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
                $fieldError = 'Please enter a valid email address format.';
                $errors[] = $fieldError;
                $errorField = 'email';
            } elseif ($password === '') {
                $fieldError = 'Please enter your password.';
                $errors[] = $fieldError;
                $errorField = 'password';
            } else {
                $user = auth_lookup_user_by_email($conn, $oldEmail);

                if ($user === null) {
                    $fieldError = 'Wrong email address — no account was found with that email.';
                    $errors[] = $fieldError;
                    $errorField = 'email';
                } else {
                    $accountStatus = strtolower(trim((string) ($user['account_status'] ?? $user['status'] ?? 'active')));
                    $storedHash = (string) ($user['password_hash'] ?? $user['password'] ?? '');
                    $passwordMatches = $storedHash !== '' && (
                        password_verify($password, $storedHash)
                        || (!str_starts_with($storedHash, '$') && hash_equals($storedHash, $password))
                    );

                    if ($accountStatus !== '' && $accountStatus !== 'active') {
                        $fieldError = 'This account is inactive. Please contact the administrator.';
                        $errors[] = $fieldError;
                        $errorField = 'email';
                    } elseif (!$passwordMatches) {
                        $fieldError = 'Wrong password — the password you entered is incorrect.';
                        $errors[] = $fieldError;
                        $errorField = 'password';
                    } else {
                        auth_establish_session($user);

                        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                        auth_log_successful_login($conn, (int) $user['id'], $ipAddress);

                        $role = strtolower(trim((string) ($user['role'] ?? '')));
                        if ($role === 'admin') {
                            auth_redirect($baseUrl . '/admin/dashboard.php');
                        }

                        if ((int) ($user['must_change_password'] ?? 0) === 1) {
                            auth_redirect($baseUrl . '/staff/profile.php');
                        }

                        auth_redirect($baseUrl . '/staff/dashboard.php');
                    }
                }
            }
        }
    }

    $pageTitle = 'Sign In';
    $companyName = defined('COMPANY_NAME') ? COMPANY_NAME : 'Mang Lechon Restaurant';
    $appName = defined('APP_NAME') ? APP_NAME : 'Workforce Management System';
    $logoUrl = $baseUrl . '/assets/images/company-logo.png';
    $backgroundImageUrl = $baseUrl . '/assets/images/login-bg.jpg';

    $loginView = is_file(__DIR__ . '/../views/auth/login.php')
        ? __DIR__ . '/../views/auth/login.php'
        : __DIR__ . '/../views/login.php';

    require $loginView;
}

function handle_logout_page(?mysqli $conn = null, string $baseUrl = '/Scheduling_System'): never
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = (int) ($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

    if ($userId > 0) {
        try {
            $db = auth_resolve_connection($conn);
            $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

            if (function_exists('user_record_logout')) {
                user_record_logout($db, $userId, $ipAddress);
            } else {
                $safeIp = substr($ipAddress, 0, 45);
                $stmt = $db->prepare(
                    "INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, description, ip_address)
                     VALUES (?, 'logout', 'users', ?, 'User signed out of the system.', ?)"
                );
                if ($stmt) {
                    $entityId = (string) $userId;
                    $stmt->bind_param('iss', $userId, $entityId, $safeIp);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            error_log('Logout audit log skipped: ' . $e->getMessage());
        }
    }

    if (function_exists('logout_user')) {
        logout_user();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    session_start();
    auth_set_flash('success', 'You have been signed out.');
    auth_redirect($baseUrl . '/login.php');
}