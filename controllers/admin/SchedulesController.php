<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminPortalController.php';
require_once __DIR__ . '/../../models/Admin/AdminStaffModel.php';
require_once __DIR__ . '/../../models/Admin/AdminSchedulesModel.php';
require_once __DIR__ . '/../../models/Admin/AdminSettingsModel.php';

function admin_schedules_set_flash(string $type, string $message): void
{
    if (function_exists('set_flash_message')) {
        set_flash_message($type, $message);
        return;
    }
    $_SESSION['flash_message'] = ['type' => $type, 'message' => $message];
}

function admin_schedules_check_csrf(string $token): bool
{
    if (function_exists('verify_csrf_token')) {
        return verify_csrf_token($token);
    }
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
    return $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function handle_admin_schedules_page(mysqli $conn, string $baseUrl = '/Scheduling_System'): void
{
    $context = admin_require_portal_context($conn, $baseUrl);
    extract($context);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if (!admin_schedules_check_csrf($submittedToken)) {
            admin_schedules_set_flash('error', 'Security verification failed. Please refresh and try again.');
            redirect($baseUrl . '/admin/schedules.php');
        }

        $action = (string) ($_POST['action'] ?? '');
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        switch ($action) {
            case 'assign_schedule':
                // Default allow_unpermitted to true so assignment doesn't block unless desired
                $_POST['allow_unpermitted'] = $_POST['allow_unpermitted'] ?? '1';
                $result = admin_assign_schedule_range($conn, $adminId, $_POST, $ipAddress);
                admin_schedules_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'takeover_range':
                $result = admin_takeover_staff_range(
                    $conn,
                    $adminId,
                    (int) ($_POST['from_staff_id'] ?? 0),
                    (int) ($_POST['to_staff_id'] ?? 0),
                    trim((string) ($_POST['takeover_start_date'] ?? $today)),
                    trim((string) ($_POST['takeover_end_date'] ?? $today)),
                    trim((string) ($_POST['takeover_reason'] ?? '')),
                    $ipAddress
                );
                admin_schedules_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'update_schedule':
                $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
                $result = admin_update_schedule($conn, $adminId, $scheduleId, $_POST, $ipAddress);
                admin_schedules_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            case 'change_state':
                $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
                $mode = (string) ($_POST['mode'] ?? '');
                $result = admin_change_schedule_state($conn, $adminId, $scheduleId, $mode, $ipAddress);
                admin_schedules_set_flash($result['ok'] ? 'success' : 'error', $result['message']);
                break;

            default:
                admin_schedules_set_flash('error', 'Unknown schedule action requested.');
                break;
        }

        redirect($baseUrl . '/admin/schedules.php');
    }

    $staffFilter = max(0, (int) ($_GET['staff_id'] ?? 0));
    $categoryFilter = max(0, (int) ($_GET['category_id'] ?? 0));
    $search = trim((string) ($_GET['search'] ?? ''));

    $filters = [
        'staff_id' => $staffFilter,
        'category_id' => $categoryFilter,
        'search' => $search,
    ];

    $allSchedules = admin_get_schedules_list($conn, $filters);
    $compactStaffSchedules = admin_get_compact_schedules_list($conn, $filters);
    $jobCategories = admin_get_job_categories($conn);
    $staffDirectory = admin_get_staff_directory($conn, $today, '', 'all', 0);
    $workforceDefaults = admin_get_workforce_settings($conn);

    $activeStaff = array_values(array_filter(
        $staffDirectory,
        static fn (array $member): bool => ($member['account_status'] ?? '') === 'active'
    ));

    $summary = [
        'total' => count($allSchedules),
        'scheduled' => 0,
        'completed' => 0,
        'needsAttention' => 0,
    ];

    foreach ($allSchedules as $item) {
        if (($item['status'] ?? '') === 'cancelled' || !empty($item['is_on_leave'])) {
            $summary['needsAttention']++;
        } elseif (($item['status'] ?? '') === 'completed' || !empty($item['check_out_time'])) {
            $summary['completed']++;
        } else {
            $summary['scheduled']++;
        }
    }

    $perPage = 12;
    $totalItems = count($compactStaffSchedules);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $currentPage = min($totalPages, max(1, (int) ($_GET['p'] ?? 1)));
    $offset = ($currentPage - 1) * $perPage;
    $schedules = array_slice($compactStaffSchedules, $offset, $perPage);

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
    $paginationBaseUrl = $baseUrl . '/admin/schedules.php' . ($queryParams !== [] ? '?' . http_build_query($queryParams) : '');
    $paginationLabel = 'staff schedule(s)';

    $page = 'schedules';
    $pageTitle = 'Schedules';

    require __DIR__ . '/../../views/admin/schedules.php';
}