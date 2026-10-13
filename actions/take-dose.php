<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

require_csrf();

$scheduledDoseId = filter_input(INPUT_POST, 'scheduled_dose_id', FILTER_VALIDATE_INT);
$doseAmountRaw = $_POST['dose_amount'] ?? '';
$doseUnit = trim((string)($_POST['dose_unit'] ?? ''));

if (!$scheduledDoseId || !is_numeric($doseAmountRaw) || (float)$doseAmountRaw <= 0 || $doseUnit === '') {
    redirect_home('Invalid dose information.', 'danger');
}

$doseAmount = (float)$doseAmountRaw;
$today = date('Y-m-d');
$nowTime = date('H:i:s');

try {
    $mysqli->begin_transaction();

    /*
     * Lock the scheduled row and fetch its current protocol item.
     */
    $stmt = $mysqli->prepare("
        SELECT
            sd.scheduled_dose_id,
            sd.protocol_item_id,
            sd.formulation_id,
            sd.dose_amount AS scheduled_amount,
            sd.dose_unit AS scheduled_unit,
            sd.time_of_day,
            sd.status,

            pi.protocol_id,
            pi.schedule_type,
            pi.dose_amount AS protocol_amount,
            pi.dose_unit AS protocol_unit,
            pi.time_of_day AS protocol_time_of_day,
            pi.effective_from,
            pi.effective_to,
            pi.cycle_anchor_date,
            pi.run_days,
            pi.off_days,
            pi.repeat_cycle,
            pi.mix_reminder_days,
            pi.notes AS protocol_notes

        FROM scheduled_doses sd
        JOIN protocol_items pi
            ON pi.protocol_item_id = sd.protocol_item_id
        WHERE sd.scheduled_dose_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $scheduledDoseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Scheduled dose was not found.');
    }

    if ($row['status'] !== 'pending') {
        throw new RuntimeException('This scheduled dose is no longer pending.');
    }

    /*
     * Record what was actually taken.
     */
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

    $notes = 'Recorded from dashboard';
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
    $administrationId = $mysqli->insert_id;
    $stmt->close();

    /*
     * Mark today's scheduled dose as taken, while preserving the originally
     * scheduled amount for audit/history.
     */
    $stmt = $mysqli->prepare("
        UPDATE scheduled_doses
        SET
            status = 'taken',
            administration_id = ?
        WHERE scheduled_dose_id = ?
    ");
    $stmt->bind_param('ii', $administrationId, $scheduledDoseId);
    $stmt->execute();
    $stmt->close();

    /*
     * If the actual dose differs from the current protocol dose, effective-date
     * the current protocol through today and create a new version beginning
     * tomorrow. This keeps today's planned dose intact and changes the protocol
     * going forward without resetting the cycle anchor.
     */
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
                ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 1, ?
            )
        ");

        $newNotes = 'Dose changed from dashboard after administration on ' . $today;

        $stmt->bind_param(
            'iisdssssiiiis',
            $row['protocol_id'],
            $row['formulation_id'],
            $row['schedule_type'],
            $doseAmount,
            $doseUnit,
            $row['protocol_time_of_day'],
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

        /*
         * Copy weekday mappings to the new protocol version.
         */
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
            'Dose recorded. The protocol was updated to the new dose beginning tomorrow.',
            'success'
        );
    }

    redirect_home('Dose recorded.', 'success');

} catch (Throwable $e) {
    $mysqli->rollback();
    error_log('take-dose.php: ' . $e->getMessage());
    redirect_home('Unable to record the dose.', 'danger');
}
