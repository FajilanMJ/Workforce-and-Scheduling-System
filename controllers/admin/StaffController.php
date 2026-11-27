<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminPortalController.php';
require_once __DIR__ . '/../../models/Admin/AdminStaffModel.php';

// Store a one-time flash banner message safely.
function admin_staff_set_flash(string $type, string $message): void
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
function admin_staff_check_csrf(string $token): bool
{
    if (function_exists('verify_csrf_token')) {
        return verify_csrf_token($token);
    }

    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    return $sessionToken !== '' && hash_equals($sessionToken, $token);
}

// Process and render the administrator Staff Management page.
function handle_admin_staff_page(mysqli $conn, string $baseUrl = '/Scheduling_System'): void
{
    $context = admin_require_portal_context($conn, $baseUrl);
    extract($context);

    // Handle POST form submissions using Post/Redirect/Get.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if (!admin_staff_check_csrf($submittedToken)) {
            admin_staff_set_flash('error', 'Security verification failed. Please refresh and try again.');
            redirect($baseUrl . '/admin/staff.php');
        }

        $action = (string) ($_POST['action'] ?? '');
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        switch ($action) {
            case 'create_staff':
                $result = admin_create_staff_account($conn, $adminId, $_POST, $ipAddress);
                admin_staff_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'update_permissions':
                $staffId = (int) ($_POST['staff_id'] ?? 0);
                $positionInput = (string) ($_POST['position'] ?? '');
                $primaryCat = (int) ($_POST['primary_category_id'] ?? 0);
                $permitted = (array) ($_POST['permitted_categories'] ?? []);

                $result = admin_update_staff_permissions(
                    $conn,
                    $adminId,
                    $staffId,
                    $positionInput,
                    $primaryCat > 0 ? $primaryCat : null,
                    $permitted,
                    $ipAddress
                );
                admin_staff_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'update_status':
                $staffId = (int) ($_POST['staff_id'] ?? 0);
                $statusAction = (string) ($_POST['status_action'] ?? '');
                $targetDate = (string) ($_POST['target_date'] ?? $today);
                $leaveEndDate = (string) ($_POST['leave_end_date'] ?? $targetDate);
                $reason = (string) ($_POST['status_reason'] ?? '');

                $result = admin_update_staff_status(
                    $conn,
                    $adminId,
                    $staffId,
                    $statusAction,
                    $targetDate,
                    $leaveEndDate,
                    $reason,
                    $ipAddress
                );
                admin_staff_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'takeover_shift':
                $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
                $newStaffId = (int) ($_POST['new_staff_id'] ?? 0);
                $takeoverReason = (string) ($_POST['takeover_reason'] ?? '');

                $result = admin_reassign_shift_takeover(
                    $conn,
                    $adminId,
                    $scheduleId,
                    $newStaffId,
                    $takeoverReason,
                    $ipAddress
                );
                admin_staff_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'reset_password':
                $staffId = (int) ($_POST['staff_id'] ?? 0);
                $newPassword = (string) ($_POST['new_temp_password'] ?? '');

                $result = admin_reset_staff_password($conn, $adminId, $staffId, $newPassword, $ipAddress);
                admin_staff_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'delete_staff':
                $staffId = (int) ($_POST['staff_id'] ?? 0);
                $separationReason = (string) ($_POST['separation_reason'] ?? 'Retired');
                $notes = (string) ($_POST['separation_notes'] ?? '');

                $result = admin_delete_staff_account($conn, $adminId, $staffId, $separationReason, $notes, $ipAddress);
                admin_staff_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            default:
                admin_staff_set_flash('error', 'Unknown action requested.');
                break;
        }

        redirect($baseUrl . '/admin/staff.php');
    }

    // Read GET filter and pagination inputs.
    $search = trim((string) ($_GET['search'] ?? ''));
    $statusFilter = (string) ($_GET['status'] ?? 'all');
    $allowedFilters = ['all', 'active', 'present', 'late', 'absent', 'on_leave', 'scheduled', 'inactive'];
    if (!in_array($statusFilter, $allowedFilters, true)) {
        $statusFilter = 'all';
    }
    $categoryFilter = max(0, (int) ($_GET['category'] ?? 0));

    $jobCategories = admin_get_job_categories($conn);
    $allFilteredStaff = admin_get_staff_directory($conn, $today, $search, $statusFilter, $categoryFilter);
    $allActiveStaff = admin_get_staff_directory($conn, $today, '', 'all', 0);
    $takeoverShifts = admin_get_takeover_shifts($conn, $today);

    $onLeaveOrUncoveredShifts = array_values(array_filter(
        $takeoverShifts,
        static fn (array $shift): bool => !empty($shift['is_on_leave'])
    ));

    // Paginate staff directory (12 per page).
    $perPage = 12;
    $totalItems = count($allFilteredStaff);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $currentPage = min($totalPages, max(1, (int) ($_GET['p'] ?? 1)));
    $offset = ($currentPage - 1) * $perPage;
    $staffList = array_slice($allFilteredStaff, $offset, $perPage);

    $pagination = [
        'currentPage' => $currentPage,
        'totalPages' => $totalPages,
        'totalItems' => $totalItems,
        'startItem' => $totalItems > 0 ? $offset + 1 : 0,
        'endItem' => min($totalItems, $offset + $perPage),
    ];

    $queryParams = array_filter([
        'search' => $search !== '' ? $search : null,
        'status' => $statusFilter !== 'all' ? $statusFilter : null,
        'category' => $categoryFilter > 0 ? $categoryFilter : null,
    ]);
    $paginationBaseUrl = $baseUrl . '/admin/staff.php' . ($queryParams !== [] ? '?' . http_build_query($queryParams) : '');
    $paginationLabel = 'staff member(s)';

    // Generate a clean default temporary password for the compact Create Account modal.
    $suggestedTempPassword = 'ML@' . strtoupper(bin2hex(random_bytes(3))) . '26';

    $page = 'staff';
    $pageTitle = 'Staff Management';

    require __DIR__ . '/../../views/admin/staff.php';
}