<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($baseUrl) ?>/assets/css/portal.css" rel="stylesheet">
    <link href="<?= e($baseUrl) ?>/assets/css/admin_portal.css" rel="stylesheet">
</head>
<body class="staff-portal">
    <!-- Top navigation bar -->
    <header class="topbar">
        <a class="brand" href="<?= e($baseUrl) ?>/admin/dashboard.php" aria-label="Mang Lechon admin dashboard">
            <img
                class="brand-logo"
                src="<?= e($baseUrl) ?>/assets/images/company-logo.png"
                alt="<?= e(COMPANY_NAME) ?> logo"
                onerror="this.style.display='none'"
            >
            <span>
                <span class="brand-name d-block"><?= e(COMPANY_NAME) ?></span>
                <span class="brand-subtitle">Workforce Management System</span>
            </span>
        </a>

        <div class="topbar-right">
            <div class="live-clock">
                <div id="liveDate"><?= e(date('l, F j, Y')) ?></div>
                <strong id="liveTime"><?= e(date('g:i:s A')) ?></strong>
            </div>

            <!-- Password-assistance notification menu -->
            <div class="topbar-mail">
                <button
                    type="button"
                    class="topbar-mail-trigger"
                    id="adminTopbarMailTrigger"
                    aria-expanded="false"
                    aria-controls="adminTopbarMailMenu"
                    title="Password assistance requests"
                >
                    <span>&#9993;</span>
                    <?php if (($unreadMailCount ?? 0) > 0): ?>
                        <span class="mail-notification-dot" aria-label="Unread requests"></span>
                    <?php endif; ?>
                </button>

                <div class="topbar-mail-menu" id="adminTopbarMailMenu" aria-label="Recent password assistance requests">
                    <div class="topbar-mail-header">
                        <span>Password Assistance</span>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (($unreadMailCount ?? 0) > 0): ?>
                                <form method="post" action="<?= e((string) ($_SERVER['REQUEST_URI'] ?? ($baseUrl . '/admin/dashboard.php'))) ?>" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="mark_all_assistance_read">
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none fw-bold" style="font-size: 11px;">
                                        Mark all read
                                    </button>
                                </form>
                                <span>&middot;</span>
                            <?php endif; ?>
                            <span><?= (int) ($unreadMailCount ?? 0) ?> unread</span>
                        </div>
                    </div>

                    <?php if (!empty($recentMailRequests)): ?>
                        <?php foreach ($recentMailRequests as $mail): ?>
                            <?php
                            $requestName = ($mail['full_name'] ?? '') !== ''
                                ? (string) $mail['full_name']
                                : ((string) ($mail['submitted_email'] ?? 'Account assistance request'));
                            $searchTerm = (string) (($mail['employee_id'] ?? '') !== ''
                                ? $mail['employee_id']
                                : (($mail['submitted_employee_id'] ?? '') !== '' ? $mail['submitted_employee_id'] : ($mail['submitted_email'] ?? '')));
                            $requestDate = (string) ($mail['created_at'] ?? '');
                            $isUnread = ((int) ($mail['is_read'] ?? 0)) === 0;
                            ?>
                            <a
                                href="<?= e($baseUrl) ?>/admin/staff.php?search=<?= urlencode($searchTerm) ?>&amp;read_request=<?= (int) $mail['id'] ?>"
                                class="mail-request-item <?= $isUnread ? 'unread' : '' ?>"
                            >
                                <div class="mail-request-title"><?= e($requestName) ?></div>
                                <div class="mail-request-meta">
                                    <?= e((string) ($mail['submitted_email'] ?? '')) ?>
                                    <?php if ($requestDate !== ''): ?>
                                        &middot; <?= e($requestDate) ?>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="small muted text-center py-3">
                            You're all caught up. No requests found.
                        </div>
                    <?php endif; ?>

                    <a href="<?= e($baseUrl) ?>/admin/staff.php" class="topbar-mail-footer">
                        Manage Staff Accounts
                    </a>
                </div>
            </div>

            <!-- Admin account menu -->
            <div class="topbar-account">
                <button
                    type="button"
                    class="topbar-account-trigger"
                    id="adminTopbarAccountTrigger"
                    aria-expanded="false"
                    aria-controls="adminTopbarAccountMenu"
                    title="Administrator account menu"
                >
                    <span class="topbar-account-avatar">
                        <?php if ($profilePictureUrl !== ''): ?>
                            <img src="<?= e($profilePictureUrl) ?>" alt="Admin profile picture">
                        <?php else: ?>
                            <?= e($initials) ?>
                        <?php endif; ?>
                    </span>
                    <span class="topbar-account-details">
                        <span class="topbar-account-name"><?= e($adminName) ?></span>
                        <span class="topbar-account-position"><?= e($position ?: 'Administrator') ?></span>
                    </span>
                    <span class="topbar-account-caret">⌄</span>
                </button>

                <div class="topbar-account-menu" id="adminTopbarAccountMenu" aria-label="Administrator account options">
                    <a href="<?= e($baseUrl) ?>/admin/settings.php" class="<?= $page === 'settings' ? 'active' : '' ?>">
                        <span class="account-menu-icon">&#9881;</span>
                        <span>Settings</span>
                    </a>
                    <a href="<?= e($baseUrl) ?>/logout.php" class="logout-link">
                        <span class="account-menu-icon">&#8618;</span>
                        <span>Log Out</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="app-layout" id="appLayout">
        <!-- Sidebar navigation -->
        <aside class="sidebar" id="adminSidebar" aria-label="Administrator navigation">
            <div class="sidebar-top">
                <button
                    class="sidebar-toggle"
                    id="sidebarToggle"
                    type="button"
                    aria-label="Collapse sidebar"
                    aria-expanded="true"
                    title="Collapse sidebar"
                >‹</button>
            </div>

            <div class="nav-label">Main Menu</div>

            <a class="sidebar-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/admin/dashboard.php" title="Dashboard">
                <span class="sidebar-icon">&#9638;</span>
                <span>Dashboard</span>
            </a>

            <a class="sidebar-link <?= $page === 'staff' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/admin/staff.php" title="Staff Management">
                <span class="sidebar-icon">&#9823;</span>
                <span>Staff Management</span>
            </a>

            <a class="sidebar-link <?= $page === 'schedules' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/admin/schedules.php" title="Schedules">
                <span class="sidebar-icon">&#9636;</span>
                <span>Schedules</span>
            </a>

            <a class="sidebar-link <?= $page === 'attendance' ? 'active' : '' ?>" href="<?= e($baseUrl) ?>/admin/attendance.php" title="Attendance">
                <span class="sidebar-icon">&#10003;</span>
                <span>Attendance</span>
            </a>
        </aside>

        <!-- Main page content -->
        <main class="main-content">
            <div class="page-heading">
                <div>
                    <h1><?= e($pageTitle) ?></h1>
                    <div class="muted small"><?= e(COMPANY_NAME) ?> &bull; Administrator Workspace</div>
                </div>
            </div>

            <!-- One-time flash alert -->
            <?php if (is_array($flash) && !empty($flash['message'])): ?>
                <?php
                $alertClass = match ($flash['type'] ?? '') {
                    'success' => 'success',
                    'error', 'danger' => 'danger',
                    'warning' => 'warning',
                    default => 'info',
                };
                ?>
                <div class="alert alert-<?= e($alertClass) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>