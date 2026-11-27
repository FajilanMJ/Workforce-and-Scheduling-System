<?php
declare(strict_types=1);

// --- Schema Guard & Shared Helpers ---

function admin_ensure_staff_schema(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $conn->query(
        'CREATE TABLE IF NOT EXISTS staff_job_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL,
            category_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_category (staff_id, category_id),
            KEY idx_category_id (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $colRes = $conn->query("SHOW COLUMNS FROM users LIKE 'primary_category_id'");
    if ($colRes && $colRes->num_rows === 0) {
        $conn->query('ALTER TABLE users ADD COLUMN primary_category_id INT NULL DEFAULT NULL AFTER position');
    }
}

if (!function_exists('admin_format_time')) {
    function admin_format_time(?string $timeValue): string
    {
        if ($timeValue === null || trim($timeValue) === '') {
            return '—';
        }

        $timestamp = strtotime($timeValue);
        return $timestamp !== false ? date('g:i A', $timestamp) : $timeValue;
    }
}

if (!function_exists('admin_get_job_categories')) {
    function admin_get_job_categories(mysqli $conn): array
    {
        $result = $conn->query(
            'SELECT id, category_name, description
             FROM job_categories
             WHERE is_active = 1
             ORDER BY category_name ASC'
        );

        if (!$result) {
            return [];
        }

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }

        return $categories;
    }
}

function admin_staff_write_audit(
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

// --- Staff Directory Query ---

function admin_get_staff_directory(
    mysqli $conn,
    string $today,
    string $search = '',
    string $statusFilter = 'all',
    int $categoryFilter = 0
): array {
    admin_ensure_staff_schema($conn);

    $sql = "SELECT
                u.id,
                u.employee_id,
                u.full_name,
                u.email,
                u.position,
                u.primary_category_id,
                u.account_status,
                pjc.category_name AS primary_category_name,
                s.id AS today_schedule_id,
                s.start_time AS today_start_time,
                s.end_time AS today_end_time,
                s.notes AS today_notes,
                a.check_in_time AS today_check_in,
                a.check_out_time AS today_check_out,
                a.attendance_status AS today_attendance_status
            FROM users u
            LEFT JOIN job_categories pjc ON pjc.id = u.primary_category_id
            LEFT JOIN schedules s
                ON s.staff_id = u.id
                AND s.schedule_date = ?
                AND s.status <> 'cancelled'
            LEFT JOIN attendance a
                ON a.schedule_id = s.id AND a.staff_id = u.id
            WHERE u.role = 'staff'";

    $types = 's';
    $params = [$today];

    if ($search !== '') {
        $sql .= ' AND (u.full_name LIKE ? OR u.employee_id LIKE ? OR u.email LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($categoryFilter > 0) {
        $sql .= ' AND (u.primary_category_id = ? OR EXISTS (
                    SELECT 1 FROM staff_job_permissions sjp
                    WHERE sjp.staff_id = u.id AND sjp.category_id = ?
                  ))';
        $types .= 'ii';
        $params[] = $categoryFilter;
        $params[] = $categoryFilter;
    }

    $sql .= ' ORDER BY u.full_name ASC, s.start_time ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $staffById = [];
    while ($row = $res->fetch_assoc()) {
        $id = (int) $row['id'];
        if (!isset($staffById[$id])) {
            $row['permitted_category_ids'] = [];
            $row['permitted_categories'] = [];
            $staffById[$id] = $row;
        }
    }
    $stmt->close();

    if ($staffById === []) {
        return [];
    }

    $permRes = $conn->query(
        'SELECT sjp.staff_id, sjp.category_id, jc.category_name
         FROM staff_job_permissions sjp
         INNER JOIN job_categories jc ON jc.id = sjp.category_id
         ORDER BY jc.category_name ASC'
    );
    if ($permRes) {
        while ($p = $permRes->fetch_assoc()) {
            $sid = (int) $p['staff_id'];
            if (isset($staffById[$sid])) {
                $catId = (int) $p['category_id'];
                $catName = (string) $p['category_name'];
                $staffById[$sid]['permitted_category_ids'][] = $catId;
                $staffById[$sid]['permitted_categories'][] = [
                    'id' => $catId,
                    'name' => $catName,
                ];
            }
        }
    }

    $filtered = [];
    foreach ($staffById as $member) {
        $accountStatus = (string) ($member['account_status'] ?? 'active');
        $notes = (string) ($member['today_notes'] ?? '');
        $attStatus = (string) ($member['today_attendance_status'] ?? '');
        $hasShift = !empty($member['today_schedule_id']);
        $isOnLeave = $hasShift && stripos($notes, '[ON LEAVE]') !== false;
        $ended = $hasShift && strtotime($today . ' ' . $member['today_end_time']) < time();

        if ($accountStatus === 'inactive') {
            $displayStatus = 'inactive';
        } elseif ($isOnLeave) {
            $displayStatus = 'on_leave';
        } elseif ($attStatus === 'late') {
            $displayStatus = 'late';
        } elseif (!empty($member['today_check_in']) || in_array($attStatus, ['present', 'completed'], true)) {
            $displayStatus = 'present';
        } elseif ($hasShift && ($attStatus === 'absent' || $ended)) {
            $displayStatus = 'absent';
        } elseif ($hasShift) {
            $displayStatus = 'scheduled';
        } else {
            $displayStatus = 'active';
        }

        $member['display_status'] = $displayStatus;

        $matches = match ($statusFilter) {
            'present' => $displayStatus === 'present' || $displayStatus === 'late',
            'late' => $displayStatus === 'late',
            'on_leave' => $displayStatus === 'on_leave',
            'absent' => $displayStatus === 'absent',
            'scheduled' => $displayStatus === 'scheduled',
            'active' => $accountStatus === 'active',
            'inactive' => $accountStatus === 'inactive',
            default => true,
        };

        if ($matches) {
            $filtered[] = $member;
        }
    }

    return $filtered;
}

// --- Create Staff & Shift Permissions ---

function admin_create_staff_account(mysqli $conn, int $adminId, array $input, string $ipAddress): array
{
    admin_ensure_staff_schema($conn);

    $fullName = trim((string) ($input['full_name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $position = trim((string) ($input['position'] ?? 'Staff'));
    $primaryCatId = (int) ($input['primary_category_id'] ?? 0);
    $permittedIds = array_map('intval', (array) ($input['permitted_categories'] ?? []));
    $tempPassword = trim((string) ($input['temp_password'] ?? ''));

    if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please enter a valid full name and email address.'];
    }

    if (strlen($tempPassword) < 8) {
        $tempPassword = 'ML@' . strtoupper(bin2hex(random_bytes(3))) . '26';
    }

    if ($primaryCatId > 0 && !in_array($primaryCatId, $permittedIds, true)) {
        $permittedIds[] = $primaryCatId;
    }

    $empId = 'ML-' . strtoupper(bin2hex(random_bytes(4)));
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
    $catParam = $primaryCatId > 0 ? $primaryCatId : null;
    $posParam = $position !== '' ? $position : 'Staff';

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "INSERT INTO users (
                employee_id, full_name, email, password_hash, role, position,
                primary_category_id, account_status, must_change_password
             ) VALUES (?, ?, ?, ?, 'staff', ?, ?, 'active', 1)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare staff insert.');
        }

        $stmt->bind_param('sssssi', $empId, $fullName, $email, $hash, $posParam, $catParam);
        $stmt->execute();
        $newStaffId = (int) $conn->insert_id;
        $stmt->close();

        if ($permittedIds !== []) {
            $permStmt = $conn->prepare('INSERT IGNORE INTO staff_job_permissions (staff_id, category_id) VALUES (?, ?)');
            if ($permStmt) {
                foreach (array_unique($permittedIds) as $cid) {
                    if ($cid > 0) {
                        $permStmt->bind_param('ii', $newStaffId, $cid);
                        $permStmt->execute();
                    }
                }
                $permStmt->close();
            }
        }

        admin_staff_write_audit(
            $conn,
            $adminId,
            'create_staff',
            'users',
            (string) $newStaffId,
            "Created staff account {$fullName} ({$empId}).",
            $ipAddress
        );

        $conn->commit();

        return [
            'ok' => true,
            'message' => "Created {$fullName} ({$empId}). Temporary password: {$tempPassword}",
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Unable to create staff account. Email may already exist.'];
    }
}

function admin_update_staff_permissions(
    mysqli $conn,
    int $adminId,
    int $staffId,
    string $position,
    ?int $primaryCatId,
    array $permittedCategories,
    string $ipAddress
): array {
    admin_ensure_staff_schema($conn);

    if ($staffId <= 0) {
        return ['ok' => false, 'message' => 'Invalid staff member selected.'];
    }

    $permittedIds = array_map('intval', $permittedCategories);
    if ($primaryCatId !== null && $primaryCatId > 0 && !in_array($primaryCatId, $permittedIds, true)) {
        $permittedIds[] = $primaryCatId;
    }

    $posParam = trim($position) !== '' ? trim($position) : 'Staff';

    $conn->begin_transaction();

    try {
        $upd = $conn->prepare("UPDATE users SET position = ?, primary_category_id = ? WHERE id = ? AND role = 'staff'");
        if (!$upd) {
            throw new RuntimeException('Unable to update staff profile.');
        }
        $upd->bind_param('sii', $posParam, $primaryCatId, $staffId);
        $upd->execute();
        $upd->close();

        $del = $conn->prepare('DELETE FROM staff_job_permissions WHERE staff_id = ?');
        if ($del) {
            $del->bind_param('i', $staffId);
            $del->execute();
            $del->close();
        }

        if ($permittedIds !== []) {
            $ins = $conn->prepare('INSERT IGNORE INTO staff_job_permissions (staff_id, category_id) VALUES (?, ?)');
            if ($ins) {
                foreach (array_unique($permittedIds) as $cid) {
                    if ($cid > 0) {
                        $ins->bind_param('ii', $staffId, $cid);
                        $ins->execute();
                    }
                }
                $ins->close();
            }
        }

        admin_staff_write_audit(
            $conn,
            $adminId,
            'update_staff_roles',
            'users',
            (string) $staffId,
            "Updated shift roles and permissions for staff #{$staffId}.",
            $ipAddress
        );

        $conn->commit();

        return ['ok' => true, 'message' => 'Staff shift permissions updated successfully.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => 'Unable to update staff shift permissions.'];
    }
}

// --- Status & Shift Takeover ---

function admin_update_staff_status(
    mysqli $conn,
    int $adminId,
    int $staffId,
    string $statusAction,
    string $targetDate,
    string $leaveEndDate,
    string $reason,
    string $ipAddress
): array {
    if ($staffId <= 0) {
        return ['ok' => false, 'message' => 'Invalid staff member selected.'];
    }

    $cleanReason = trim($reason);
    $noteSuffix = $cleanReason !== '' ? "[ON LEAVE: {$cleanReason}]" : '[ON LEAVE]';

    if ($statusAction === 'on_leave') {
        $endDate = strtotime($leaveEndDate) >= strtotime($targetDate) ? $leaveEndDate : $targetDate;
        $stmt = $conn->prepare(
            "UPDATE schedules
             SET notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?))
             WHERE staff_id = ?
               AND schedule_date BETWEEN ? AND ?
               AND status = 'scheduled'
               AND COALESCE(notes, '') NOT LIKE '%[ON LEAVE]%'"
        );
        if ($stmt) {
            $stmt->bind_param('siss', $noteSuffix, $staffId, $targetDate, $endDate);
            $stmt->execute();
            $stmt->close();
        }

        admin_staff_write_audit(
            $conn,
            $adminId,
            'mark_staff_leave',
            'users',
            (string) $staffId,
            "Marked staff #{$staffId} On Leave ({$targetDate} to {$endDate}).",
            $ipAddress
        );

        return ['ok' => true, 'message' => 'Employee marked On Leave and flagged for shift takeover.'];
    }

    if ($statusAction === 'present' || $statusAction === 'absent') {
        $schedStmt = $conn->prepare(
            "SELECT id, start_time FROM schedules
             WHERE staff_id = ? AND schedule_date = ? AND status <> 'cancelled'
             ORDER BY start_time ASC LIMIT 1"
        );
        if (!$schedStmt) {
            return ['ok' => false, 'message' => 'Unable to look up shift for selected date.'];
        }
        $schedStmt->bind_param('is', $staffId, $targetDate);
        $schedStmt->execute();
        $shift = $schedStmt->get_result()->fetch_assoc();
        $schedStmt->close();

        if (!$shift) {
            return ['ok' => false, 'message' => "No scheduled shift found for that employee on {$targetDate}."];
        }

        $scheduleId = (int) $shift['id'];
        $checkInSql = $statusAction === 'present'
            ? ($targetDate === date('Y-m-d') ? date('Y-m-d H:i:s') : $targetDate . ' ' . $shift['start_time'])
            : null;

        $upsert = $conn->prepare(
            'INSERT INTO attendance (schedule_id, staff_id, check_in_time, check_out_time, attendance_status)
             VALUES (?, ?, ?, NULL, ?)
             ON DUPLICATE KEY UPDATE
                check_in_time = VALUES(check_in_time),
                attendance_status = VALUES(attendance_status)'
        );
        if ($upsert) {
            $upsert->bind_param('iiss', $scheduleId, $staffId, $checkInSql, $statusAction);
            $upsert->execute();
            $upsert->close();
        }

        admin_staff_write_audit(
            $conn,
            $adminId,
            'manual_staff_attendance',
            'attendance',
            (string) $scheduleId,
            "Set staff #{$staffId} status on {$targetDate} to {$statusAction}.",
            $ipAddress
        );

        return ['ok' => true, 'message' => 'Shift attendance status updated to ' . ucfirst($statusAction) . '.'];
    }

    if ($statusAction === 'active' || $statusAction === 'inactive') {
        $stmt = $conn->prepare("UPDATE users SET account_status = ? WHERE id = ? AND role = 'staff'");
        if ($stmt) {
            $stmt->bind_param('si', $statusAction, $staffId);
            $stmt->execute();
            $stmt->close();
        }

        admin_staff_write_audit(
            $conn,
            $adminId,
            'change_staff_account_status',
            'users',
            (string) $staffId,
            "Set staff #{$staffId} account status to {$statusAction}.",
            $ipAddress
        );

        return ['ok' => true, 'message' => 'Employee account status updated.'];
    }

    return ['ok' => false, 'message' => 'Invalid status selection.'];
}

function admin_get_takeover_shifts(mysqli $conn, string $today): array
{
    $stmt = $conn->prepare(
        "SELECT
            s.id,
            s.staff_id,
            s.assigned_category_id,
            s.schedule_date,
            s.start_time,
            s.end_time,
            s.notes,
            u.full_name AS staff_name,
            jc.category_name
         FROM schedules s
         INNER JOIN users u ON u.id = s.staff_id
         INNER JOIN job_categories jc ON jc.id = s.assigned_category_id
         LEFT JOIN attendance a
            ON a.schedule_id = s.id AND a.staff_id = s.staff_id
         WHERE s.schedule_date >= ?
           AND s.status = 'scheduled'
           AND a.check_in_time IS NULL
         ORDER BY (COALESCE(s.notes, '') LIKE '%[ON LEAVE%') DESC, s.schedule_date ASC, s.start_time ASC"
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $today);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $row['is_on_leave'] = stripos((string) ($row['notes'] ?? ''), '[ON LEAVE') !== false;
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function admin_reassign_shift_takeover(
    mysqli $conn,
    int $adminId,
    int $scheduleId,
    int $newStaffId,
    string $takeoverReason,
    string $ipAddress
): array {
    if ($scheduleId <= 0 || $newStaffId <= 0) {
        return ['ok' => false, 'message' => 'Please select both a shift and a replacement employee.'];
    }

    $cleanReason = trim($takeoverReason);
    $tag = $cleanReason !== '' ? "[Takeover: {$cleanReason}]" : '[Shift Takeover]';

    $stmt = $conn->prepare(
        "UPDATE schedules
         SET staff_id = ?,
             notes = TRIM(CONCAT(TRIM(REGEXP_REPLACE(COALESCE(notes, ''), '\\[ON LEAVE[^\\]]*\\]', '')), ' ', ?))
         WHERE id = ? AND status = 'scheduled'"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to execute shift takeover.'];
    }

    $stmt->bind_param('isi', $newStaffId, $tag, $scheduleId);
    $stmt->execute();
    $stmt->close();

    admin_staff_write_audit(
        $conn,
        $adminId,
        'shift_takeover',
        'schedules',
        (string) $scheduleId,
        "Reassigned shift #{$scheduleId} to replacement staff #{$newStaffId}.",
        $ipAddress
    );

    return ['ok' => true, 'message' => 'Shift takeover completed successfully.'];
}

// --- Password Reset & Account Removal ---

function admin_reset_staff_password(
    mysqli $conn,
    int $adminId,
    int $staffId,
    string $newPassword,
    string $ipAddress
): array {
    if ($staffId <= 0) {
        return ['ok' => false, 'message' => 'Invalid staff member selected.'];
    }

    $tempPassword = trim($newPassword);
    if (strlen($tempPassword) < 8) {
        return ['ok' => false, 'message' => 'Temporary password must be at least 8 characters.'];
    }

    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "UPDATE users
         SET password_hash = ?, must_change_password = 1
         WHERE id = ? AND role = 'staff'"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to reset temporary password.'];
    }

    $stmt->bind_param('si', $hash, $staffId);
    $stmt->execute();
    $stmt->close();

    admin_staff_write_audit(
        $conn,
        $adminId,
        'reset_staff_password',
        'users',
        (string) $staffId,
        "Reset temporary password for staff #{$staffId}.",
        $ipAddress
    );

    return [
        'ok' => true,
        'message' => "Temporary password updated to {$tempPassword}. Staff will be prompted to change it on next login.",
    ];
}

function admin_delete_staff_account(
    mysqli $conn,
    int $adminId,
    int $staffId,
    string $separationReason,
    string $notes,
    string $ipAddress
): array {
    if ($staffId <= 0) {
        return ['ok' => false, 'message' => 'Invalid staff member selected.'];
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'staff'");
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to delete employee record.'];
    }

    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $stmt->close();

    $detail = "Deleted staff account #{$staffId} (Reason: {$separationReason}" . (trim($notes) !== '' ? " - {$notes}" : '') . ').';
    admin_staff_write_audit(
        $conn,
        $adminId,
        'delete_staff',
        'users',
        (string) $staffId,
        $detail,
        $ipAddress
    );

    return ['ok' => true, 'message' => 'Employee record removed.'];
}