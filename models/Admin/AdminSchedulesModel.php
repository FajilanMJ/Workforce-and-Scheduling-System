<?php
declare(strict_types=1);

// --- Audit Helper ---

function admin_schedules_write_audit(
    mysqli $conn,
    int $adminId,
    string $action,
    string $entityId,
    string $description,
    string $ipAddress
): void {
    $entityType = 'schedules';
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

// --- Schedules Roster Query ---

function admin_get_schedules_list(mysqli $conn, array $filters): array
{
    $sql = "SELECT
                s.id,
                s.staff_id,
                s.assigned_category_id,
                s.schedule_date,
                s.start_time,
                s.end_time,
                s.task_description,
                s.work_location,
                s.notes,
                s.status,
                u.full_name AS staff_name,
                u.employee_id,
                u.position AS staff_position,
                jc.category_name,
                a.check_in_time,
                a.check_out_time,
                a.attendance_status
            FROM schedules s
            INNER JOIN users u ON u.id = s.staff_id
            INNER JOIN job_categories jc ON jc.id = s.assigned_category_id
            LEFT JOIN attendance a
                ON a.schedule_id = s.id AND a.staff_id = s.staff_id
            WHERE 1 = 1";

    $types = '';
    $params = [];

    $staffId = (int) ($filters['staff_id'] ?? 0);
    $categoryId = (int) ($filters['category_id'] ?? 0);
    $search = trim((string) ($filters['search'] ?? ''));

    if ($staffId > 0) {
        $sql .= ' AND s.staff_id = ?';
        $types .= 'i';
        $params[] = $staffId;
    }

    if ($categoryId > 0) {
        $sql .= ' AND s.assigned_category_id = ?';
        $types .= 'i';
        $params[] = $categoryId;
    }

    if ($search !== '') {
        $sql .= ' AND (u.full_name LIKE ? OR u.employee_id LIKE ? OR s.task_description LIKE ? OR s.work_location LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY s.schedule_date DESC, s.start_time ASC, u.full_name ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $row['is_on_leave'] = stripos((string) ($row['notes'] ?? ''), '[ON LEAVE]') !== false;
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

// --- Group Shifts Into One Compact Row Per Staff Member ---

function admin_get_compact_schedules_list(mysqli $conn, array $filters): array
{
    $rawShifts = admin_get_schedules_list($conn, $filters);
    $grouped = [];

    foreach ($rawShifts as $shift) {
        $staffId = (int) $shift['staff_id'];
        $schedDate = (string) $shift['schedule_date'];
        $status = (string) ($shift['status'] ?? 'scheduled');
        $isOnLeave = !empty($shift['is_on_leave']);

        if (!isset($grouped[$staffId])) {
            $grouped[$staffId] = [
                'staff_id' => $staffId,
                'staff_name' => (string) $shift['staff_name'],
                'employee_id' => (string) $shift['employee_id'],
                'staff_position' => (string) ($shift['staff_position'] ?? ''),
                'start_date' => $schedDate,
                'end_date' => $schedDate,
                'shift_count' => 0,
                'start_time' => (string) $shift['start_time'],
                'end_time' => (string) $shift['end_time'],
                'category_name' => (string) $shift['category_name'],
                'assigned_category_id' => (int) $shift['assigned_category_id'],
                'task_description' => (string) $shift['task_description'],
                'work_location' => (string) ($shift['work_location'] ?? ''),
                'has_leave' => false,
                'active_count' => 0,
                'completed_count' => 0,
                'cancelled_count' => 0,
                'latest_shift' => $shift,
                'shifts' => [],
            ];
        }

        if ($schedDate < $grouped[$staffId]['start_date']) {
            $grouped[$staffId]['start_date'] = $schedDate;
        }
        if ($schedDate > $grouped[$staffId]['end_date']) {
            $grouped[$staffId]['end_date'] = $schedDate;
            $grouped[$staffId]['latest_shift'] = $shift;
            $grouped[$staffId]['start_time'] = (string) $shift['start_time'];
            $grouped[$staffId]['end_time'] = (string) $shift['end_time'];
            $grouped[$staffId]['category_name'] = (string) $shift['category_name'];
            $grouped[$staffId]['assigned_category_id'] = (int) $shift['assigned_category_id'];
            $grouped[$staffId]['task_description'] = (string) $shift['task_description'];
            $grouped[$staffId]['work_location'] = (string) ($shift['work_location'] ?? '');
        }

        $grouped[$staffId]['shift_count']++;
        $grouped[$staffId]['shifts'][] = $shift;

        if ($isOnLeave) {
            $grouped[$staffId]['has_leave'] = true;
        }
        if ($status === 'cancelled') {
            $grouped[$staffId]['cancelled_count']++;
        } elseif ($status === 'completed' || !empty($shift['check_out_time'])) {
            $grouped[$staffId]['completed_count']++;
        } else {
            $grouped[$staffId]['active_count']++;
        }
    }

    return array_values($grouped);
}

// --- Assign Single or Multi-Day Shifts ---

function admin_assign_schedule_range(mysqli $conn, int $adminId, array $input, string $ipAddress): array
{
    if (function_exists('admin_ensure_staff_schema')) {
        admin_ensure_staff_schema($conn);
    }

    $staffId = (int) ($input['staff_id'] ?? 0);
    $categoryId = (int) ($input['assigned_category_id'] ?? $input['category_id'] ?? 0);
    $startDate = trim((string) ($input['start_date'] ?? $input['schedule_date'] ?? ''));
    $endDate = trim((string) ($input['end_date'] ?? $startDate));
    $startTime = trim((string) ($input['start_time'] ?? ''));
    $endTime = trim((string) ($input['end_time'] ?? ''));
    $location = trim((string) ($input['work_location'] ?? ''));
    $task = trim((string) ($input['task_description'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $weekdays = array_map('intval', (array) ($input['weekdays'] ?? []));
    $allowUnpermitted = !empty($input['allow_unpermitted']);

    if ($staffId <= 0 || $categoryId <= 0 || $startDate === '' || $startTime === '' || $endTime === '' || $task === '') {
        return ['ok' => false, 'message' => 'Please complete all required shift assignment fields.'];
    }

    if (strtotime($endDate) < strtotime($startDate)) {
        return ['ok' => false, 'message' => 'End date cannot be earlier than start date.'];
    }

    if (!$allowUnpermitted) {
        $permCheck = $conn->prepare(
            'SELECT 1 FROM staff_job_permissions WHERE staff_id = ? AND category_id = ? LIMIT 1'
        );
        if ($permCheck) {
            $permCheck->bind_param('ii', $staffId, $categoryId);
            $permCheck->execute();
            $permitted = (bool) $permCheck->get_result()->fetch_assoc();
            $permCheck->close();

            if (!$permitted) {
                return [
                    'ok' => false,
                    'message' => 'Employee does not hold that Shift Role yet. Check the override option or update their Shift Roles first.',
                ];
            }
        }
    }

    $stmt = $conn->prepare(
        "INSERT INTO schedules (
            staff_id, assigned_category_id, schedule_date, start_time, end_time,
            task_description, work_location, notes, status, created_by
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to prepare shift assignment.'];
    }

    $locParam = $location !== '' ? $location : null;
    $notesParam = $notes !== '' ? $notes : null;
    $createdCount = 0;

    $cursor = strtotime($startDate);
    $limit = strtotime($endDate);

    while ($cursor !== false && $cursor <= $limit) {
        $dayNum = (int) date('N', $cursor);
        if ($weekdays === [] || in_array($dayNum, $weekdays, true)) {
            $dateStr = date('Y-m-d', $cursor);
            $stmt->bind_param(
                'iissssssi',
                $staffId,
                $categoryId,
                $dateStr,
                $startTime,
                $endTime,
                $task,
                $locParam,
                $notesParam,
                $adminId
            );
            $stmt->execute();
            $createdCount++;
        }
        $cursor = strtotime('+1 day', $cursor);
    }

    $stmt->close();

    if ($createdCount === 0) {
        return ['ok' => false, 'message' => 'No shifts matched the selected dates and weekdays.'];
    }

    admin_schedules_write_audit(
        $conn,
        $adminId,
        'assign_schedule',
        (string) $staffId,
        "Assigned {$createdCount} shift(s) to staff #{$staffId} ({$startDate} to {$endDate}).",
        $ipAddress
    );

    return ['ok' => true, 'message' => "Assigned {$createdCount} shift(s) successfully."];
}

// --- Simplified Range Shift Takeover ---

function admin_takeover_staff_range(
    mysqli $conn,
    int $adminId,
    int $fromStaffId,
    int $toStaffId,
    string $startDate,
    string $endDate,
    string $reason,
    string $ipAddress
): array {
    if ($fromStaffId <= 0 || $toStaffId <= 0 || $fromStaffId === $toStaffId) {
        return ['ok' => false, 'message' => 'Please select two different staff members for takeover.'];
    }

    if ($startDate === '' || $endDate === '' || strtotime($endDate) < strtotime($startDate)) {
        return ['ok' => false, 'message' => 'Please provide a valid takeover date range.'];
    }

    $cleanReason = trim($reason);
    $tag = $cleanReason !== '' ? "[Takeover: {$cleanReason}]" : '[Shift Takeover]';

    $stmt = $conn->prepare(
        "UPDATE schedules s
         LEFT JOIN attendance a ON a.schedule_id = s.id AND a.staff_id = s.staff_id
         SET s.staff_id = ?,
             s.notes = TRIM(CONCAT(TRIM(REGEXP_REPLACE(COALESCE(s.notes, ''), '\\[ON LEAVE[^\\]]*\\]', '')), ' ', ?))
         WHERE s.staff_id = ?
           AND s.schedule_date BETWEEN ? AND ?
           AND s.status = 'scheduled'
           AND a.check_in_time IS NULL"
    );

    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to process shift takeover.'];
    }

    $stmt->bind_param('isiss', $toStaffId, $tag, $fromStaffId, $startDate, $endDate);
    $stmt->execute();
    $moved = $stmt->affected_rows;
    $stmt->close();

    if ($moved <= 0) {
        return ['ok' => false, 'message' => 'No upcoming unstarted shifts were found in that date range to take over.'];
    }

    admin_schedules_write_audit(
        $conn,
        $adminId,
        'bulk_shift_takeover',
        (string) $toStaffId,
        "Transferred {$moved} shift(s) from staff #{$fromStaffId} to #{$toStaffId} ({$startDate} to {$endDate}).",
        $ipAddress
    );

    return ['ok' => true, 'message' => "Transferred {$moved} shift(s) to the replacement employee."];
}

// --- Edit & Status Transitions ---

function admin_update_schedule(
    mysqli $conn,
    int $adminId,
    int $scheduleId,
    array $input,
    string $ipAddress
): array {
    $staffId = (int) ($input['staff_id'] ?? 0);
    $categoryId = (int) ($input['assigned_category_id'] ?? $input['category_id'] ?? 0);
    $schedDate = trim((string) ($input['schedule_date'] ?? ''));
    $startTime = trim((string) ($input['start_time'] ?? ''));
    $endTime = trim((string) ($input['end_time'] ?? ''));
    $location = trim((string) ($input['work_location'] ?? ''));
    $task = trim((string) ($input['task_description'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    $status = (string) ($input['status'] ?? 'scheduled');

    if ($scheduleId <= 0 || $staffId <= 0 || $categoryId <= 0 || $schedDate === '' || $startTime === '' || $endTime === '' || $task === '') {
        return ['ok' => false, 'message' => 'Please fill out all required shift fields.'];
    }

    if (!in_array($status, ['scheduled', 'completed', 'cancelled'], true)) {
        $status = 'scheduled';
    }

    $locParam = $location !== '' ? $location : null;
    $notesParam = $notes !== '' ? $notes : null;

    $stmt = $conn->prepare(
        'UPDATE schedules
         SET staff_id = ?,
             assigned_category_id = ?,
             schedule_date = ?,
             start_time = ?,
             end_time = ?,
             work_location = ?,
             task_description = ?,
             notes = ?,
             status = ?
         WHERE id = ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to update shift.'];
    }

    $stmt->bind_param(
        'iisssssssi',
        $staffId,
        $categoryId,
        $schedDate,
        $startTime,
        $endTime,
        $locParam,
        $task,
        $notesParam,
        $status,
        $scheduleId
    );
    $stmt->execute();
    $stmt->close();

    admin_schedules_write_audit(
        $conn,
        $adminId,
        'update_schedule',
        (string) $scheduleId,
        "Updated shift #{$scheduleId}.",
        $ipAddress
    );

    return ['ok' => true, 'message' => 'Shift assignment updated.'];
}

function admin_change_schedule_state(
    mysqli $conn,
    int $adminId,
    int $scheduleId,
    string $mode,
    string $ipAddress
): array {
    if ($scheduleId <= 0) {
        return ['ok' => false, 'message' => 'Invalid shift selected.'];
    }

    if ($mode === 'cancel') {
        $stmt = $conn->prepare("UPDATE schedules SET status = 'cancelled' WHERE id = ?");
        $msg = 'Shift cancelled.';
    } elseif ($mode === 'restore') {
        $stmt = $conn->prepare("UPDATE schedules SET status = 'scheduled' WHERE id = ?");
        $msg = 'Shift restored to scheduled.';
    } elseif ($mode === 'delete') {
        $check = $conn->prepare('SELECT 1 FROM attendance WHERE schedule_id = ? AND check_in_time IS NOT NULL LIMIT 1');
        if ($check) {
            $check->bind_param('i', $scheduleId);
            $check->execute();
            $hasCheckIn = (bool) $check->get_result()->fetch_assoc();
            $check->close();

            if ($hasCheckIn) {
                return ['ok' => false, 'message' => 'Cannot delete a shift that already has a recorded check-in.'];
            }
        }

        $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
        $msg = 'Shift deleted.';
    } else {
        return ['ok' => false, 'message' => 'Invalid shift action.'];
    }

    if (!$stmt) {
        return ['ok' => false, 'message' => 'Unable to process shift action.'];
    }

    $stmt->bind_param('i', $scheduleId);
    $stmt->execute();
    $stmt->close();

    admin_schedules_write_audit(
        $conn,
        $adminId,
        'schedule_' . $mode,
        (string) $scheduleId,
        "Performed '{$mode}' on shift #{$scheduleId}.",
        $ipAddress
    );

    return ['ok' => true, 'message' => $msg];
}