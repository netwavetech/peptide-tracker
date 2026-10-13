<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
app_require_auth();
session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$csrf = csrf_token();
$today = date('Y-m-d');
$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$activeProtocol = $mysqli->query("
    SELECT protocol_id, name
    FROM protocols
    WHERE active = 1
    ORDER BY protocol_id DESC
    LIMIT 1
")->fetch_assoc();

$protocolId = $activeProtocol ? (int)$activeProtocol['protocol_id'] : 0;

$rows = [];
if ($protocolId) {
    $stmt = $mysqli->prepare("
        SELECT
            f.formulation_id,
            f.name AS formulation_name,
            pi.protocol_item_id,
            pi.schedule_type,
            pi.dose_amount,
            pi.dose_unit,
            pi.time_of_day,
            pi.effective_from,
            pi.effective_to,
            pi.cycle_anchor_date,
            pi.run_days,
            pi.off_days,
            pi.repeat_cycle,
            pi.mix_reminder_days,
            pi.notes,
            GROUP_CONCAT(piw.weekday ORDER BY piw.weekday) AS weekdays,
            EXISTS (
                SELECT 1
                FROM prepared_vials pv
                JOIN vial_presentations vp
                    ON vp.vial_presentation_id = pv.vial_presentation_id
                WHERE vp.formulation_id = f.formulation_id
                  AND pv.status = 'active'
            ) AS has_active_vial
        FROM formulations f
        LEFT JOIN protocol_items pi
            ON pi.protocol_item_id = (
                SELECT pi2.protocol_item_id
                FROM protocol_items pi2
                WHERE pi2.protocol_id = ?
                  AND pi2.formulation_id = f.formulation_id
                  AND pi2.active = 1
                ORDER BY pi2.effective_from DESC, pi2.protocol_item_id DESC
                LIMIT 1
            )
        LEFT JOIN protocol_item_weekdays piw
            ON piw.protocol_item_id = pi.protocol_item_id
        WHERE f.active = 1
        GROUP BY
            f.formulation_id, f.name, pi.protocol_item_id, pi.schedule_type,
            pi.dose_amount, pi.dose_unit, pi.time_of_day, pi.effective_from,
            pi.effective_to, pi.cycle_anchor_date, pi.run_days, pi.off_days,
            pi.repeat_cycle, pi.mix_reminder_days, pi.notes
        ORDER BY f.name
    ");
    $stmt->bind_param('i', $protocolId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}


function protocol_display_start(array $row): ?string
{
    if (empty($row['protocol_item_id'])) {
        return null;
    }

    /*
     * cycle_anchor_date is the persisted CURRENT cycle start for cycling
     * protocols. The daily cron advances it when a repeating cycle rolls over.
     * For continuous protocols, effective_from is the meaningful start date.
     */
    if (!empty($row['run_days']) && !empty($row['cycle_anchor_date'])) {
        return $row['cycle_anchor_date'];
    }

    return !empty($row['effective_from']) ? $row['effective_from'] : null;
}

function protocol_display_end(array $row): ?string
{
    if (empty($row['protocol_item_id'])) {
        return null;
    }

    if (!empty($row['run_days'])) {
        $start = protocol_display_start($row);
        if ($start === null) {
            return null;
        }

        return (new DateTimeImmutable($start))
            ->modify('+' . max(1, (int)$row['run_days']) . ' days')
            ->format('Y-m-d');
    }

    return !empty($row['effective_to']) ? $row['effective_to'] : null;
}

function cycle_label(array $row): string {
    if ($row['protocol_item_id'] === null) return 'No active protocol';
    if ($row['run_days'] === null) return 'Continuous';
    if ((int)$row['repeat_cycle'] === 1) return (int)$row['run_days'] . ' on / ' . (int)$row['off_days'] . ' off';
    return 'Finite ' . (int)$row['run_days'] . '-day run';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Protocols | Peptide Research</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/app.css?v=20261013-modal-scroll-v2" rel="stylesheet">
</head>
<body>
<div class="app-layout">
    <aside class="app-sidebar">
        <a class="sidebar-brand" href="index.php">
            <span class="sidebar-brand-mark"><i class="bi bi-activity"></i></span>
            <span><strong>Peptide</strong><small>Research</small></span>
        </a>
        <div class="sidebar-label">Workspace</div>
        <nav class="sidebar-nav" aria-label="Research sections">
            <a class="sidebar-link" href="index.php"><i class="bi bi-grid-1x2"></i><span>Dashboard</span></a>
            <a class="sidebar-link" href="history.php"><i class="bi bi-graph-up-arrow"></i><span>History</span></a>
            <a class="sidebar-link active" href="protocols.php"><i class="bi bi-sliders2"></i><span>Protocols</span></a>
            <a class="sidebar-link" href="index.php#schedule"><i class="bi bi-calendar2-check"></i><span>Schedule</span></a>
            <a class="sidebar-link" href="index.php#vials"><i class="bi bi-droplet-half"></i><span>Active Vials</span></a>
            <a class="sidebar-link" href="index.php#inventory"><i class="bi bi-box-seam"></i><span>Inventory</span></a>
        </nav>
        <div class="sidebar-spacer"></div>
        <div class="sidebar-foot"><div class="sidebar-foot-icon"><i class="bi bi-shield-check"></i></div><div><strong>Research Tracker</strong><small>Personal dashboard</small></div></div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left"><a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Dashboard</a></div>
            <div class="topbar-right"><span class="topbar-date-label">PROTOCOLS</span><strong><?= h($activeProtocol['name'] ?? 'No active protocol') ?></strong></div>
        </header>

        <main class="dashboard-content protocol-page-content">
            <div class="protocol-page-inner" style="padding: 0 32px 36px; box-sizing: border-box; width: 100%;">
            <div class="dashboard-intro mb-4">
                <div><div class="eyebrow">Research configuration</div><h1>Protocols</h1><p>View and edit the current protocol for every peptide, or add one where none is active.</p></div>
            </div>

            <?php if ($flashMessage): ?>
                <div class="alert alert-<?= h($flashType) ?> alert-dismissible fade show" role="alert">
                    <?= h($flashMessage) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!$activeProtocol): ?>
                <div class="alert alert-warning">No active master protocol exists. Create or activate a protocol record before adding peptide protocol items.</div>
            <?php else: ?>
                <div class="card management-card protocol-page-card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 protocol-list-table">
                                <thead>
                                    <tr>
                                        <th>Peptide</th>
                                        <th>Schedule</th>
                                        <th>Dose</th>
                                        <th>Cycle Start</th>
                                        <th>Cycle End</th>
                                        <th>Cycle</th>
                                        <th>Vial</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $hasProtocol = !empty($row['protocol_item_id']); ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= h($row['formulation_name']) ?></div>
                                            <?php if ($hasProtocol && !empty($row['effective_from'])): ?>
                                                <div class="protocol-original-start">Original: <?= h(date('M j, Y', strtotime($row['effective_from']))) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasProtocol): ?>
                                                <span class="badge badge-soft"><?= h($row['schedule_type'] === 'as_needed' ? 'As needed' : ucfirst($row['time_of_day'])) ?></span>
                                            <?php else: ?>
                                                <span class="text-body-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasProtocol): ?>
                                                <strong><?= h(format_dose($row['dose_amount'])) ?> <?= h($row['dose_unit']) ?></strong>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <?php
                                            $displayStart = $hasProtocol ? protocol_display_start($row) : null;
                                            $displayEnd = $hasProtocol ? protocol_display_end($row) : null;
                                        ?>
                                        <td class="text-nowrap">
                                            <?php if ($displayStart): ?>
                                                <?= h(date('M j, Y', strtotime($displayStart))) ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <?php if ($hasProtocol): ?>
                                                <?php if ($displayEnd): ?>
                                                    <?= h(date('M j, Y', strtotime($displayEnd))) ?>
                                                <?php else: ?>
                                                    <span class="text-body-secondary">Ongoing</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h(cycle_label($row)) ?></td>
                                        <td>
                                            <?php if ((int)$row['has_active_vial'] === 1): ?>
                                                <span class="badge text-bg-success">Active vial</span>
                                            <?php else: ?>
                                                <span class="badge badge-soft">No active vial</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><button type="button" class="btn <?= $hasProtocol ? 'btn-outline-secondary' : 'btn-clinical' ?> btn-sm protocol-edit-btn" data-bs-toggle="modal" data-bs-target="#protocolEditModal" data-row='<?= h(json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG)) ?>'><?= $hasProtocol ? 'Edit' : 'Add Protocol' ?></button></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="protocolEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="actions/save-protocol.php" id="protocolEditForm">
                <div class="modal-header"><div><h2 class="modal-title fs-5" id="protocolModalTitle">Protocol</h2><div class="small text-body-secondary">Changes apply to the current protocol configuration.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="protocol_item_id" id="p_protocol_item_id">
                    <input type="hidden" name="formulation_id" id="p_formulation_id">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Protocol type</label><select class="form-select" name="schedule_type" id="p_schedule_type"><option value="scheduled">Scheduled</option><option value="as_needed">As needed</option></select></div>
                        <div class="col-md-4"><label class="form-label">Dose</label><input class="form-control" type="number" min="0.0001" step="0.0001" name="dose_amount" id="p_dose_amount" required></div>
                        <div class="col-md-4"><label class="form-label">Unit</label><select class="form-select" name="dose_unit" id="p_dose_unit"><option value="mg">mg</option><option value="mcg">mcg</option></select></div>
                        <div class="col-md-4"><label class="form-label">Time of day</label><select class="form-select" name="time_of_day" id="p_time_of_day"><option value="morning">Morning</option><option value="afternoon">Afternoon</option><option value="evening">Evening</option><option value="night">Night</option></select></div>
                        <div class="col-md-4"><label class="form-label">Original protocol start</label><input class="form-control" type="date" name="effective_from" id="p_effective_from" required></div>
                        <div class="col-md-4"><label class="form-label">Ends</label><input class="form-control" type="date" name="effective_to" id="p_effective_to"><div class="form-text">Optional</div></div>
                        <div class="col-md-4"><label class="form-label">Mix reminder days</label><input class="form-control" type="number" min="0" step="1" name="mix_reminder_days" id="p_mix_reminder_days" value="2"></div>
                    </div>

                    <div id="pAsNeededMessage" class="protocol-mode-note mt-3 d-none">
                        This protocol is currently <strong>As needed</strong>. Change Protocol type above to <strong>Scheduled</strong> to configure weekdays and cycle timing.
                    </div>

                    <div id="pScheduledFields" class="mt-4 protocol-scheduled-panel">
                        <label class="form-label">Days of week</label>
                        <div class="protocol-weekdays mb-3">
                        <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $d=>$label): ?>
                            <input class="btn-check p-weekday" type="checkbox" name="weekdays[]" value="<?= $d ?>" id="p_day_<?= $d ?>"><label class="btn btn-outline-secondary btn-sm" for="p_day_<?= $d ?>"><?= $label ?></label>
                        <?php endforeach; ?>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label">Cycle</label><select class="form-select" name="cycle_mode" id="p_cycle_mode"><option value="none">No cycle</option><option value="repeating">Repeating ON / OFF</option><option value="finite">Finite run</option></select></div>
                            <div class="col-md-4 p-cycle-field"><label class="form-label">Current cycle starts</label><input class="form-control" type="date" name="cycle_anchor_date" id="p_cycle_anchor_date"><div class="form-text">The daily scheduler advances this date when a repeating cycle rolls over.</div></div>
                            <div class="col-md-2 p-cycle-field"><label class="form-label">Days on</label><input class="form-control" type="number" min="1" step="1" name="run_days" id="p_run_days"></div>
                            <div class="col-md-2 p-cycle-field p-off-field"><label class="form-label">Days off</label><input class="form-control" type="number" min="0" step="1" name="off_days" id="p_off_days" value="0"></div>
                        </div>
                    </div>
                    <div class="mt-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes" id="p_notes" rows="2"></textarea></div>
                    <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="active" value="1" id="p_active" checked><label class="form-check-label" for="p_active">Protocol active</label></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save Protocol</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
const today = <?= json_encode($today) ?>;
const modalTitle = document.getElementById('protocolModalTitle');
const scheduleType = document.getElementById('p_schedule_type');
const cycleMode = document.getElementById('p_cycle_mode');

function updateProtocolFields() {
    const scheduled = scheduleType.value === 'scheduled';
    document.getElementById('pScheduledFields').classList.toggle('d-none', !scheduled);
    document.getElementById('pAsNeededMessage').classList.toggle('d-none', scheduled);
    const mode = cycleMode.value;
    document.querySelectorAll('.p-cycle-field').forEach(el => el.classList.toggle('d-none', !scheduled || mode === 'none'));
    document.querySelectorAll('.p-off-field').forEach(el => el.classList.toggle('d-none', !scheduled || mode !== 'repeating'));
}

function fillProtocolModal(row) {
    const has = !!row.protocol_item_id;
    modalTitle.textContent = (has ? 'Edit ' : 'Add ') + row.formulation_name + ' Protocol';
    document.getElementById('p_protocol_item_id').value = row.protocol_item_id || '';
    document.getElementById('p_formulation_id').value = row.formulation_id;
    scheduleType.value = row.schedule_type || 'scheduled';
    document.getElementById('p_dose_amount').value = row.dose_amount || '';
    document.getElementById('p_dose_unit').value = row.dose_unit || 'mg';
    document.getElementById('p_time_of_day').value = row.time_of_day || 'morning';
    document.getElementById('p_effective_from').value = row.effective_from || today;
    document.getElementById('p_effective_to').value = row.effective_to || '';
    document.getElementById('p_mix_reminder_days').value = row.mix_reminder_days ?? 2;
    document.getElementById('p_cycle_anchor_date').value = row.cycle_anchor_date || row.effective_from || today;
    document.getElementById('p_run_days').value = row.run_days || '';
    document.getElementById('p_off_days').value = row.off_days ?? 0;
    document.getElementById('p_notes').value = row.notes || '';
    document.getElementById('p_active').checked = true;

    let mode = 'none';
    if (row.run_days) mode = Number(row.repeat_cycle) === 1 ? 'repeating' : 'finite';
    cycleMode.value = mode;

    const weekdays = String(row.weekdays || '').split(',').filter(Boolean);
    document.querySelectorAll('.p-weekday').forEach(cb => cb.checked = weekdays.includes(cb.value));
    updateProtocolFields();
}

document.querySelectorAll('.protocol-edit-btn').forEach(btn => {
    btn.addEventListener('click', () => fillProtocolModal(JSON.parse(btn.dataset.row)));
});
scheduleType.addEventListener('change', updateProtocolFields);
cycleMode.addEventListener('change', updateProtocolFields);
updateProtocolFields();
</script>
</body>
</html>
