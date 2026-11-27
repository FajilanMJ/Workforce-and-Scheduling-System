<?php
declare(strict_types=1);

// --- Schema Inspection & Audit Helper ---

function admin_settings_schema(mysqli $conn): array
{
    static $schema = null;
    if (is_array($schema)) {
        return $schema;
    }

    $conn->query(
        'CREATE TABLE IF NOT EXISTS admin_email_verifications (
            admin_id INT PRIMARY KEY,
            verified_email VARCHAR(255) NULL DEFAULT NULL,
            pending_email VARCHAR(255) NULL DEFAULT NULL,
            code_hash VARCHAR(255) NULL DEFAULT NULL,
            expires_at DATETIME NULL DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS admin_recovery_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_admin_id (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $conn->query(
        'CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_by INT NULL DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $getCols = static function (string $table) use ($conn): array {
        $cols = [];
        $res = $conn->query("SHOW COLUMNS FROM `{$table}`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cols[] = (string) ($row['Field'] ?? '');
            }
        }
        return $cols;
    };

    $verCols = $getCols('admin_email_verifications');
    $recCols = $getCols('admin_recovery_codes');
    $setCols = $getCols('system_settings');

    $verIdCol = 'admin_id';
    foreach (['admin_id', 'user_id', 'staff_id', 'account_id', 'id'] as $candidate) {
        if (in_array($candidate, $verCols, true)) {
            $verIdCol = $candidate;
            break;
        }
    }

    $recIdCol = 'admin_id';
    foreach (['admin_id', 'user_id', 'admin_user_id', 'account_id', 'staff_id'] as $candidate) {
        if (in_array($candidate, $recCols, true)) {
            $recIdCol = $candidate;
            break;
        }
    }
    if (!in_array($recIdCol, $recCols, true)) {
        foreach ($recCols as $col) {
            if ($col !== 'id' && str_ends_with($col, '_id')) {
                $recIdCol = $col;
                break;
            }
        }
    }

    $schema = [
        'ver_id' => $verIdCol,
        'ver_code' => in_array('code_hash', $verCols, true)
            ? 'code_hash'
            : (in_array('verification_code', $verCols, true) ? 'verification_code' : 'code'),
        'rec_id' => $recIdCol,
        'rec_code' => in_array('code_hash', $recCols, true) ? 'code_hash' : 'code',
        'rec_has_used_at' => in_array('used_at', $recCols, true),
        'set_key' => in_array('setting_key', $setCols, true)
            ? 'setting_key'
            : (in_array('config_key', $setCols, true) ? 'config_key' : 'name'),
        'set_val' => in_array('setting_value', $setCols, true)
            ? 'setting_value'
            : (in_array('config_value', $setCols, true) ? 'config_value' : 'value'),
        'set_has_updated_by' => in_array('updated_by', $setCols, true),
    ];

    return $schema;
}

function admin_settings_write_audit(
    mysqli $conn,
    int $adminId,
    string $action,
    string $entityType,
    string $entityId,
    string $description,
    string $ipAddress
): void {
    $safeIp = substr($ipAddress, 0, 45);
    $stmt = $conn->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, description, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    if ($stmt) {
        $stmt->bind_param('isssss', $adminId, $action, $entityType, $entityId, $description, $safeIp);
        $stmt->execute();
        $stmt->close();
    }
}

// --- 1. Administrator Profile ---

function admin_update_profile_settings(
    mysqli $conn,
    int $adminId,
    string $fullName,
    string $position,
    ?string $newAvatarPath,
    bool $removePhoto,
    string $ipAddress
): array {
    $cleanName = trim($fullName);
    $cleanPosition = trim($position) !== '' ? trim($position) : 'Administrator';

    if ($cleanName === '') {
        return ['ok' => false, 'message' => 'Full name is required.'];
    }

    $oldPath = '';
    $cur = $conn->prepare('SELECT profile_picture FROM users WHERE id = ? LIMIT 1');
    if ($cur) {
        $cur->bind_param('i', $adminId);
        $cur->execute();
        $row = $cur->get_result()->fetch_assoc();
        $oldPath = (string) ($row['profile_picture'] ?? '');
        $cur->close();
    }

    if ($removePhoto) {
        $stmt = $conn->prepare(
            "UPDATE users SET full_name = ?, position = ?, profile_picture = NULL WHERE id = ? AND role = 'admin'"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to update administrator profile.'];
        }
        $stmt->bind_param('ssi', $cleanName, $cleanPosition, $adminId);
    } elseif ($newAvatarPath !== null) {
        $stmt = $conn->prepare(
            "UPDATE users SET full_name = ?, position = ?, profile_picture = ? WHERE id = ? AND role = 'admin'"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to update administrator profile.'];
        }
        $stmt->bind_param('sssi', $cleanName, $cleanPosition, $newAvatarPath, $adminId);
    } else {
        $stmt = $conn->prepare(
            "UPDATE users SET full_name = ?, position = ? WHERE id = ? AND role = 'admin'"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to update administrator profile.'];
        }
        $stmt->bind_param('ssi', $cleanName, $cleanPosition, $adminId);
    }

    $stmt->execute();
    $stmt->close();

    if (($removePhoto || $newAvatarPath !== null) && str_starts_with($oldPath, 'assets/uploads/avatars/')) {
        $fullOldFile = __DIR__ . '/../../' . $oldPath;
        if (is_file($fullOldFile)) {
            @unlink($fullOldFile);
        }
    }

    admin_settings_write_audit(
        $conn,
        $adminId,
        'update_admin_profile',
        'users',
        (string) $adminId,
        'Updated administrator profile settings.',
        $ipAddress
    );

    return ['ok' => true, 'message' => 'Administrator profile updated successfully.'];
}

// --- 2. Email Verification ---

function admin_get_email_verification_state(mysqli $conn, int $adminId, string $currentEmail): array
{
    $schema = admin_settings_schema($conn);
    $idCol = $schema['ver_id'];

    $normalizedCurrent = strtolower(trim($currentEmail));
    $verifiedEmail = '';
    $pendingEmail = '';

    $stmt = $conn->prepare(
        "SELECT verified_email, pending_email, expires_at
         FROM admin_email_verifications
         WHERE `{$idCol}` = ?
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $verifiedEmail = strtolower(trim((string) ($row['verified_email'] ?? '')));
            $rawPending = strtolower(trim((string) ($row['pending_email'] ?? '')));
            $expiresAt = (string) ($row['expires_at'] ?? '');

            if ($rawPending !== '' && ($expiresAt === '' || strtotime($expiresAt) >= time())) {
                $pendingEmail = $rawPending;
            }
        }
    }

    $isVerified = ($verifiedEmail !== '' && $verifiedEmail === $normalizedCurrent && $pendingEmail === '');

    return [
        'isVerified' => $isVerified,
        'verifiedEmail' => $verifiedEmail,
        'pendingEmail' => $pendingEmail,
    ];
}

function admin_start_email_verification(
    mysqli $conn,
    int $adminId,
    string $targetEmail,
    string $ipAddress
): array {
    $schema = admin_settings_schema($conn);
    $idCol = $schema['ver_id'];
    $codeCol = $schema['ver_code'];

    $cleanEmail = strtolower(trim($targetEmail));
    if ($cleanEmail === '' || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL) || strlen($cleanEmail) > 254) {
        return ['ok' => false, 'message' => 'Please enter a valid email address.'];
    }

    $dup = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    if ($dup) {
        $dup->bind_param('si', $cleanEmail, $adminId);
        $dup->execute();
        $exists = (bool) $dup->get_result()->fetch_assoc();
        $dup->close();

        if ($exists) {
            return ['ok' => false, 'message' => 'That email address is already assigned to another account.'];
        }
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $storedCode = $codeCol === 'code_hash' ? password_hash($code, PASSWORD_DEFAULT) : $code;
    $expiresAt = date('Y-m-d H:i:s', time() + 900);

    $check = $conn->prepare("SELECT `{$idCol}` FROM admin_email_verifications WHERE `{$idCol}` = ? LIMIT 1");
    $hasRow = false;
    if ($check) {
        $check->bind_param('i', $adminId);
        $check->execute();
        $hasRow = (bool) $check->get_result()->fetch_assoc();
        $check->close();
    }

    if ($hasRow) {
        $stmt = $conn->prepare(
            "UPDATE admin_email_verifications
             SET pending_email = ?, `{$codeCol}` = ?, expires_at = ?
             WHERE `{$idCol}` = ?"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to start email verification.'];
        }
        $stmt->bind_param('sssi', $cleanEmail, $storedCode, $expiresAt, $adminId);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO admin_email_verifications (`{$idCol}`, pending_email, `{$codeCol}`, expires_at)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to start email verification.'];
        }
        $stmt->bind_param('isss', $adminId, $cleanEmail, $storedCode, $expiresAt);
    }

    $stmt->execute();
    $stmt->close();

    admin_settings_write_audit(
        $conn,
        $adminId,
        'request_email_verification',
        'users',
        (string) $adminId,
        "Requested verification code for {$cleanEmail}.",
        $ipAddress
    );

    return [
        'ok' => true,
        'email' => $cleanEmail,
        'code' => $code,
        'message' => "A 6-digit verification code has been sent to {$cleanEmail}.",
    ];
}

function admin_confirm_email_verification(
    mysqli $conn,
    int $adminId,
    string $code,
    string $ipAddress
): array {
    $schema = admin_settings_schema($conn);
    $idCol = $schema['ver_id'];
    $codeCol = $schema['ver_code'];

    $cleanCode = trim($code);
    if (!preg_match('/^\d{6}$/', $cleanCode)) {
        return ['ok' => false, 'message' => 'Please enter a valid 6-digit verification code.'];
    }

    $stmt = $conn->prepare(
        "SELECT pending_email, `{$codeCol}` AS stored_code, expires_at
         FROM admin_email_verifications
         WHERE `{$idCol}` = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to verify code.'];
    }

    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['pending_email']) || empty($row['stored_code'])) {
        return ['ok' => false, 'message' => 'No pending email verification request was found.'];
    }

    if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'That verification code has expired. Please request a new code.'];
    }

    $stored = (string) $row['stored_code'];
    $valid = str_starts_with($stored, '$')
        ? password_verify($cleanCode, $stored)
        : hash_equals($stored, $cleanCode);

    if (!$valid) {
        return ['ok' => false, 'message' => 'Incorrect verification code. Please check your email and try again.'];
    }

    $verifiedEmail = strtolower(trim((string) $row['pending_email']));

    $conn->begin_transaction();
    try {
        $updUser = $conn->prepare("UPDATE users SET email = ? WHERE id = ? AND role = 'admin'");
        if (!$updUser) {
            throw new RuntimeException('Unable to update user email.');
        }
        $updUser->bind_param('si', $verifiedEmail, $adminId);
        $updUser->execute();
        $updUser->close();

        $updVer = $conn->prepare(
            "UPDATE admin_email_verifications
             SET verified_email = ?,
                 pending_email = NULL,
                 `{$codeCol}` = NULL,
                 expires_at = NULL
             WHERE `{$idCol}` = ?"
        );
        if (!$updVer) {
            throw new RuntimeException('Unable to finalize verification status.');
        }
        $updVer->bind_param('si', $verifiedEmail, $adminId);
        $updVer->execute();
        $updVer->close();

        admin_settings_write_audit(
            $conn,
            $adminId,
            'verify_admin_email',
            'users',
            (string) $adminId,
            "Verified administrator email address ({$verifiedEmail}).",
            $ipAddress
        );

        $conn->commit();

        return [
            'ok' => true,
            'email' => $verifiedEmail,
            'message' => 'Administrator email address verified successfully.',
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Unable to complete email verification.'];
    }
}

function admin_cancel_email_verification(mysqli $conn, int $adminId): void
{
    $schema = admin_settings_schema($conn);
    $idCol = $schema['ver_id'];
    $codeCol = $schema['ver_code'];

    $stmt = $conn->prepare(
        "UPDATE admin_email_verifications
         SET pending_email = NULL, `{$codeCol}` = NULL, expires_at = NULL
         WHERE `{$idCol}` = ?"
    );
    if ($stmt) {
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $stmt->close();
    }
}

// --- 3. Password & Recovery Codes ---

function admin_change_own_password(
    mysqli $conn,
    int $adminId,
    string $currentPassword,
    string $newPassword,
    string $confirmPassword,
    string $ipAddress
): array {
    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        return ['ok' => false, 'message' => 'Please fill out all password fields.'];
    }

    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'New password must be at least 8 characters long.'];
    }

    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'message' => 'New password and confirmation do not match.'];
    }

    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to verify current password.'];
    }

    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
        return ['ok' => false, 'message' => 'Your current password is incorrect.'];
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $conn->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ? LIMIT 1');
    if (!$upd) {
        return ['ok' => false, 'message' => 'Unable to update password.'];
    }

    $upd->bind_param('si', $newHash, $adminId);
    $upd->execute();
    $upd->close();

    admin_settings_write_audit(
        $conn,
        $adminId,
        'change_own_password',
        'users',
        (string) $adminId,
        'Updated administrator account password.',
        $ipAddress
    );

    return ['ok' => true, 'message' => 'Administrator password updated successfully.'];
}

function admin_count_active_recovery_codes(mysqli $conn, int $adminId): int
{
    $schema = admin_settings_schema($conn);
    $idCol = $schema['rec_id'];
    $usedFilter = $schema['rec_has_used_at'] ? ' AND used_at IS NULL' : '';

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM admin_recovery_codes WHERE `{$idCol}` = ?{$usedFilter}");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0);
}

function admin_regenerate_recovery_codes(mysqli $conn, int $adminId, string $ipAddress): array
{
    $schema = admin_settings_schema($conn);
    $idCol = $schema['rec_id'];
    $codeCol = $schema['rec_code'];

    $plainCodes = [];
    for ($i = 0; $i < 8; $i++) {
        $partA = strtoupper(bin2hex(random_bytes(2)));
        $partB = strtoupper(bin2hex(random_bytes(2)));
        $plainCodes[] = $partA . '-' . $partB;
    }

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM admin_recovery_codes WHERE `{$idCol}` = ?");
        if ($del) {
            $del->bind_param('i', $adminId);
            $del->execute();
            $del->close();
        }

        $ins = $conn->prepare("INSERT INTO admin_recovery_codes (`{$idCol}`, `{$codeCol}`) VALUES (?, ?)");
        if (!$ins) {
            throw new RuntimeException('Unable to save recovery codes.');
        }

        foreach ($plainCodes as $code) {
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $ins->bind_param('is', $adminId, $hash);
            $ins->execute();
        }
        $ins->close();

        admin_settings_write_audit(
            $conn,
            $adminId,
            'regenerate_recovery_codes',
            'users',
            (string) $adminId,
            'Regenerated 8 backup administrator recovery codes.',
            $ipAddress
        );

        $conn->commit();

        return [
            'ok' => true,
            'codes' => $plainCodes,
            'message' => 'Generated 8 new single-use backup recovery codes.',
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Unable to generate recovery codes right now.'];
    }
}

// --- 4. Workforce Rules & Job Categories ---

function admin_get_workforce_settings(mysqli $conn): array
{
    $schema = admin_settings_schema($conn);
    $keyCol = $schema['set_key'];
    $valCol = $schema['set_val'];

    $defaults = [
        'late_grace_minutes' => '15',
        'early_check_in_minutes' => '30',
        'default_shift_start' => '06:00',
        'default_shift_end' => '14:00',
    ];

    $res = $conn->query("SELECT `{$keyCol}` AS k, `{$valCol}` AS v FROM system_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $key = (string) ($row['k'] ?? '');
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = (string) $row['v'];
            }
        }
    }

    return $defaults;
}

function admin_save_workforce_settings(
    mysqli $conn,
    int $adminId,
    array $input,
    string $ipAddress
): array {
    $schema = admin_settings_schema($conn);
    $keyCol = $schema['set_key'];
    $valCol = $schema['set_val'];
    $hasUpdatedBy = $schema['set_has_updated_by'];

    $lateGrace = max(0, min(120, (int) ($input['late_grace_minutes'] ?? 15)));
    $earlyCheckIn = max(0, min(180, (int) ($input['early_check_in_minutes'] ?? 30)));
    $defaultStart = trim((string) ($input['default_shift_start'] ?? '06:00'));
    $defaultEnd = trim((string) ($input['default_shift_end'] ?? '14:00'));

    if (!preg_match('/^\d{2}:\d{2}$/', $defaultStart)) {
        $defaultStart = '06:00';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $defaultEnd)) {
        $defaultEnd = '14:00';
    }

    $pairs = [
        'late_grace_minutes' => (string) $lateGrace,
        'early_check_in_minutes' => (string) $earlyCheckIn,
        'default_shift_start' => $defaultStart,
        'default_shift_end' => $defaultEnd,
    ];

    $checkStmt = $conn->prepare("SELECT `{$keyCol}` FROM system_settings WHERE `{$keyCol}` = ? LIMIT 1");
    $updStmt = $hasUpdatedBy
        ? $conn->prepare("UPDATE system_settings SET `{$valCol}` = ?, updated_by = ? WHERE `{$keyCol}` = ?")
        : $conn->prepare("UPDATE system_settings SET `{$valCol}` = ? WHERE `{$keyCol}` = ?");
    $insStmt = $hasUpdatedBy
        ? $conn->prepare("INSERT INTO system_settings (`{$keyCol}`, `{$valCol}`, updated_by) VALUES (?, ?, ?)")
        : $conn->prepare("INSERT INTO system_settings (`{$keyCol}`, `{$valCol}`) VALUES (?, ?)");

    if (!$checkStmt || !$updStmt || !$insStmt) {
        return ['ok' => false, 'message' => 'Unable to save workforce rules.'];
    }

    foreach ($pairs as $key => $val) {
        $checkStmt->bind_param('s', $key);
        $checkStmt->execute();
        $exists = (bool) $checkStmt->get_result()->fetch_assoc();

        if ($exists) {
            if ($hasUpdatedBy) {
                $updStmt->bind_param('sis', $val, $adminId, $key);
            } else {
                $updStmt->bind_param('ss', $val, $key);
            }
            $updStmt->execute();
        } else {
            if ($hasUpdatedBy) {
                $insStmt->bind_param('ssi', $key, $val, $adminId);
            } else {
                $insStmt->bind_param('ss', $key, $val);
            }
            $insStmt->execute();
        }
    }

    $checkStmt->close();
    $updStmt->close();
    $insStmt->close();

    admin_settings_write_audit(
        $conn,
        $adminId,
        'save_workforce_settings',
        'system_settings',
        'workforce_rules',
        'Updated workforce attendance thresholds and default shift hours.',
        $ipAddress
    );

    return ['ok' => true, 'message' => 'Workforce scheduling and attendance rules saved.'];
}

function admin_get_all_job_categories(mysqli $conn): array
{
    $res = $conn->query(
        'SELECT id, category_name, description, is_active
         FROM job_categories
         ORDER BY is_active DESC, category_name ASC'
    );

    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function admin_manage_job_category(
    mysqli $conn,
    int $adminId,
    string $mode,
    string $categoryName,
    string $description,
    int $categoryId,
    string $ipAddress
): array {
    if ($mode === 'add') {
        $cleanName = trim($categoryName);
        $cleanDesc = trim($description);

        if ($cleanName === '') {
            return ['ok' => false, 'message' => 'Please enter a job category name.'];
        }

        $check = $conn->prepare('SELECT id FROM job_categories WHERE LOWER(category_name) = LOWER(?) LIMIT 1');
        if ($check) {
            $check->bind_param('s', $cleanName);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                $existingId = (int) $existing['id'];
                $reEnable = $conn->prepare('UPDATE job_categories SET is_active = 1 WHERE id = ?');
                if ($reEnable) {
                    $reEnable->bind_param('i', $existingId);
                    $reEnable->execute();
                    $reEnable->close();
                }

                return ['ok' => true, 'message' => "Job category '{$cleanName}' is already listed and active."];
            }
        }

        $descParam = $cleanDesc !== '' ? $cleanDesc : null;
        $ins = $conn->prepare('INSERT INTO job_categories (category_name, description, is_active) VALUES (?, ?, 1)');
        if (!$ins) {
            return ['ok' => false, 'message' => 'Unable to add job category.'];
        }

        $ins->bind_param('ss', $cleanName, $descParam);
        $ins->execute();
        $newId = (int) $conn->insert_id;
        $ins->close();

        admin_settings_write_audit(
            $conn,
            $adminId,
            'add_job_category',
            'job_categories',
            (string) $newId,
            "Added job category '{$cleanName}'.",
            $ipAddress
        );

        return ['ok' => true, 'message' => "Added job category '{$cleanName}'."];
    }

    if ($mode === 'toggle' && $categoryId > 0) {
        $stmt = $conn->prepare('UPDATE job_categories SET is_active = 1 - is_active WHERE id = ?');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to toggle job category status.'];
        }

        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $stmt->close();

        admin_settings_write_audit(
            $conn,
            $adminId,
            'toggle_job_category',
            'job_categories',
            (string) $categoryId,
            "Toggled active state for job category #{$categoryId}.",
            $ipAddress
        );

        return ['ok' => true, 'message' => 'Job category status updated.'];
    }

    return ['ok' => false, 'message' => 'Invalid job category action.'];
}