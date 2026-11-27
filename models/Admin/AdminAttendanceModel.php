<?php
declare(strict_types=1);

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

// Write an audit log entry for administrator attendance adjustments.
function admin_attendance_write_audit(
    mysqli $conn,
    int $adminId,
    string $action,
    string $entityId,
    string $description,
    string $ipAddress
): void {
    $entityType = 'attendance';
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

// Normalize HTML datetime-local input ("YYYY-MM-DDTHH:MM") into MySQL DATETIME.
function admin_attendance_normalize_datetime(?string $rawValue): ?string
{
    if ($rawValue === null) {
        return null;
    }

    $trimmed = trim($rawValue);
    if ($trimmed === '') {
        return null;
    }

    $normalized = str_replace('T', ' ', $trimmed);
    $timestamp = strtotime($normalized);

    return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
}

// Fetch filtered shift & attendance logs with computed effective_status.
function admin_get_attendance_logs(mysqli $conn, array $filters, string $today): array
{
    $sql = "SELECT
                s.id AS schedule_id,
                s.staff_id,
                s.assigned_category_id,
                s.schedule_date,
                s.start_time,
                s.end_time,
                s.task_description,
                s.work_location,
                s.notes,
                s.status AS schedule_status,
                u.full_name AS staff_name,
                u.employee_id,
                u.position AS staff_position,
                jc.category_name,
                a.id AS attendance_id,
                a.check_in_time,
                a.check_out_time,
                a.attendance_status
            FROM schedules s
            INNER JOIN users u ON u.id = s.staff_id
            INNER JOIN job_categories jc ON jc.id = s.assigned_category_id
            LEFT JOIN attendance a
                ON a.schedule_id = s.id AND a.staff_id = s.staff_id
            WHERE s.status <> 'cancelled'";

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
    $res = $stmt->get_result();
    $rows = [];
    $now = time();

    while ($row = $res->fetch_assoc()) {
        $schedDate = (string) $row['schedule_date'];
        $notes = (string) ($row['notes'] ?? '');
        $storedStatus = (string) ($row['attendance_status'] ?? '');
        $hasCheckIn = !empty($row['check_in_time']);
        $hasCheckOut = !empty($row['check_out_time']);
        $isOnLeave = stripos($notes, '[ON LEAVE]') !== false;
        $shiftEndTs = strtotime($schedDate . ' ' . $row['end_time']);
        $shiftEnded = $shiftEndTs !== false && $shiftEndTs < $now;

        if ($isOnLeave) {
            $effectiveStatus = 'on_leave';
        } elseif ($storedStatus === 'absent') {
            $effectiveStatus = 'absent';
        } elseif ($storedStatus === 'completed' || $hasCheckOut) {
            $effectiveStatus = 'completed';
        } elseif ($storedStatus === 'late') {
            $effectiveStatus = 'late';
        } elseif ($storedStatus === 'present') {
            $effectiveStatus = 'present';
        } elseif ($storedStatus === 'incomplete') {
            $effectiveStatus = 'incomplete';
        } elseif ($hasCheckIn) {
            $effectiveStatus = $shiftEnded ? 'incomplete' : 'present';
        } elseif ($shiftEnded) {
            $effectiveStatus = 'absent';
        } else {
            $effectiveStatus = 'not_started';
        }

        $row['is_today'] = ($schedDate === $today);
        $row['effective_status'] = $effectiveStatus;
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

// Group attendance records into one compact row per staff member with Start Date – End Date.
function admin_get_compact_attendance_logs(mysqli $conn, array $filters, string $today): array
{
    $rawRecords = admin_get_attendance_logs($conn, $filters, $today);
    $grouped = [];

    foreach ($rawRecords as $rec) {
        $staffId = (int) $rec['staff_id'];
        $schedDate = (string) $rec['schedule_date'];
        $status = (string) ($rec['effective_status'] ?? 'not_started');

        if (!isset($grouped[$staffId])) {
            $grouped[$staffId] = [
                'staff_id' => $staffId,
                'staff_name' => (string) $rec['staff_name'],
                'employee_id' => (string) $rec['employee_id'],
                'staff_position' => (string) ($rec['staff_position'] ?? ''),
                'start_date' => $schedDate,
                'end_date' => $schedDate,
                'shift_count' => 0,
                'present_completed' => 0,
                'late' => 0,
                'absent_leave' => 0,
                'pending' => 0,
                'focus_shift' => $rec,
                'has_today_focus' => ($schedDate === $today),
                'shifts' => [],
            ];
        }

        if ($schedDate < $grouped[$staffId]['start_date']) {
            $grouped[$staffId]['start_date'] = $schedDate;
        }
        if ($schedDate > $grouped[$staffId]['end_date']) {
            $grouped[$staffId]['end_date'] = $schedDate;
        }

        // Prefer today's shift as the primary row focus; otherwise keep the most recent shift.
        if ($schedDate === $today && !$grouped[$staffId]['has_today_focus']) {
            $grouped[$staffId]['focus_shift'] = $rec;
            $grouped[$staffId]['has_today_focus'] = true;
        }

        $grouped[$staffId]['shift_count']++;
        $grouped[$staffId]['shifts'][] = $rec;

        if ($status === 'present' || $status === 'completed') {
            $grouped[$staffId]['present_completed']++;
        } elseif ($status === 'late') {
            $grouped[$staffId]['late']++;
        } elseif ($status === 'absent' || $status === 'on_leave' || $status === 'incomplete') {
            $grouped[$staffId]['absent_leave']++;
        } else {
            $grouped[$staffId]['pending']++;
        }
    }

    return array_values($grouped);
}

// Create, update, or reset an attendance record for a scheduled shift.
function admin_update_attendance_record(
    mysqli $conn,
    int $adminId,
    int $scheduleId,
    string $newStatus,
    ?string $checkInInput,
    ?string $checkOutInput,
    string $ipAddress
): array {
    $allowedStatuses = ['present', 'late', 'completed', 'absent', 'incomplete', 'not_started'];
    if ($scheduleId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        return ['ok' => false, 'message' => 'Invalid shift or attendance status selected.'];
    }

    $schedStmt = $conn->prepare(
        'SELECT id, staff_id, schedule_date, start_time, end_time
         FROM schedules
         WHERE id = ?
         LIMIT 1'
    );
    if (!$schedStmt) {
        return ['ok' => false, 'message' => 'Unable to look up scheduled shift.'];
    }

    $schedStmt->bind_param('i', $scheduleId);
    $schedStmt->execute();
    $shift = $schedStmt->get_result()->fetch_assoc();
    $schedStmt->close();

    if (!$shift) {
        return ['ok' => false, 'message' => 'Scheduled shift was not found.'];
    }

    $staffId = (int) $shift['staff_id'];
    $schedDate = (string) $shift['schedule_date'];
    $startTime = (string) $shift['start_time'];
    $endTime = (string) $shift['end_time'];

    if ($newStatus === 'not_started') {
        $delStmt = $conn->prepare('DELETE FROM attendance WHERE schedule_id = ? AND staff_id = ?');
        if ($delStmt) {
            $delStmt->bind_param('ii', $scheduleId, $staffId);
            $delStmt->execute();
            $delStmt->close();
        }

        admin_attendance_write_audit(
            $conn,
            $adminId,
            'reset_attendance',
            (string) $scheduleId,
            "Reset attendance for shift #{$scheduleId} to Pending.",
            $ipAddress
        );

        return ['ok' => true, 'message' => 'Attendance record reset to Pending.'];
    }

    $existingStmt = $conn->prepare(
        'SELECT id, check_in_time, check_out_time
         FROM attendance
         WHERE schedule_id = ? AND staff_id = ?
         LIMIT 1'
    );
    $existing = null;
    if ($existingStmt) {
        $existingStmt->bind_param('ii', $scheduleId, $staffId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
    }

    $parsedIn = admin_attendance_normalize_datetime($checkInInput);
    $parsedOut = admin_attendance_normalize_datetime($checkOutInput);

    $defaultIn = ($schedDate === date('Y-m-d'))
        ? date('Y-m-d H:i:s')
        : ($schedDate . ' ' . $startTime);
    $defaultOut = ($schedDate === date('Y-m-d'))
        ? date('Y-m-d H:i:s')
        : ($schedDate . ' ' . $endTime);

    if ($newStatus === 'absent') {
        $finalCheckIn = null;
        $finalCheckOut = null;
    } elseif ($newStatus === 'completed') {
        $finalCheckIn = $parsedIn ?? (!empty($existing['check_in_time']) ? (string) $existing['check_in_time'] : $defaultIn);
        $finalCheckOut = $parsedOut ?? (!empty($existing['check_out_time']) ? (string) $existing['check_out_time'] : $defaultOut);
    } elseif ($newStatus === 'incomplete') {
        $finalCheckIn = $parsedIn ?? (!empty($existing['check_in_time']) ? (string) $existing['check_in_time'] : $defaultIn);
        $finalCheckOut = null;
    } else {
        $finalCheckIn = $parsedIn ?? (!empty($existing['check_in_time']) ? (string) $existing['check_in_time'] : $defaultIn);
        $finalCheckOut = $parsedOut;
    }

    if ($existing) {
        $attendanceId = (int) $existing['id'];
        $upd = $conn->prepare(
            'UPDATE attendance
             SET check_in_time = ?,
                 check_out_time = ?,
                 attendance_status = ?
             WHERE id = ?'
        );
        if (!$upd) {
            return ['ok' => false, 'message' => 'Unable to update attendance record.'];
        }
        $upd->bind_param('sssi', $finalCheckIn, $finalCheckOut, $newStatus, $attendanceId);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare(
            'INSERT INTO attendance (schedule_id, staff_id, check_in_time, check_out_time, attendance_status)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            return ['ok' => false, 'message' => 'Unable to save attendance record.'];
        }
        $ins->bind_param('iisss', $scheduleId, $staffId, $finalCheckIn, $finalCheckOut, $newStatus);
        $ins->execute();
        $ins->close();
    }

    admin_attendance_write_audit(
        $conn,
        $adminId,
        'update_attendance',
        (string) $scheduleId,
        "Updated shift #{$scheduleId} attendance status to {$newStatus}.",
        $ipAddress
    );

    return ['ok' => true, 'message' => 'Attendance record updated to ' . ucfirst($newStatus) . '.'];
}