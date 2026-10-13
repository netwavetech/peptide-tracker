<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

require_csrf();

$weightRaw = $_POST['weight'] ?? '';

if (!is_numeric($weightRaw) || (float)$weightRaw <= 0) {
    redirect_home('Invalid weight.', 'danger');
}

$weight = (float)$weightRaw;
$measuredAt = date('Y-m-d H:i:s');

$stmt = $mysqli->prepare("
    INSERT INTO weight_log (
        measured_at,
        weight,
        notes
    )
    VALUES (?, ?, 'Entered from dashboard')
");
$stmt->bind_param('sd', $measuredAt, $weight);
$stmt->execute();

redirect_home('Weight added.', 'success');
