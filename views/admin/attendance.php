<?php
declare(strict_types=1);

require __DIR__ . '/../layouts/admin_header.php';
?>
<!-- Warm Sage & Cream Summary Strip -->
<section class="schedule-summary-strip">
    <div class="warm-summary-tile">
        <div class="warm-summary-label">Total Shift Logs</div>
        <div class="warm-summary-number"><?= (int) $summary['total'] ?></div>
    </div>

    <div class="warm-summary-tile sage-tint">
        <div class="warm-summary-label">Present / Completed</div>
        <div class="warm-summary-number"><?= (int) $summary['presentCompleted'] ?></div>
    </div>

    <div class="warm-summary-tile honey-tint">
        <div class="warm-summary-label">Late Check-Ins</div>
        <div class="warm-summary-number"><?= (int) $summary['late'] ?></div>
    </div>

    <div class="warm-summary-tile">
        <div class="warm-summary-label">Absent / On Leave</div>
        <div class="warm-summary-number"><?= (int) $summary['absentOrLeave'] ?></div>
    </div>
</section>

<!-- Main Compact Attendance Card -->
<section class="content-card">
    <div class="card-heading">
        <div>
            <h2>Workforce Attendance Logs</h2>
            <div class="small muted">Compact employee attendance ranges &amp; daily shift time logs</div>
        </div>
    </div>

    <!-- Instant Filter Bar -->
    <form method="get" action="<?= e($baseUrl) ?>/admin/attendance.php" class="schedule-filter-panel auto-filter-form">
        <div class="schedule-filter-row">
            <div class="schedule-filter-field">
                <label for="filterAttendanceStaff">Employee</label>
                <select id="filterAttendanceStaff" name="staff_id" class="form-select form-select-sm" style="min-width: 190px;">
                    <option value="0">All Employees</option>
                    <?php foreach ($staffDirectory as $member): ?>
                        <option value="<?= (int) $member['id'] ?>" <?= $staffFilter === (int) $member['id'] ? 'selected' : '' ?>>
                            <?= e((string) $member['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="schedule-filter-field">
                <label for="filterAttendanceCategory">Category</label>
                <select id="filterAttendanceCategory" name="category_id" class="form-select form-select-sm" style="min-width: 185px;">
                    <option value="0">All Categories</option>
                    <?php foreach ($jobCategories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= $categoryFilter === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= e((string) $cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="schedule-filter-field flex-grow-1" style="max-width: 280px;">
                <label for="filterAttendanceSearch">Keyword</label>
                <input
                    type="search"
                    id="filterAttendanceSearch"
                    name="search"
                    value="<?= e($search) ?>"
                    class="form-control form-control-sm"
                    placeholder="Search task, station, or staff..."
                >
            </div>

            <?php if ($staffFilter > 0 || $categoryFilter > 0 || $search !== ''): ?>
                <div>
                    <a href="<?= e($baseUrl) ?>/admin/attendance.php" class="btn btn-light btn-sm">Reset</a>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!empty($attendanceRecords)): ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Shift Date Range</th>
                        <th>Attendance Tally</th>
                        <th>Latest / Today Log</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attendanceRecords as $row): ?>
                        <?php
                        $startDate = (string) $row['start_date'];
                        $endDate = (string) $row['end_date'];
                        $shiftCount = (int) $row['shift_count'];
                        $includesToday = ($today >= $startDate && $today <= $endDate);
                        $focus = $row['focus_shift'];
                        $focusSchedId = (int) $focus['schedule_id'];
                        $focusDate = (string) $focus['schedule_date'];
                        $effectiveStatus = (string) ($focus['effective_status'] ?? 'not_started');

                        $dateRangeText = ($startDate === $endDate)
                            ? date('M j, Y', strtotime($startDate))
                            : date('M j, Y', strtotime($startDate)) . ' – ' . date('M j, Y', strtotime($endDate));

                        [$pillClass, $pillLabel] = match ($effectiveStatus) {
                            'present' => ['scheduled', 'Present'],
                            'completed' => ['completed', 'Completed'],
                            'late' => ['late', 'Late'],
                            'on_leave' => ['leave', 'On Leave'],
                            'absent' => ['cancelled', 'Absent'],
                            'incomplete' => ['late', 'Incomplete'],
                            default => ['pending', 'Pending'],
                        };

                        $checkInVal = !empty($focus['check_in_time'])
                            ? date('Y-m-d\TH:i', strtotime((string) $focus['check_in_time']))
                            : '';
                        $checkOutVal = !empty($focus['check_out_time'])
                            ? date('Y-m-d\TH:i', strtotime((string) $focus['check_out_time']))
                            : '';
                        $shiftSummary = date('M j, Y', strtotime($focusDate))
                            . ' (' . admin_format_time((string) $focus['start_time'])
                            . ' – ' . admin_format_time((string) $focus['end_time']) . ')';
                        $modalStatusDefault = in_array($effectiveStatus, ['present', 'completed', 'late', 'absent', 'incomplete', 'not_started'], true)
                            ? $effectiveStatus
                            : 'absent';

                        // Build compact JSON list of shifts for the modal date selector.
                        $shiftOptions = [];
                        foreach ($row['shifts'] as $sItem) {
                            $sDate = (string) $sItem['schedule_date'];
                            $sEff = (string) ($sItem['effective_status'] ?? 'not_started');
                            $shiftOptions[] = [
                                'id' => (int) $sItem['schedule_id'],
                                'label' => date('M j, Y', strtotime($sDate))
                                    . ' • ' . admin_format_time((string) $sItem['start_time'])
                                    . '–' . admin_format_time((string) $sItem['end_time'])
                                    . ' (' . $sItem['category_name'] . ')',
                                'status' => in_array($sEff, ['present', 'completed', 'late', 'absent', 'incomplete', 'not_started'], true) ? $sEff : 'absent',
                                'checkIn' => !empty($sItem['check_in_time']) ? date('Y-m-d\TH:i', strtotime((string) $sItem['check_in_time'])) : '',
                                'checkOut' => !empty($sItem['check_out_time']) ? date('Y-m-d\TH:i', strtotime((string) $sItem['check_out_time'])) : '',
                            ];
                        }
                        $shiftsJson = htmlspecialchars((string) json_encode($shiftOptions, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
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
                                        <?= !empty($row['has_today_focus']) ? ' &bull; Active Today' : '' ?>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <div class="small">
                                    <span class="text-success fw-semibold"><?= (int) $row['present_completed'] ?> Present</span>
                                    &middot;
                                    <span class="text-warning fw-semibold"><?= (int) $row['late'] ?> Late</span>
                                </div>
                                <div class="small muted mt-1">
                                    <?= (int) $row['absent_leave'] ?> Absent/Leave &middot; <?= (int) $row['pending'] ?> Pending
                                </div>
                            </td>

                            <td>
                                <div class="small fw-semibold" style="color: var(--green-dark);">
                                    <?= $focusDate === $today ? 'Today' : e(date('M j', strtotime($focusDate))) ?>
                                    &middot; <?= e((string) $focus['category_name']) ?>
                                </div>
                                <div class="attendance-time-pair mt-1">
                                    <span>
                                        <strong>In:</strong>
                                        <?= !empty($focus['check_in_time']) ? e(admin_format_time((string) $focus['check_in_time'])) : '—' ?>
                                    </span>
                                    <span>
                                        <strong>Out:</strong>
                                        <?= !empty($focus['check_out_time']) ? e(admin_format_time((string) $focus['check_out_time'])) : '—' ?>
                                    </span>
                                </div>
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
                                        data-bs-target="#editAttendanceModal"
                                        data-schedule-id="<?= $focusSchedId ?>"
                                        data-staff-name="<?= e((string) $row['staff_name']) ?>"
                                        data-shift-label="<?= e($shiftSummary) ?>"
                                        data-status="<?= e($modalStatusDefault) ?>"
                                        data-check-in="<?= e($checkInVal) ?>"
                                        data-check-out="<?= e($checkOutVal) ?>"
                                        data-shifts="<?= $shiftsJson ?>"
                                    >
                                        Edit Log
                                    </button>

                                    <div class="dropdown d-inline-block">
                                        <button
                                            type="button"
                                            class="row-action-toggle"
                                            data-bs-toggle="dropdown"
                                            data-bs-popper-config='{"strategy":"fixed"}'
                                            aria-expanded="false"
                                            title="Quick mark <?= $focusDate === $today ? 'today' : e(date('M j', strtotime($focusDate))) ?>"
                                        >
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                <circle cx="8" cy="3" r="1.4"/>
                                                <circle cx="8" cy="8" r="1.4"/>
                                                <circle cx="8" cy="13" r="1.4"/>
                                            </svg>
                                        </button>

                                        <ul class="dropdown-menu dropdown-menu-end row-action-menu">
                                            <li class="dropdown-header small text-muted">
                                                Quick Mark (<?= $focusDate === $today ? 'Today' : e(date('M j', strtotime($focusDate))) ?>)
                                            </li>
                                            <li>
                                                <form method="post" action="<?= e($baseUrl) ?>/admin/attendance.php">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="update_attendance">
                                                    <input type="hidden" name="schedule_id" value="<?= $focusSchedId ?>">
                                                    <input type="hidden" name="attendance_status" value="present">
                                                    <button type="submit" class="dropdown-item">Mark Present</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="post" action="<?= e($baseUrl) ?>/admin/attendance.php">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="update_attendance">
                                                    <input type="hidden" name="schedule_id" value="<?= $focusSchedId ?>">
                                                    <input type="hidden" name="attendance_status" value="late">
                                                    <button type="submit" class="dropdown-item action-warn">Mark Late</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="post" action="<?= e($baseUrl) ?>/admin/attendance.php">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="update_attendance">
                                                    <input type="hidden" name="schedule_id" value="<?= $focusSchedId ?>">
                                                    <input type="hidden" name="attendance_status" value="completed">
                                                    <button type="submit" class="dropdown-item">Mark Completed</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="post" action="<?= e($baseUrl) ?>/admin/attendance.php">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="update_attendance">
                                                    <input type="hidden" name="schedule_id" value="<?= $focusSchedId ?>">
                                                    <input type="hidden" name="attendance_status" value="absent">
                                                    <button type="submit" class="dropdown-item action-danger">Mark Absent</button>
                                                </form>
                                            </li>
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
        <div class="empty-state">No attendance logs matched your current filter.</div>
    <?php endif; ?>
</section>

<!-- Edit Attendance Modal with Shift Date Selector -->
<div class="modal fade" id="editAttendanceModal" tabindex="-1" aria-labelledby="editAttendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e($baseUrl) ?>/admin/attendance.php" class="modal-content">
            <div class="modal-header admin-modal-header">
                <h5 class="modal-title" id="editAttendanceModalLabel">
                    Attendance Record &mdash; <span id="attendanceStaffLabel"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body warm-modal-body">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="update_attendance">
                <input type="hidden" name="schedule_id" id="attendanceScheduleId" value="">

                <div class="small muted mb-2" id="attendanceShiftLabel"></div>

                <div class="mb-2" id="attendanceShiftPickerGroup" style="display: none;">
                    <label for="attendanceShiftPicker" class="form-label small fw-bold mb-1">Select Shift Date *</label>
                    <select id="attendanceShiftPicker" class="form-select form-select-sm"></select>
                </div>

                <div class="mb-2">
                    <label for="attendanceStatusSelect" class="form-label small fw-bold mb-1">Attendance Status *</label>
                    <select id="attendanceStatusSelect" name="attendance_status" class="form-select form-select-sm" required>
                        <option value="present">Present</option>
                        <option value="late">Late</option>
                        <option value="completed">Completed</option>
                        <option value="absent">Absent</option>
                        <option value="incomplete">Incomplete</option>
                        <option value="not_started">Not Started (Pending)</option>
                    </select>
                </div>

                <div class="row g-2">
                    <div class="col-sm-6">
                        <label for="attendanceCheckInInput" class="form-label small fw-bold mb-1">Check-In Time</label>
                        <input type="datetime-local" id="attendanceCheckInInput" name="check_in_time" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-6">
                        <label for="attendanceCheckOutInput" class="form-label small fw-bold mb-1">Check-Out Time</label>
                        <input type="datetime-local" id="attendanceCheckOutInput" name="check_out_time" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="form-text small mt-1">
                    Leave timestamps blank to auto-fill from the scheduled shift when marking Present, Late, or Completed.
                </div>
            </div>

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-green btn-sm">Save Attendance</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('editAttendanceModal');
    const pickerGroup = document.getElementById('attendanceShiftPickerGroup');
    const picker = document.getElementById('attendanceShiftPicker');
    if (!modalEl || !picker || !pickerGroup) return;

    let shiftsMap = {};

    modalEl.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        shiftsMap = {};
        picker.innerHTML = '';

        let shifts = [];
        try {
            shifts = JSON.parse(trigger.getAttribute('data-shifts') || '[]');
        } catch (e) {
            shifts = [];
        }

        if (shifts.length > 1) {
            pickerGroup.style.display = 'block';
            shifts.forEach(function (item) {
                shiftsMap[String(item.id)] = item;
                const opt = document.createElement('option');
                opt.value = String(item.id);
                opt.textContent = item.label;
                picker.appendChild(opt);
            });
            picker.value = trigger.getAttribute('data-schedule-id') || String(shifts[0].id);
        } else {
            pickerGroup.style.display = 'none';
        }
    });

    picker.addEventListener('change', function () {
        const chosen = shiftsMap[picker.value];
        if (!chosen) return;
        document.getElementById('attendanceScheduleId').value = String(chosen.id);
        document.getElementById('attendanceShiftLabel').textContent = chosen.label;
        document.getElementById('attendanceStatusSelect').value = chosen.status;
        document.getElementById('attendanceCheckInInput').value = chosen.checkIn || '';
        document.getElementById('attendanceCheckOutInput').value = chosen.checkOut || '';
    });
});
</script>
<?php
require __DIR__ . '/../layouts/admin_footer.php';