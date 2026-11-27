<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminPortalController.php';
require_once __DIR__ . '/../../models/Admin/AdminStaffModel.php';
require_once __DIR__ . '/../../models/Admin/AdminAttendanceModel.php';

function admin_attendance_set_flash(string $type, string $message): void
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

function admin_attendance_check_csrf(string $token): bool
{
    if (function_exists('verify_csrf_token')) {
        return verify_csrf_token($token);
    }

    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    return $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function handle_admin_attendance_page(mysqli $conn, string $baseUrl = '/Scheduling_System'): void
{
    $context = admin_require_portal_context($conn, $baseUrl);
    extract($context);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if (!admin_attendance_check_csrf($submittedToken)) {
            admin_attendance_set_flash('error', 'Security verification failed. Please refresh and try again.');
            redirect($baseUrl . '/admin/attendance.php');
        }

        $action = (string) ($_POST['action'] ?? '');
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if ($action === 'update_attendance') {
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            $newStatus = (string) ($_POST['attendance_status'] ?? '');
            $checkInInput = array_key_exists('check_in_time', $_POST) ? (string) $_POST['check_in_time'] : null;
            $checkOutInput = array_key_exists('check_out_time', $_POST) ? (string) $_POST['check_out_time'] : null;

            $result = admin_update_attendance_record(
                $conn,
                $adminId,
                $scheduleId,
                $newStatus,
                $checkInInput,
                $checkOutInput,
                $ipAddress
            );
            admin_attendance_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
        } else {
            admin_attendance_set_flash('error', 'Unknown attendance action requested.');
        }

        redirect($baseUrl . '/admin/attendance.php');
    }

    $staffFilter = max(0, (int) ($_GET['staff_id'] ?? 0));
    $categoryFilter = max(0, (int) ($_GET['category_id'] ?? 0));
    $search = trim((string) ($_GET['search'] ?? ''));

    $filters = [
        'staff_id' => $staffFilter,
        'category_id' => $categoryFilter,
        'search' => $search,
    ];

    $allRecords = admin_get_attendance_logs($conn, $filters, $today);
    $compactRecords = admin_get_compact_attendance_logs($conn, $filters, $today);
    $jobCategories = admin_get_job_categories($conn);
    $staffDirectory = admin_get_staff_directory($conn, $today, '', 'all', 0);

    $summary = [
        'total' => count($allRecords),
        'presentCompleted' => 0,
        'late' => 0,
        'absentOrLeave' => 0,
    ];

    foreach ($allRecords as $rec) {
        $status = (string) ($rec['effective_status'] ?? 'not_started');
        if ($status === 'present' || $status === 'completed') {
            $summary['presentCompleted']++;
        } elseif ($status === 'late') {
            $summary['late']++;
        } elseif ($status === 'absent' || $status === 'on_leave' || $status === 'incomplete') {
            $summary['absentOrLeave']++;
        }
    }

    // Paginate compact staff rows (12 per page).
    $perPage = 12;
    $totalItems = count($compactRecords);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $currentPage = min($totalPages, max(1, (int) ($_GET['p'] ?? 1)));
    $offset = ($currentPage - 1) * $perPage;
    $attendanceRecords = array_slice($compactRecords, $offset, $perPage);

    $pagination = [
        'currentPage' => $currentPage,
        'totalPages' => $totalPages,
        'totalItems' => $totalItems,
        'startItem' => $totalItems > 0 ? $offset + 1 : 0,
        'endItem' => min($totalItems, $offset + $perPage),
    ];

    $queryParams = array_filter([
        'staff_id' => $staffFilter > 0 ? $staffFilter : null,
        'category_id' => $categoryFilter > 0 ? $categoryFilter : null,
        'search' => $search !== '' ? $search : null,
    ]);
    $paginationBaseUrl = $baseUrl . '/admin/attendance.php' . ($queryParams !== [] ? '?' . http_build_query($queryParams) : '');
    $paginationLabel = 'staff member(s)';

    $page = 'attendance';
    $pageTitle = 'Attendance';

    require __DIR__ . '/../../views/admin/attendance.php';
}