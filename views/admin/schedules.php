<?php
declare(strict_types=1);

require __DIR__ . '/../layouts/admin_header.php';
?>
<!-- Warm Sage & Cream Summary Strip -->
<section class="schedule-summary-strip">
    <div class="warm-summary-tile">
        <div class="warm-summary-label">Total Shifts</div>
        <div class="warm-summary-number"><?= (int) $summary['total'] ?></div>
    </div>

    <div class="warm-summary-tile sage-tint">
        <div class="warm-summary-label">Scheduled / Active</div>
        <div class="warm-summary-number"><?= (int) $summary['scheduled'] ?></div>
    </div>

    <div class="warm-summary-tile">
        <div class="warm-summary-label">Completed</div>
        <div class="warm-summary-number"><?= (int) $summary['completed'] ?></div>
    </div>

    <div class="warm-summary-tile honey-tint">
        <div class="warm-summary-label">On Leave / Cancelled</div>
        <div class="warm-summary-number"><?= (int) $summary['needsAttention'] ?></div>
    </div>
</section>

<!-- Main Compact Schedules Card -->
<section class="content-card">
    <div class="card-heading">
        <div>
            <h2>Staff Shift Schedules</h2>
            <div class="small muted">Compact employee schedule ranges &amp; quick shift takeover</div>
        </div>

        <div class="d-flex gap-2">
            <button
                type="button"
                class="btn btn-outline-green btn-sm"
                data-bs-toggle="modal"
                data-bs-target="#takeoverRangeModal"
            >
                &#8644; Shift Takeover
            </button>
            <button
                type="button"
                class="btn btn-green btn-sm"
                data-bs-toggle="modal"
                data-bs-target="#assignScheduleModal"
            >
                + Assign Shift
            </button>
        </div>
    </div>

    <!-- Instant Filter Bar -->
    <form method="get" action="<?= e($baseUrl) ?>/admin/schedules.php" class="schedule-filter-panel auto-filter-form">
        <div class="schedule-filter-row">
            <div class="schedule-filter-field">
                <label for="filterStaff">Employee</label>
                <select id="filterStaff" name="staff_id" class="form-select form-select-sm" style="min-width: 190px;">
                    <option value="0">All Employees</option>
                    <?php foreach ($staffDirectory as $member): ?>
                        <option value="<?= (int) $member['id'] ?>" <?= $staffFilter === (int) $member['id'] ? 'selected' : '' ?>>
                            <?= e((string) $member['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="schedule-filter-field">
                <label for="filterCategory">Category</label>
                <select id="filterCategory" name="category_id" class="form-select form-select-sm" style="min-width: 185px;">
                    <option value="0">All Categories</option>
                    <?php foreach ($jobCategories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= $categoryFilter === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= e((string) $cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="schedule-filter-field flex-grow-1" style="max-width: 280px;">
                <label for="filterSearch">Keyword</label>
                <input
                    type="search"
                    id="filterSearch"
                    name="search"
                    value="<?= e($search) ?>"
                    class="form-control form-control-sm"
                    placeholder="Search task, station, or staff..."
                >
            </div>

            <?php if ($staffFilter > 0 || $categoryFilter > 0 || $search !== ''): ?>
                <div>
                    <a href="<?= e($baseUrl) ?>/admin/schedules.php" class="btn btn-light btn-sm">Reset</a>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!empty($schedules)): ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Shift Date Range</th>
                        <th>Shift Hours</th>
                        <th>Role &amp; Task</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $row): ?>
                        <?php
                        $staffId = (int) $row['staff_id'];
                        $startDate = (string) $row['start_date'];
                        $endDate = (string) $row['end_date'];
                        $shiftCount = (int) $row['shift_count'];
                        $includesToday = ($today >= $startDate && $today <= $endDate);
                        $latest = $row['latest_shift'];

                        $dateRangeText = ($startDate === $endDate)
                            ? date('M j, Y', strtotime($startDate))
                            : date('M j, Y', strtotime($startDate)) . ' – ' . date('M j, Y', strtotime($endDate));

                        if (!empty($row['has_leave'])) {
                            $pillClass = 'leave';
                            $pillLabel = 'On Leave';
                        } elseif ($row['active_count'] > 0) {
                            $pillClass = 'scheduled';
                            $pillLabel = $row['active_count'] . ' Scheduled';
                        } elseif ($row['completed_count'] > 0) {
                            $pillClass = 'completed';
                            $pillLabel = 'Completed';
                        } else {
                            $pillClass = 'cancelled';
                            $pillLabel = 'Cancelled';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?= e((string) $row['staff_name']) ?></strong>
                                <div class="small muted mt-1">
                                    <?= e((string) $row['employee_id']) ?>
                                    <?php if (!empty($row['staff_position'])): ?>
                                        &middot; <?= e((string) $row['staff_position']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <div class="shift-date-box <?= $includesToday ? 'today-box' : '' ?>">
                                    <span class="shift-date-main"><?= e($dateRangeText) ?></span>
                                    <span class="shift-date-sub">
                                        <?= $shiftCount ?> <?= $shiftCount === 1 ? 'shift' : 'shifts' ?>
                                        <?= $includesToday ? ' &bull; Active Today' : '' ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <strong>
                                    <?= e(admin_format_time((string) $row['start_time'])) ?>
                                    &ndash;
                                    <?= e(admin_format_time((string) $row['end_time'])) ?>
                                </strong>
                                <?php if (!empty($row['work_location'])): ?>
                                    <div class="small muted mt-1"><?= e((string) $row['work_location']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="warm-category-tag"><?= e((string) $row['category_name']) ?></span>
                                <div class="mt-1"><?= e((string) $row['task_description']) ?></div>
                            </td>

                            <td>
                                <span class="warm-status-pill <?= e($pillClass) ?>"><?= e($pillLabel) ?></span>
                            </td>

                            <td class="text-end">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    <button
                                        type="button"
                                        class="btn btn-outline-green btn-sm py-1 px-2"
                                        style="font-size: 11.5px;"
                                        data-bs-toggle="modal"
                                        data-bs-target="#takeoverRangeModal"
                                        onclick="
                                            document.getElementById('takeoverFromStaff').value='<?= $staffId ?>';
                                            document.getElementById('takeoverStartDate').value='<?= e($startDate) ?>';
                                            document.getElementById('takeoverEndDate').value='<?= e($endDate) ?>';
                                        "
                                    >
                                        Take Over
                                    </button>

                                    <div class="dropdown d-inline-block">
                                        <button
                                            type="button"
                                            class="row-action-toggle"
                                            data-bs-toggle="dropdown"
                                            data-bs-popper-config='{"strategy":"fixed"}'
                                            aria-expanded="false"
                                            title="More actions"
                                        >
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                <circle cx="8" cy="3" r="1.4"/>
                                                <circle cx="8" cy="8" r="1.4"/>
                                                <circle cx="8" cy="13" r="1.4"/>
                                            </svg>
                                        </button>

                                        <ul class="dropdown-menu dropdown-menu-end row-action-menu">
                                            <li>
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#assignScheduleModal"
                                                    onclick="
                                                        document.getElementById('assignStaffSelect').value='<?= $staffId ?>';
                                                        document.getElementById('assignCategorySelect').value='<?= (int) $row['assigned_category_id'] ?>';
                                                    "
                                                >
                                                    + Add More Dates
                                                </button>
                                            </li>
                                            <li>
                                                <button
                                                    type="button"
                                                    class="dropdown-item"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editScheduleModal"
                                                    data-schedule-id="<?= (int) $latest['id'] ?>"
                                                    data-staff-id="<?= $staffId ?>"
                                                    data-category-id="<?= (int) $latest['assigned_category_id'] ?>"
                                                    data-schedule-date="<?= e((string) $latest['schedule_date']) ?>"
                                                    data-start-time="<?= e(substr((string) $latest['start_time'], 0, 5)) ?>"
                                                    data-end-time="<?= e(substr((string) $latest['end_time'], 0, 5)) ?>"
                                                    data-work-location="<?= e((string) ($latest['work_location'] ?? '')) ?>"
                                                    data-task-description="<?= e((string) ($latest['task_description'] ?? '')) ?>"
                                                    data-notes="<?= e((string) ($latest['notes'] ?? '')) ?>"
                                                    data-status="<?= e((string) ($latest['status'] ?? 'scheduled')) ?>"
                                                >
                                                    Edit Latest Shift
                                                </button>
                                            </li>
                                            <?php if (($latest['status'] ?? '') !== 'cancelled'): ?>
                                                <li>
                                                    <form method="post" action="<?= e($baseUrl) ?>/admin/schedules.php">
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="change_state">
                                                        <input type="hidden" name="schedule_id" value="<?= (int) $latest['id'] ?>">
                                                        <input type="hidden" name="mode" value="cancel">
                                                        <button type="submit" class="dropdown-item action-warn">Cancel Latest Shift</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php require __DIR__ . '/../partials/pagination.php'; ?>
    <?php else: ?>
        <div class="empty-state">
            No shifts found. Click <strong>+ Assign Shift</strong> to schedule staff.
        </div>
    <?php endif; ?>
</section>

<!-- 1. Streamlined Assign Shift Modal -->
<div class="modal fade" id="assignScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e($baseUrl) ?>/admin/schedules.php" class="modal-content">
            <div class="modal-header admin-modal-header">
                <h5 class="modal-title">Assign Shift Date Range</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body warm-modal-body">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="assign_schedule">
                <input type="hidden" name="allow_unpermitted" value="1">

                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Staff Member *</label>
                        <select name="staff_id" id="assignStaffSelect" class="form-select form-select-sm" required>
                            <option value="">Choose employee...</option>
                            <?php foreach ($activeStaff as $member): ?>
                                <option
                                    value="<?= (int) $member['id'] ?>"
                                    onchange="if(this.dataset.primary){document.getElementById('assignCategorySelect').value=this.dataset.primary;}"
                                    data-primary="<?= (int) ($member['primary_category_id'] ?? 0) ?>"
                                >
                                    <?= e((string) $member['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Job Category *</label>
                        <select name="assigned_category_id" id="assignCategorySelect" class="form-select form-select-sm" required>
                            <option value="">Choose category...</option>
                            <?php foreach ($jobCategories as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>"><?= e((string) $cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Start Date *</label>
                        <input
                            type="date"
                            id="assignStartDate"
                            name="start_date"
                            class="form-control form-control-sm"
                            value="<?= e($today) ?>"
                            onchange="if(document.getElementById('assignEndDate').value < this.value){document.getElementById('assignEndDate').value = this.value;}"
                            required
                        >
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">End Date *</label>
                        <input type="date" id="assignEndDate" name="end_date" class="form-control form-control-sm" value="<?= e($today) ?>" required>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small fw-bold mb-1">Start Time *</label>
                        <input type="time" name="start_time" class="form-control form-control-sm" value="<?= e($workforceDefaults['default_shift_start'] ?? '06:00') ?>" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small fw-bold mb-1">End Time *</label>
                        <input type="time" name="end_time" class="form-control form-control-sm" value="<?= e($workforceDefaults['default_shift_end'] ?? '14:00') ?>" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small fw-bold mb-1">Station</label>
                        <input type="text" name="work_location" class="form-control form-control-sm" placeholder="e.g., Kitchen">
                    </div>
                </div>

                <div>
                    <label class="form-label small fw-bold mb-1">Task Description *</label>
                    <input type="text" name="task_description" class="form-control form-control-sm" required placeholder="e.g., Morning prep & grilling">
                </div>
            </div>

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-green btn-sm">Assign Shifts</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Simplified Shift Takeover Modal -->
<div class="modal fade" id="takeoverRangeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e($baseUrl) ?>/admin/schedules.php" class="modal-content">
            <div class="modal-header admin-modal-header">
                <h5 class="modal-title">Quick Shift Takeover</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body warm-modal-body">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="takeover_range">

                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Original Staff *</label>
                        <select name="from_staff_id" id="takeoverFromStaff" class="form-select form-select-sm" required>
                            <option value="">Select staff...</option>
                            <?php foreach ($staffDirectory as $member): ?>
                                <option value="<?= (int) $member['id'] ?>"><?= e((string) $member['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Replacement Staff *</label>
                        <select name="to_staff_id" class="form-select form-select-sm" required>
                            <option value="">Select replacement...</option>
                            <?php foreach ($activeStaff as $member): ?>
                                <option value="<?= (int) $member['id'] ?>"><?= e((string) $member['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">From Date *</label>
                        <input type="date" name="takeover_start_date" id="takeoverStartDate" class="form-control form-control-sm" value="<?= e($today) ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">To Date *</label>
                        <input type="date" name="takeover_end_date" id="takeoverEndDate" class="form-control form-control-sm" value="<?= e($today) ?>" required>
                    </div>
                </div>

                <div>
                    <label class="form-label small fw-bold mb-1">Reason (Optional)</label>
                    <input type="text" name="takeover_reason" class="form-control form-control-sm" placeholder="e.g., Covering sick leave">
                </div>
            </div>

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-green btn-sm">Transfer Shifts</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Edit Single Shift Modal -->
<div class="modal fade" id="editScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e($baseUrl) ?>/admin/schedules.php" class="modal-content">
            <div class="modal-header admin-modal-header">
                <h5 class="modal-title">Edit Latest Shift</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body warm-modal-body">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="update_schedule">
                <input type="hidden" name="schedule_id" id="editScheduleId" value="">

                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Staff Member *</label>
                        <select name="staff_id" id="editStaffSelect" class="form-select form-select-sm" required>
                            <?php foreach ($staffDirectory as $member): ?>
                                <option value="<?= (int) $member['id'] ?>"><?= e((string) $member['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Job Category *</label>
                        <select name="assigned_category_id" id="editCategorySelect" class="form-select form-select-sm" required>
                            <?php foreach ($jobCategories as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>"><?= e((string) $cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-sm-4">
                        <label class="form-label small fw-bold mb-1">Shift Date *</label>
                        <input type="date" id="editScheduleDate" name="schedule_date" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small fw-bold mb-1">Start Time *</label>
                        <input type="time" id="editStartTime" name="start_time" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small fw-bold mb-1">End Time *</label>
                        <input type="time" id="editEndTime" name="end_time" class="form-control form-control-sm" required>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-sm-7">
                        <label class="form-label small fw-bold mb-1">Work Station</label>
                        <input type="text" id="editWorkLocation" name="work_location" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label small fw-bold mb-1">Status</label>
                        <select id="editScheduleStatus" name="status" class="form-select form-select-sm">
                            <option value="scheduled">Scheduled</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold mb-1">Task Description *</label>
                    <input type="text" id="editTaskDescription" name="task_description" class="form-control form-control-sm" required>
                </div>

                <div>
                    <label class="form-label small fw-bold mb-1">Notes</label>
                    <input type="text" id="editScheduleNotes" name="notes" class="form-control form-control-sm">
                </div>
            </div>

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-green btn-sm">Update Shift</button>
            </div>
        </form>
    </div>
</div>
<?php
require __DIR__ . '/../layouts/admin_footer.php';