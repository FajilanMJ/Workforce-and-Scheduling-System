<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminPortalController.php';
require_once __DIR__ . '/../../models/Admin/AdminSettingsModel.php';

// Store a one-time flash banner message safely.
function admin_settings_set_flash(string $type, string $message): void
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

// Verify submitted CSRF token safely.
function admin_settings_check_csrf(string $token): bool
{
    if (function_exists('verify_csrf_token')) {
        return verify_csrf_token($token);
    }

    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    return $sessionToken !== '' && hash_equals($sessionToken, $token);
}

// Safely load the Google OAuth client and token path from config/google_oauth.php.
function admin_settings_get_google_oauth_config(): ?array
{
    $configFile = __DIR__ . '/../../config/google_oauth.php';
    if (!is_file($configFile)) {
        return null;
    }

    try {
        $config = require $configFile;
        return is_array($config) ? $config : null;
    } catch (Throwable $e) {
        error_log('Google OAuth config load failed: ' . $e->getMessage());
        return null;
    }
}

// Dispatch verification code email via existing mail helpers or the Google OAuth Gmail client.
function admin_settings_dispatch_verification_email(string $recipientEmail, string $recipientName, string $code): bool
{
    $mailFiles = [
        __DIR__ . '/../../includes/gmail_mailer.php',
        __DIR__ . '/../../includes/mailer.php',
        __DIR__ . '/../../includes/mail.php',
        __DIR__ . '/../../includes/gmail.php',
    ];

    foreach ($mailFiles as $file) {
        if (is_file($file)) {
            require_once $file;
        }
    }

    $subject = COMPANY_NAME . ' — Administrator Email Verification Code';
    $bodyText = "Hello {$recipientName},\n\nYour 6-digit verification code is: {$code}\n\nThis code expires in 15 minutes.";
    $bodyHtml = '<p>Hello ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Your 6-digit administrator email verification code is:</p>'
        . '<h2 style="letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p>This code expires in 15 minutes.</p>';

    try {
        if (function_exists('send_system_email')) {
            return (bool) send_system_email($recipientEmail, $subject, $bodyHtml, $bodyText);
        }
        if (function_exists('send_gmail_message')) {
            return (bool) send_gmail_message($recipientEmail, $subject, $bodyHtml, $bodyText);
        }
        if (function_exists('send_email')) {
            return (bool) send_email($recipientEmail, $subject, $bodyHtml, $bodyText);
        }

        // Send directly through the configured Google OAuth Gmail API client.
        $oauthConfig = admin_settings_get_google_oauth_config();
        $client = is_array($oauthConfig) ? ($oauthConfig['client'] ?? null) : null;
        $tokenFile = is_array($oauthConfig) ? (string) ($oauthConfig['token_file'] ?? '') : '';

        if (
            $client instanceof \Google\Client
            && $tokenFile !== ''
            && is_file($tokenFile)
            && class_exists(\Google\Service\Gmail::class)
        ) {
            $tokenData = json_decode((string) file_get_contents($tokenFile), true);
            if (!is_array($tokenData) || (empty($tokenData['access_token']) && empty($tokenData['refresh_token']))) {
                return false;
            }

            $client->setAccessToken($tokenData);

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $client->getRefreshToken();
                if (!$refreshToken) {
                    return false;
                }

                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                if (!empty($newToken['error'])) {
                    return false;
                }

                $mergedToken = array_merge($tokenData, $client->getAccessToken());
                file_put_contents($tokenFile, json_encode($mergedToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $safeTo = str_replace(["\r", "\n"], '', trim($recipientEmail));
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

            $mimeMessage = "To: {$safeTo}\r\n"
                . "Subject: {$encodedSubject}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($bodyHtml));

            $rawMessage = rtrim(strtr(base64_encode($mimeMessage), '+/', '-_'), '=');

            $gmailService = new \Google\Service\Gmail($client);
            $gmailMessage = new \Google\Service\Gmail\Message();
            $gmailMessage->setRaw($rawMessage);
            $gmailService->users_messages->send('me', $gmailMessage);

            return true;
        }
    } catch (Throwable $e) {
        error_log('Admin verification email dispatch failed: ' . $e->getMessage());
        return false;
    }

    return false;
}

// Handle optional administrator profile picture upload and return its relative path.
function admin_settings_handle_avatar_upload(array $fileInput, int $adminId): array
{
    if (empty($fileInput['name']) || ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }

    if (($fileInput['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Profile picture upload failed. Please try a smaller image.'];
    }

    if (($fileInput['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Profile picture must be 2 MB or smaller.'];
    }

    $tmpPath = (string) ($fileInput['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $tmpPath !== '' ? (string) $finfo->file($tmpPath) : '';

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimes[$mime])) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, or WebP images are allowed.'];
    }

    $uploadDir = __DIR__ . '/../../assets/uploads/avatars';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'message' => 'Unable to prepare avatar upload directory.'];
    }

    $filename = 'admin_' . $adminId . '_' . bin2hex(random_bytes(4)) . '.' . $allowedMimes[$mime];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        return ['ok' => false, 'message' => 'Unable to save uploaded profile picture.'];
    }

    return [
        'ok' => true,
        'path' => 'assets/uploads/avatars/' . $filename,
    ];
}

// Process and render the administrator Settings page.
function handle_admin_settings_page(mysqli $conn, string $baseUrl = '/Scheduling_System'): void
{
    $context = admin_require_portal_context($conn, $baseUrl);
    extract($context);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if (!admin_settings_check_csrf($submittedToken)) {
            admin_settings_set_flash('error', 'Security verification failed. Please refresh and try again.');
            redirect($baseUrl . '/admin/settings.php');
        }

        $action = (string) ($_POST['action'] ?? '');
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        switch ($action) {
            case 'update_profile':
                $removePhoto = !empty($_POST['remove_picture']);
                $upload = $removePhoto
                    ? ['ok' => true, 'path' => null]
                    : admin_settings_handle_avatar_upload($_FILES['profile_picture'] ?? [], $adminId);

                if (!$upload['ok']) {
                    admin_settings_set_flash('error', (string) $upload['message']);
                    break;
                }

                $fullNameInput = (string) ($_POST['full_name'] ?? '');
                $positionInput = (string) ($_POST['position'] ?? '');
                $result = admin_update_profile_settings(
                    $conn,
                    $adminId,
                    $fullNameInput,
                    $positionInput,
                    $upload['path'],
                    $removePhoto,
                    $ipAddress
                );

                if ($result['ok'] && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['full_name'] = trim($fullNameInput);
                }

                admin_settings_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'send_email_verification':
                $targetEmail = trim((string) ($_POST['new_email'] ?? $adminEmail));
                $result = admin_start_email_verification($conn, $adminId, $targetEmail, $ipAddress);

                if ($result['ok']) {
                    $sent = admin_settings_dispatch_verification_email(
                        (string) $result['email'],
                        $adminName,
                        (string) $result['code']
                    );

                    if ($sent) {
                        admin_settings_set_flash('success', $result['message']);
                    } else {
                        admin_settings_set_flash(
                            'warning',
                            "Verification started for {$result['email']}, but automated email delivery could not send. Use code: {$result['code']}"
                        );
                    }
                } else {
                    admin_settings_set_flash('error', $result['message']);
                }
                break;

            case 'confirm_email_verification':
                $code = (string) ($_POST['verification_code'] ?? '');
                $result = admin_confirm_email_verification($conn, $adminId, $code, $ipAddress);

                if ($result['ok'] && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['email'] = (string) $result['email'];
                }

                admin_settings_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'cancel_email_verification':
                admin_cancel_email_verification($conn, $adminId);
                admin_settings_set_flash('info', 'Pending email change canceled.');
                break;

            case 'change_password':
                $result = admin_change_own_password(
                    $conn,
                    $adminId,
                    (string) ($_POST['current_password'] ?? ''),
                    (string) ($_POST['new_password'] ?? ''),
                    (string) ($_POST['confirm_password'] ?? ''),
                    $ipAddress
                );
                admin_settings_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'regenerate_recovery_codes':
                $result = admin_regenerate_recovery_codes($conn, $adminId, $ipAddress);
                if ($result['ok']) {
                    $_SESSION['generated_recovery_codes'] = $result['codes'];
                }
                admin_settings_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'save_workforce_settings':
                $result = admin_save_workforce_settings($conn, $adminId, $_POST, $ipAddress);
                admin_settings_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'manage_category':
                $result = admin_manage_job_category(
                    $conn,
                    $adminId,
                    (string) ($_POST['mode'] ?? ''),
                    (string) ($_POST['category_name'] ?? ''),
                    (string) ($_POST['description'] ?? ''),
                    (int) ($_POST['category_id'] ?? 0),
                    $ipAddress
                );
                admin_settings_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            default:
                admin_settings_set_flash('error', 'Unknown settings action requested.');
                break;
        }

        redirect($baseUrl . '/admin/settings.php');
    }

    // Load settings view data.
    $emailState = admin_get_email_verification_state($conn, $adminId, $adminEmail);
    $activeRecoveryCodesCount = admin_count_active_recovery_codes($conn, $adminId);
    $workforceSettings = admin_get_workforce_settings($conn);
    $allCategories = admin_get_all_job_categories($conn);

    // Check C:\xampp\secure\Scheduling_System\google-token.json via config/google_oauth.php.
    $oauthConfig = admin_settings_get_google_oauth_config();
    $tokenFile = is_array($oauthConfig) ? (string) ($oauthConfig['token_file'] ?? '') : '';
    $gmailTokenExists = false;

    if ($tokenFile !== '' && is_file($tokenFile)) {
        $decodedToken = json_decode((string) file_get_contents($tokenFile), true);
        $gmailTokenExists = is_array($decodedToken)
            && (!empty($decodedToken['access_token']) || !empty($decodedToken['refresh_token']));
    }

    $generatedRecoveryCodes = [];
    if (!empty($_SESSION['generated_recovery_codes']) && is_array($_SESSION['generated_recovery_codes'])) {
        $generatedRecoveryCodes = $_SESSION['generated_recovery_codes'];
        unset($_SESSION['generated_recovery_codes']);
    }

    $page = 'settings';
    $pageTitle = 'Settings';

    require __DIR__ . '/../../views/admin/settings.php';
}