<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_csrf();

$protocolItemId = filter_input(INPUT_POST, 'protocol_item_id', FILTER_VALIDATE_INT);
$doseRaw = trim((string)($_POST['dose_amount'] ?? ''));
$doseUnit = trim((string)($_POST['dose_unit'] ?? ''));
$timeOfDay = trim((string)($_POST['time_of_day'] ?? ''));
$today = date('Y-m-d');

if (!$protocolItemId || !is_numeric($doseRaw) || (float)$doseRaw <= 0) {
    redirect_home('Choose a peptide and enter a valid dose.', 'danger');
}
if (!in_array($doseUnit, ['mg', 'mcg'], true)) {
    redirect_home('Choose a valid dose unit.', 'danger');
}
if (!in_array($timeOfDay, ['morning', 'afternoon', 'evening', 'night'], true)) {
    redirect_home('Choose a valid time of day.', 'danger');
}
$doseAmount = (float)$doseRaw;

try {
    $mysqli->begin_transaction();

    $stmt = $mysqli->prepare("
        SELECT pi.protocol_item_id, pi.formulation_id, f.name AS formulation_name
        FROM protocol_items pi
        JOIN protocols p ON p.protocol_id = pi.protocol_id
        JOIN formulations f ON f.formulation_id = pi.formulation_id
        WHERE pi.protocol_item_id = ?
          AND pi.active = 1
          AND p.active = 1
          AND pi.effective_from <= ?
          AND (pi.effective_to IS NULL OR pi.effective_to >= ?)
          AND EXISTS (
              SELECT 1
              FROM prepared_vials pv
              JOIN vial_presentations vp
                  ON vp.vial_presentation_id = pv.vial_presentation_id
              WHERE vp.formulation_id = pi.formulation_id
                AND pv.status = 'active'
          )
        FOR UPDATE
    ");
    $stmt->bind_param('iss', $protocolItemId, $today, $today);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) {
        throw new RuntimeException('That peptide must have both an active vial and a current protocol.');
    }

    $formulationId = (int)$item['formulation_id'];
    $stmt = $mysqli->prepare("
        SELECT scheduled_dose_id
        FROM scheduled_doses
        WHERE protocol_item_id = ?
          AND scheduled_date = ?
          AND status <> 'cancelled'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param('is', $protocolItemId, $today);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        throw new RuntimeException($item['formulation_name'] . ' is already on today\'s schedule.');
    }

    $stmt = $mysqli->prepare("
        INSERT INTO scheduled_doses (
            protocol_item_id,
            formulation_id,
            scheduled_date,
            time_of_day,
            dose_amount,
            dose_unit,
            status
        )
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param('iissds', $protocolItemId, $formulationId, $today, $timeOfDay, $doseAmount, $doseUnit);
    $stmt->execute();
    $stmt->close();

    $mysqli->commit();
    redirect_home($item['formulation_name'] . ' was added to today\'s schedule.', 'success');
} catch (Throwable $e) {
    $mysqli->rollback();
    error_log('add-today-dose.php: ' . $e->getMessage());
    redirect_home($e->getMessage(), 'danger');
}
