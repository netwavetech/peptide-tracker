<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

require_csrf();

$scheduledDoseId = filter_input(INPUT_POST, 'scheduled_dose_id', FILTER_VALIDATE_INT);

if (!$scheduledDoseId) {
    redirect_home('Invalid scheduled dose.', 'danger');
}

$stmt = $mysqli->prepare("
    UPDATE scheduled_doses
    SET status = 'skipped'
    WHERE
        scheduled_dose_id = ?
        AND status = 'pending'
");
$stmt->bind_param('i', $scheduledDoseId);
$stmt->execute();

if ($stmt->affected_rows === 1) {
    redirect_home('Dose marked as skipped.', 'secondary');
}

redirect_home('That dose is no longer pending.', 'warning');
