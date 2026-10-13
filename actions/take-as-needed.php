<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

require_csrf();

$protocolItemId = filter_input(INPUT_POST, 'protocol_item_id', FILTER_VALIDATE_INT);
$doseAmountRaw = $_POST['dose_amount'] ?? '';
$doseUnit = trim((string)($_POST['dose_unit'] ?? ''));

if (!$protocolItemId || !is_numeric($doseAmountRaw) || (float)$doseAmountRaw <= 0 || $doseUnit === '') {
    redirect_home('Invalid as-needed dose information.', 'danger');
}

$doseAmount = (float)$doseAmountRaw;
$today = date('Y-m-d');
$nowTime = date('H:i:s');

try {
    $mysqli->begin_transaction();

    $stmt = $mysqli->prepare("
        SELECT
            pi.protocol_item_id,
            pi.protocol_id,
            pi.formulation_id,
            pi.schedule_type,
            pi.dose_amount AS protocol_amount,
            pi.dose_unit AS protocol_unit,
            pi.time_of_day,
            pi.effective_from,
            pi.effective_to,
            pi.cycle_anchor_date,
            pi.run_days,
            pi.off_days,
            pi.repeat_cycle,
            pi.mix_reminder_days,
            pi.active
        FROM protocol_items pi
        WHERE pi.protocol_item_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $protocolItemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['schedule_type'] !== 'as_needed' || (int)$row['active'] !== 1) {
        throw new RuntimeException('Active as-needed protocol item was not found.');
    }

    $stmt = $mysqli->prepare("
        INSERT INTO administrations (
            protocol_item_id,
            formulation_id,
            prepared_vial_id,
            administered_date,
            administered_time,
            time_of_day,
            dose_amount,
            dose_unit,
            notes
        )
        VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)
    ");

    $notes = 'As-needed dose recorded from dashboard';
    $stmt->bind_param(
        'iisssdss',
        $row['protocol_item_id'],
        $row['formulation_id'],
        $today,
        $nowTime,
        $row['time_of_day'],
        $doseAmount,
        $doseUnit,
        $notes
    );
    $stmt->execute();
    $stmt->close();

    $protocolAmount = (float)$row['protocol_amount'];
    $protocolUnit = (string)$row['protocol_unit'];

    $doseChanged =
        abs($doseAmount - $protocolAmount) > 0.000001
        || strcasecmp($doseUnit, $protocolUnit) !== 0;

    if ($doseChanged) {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $stmt = $mysqli->prepare("
            UPDATE protocol_items
            SET
                effective_to = ?,
                active = 0
            WHERE protocol_item_id = ?
        ");
        $stmt->bind_param('si', $today, $row['protocol_item_id']);
        $stmt->execute();
        $stmt->close();

        $stmt = $mysqli->prepare("
            INSERT INTO protocol_items (
                protocol_id,
                formulation_id,
                schedule_type,
                dose_amount,
                dose_unit,
                time_of_day,
                effective_from,
                effective_to,
                cycle_anchor_date,
                run_days,
                off_days,
                repeat_cycle,
                mix_reminder_days,
                active,
                notes
            )
            VALUES (
                ?, ?, 'as_needed', ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 1, ?
            )
        ");

        $newNotes = 'As-needed default dose changed from dashboard after administration on ' . $today;

        $stmt->bind_param(
            'iidssssiiiis',
            $row['protocol_id'],
            $row['formulation_id'],
            $doseAmount,
            $doseUnit,
            $row['time_of_day'],
            $tomorrow,
            $row['cycle_anchor_date'],
            $row['run_days'],
            $row['off_days'],
            $row['repeat_cycle'],
            $row['mix_reminder_days'],
            $newNotes
        );
        $stmt->execute();
        $newProtocolItemId = $mysqli->insert_id;
        $stmt->close();

        $stmt = $mysqli->prepare("
            INSERT INTO protocol_item_weekdays (
                protocol_item_id,
                weekday
            )
            SELECT ?, weekday
            FROM protocol_item_weekdays
            WHERE protocol_item_id = ?
        ");
        $stmt->bind_param('ii', $newProtocolItemId, $row['protocol_item_id']);
        $stmt->execute();
        $stmt->close();
    }

    $mysqli->commit();

    if ($doseChanged) {
        redirect_home(
            'As-needed dose recorded and its default protocol dose was updated beginning tomorrow.',
            'success'
        );
    }

    redirect_home('As-needed dose recorded.', 'success');

} catch (Throwable $e) {
    $mysqli->rollback();
    error_log('take-as-needed.php: ' . $e->getMessage());
    redirect_home('Unable to record the as-needed dose.', 'danger');
}
