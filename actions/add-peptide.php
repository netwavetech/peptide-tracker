<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

require_csrf();

$formulationId = filter_input(INPUT_POST, 'formulation_id', FILTER_VALIDATE_INT);
$peptideName = trim((string)($_POST['peptide_name'] ?? ''));
$shortName = trim((string)($_POST['short_name'] ?? ''));
$halfLifeRaw = trim((string)($_POST['half_life_hours'] ?? ''));
$vialAmountRaw = trim((string)($_POST['vial_amount'] ?? ''));
$vialAmountUnit = trim((string)($_POST['vial_amount_unit'] ?? ''));

$addToProtocol = isset($_POST['add_to_protocol']) && $_POST['add_to_protocol'] === '1';
$protocolScheduleType = trim((string)($_POST['protocol_schedule_type'] ?? 'scheduled'));
$protocolDoseRaw = trim((string)($_POST['protocol_dose_amount'] ?? ''));
$protocolDoseUnit = trim((string)($_POST['protocol_dose_unit'] ?? 'mg'));
$protocolTimeOfDay = trim((string)($_POST['protocol_time_of_day'] ?? 'morning'));
$protocolEffectiveFrom = trim((string)($_POST['protocol_effective_from'] ?? date('Y-m-d')));
$protocolWeekdays = $_POST['protocol_weekdays'] ?? [];
$protocolCycleMode = trim((string)($_POST['protocol_cycle_mode'] ?? 'none'));
$protocolCycleAnchorDate = trim((string)($_POST['protocol_cycle_anchor_date'] ?? $protocolEffectiveFrom));
$protocolRunDaysRaw = trim((string)($_POST['protocol_run_days'] ?? ''));
$protocolOffDaysRaw = trim((string)($_POST['protocol_off_days'] ?? '0'));
$protocolMixReminderRaw = trim((string)($_POST['protocol_mix_reminder_days'] ?? '2'));
$protocolNotes = trim((string)($_POST['protocol_notes'] ?? ''));

if (!is_numeric($vialAmountRaw) || (float)$vialAmountRaw <= 0) {
    redirect_home('Enter a valid vial size.', 'danger');
}
$vialAmount = (float)$vialAmountRaw;

if (!in_array($vialAmountUnit, ['mg', 'mcg'], true)) {
    redirect_home('Choose a valid vial unit.', 'danger');
}

$halfLifeHours = null;
if ($halfLifeRaw !== '') {
    if (!is_numeric($halfLifeRaw) || (float)$halfLifeRaw <= 0) {
        redirect_home('Half-life must be a positive number of hours or left blank.', 'danger');
    }
    $halfLifeHours = (float)$halfLifeRaw;
}

/*
 * Protocol settings are only used when creating a new peptide. Selecting an
 * existing peptide from the dialog still means "add another vial size only."
 */
$protocolDoseAmount = null;
$protocolRunDays = null;
$protocolOffDays = 0;
$protocolRepeatCycle = 0;
$protocolMixReminderDays = 2;

if (!$formulationId && $addToProtocol) {
    if (!in_array($protocolScheduleType, ['scheduled', 'as_needed'], true)) {
        redirect_home('Choose a valid protocol schedule type.', 'danger');
    }

    if (!is_numeric($protocolDoseRaw) || (float)$protocolDoseRaw <= 0) {
        redirect_home('Enter a valid protocol dose.', 'danger');
    }
    $protocolDoseAmount = (float)$protocolDoseRaw;

    if (!in_array($protocolDoseUnit, ['mg', 'mcg'], true)) {
        redirect_home('Choose a valid protocol dose unit.', 'danger');
    }

    if (!in_array($protocolTimeOfDay, ['morning', 'afternoon', 'evening', 'night'], true)) {
        redirect_home('Choose a valid time of day.', 'danger');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $protocolEffectiveFrom);
    if (!$date || $date->format('Y-m-d') !== $protocolEffectiveFrom) {
        redirect_home('Choose a valid protocol start date.', 'danger');
    }

    if (!ctype_digit($protocolMixReminderRaw) || (int)$protocolMixReminderRaw < 0) {
        redirect_home('Mix reminder days must be zero or greater.', 'danger');
    }
    $protocolMixReminderDays = (int)$protocolMixReminderRaw;

    if ($protocolScheduleType === 'scheduled') {
        if (!is_array($protocolWeekdays)) {
            $protocolWeekdays = [];
        }
        $protocolWeekdays = array_values(array_unique(array_map('intval', $protocolWeekdays)));
        $protocolWeekdays = array_values(array_filter($protocolWeekdays, static fn(int $d): bool => $d >= 1 && $d <= 7));
        sort($protocolWeekdays);

        if (!$protocolWeekdays) {
            redirect_home('Select at least one protocol day of the week.', 'danger');
        }

        if (!in_array($protocolCycleMode, ['none', 'repeating', 'finite'], true)) {
            redirect_home('Choose a valid cycle type.', 'danger');
        }

        if ($protocolCycleMode !== 'none') {
            $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $protocolCycleAnchorDate);
            if (!$anchor || $anchor->format('Y-m-d') !== $protocolCycleAnchorDate) {
                redirect_home('Choose a valid cycle start date.', 'danger');
            }

            if (!ctype_digit($protocolRunDaysRaw) || (int)$protocolRunDaysRaw < 1) {
                redirect_home('Days on must be at least 1.', 'danger');
            }
            $protocolRunDays = (int)$protocolRunDaysRaw;

            if ($protocolCycleMode === 'repeating') {
                if (!ctype_digit($protocolOffDaysRaw) || (int)$protocolOffDaysRaw < 0) {
                    redirect_home('Days off must be zero or greater.', 'danger');
                }
                $protocolOffDays = (int)$protocolOffDaysRaw;
                $protocolRepeatCycle = 1;
            } else {
                $protocolOffDays = 0;
                $protocolRepeatCycle = 0;
            }
        } else {
            $protocolCycleAnchorDate = $protocolEffectiveFrom;
            $protocolRunDays = null;
            $protocolOffDays = 0;
            $protocolRepeatCycle = 0;
        }
    } else {
        $protocolWeekdays = [];
        $protocolCycleAnchorDate = $protocolEffectiveFrom;
        $protocolRunDays = null;
        $protocolOffDays = 0;
        $protocolRepeatCycle = 0;
    }
}

try {
    $mysqli->begin_transaction();

    if ($formulationId) {
        $stmt = $mysqli->prepare("SELECT formulation_id, name FROM formulations WHERE formulation_id = ? FOR UPDATE");
        $stmt->bind_param('i', $formulationId);
        $stmt->execute();
        $formulation = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$formulation) {
            throw new RuntimeException('The selected peptide was not found.');
        }

        $stmt = $mysqli->prepare("\n            SELECT vial_presentation_id\n            FROM vial_presentations\n            WHERE formulation_id = ? AND vial_amount = ? AND vial_amount_unit = ?\n            LIMIT 1\n        ");
        $stmt->bind_param('ids', $formulationId, $vialAmount, $vialAmountUnit);
        $stmt->execute();
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($duplicate) {
            throw new RuntimeException(
                $formulation['name'] . ' already has a ' .
                rtrim(rtrim(number_format($vialAmount, 4, '.', ''), '0'), '.') .
                ' ' . $vialAmountUnit . ' vial size.'
            );
        }

        $stmt = $mysqli->prepare("\n            INSERT INTO vial_presentations (formulation_id, vial_amount, vial_amount_unit)\n            VALUES (?, ?, ?)\n        ");
        $stmt->bind_param('ids', $formulationId, $vialAmount, $vialAmountUnit);
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
        redirect_home(
            'Added ' . rtrim(rtrim(number_format($vialAmount, 4, '.', ''), '0'), '.') .
            ' ' . $vialAmountUnit . ' vial size for ' . $formulation['name'] . '.',
            'success'
        );
    }

    if ($peptideName === '') {
        throw new RuntimeException('Enter a peptide name.');
    }

    $stmt = $mysqli->prepare("SELECT formulation_id FROM formulations WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $stmt->bind_param('s', $peptideName);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        throw new RuntimeException('That peptide already exists. Select it from the list to add another vial size.');
    }

    $stmt = $mysqli->prepare("\n        INSERT INTO peptides (name, short_name, half_life_hours, active)\n        VALUES (?, NULLIF(?, ''), ?, 1)\n    ");
    $stmt->bind_param('ssd', $peptideName, $shortName, $halfLifeHours);
    $stmt->execute();
    $peptideId = $mysqli->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare("INSERT INTO formulations (name, active) VALUES (?, 1)");
    $stmt->bind_param('s', $peptideName);
    $stmt->execute();
    $newFormulationId = $mysqli->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare("\n        INSERT INTO formulation_components (formulation_id, peptide_id, amount, amount_unit)\n        VALUES (?, ?, NULL, NULL)\n    ");
    $stmt->bind_param('ii', $newFormulationId, $peptideId);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("\n        INSERT INTO vial_presentations (formulation_id, vial_amount, vial_amount_unit)\n        VALUES (?, ?, ?)\n    ");
    $stmt->bind_param('ids', $newFormulationId, $vialAmount, $vialAmountUnit);
    $stmt->execute();
    $stmt->close();

    $newProtocolItemId = null;

    if ($addToProtocol) {
        $protocol = $mysqli->query("\n            SELECT protocol_id, name\n            FROM protocols\n            WHERE active = 1\n            ORDER BY protocol_id DESC\n            LIMIT 1\n            FOR UPDATE\n        ")->fetch_assoc();

        if (!$protocol) {
            throw new RuntimeException('No active protocol was found.');
        }

        $protocolId = (int)$protocol['protocol_id'];

        $stmt = $mysqli->prepare("\n            INSERT INTO protocol_items (\n                protocol_id, formulation_id, schedule_type, dose_amount, dose_unit,\n                time_of_day, effective_from, effective_to, cycle_anchor_date, run_days,\n                off_days, repeat_cycle, mix_reminder_days, active, notes\n            )\n            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 1, NULLIF(?, ''))\n        ");
        $stmt->bind_param(
            'iisdssssiiiis',
            $protocolId,
            $newFormulationId,
            $protocolScheduleType,
            $protocolDoseAmount,
            $protocolDoseUnit,
            $protocolTimeOfDay,
            $protocolEffectiveFrom,
            $protocolCycleAnchorDate,
            $protocolRunDays,
            $protocolOffDays,
            $protocolRepeatCycle,
            $protocolMixReminderDays,
            $protocolNotes
        );
        $stmt->execute();
        $newProtocolItemId = $mysqli->insert_id;
        $stmt->close();

        if ($protocolScheduleType === 'scheduled') {
            $weekdayStmt = $mysqli->prepare("\n                INSERT INTO protocol_item_weekdays (protocol_item_id, weekday)\n                VALUES (?, ?)\n            ");
            foreach ($protocolWeekdays as $weekday) {
                $weekdayStmt->bind_param('ii', $newProtocolItemId, $weekday);
                $weekdayStmt->execute();
            }
            $weekdayStmt->close();

            /*
             * If today's date is already inside the newly-created schedule,
             * create today's pending row immediately. Otherwise the normal
             * daily scheduler will create future rows.
             */
            $today = new DateTimeImmutable('today');
            $effective = new DateTimeImmutable($protocolEffectiveFrom);
            $dueToday = $today >= $effective && in_array((int)$today->format('N'), $protocolWeekdays, true);

            if ($dueToday && $protocolRunDays !== null) {
                $anchor = new DateTimeImmutable($protocolCycleAnchorDate);
                if ($today < $anchor) {
                    $dueToday = false;
                } else {
                    $elapsed = (int)$anchor->diff($today)->format('%a');
                    if ($protocolRepeatCycle) {
                        $cycleLength = $protocolRunDays + $protocolOffDays;
                        $dueToday = $cycleLength > 0 && ($elapsed % $cycleLength) < $protocolRunDays;
                    } else {
                        $dueToday = $elapsed < $protocolRunDays;
                    }
                }
            }

            if ($dueToday) {
                $todayString = $today->format('Y-m-d');
                $stmt = $mysqli->prepare("\n                    INSERT IGNORE INTO scheduled_doses (\n                        protocol_item_id, formulation_id, scheduled_date, time_of_day,\n                        dose_amount, dose_unit, status\n                    )\n                    VALUES (?, ?, ?, ?, ?, ?, 'pending')\n                ");
                $stmt->bind_param(
                    'iissds',
                    $newProtocolItemId,
                    $newFormulationId,
                    $todayString,
                    $protocolTimeOfDay,
                    $protocolDoseAmount,
                    $protocolDoseUnit
                );
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    $mysqli->commit();

    $message = $peptideName . ' was added with a ' .
        rtrim(rtrim(number_format($vialAmount, 4, '.', ''), '0'), '.') .
        ' ' . $vialAmountUnit . ' vial size.';

    if ($addToProtocol) {
        $message .= ' The protocol was added as well.';
    }

    redirect_home($message, 'success');

} catch (Throwable $e) {
    $mysqli->rollback();
    error_log('add-peptide.php: ' . $e->getMessage());
    redirect_home($e->getMessage(), 'danger');
}
