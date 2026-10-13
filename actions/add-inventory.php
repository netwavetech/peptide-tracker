<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

require_csrf();

$vialPresentationId = filter_input(INPUT_POST, 'vial_presentation_id', FILTER_VALIDATE_INT);
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

if (!$vialPresentationId || !$quantity || $quantity < 1) {
    redirect_home('Please choose a vial and enter a valid quantity.', 'danger');
}

try {
    $stmt = $mysqli->prepare("
        SELECT f.name AS formulation_name
        FROM vial_presentations vp
        JOIN formulations f
            ON f.formulation_id = vp.formulation_id
        WHERE vp.vial_presentation_id = ?
    ");
    $stmt->bind_param('i', $vialPresentationId);
    $stmt->execute();
    $presentation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$presentation) {
        throw new RuntimeException('Vial presentation was not found.');
    }

    /*
     * Only use columns guaranteed by the current application schema. Other
     * lot metadata can remain NULL/default for a manual dashboard adjustment.
     */
    $stmt = $mysqli->prepare("
        INSERT INTO inventory_lots (
            vial_presentation_id,
            quantity_received
        )
        VALUES (?, ?)
    ");
    $stmt->bind_param('ii', $vialPresentationId, $quantity);
    $stmt->execute();
    $stmt->close();

    redirect_home(
        $quantity . ' ' . $presentation['formulation_name'] . ' vial' . ($quantity === 1 ? '' : 's') . ' added to inventory.',
        'success'
    );
} catch (Throwable $e) {
    error_log('add-inventory.php: ' . $e->getMessage());
    redirect_home('Unable to add inventory.', 'danger');
}
