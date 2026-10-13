<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

require_csrf();

$vialPresentationId = filter_input(INPUT_POST, 'vial_presentation_id', FILTER_VALIDATE_INT);

if (!$vialPresentationId) {
    redirect_home('Please choose a vial presentation.', 'danger');
}

try {
    $mysqli->begin_transaction();

    $stmt = $mysqli->prepare("
        SELECT
            vp.vial_presentation_id,
            vp.formulation_id,
            f.name AS formulation_name,
            vp.vial_amount,
            vp.vial_amount_unit
        FROM vial_presentations vp
        JOIN formulations f
            ON f.formulation_id = vp.formulation_id
        WHERE vp.vial_presentation_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $vialPresentationId);
    $stmt->execute();
    $presentation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$presentation) {
        throw new RuntimeException('Vial presentation was not found.');
    }

    /*
     * Lock all inventory lots for this exact presentation, oldest first.
     * The first lot with an unopened vial becomes the source lot.
     *
     * Multiple prepared_vials for the same formulation are intentionally
     * allowed. Starting a second vial simply consumes another inventory vial.
     */
    $stmt = $mysqli->prepare("
        SELECT inventory_lot_id, quantity_received
        FROM inventory_lots
        WHERE vial_presentation_id = ?
        ORDER BY inventory_lot_id
        FOR UPDATE
    ");
    $stmt->bind_param('i', $vialPresentationId);
    $stmt->execute();
    $lots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $inventoryLotId = null;

    $eventStmt = $mysqli->prepare("
        SELECT COALESCE(SUM(quantity), 0) AS used_qty
        FROM inventory_vial_events
        WHERE inventory_lot_id = ?
          AND event_type IN ('opened', 'discarded')
    ");

    foreach ($lots as $lot) {
        $lotId = (int)$lot['inventory_lot_id'];
        $eventStmt->bind_param('i', $lotId);
        $eventStmt->execute();
        $usedQty = (int)$eventStmt->get_result()->fetch_assoc()['used_qty'];

        if (((int)$lot['quantity_received'] - $usedQty) > 0) {
            $inventoryLotId = $lotId;
            break;
        }
    }

    $eventStmt->close();

    if ($inventoryLotId === null) {
        throw new RuntimeException(
            'No unopened ' . $presentation['formulation_name'] . ' vial of this size is in inventory.'
        );
    }

    $preparedAt = date('Y-m-d H:i:s');

    $stmt = $mysqli->prepare("
        INSERT INTO prepared_vials (
            inventory_lot_id,
            vial_presentation_id,
            mixing_recipe_id,
            prepared_at,
            status
        )
        VALUES (?, ?, NULL, ?, 'active')
    ");
    $stmt->bind_param('iis', $inventoryLotId, $vialPresentationId, $preparedAt);
    $stmt->execute();
    $stmt->close();

    $quantity = 1;
    $stmt = $mysqli->prepare("
        INSERT INTO inventory_vial_events (
            inventory_lot_id,
            event_type,
            quantity
        )
        VALUES (?, 'opened', ?)
    ");
    $stmt->bind_param('ii', $inventoryLotId, $quantity);
    $stmt->execute();
    $stmt->close();

    /* Any active vial satisfies a pending mix reminder for the formulation. */
    $stmt = $mysqli->prepare("
        UPDATE reminders
        SET status = 'completed', updated_at = NOW()
        WHERE formulation_id = ?
          AND reminder_type = 'mix'
          AND status = 'pending'
    ");
    $stmt->bind_param('i', $presentation['formulation_id']);
    $stmt->execute();
    $stmt->close();

    $mysqli->commit();

    redirect_home(
        $presentation['formulation_name'] . ' active vial added. One vial was removed from unopened inventory.',
        'success'
    );
} catch (Throwable $e) {
    $mysqli->rollback();
    error_log('add-active-vial.php: ' . $e->getMessage());
    redirect_home($e->getMessage(), 'danger');
}
