<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

require_csrf();

$preparedVialId = filter_input(INPUT_POST, 'prepared_vial_id', FILTER_VALIDATE_INT);
if (!$preparedVialId) {
    redirect_home('Invalid active vial.', 'danger');
}

$today = date('Y-m-d');

try {
    $mysqli->begin_transaction();

    /* Lock exactly the vial the user clicked. Multiple vials of the same peptide
       are intentionally supported and may be closed independently. */
    $stmt = $mysqli->prepare("
        SELECT
            pv.prepared_vial_id,
            pv.status,
            vp.formulation_id,
            f.name AS formulation_name
        FROM prepared_vials pv
        JOIN vial_presentations vp
            ON vp.vial_presentation_id = pv.vial_presentation_id
        JOIN formulations f
            ON f.formulation_id = vp.formulation_id
        WHERE pv.prepared_vial_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $preparedVialId);
    $stmt->execute();
    $vial = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$vial) {
        throw new RuntimeException('That active vial could not be found.');
    }
    if ($vial['status'] !== 'active') {
        throw new RuntimeException('That vial has already been closed.');
    }

    $stmt = $mysqli->prepare("
        UPDATE prepared_vials
        SET status = 'finished', completed_at = NOW()
        WHERE prepared_vial_id = ? AND status = 'active'
    ");
    $stmt->bind_param('i', $preparedVialId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('That vial could not be closed.');
    }
    $stmt->close();

    $formulationId = (int)$vial['formulation_id'];

    /* Do not request a new vial if another active vial of the same peptide
       still exists. */
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS active_count
        FROM prepared_vials pv
        JOIN vial_presentations vp
            ON vp.vial_presentation_id = pv.vial_presentation_id
        WHERE vp.formulation_id = ?
          AND pv.status = 'active'
    ");
    $stmt->bind_param('i', $formulationId);
    $stmt->execute();
    $activeCount = (int)$stmt->get_result()->fetch_assoc()['active_count'];
    $stmt->close();

    $createdImmediateReminder = false;

    if ($activeCount === 0) {
        /*
         * Only create an immediate replacement-vial reminder when the protocol
         * is actually in an ON/RUN period today.
         *
         * A protocol item can be active in the database while currently being
         * between cycles (for example 42 days OFF). In that case we intentionally
         * create no reminder here. check_upcoming_reminders.py remains responsible
         * for creating the normal reminder at mix_reminder_days before the next
         * cycle starts.
         *
         * Weekday scheduling is NOT considered here. If a protocol is in its ON
         * portion of the cycle, it is considered currently running even when today
         * is not one of its dose weekdays.
         */
        $stmt = $mysqli->prepare("
            SELECT
                pi.protocol_item_id,
                pi.effective_from,
                pi.effective_to,
                pi.cycle_anchor_date,
                pi.run_days,
                pi.off_days,
                pi.repeat_cycle
            FROM protocol_items pi
            JOIN protocols p
                ON p.protocol_id = pi.protocol_id
            WHERE pi.formulation_id = ?
              AND pi.active = 1
              AND p.active = 1
              AND pi.effective_from <= ?
              AND (pi.effective_to IS NULL OR pi.effective_to >= ?)
            ORDER BY pi.effective_from DESC, pi.protocol_item_id DESC
        ");
        $stmt->bind_param('iss', $formulationId, $today, $today);
        $stmt->execute();
        $protocolItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $todayDate = new DateTimeImmutable($today);
        $runningProtocolItem = null;

        foreach ($protocolItems as $protocolItem) {
            $isRunningToday = false;
            $runDays = $protocolItem['run_days'] !== null ? (int)$protocolItem['run_days'] : null;
            $offDays = $protocolItem['off_days'] !== null ? (int)$protocolItem['off_days'] : 0;
            $repeatCycle = (int)$protocolItem['repeat_cycle'] === 1;

            /* No cycle configured = continuously running while effective. */
            if ($runDays === null || $runDays <= 0) {
                $isRunningToday = true;
            } else {
                $anchorText = (string)($protocolItem['cycle_anchor_date'] ?: $protocolItem['effective_from']);
                $anchorDate = new DateTimeImmutable($anchorText);

                if ($todayDate >= $anchorDate) {
                    $daysSinceAnchor = (int)$anchorDate->diff($todayDate)->format('%a');

                    if ($repeatCycle) {
                        $cycleLength = $runDays + max(0, $offDays);
                        if ($cycleLength > 0) {
                            $cycleDay = $daysSinceAnchor % $cycleLength;
                            $isRunningToday = $cycleDay < $runDays;
                        }
                    } else {
                        /* Finite protocol: ON from anchor through run_days - 1. */
                        $isRunningToday = $daysSinceAnchor < $runDays;
                    }
                }
            }

            if ($isRunningToday) {
                $runningProtocolItem = $protocolItem;
                break;
            }
        }

        if ($runningProtocolItem) {
            $targetDate = $today;
            $message = $vial['formulation_name'] . ' has no active vial - make a new vial';
            $protocolItemId = (int)$runningProtocolItem['protocol_item_id'];
            $reminderType = 'mix';

            $stmt = $mysqli->prepare("
                INSERT INTO reminders (
                    protocol_item_id,
                    formulation_id,
                    reminder_type,
                    reminder_date,
                    target_date,
                    message,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE
                    reminder_date = VALUES(reminder_date),
                    target_date = VALUES(target_date),
                    message = VALUES(message),
                    status = 'pending',
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param(
                'iissss',
                $protocolItemId,
                $formulationId,
                $reminderType,
                $today,
                $targetDate,
                $message
            );
            $stmt->execute();
            $stmt->close();
            $createdImmediateReminder = true;
        }
    }

    $mysqli->commit();

    $message = $vial['formulation_name'] . ' vial marked as used up.';
    if ($createdImmediateReminder) {
        $message .= ' Its protocol is currently running, so a new-vial reminder was created.';
    } elseif ($activeCount === 0) {
        $message .= ' No immediate reminder was created because there is no protocol currently in an ON/run period; the reminder cron will handle the next cycle.';
    }
    redirect_home($message, 'success');

} catch (Throwable $e) {
    $mysqli->rollback();
    error_log('finish-vial.php: ' . $e->getMessage());
    redirect_home($e->getMessage(), 'danger');
}
