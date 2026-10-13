<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$today = date('Y-m-d');
$csrf = csrf_token();

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

/*
 * Reconcile today's schedule against administration history.
 *
 * This handles imported/backfilled doses and doses entered outside of the
 * scheduled-dose workflow. If a formulation already has an administration
 * recorded for today, a still-pending scheduled row is automatically marked
 * taken and linked to the existing administration.
 */
$stmt = $mysqli->prepare("
    UPDATE scheduled_doses sd
    JOIN (
        SELECT
            formulation_id,
            administered_date,
            MAX(administration_id) AS administration_id
        FROM administrations
        WHERE administered_date = ?
        GROUP BY formulation_id, administered_date
    ) existing
        ON existing.formulation_id = sd.formulation_id
       AND existing.administered_date = sd.scheduled_date
    SET
        sd.status = 'taken',
        sd.administration_id = existing.administration_id,
        sd.updated_at = NOW()
    WHERE
        sd.scheduled_date = ?
        AND sd.status = 'pending'
");
$stmt->bind_param('ss', $today, $today);
$stmt->execute();
$stmt->close();

/*
 * Today's scheduled doses.
 */
$stmt = $mysqli->prepare("
    SELECT
        sd.scheduled_dose_id,
        sd.protocol_item_id,
        sd.formulation_id,
        f.name AS formulation_name,
        sd.dose_amount,
        sd.dose_unit,
        sd.time_of_day,
        sd.status,
        sd.administration_id,
        a.dose_amount AS actual_dose_amount,
        a.dose_unit AS actual_dose_unit,
        a.administered_time AS actual_administered_time
    FROM scheduled_doses sd
    JOIN formulations f
        ON f.formulation_id = sd.formulation_id
    LEFT JOIN administrations a
        ON a.administration_id = sd.administration_id
    WHERE sd.scheduled_date = ?
    ORDER BY
        CASE sd.time_of_day
            WHEN 'morning' THEN 1
            WHEN 'afternoon' THEN 2
            WHEN 'evening' THEN 3
            WHEN 'night' THEN 4
            ELSE 5
        END,
        f.name
");
$stmt->bind_param('s', $today);
$stmt->execute();
$scheduledRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$scheduledByTime = [
    'morning' => [],
    'afternoon' => [],
    'evening' => [],
    'night' => [],
    'any' => [],
];

foreach ($scheduledRows as $row) {
    $scheduledByTime[$row['time_of_day']][] = $row;
}

/*
 * As-needed protocol items.
 *
 * If an as-needed formulation already has an administration recorded today,
 * pull the latest one so the dashboard can show it as Taken instead of
 * presenting another empty entry form.
 */
$stmt = $mysqli->prepare("
    SELECT
        pi.protocol_item_id,
        pi.formulation_id,
        f.name AS formulation_name,
        pi.dose_amount,
        pi.dose_unit,
        pi.time_of_day,

        a.administration_id AS today_administration_id,
        a.dose_amount AS actual_dose_amount,
        a.dose_unit AS actual_dose_unit,
        a.administered_time AS actual_administered_time

    FROM protocol_items pi

    JOIN protocols p
        ON p.protocol_id = pi.protocol_id

    JOIN formulations f
        ON f.formulation_id = pi.formulation_id

    LEFT JOIN administrations a
        ON a.administration_id = (
            SELECT a2.administration_id
            FROM administrations a2
            WHERE
                a2.formulation_id = pi.formulation_id
                AND a2.administered_date = ?
            ORDER BY
                COALESCE(a2.administered_time, '00:00:00') DESC,
                a2.administration_id DESC
            LIMIT 1
        )

    WHERE
        p.active = 1
        AND pi.active = 1
        AND pi.schedule_type = 'as_needed'
        AND pi.effective_from <= ?
        AND (pi.effective_to IS NULL OR pi.effective_to >= ?)

        /* As-needed choices are actionable only while a vial is active. */
        AND EXISTS (
            SELECT 1
            FROM prepared_vials pv
            JOIN vial_presentations vp
                ON vp.vial_presentation_id = pv.vial_presentation_id
            WHERE vp.formulation_id = pi.formulation_id
              AND pv.status = 'active'
        )

        /* If manually promoted to today's schedule, show it there instead. */
        AND NOT EXISTS (
            SELECT 1
            FROM scheduled_doses sd2
            WHERE sd2.protocol_item_id = pi.protocol_item_id
              AND sd2.scheduled_date = ?
              AND sd2.status <> 'cancelled'
        )

    ORDER BY f.name
");
$stmt->bind_param('ssss', $today, $today, $today, $today);
$stmt->execute();
$asNeededRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
 * Pending reminders.
 */
$stmt = $mysqli->prepare("
    SELECT
        r.reminder_id,
        r.message,
        r.target_date,
        f.name AS formulation_name
    FROM reminders r
    JOIN formulations f
        ON f.formulation_id = r.formulation_id
    WHERE
        r.status = 'pending'
        AND r.reminder_date <= ?
    ORDER BY r.target_date, f.name
");
$stmt->bind_param('s', $today);
$stmt->execute();
$reminders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
 * Active prepared vials.
 */
$activeVials = $mysqli->query("
    SELECT
        pv.prepared_vial_id,
        f.name AS formulation_name,
        vp.vial_amount,
        vp.vial_amount_unit,
        pv.prepared_at
    FROM prepared_vials pv
    JOIN vial_presentations vp
        ON vp.vial_presentation_id = pv.vial_presentation_id
    JOIN formulations f
        ON f.formulation_id = vp.formulation_id
    WHERE pv.status = 'active'
    ORDER BY f.name, pv.prepared_at DESC
")->fetch_all(MYSQLI_ASSOC);


/*
 * Peptides that can be manually added to today's schedule.
 * A formulation must have both an active vial and a currently-effective
 * protocol item. This gives an intentional override for days when the normal
 * scheduler did not produce a row (cycle exception, missed cron, etc.).
 */
$stmt = $mysqli->prepare("
    SELECT DISTINCT
        pi.protocol_item_id,
        pi.formulation_id,
        f.name AS formulation_name,
        pi.dose_amount,
        pi.dose_unit,
        pi.time_of_day,
        pi.schedule_type
    FROM protocol_items pi
    JOIN protocols p
        ON p.protocol_id = pi.protocol_id
    JOIN formulations f
        ON f.formulation_id = pi.formulation_id
    WHERE p.active = 1
      AND pi.active = 1
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
      AND NOT EXISTS (
          SELECT 1
          FROM scheduled_doses sd
          WHERE sd.protocol_item_id = pi.protocol_item_id
            AND sd.scheduled_date = ?
            AND sd.status <> 'cancelled'
      )
    ORDER BY f.name
");
$stmt->bind_param('sss', $today, $today, $today);
$stmt->execute();
$manualDoseOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
 * Unopened inventory grouped by exact vial presentation.
 *
 * This keeps 10 mg, 20 mg, etc. completely independent. A formulation can
 * therefore appear more than once in the inventory table when more than one
 * vial size is stocked.
 */
$inventoryRows = $mysqli->query("
    SELECT
        vp.vial_presentation_id,
        f.formulation_id,
        f.name AS formulation_name,
        vp.vial_amount,
        vp.vial_amount_unit,
        COALESCE(SUM(il.quantity_received), 0)
        - COALESCE(SUM(ev.opened_qty), 0)
        - COALESCE(SUM(ev.discarded_qty), 0) AS unopened_qty
    FROM vial_presentations vp
    JOIN formulations f
        ON f.formulation_id = vp.formulation_id
    LEFT JOIN inventory_lots il
        ON il.vial_presentation_id = vp.vial_presentation_id
    LEFT JOIN (
        SELECT
            inventory_lot_id,
            SUM(CASE WHEN event_type = 'opened' THEN quantity ELSE 0 END) AS opened_qty,
            SUM(CASE WHEN event_type = 'discarded' THEN quantity ELSE 0 END) AS discarded_qty
        FROM inventory_vial_events
        GROUP BY inventory_lot_id
    ) ev
        ON ev.inventory_lot_id = il.inventory_lot_id
    GROUP BY
        vp.vial_presentation_id,
        f.formulation_id,
        f.name,
        vp.vial_amount,
        vp.vial_amount_unit
    HAVING unopened_qty > 0
    ORDER BY f.name, vp.vial_amount
")->fetch_all(MYSQLI_ASSOC);

/*
 * Vial presentations used by the Add Active Vial and Add Inventory dialogs.
 */
$vialPresentations = $mysqli->query("
    SELECT
        vp.vial_presentation_id,
        vp.formulation_id,
        f.name AS formulation_name,
        vp.vial_amount,
        vp.vial_amount_unit
    FROM vial_presentations vp
    JOIN formulations f
        ON f.formulation_id = vp.formulation_id
    ORDER BY f.name, vp.vial_amount
")->fetch_all(MYSQLI_ASSOC);

/*
 * Existing formulations for the Add Peptide / Vial Size dialog.
 */
$existingFormulations = $mysqli->query("
    SELECT formulation_id, name
    FROM formulations
    WHERE active = 1
    ORDER BY name
")->fetch_all(MYSQLI_ASSOC);

/*
 * Active protocol used when a newly-created peptide is optionally added to
 * the current research protocol from the same dialog.
 */
$activeProtocol = $mysqli->query("
    SELECT protocol_id, name
    FROM protocols
    WHERE active = 1
    ORDER BY protocol_id DESC
    LIMIT 1
")->fetch_assoc();


/*
 * Only unopened inventory can be promoted to an active vial.
 * Keep this presentation-specific so 10 mg and 20 mg inventory are not mixed.
 */
$availableVialPresentations = $mysqli->query("
    SELECT
        vp.vial_presentation_id,
        vp.formulation_id,
        f.name AS formulation_name,
        vp.vial_amount,
        vp.vial_amount_unit,
        SUM(
            il.quantity_received
            - COALESCE((
                SELECT SUM(ive.quantity)
                FROM inventory_vial_events ive
                WHERE ive.inventory_lot_id = il.inventory_lot_id
                  AND ive.event_type IN ('opened', 'discarded')
            ), 0)
        ) AS unopened_qty
    FROM vial_presentations vp
    JOIN formulations f
        ON f.formulation_id = vp.formulation_id
    JOIN inventory_lots il
        ON il.vial_presentation_id = vp.vial_presentation_id
    GROUP BY
        vp.vial_presentation_id,
        vp.formulation_id,
        f.name,
        vp.vial_amount,
        vp.vial_amount_unit
    HAVING unopened_qty > 0
    ORDER BY f.name, vp.vial_amount
")->fetch_all(MYSQLI_ASSOC);

/*
 * Weight dashboard.
 *
 * Weekly loss is calculated as:
 * previous week's final recorded weight - current week's latest recorded weight.
 * This matches the spreadsheet-style "current week loss" behavior even if the
 * first weigh-in of the current week occurs after the prior week closed.
 */
$weightRows = $mysqli->query("
    SELECT weight, measured_at
    FROM weight_log
    ORDER BY measured_at ASC
")->fetch_all(MYSQLI_ASSOC);

$latestWeight = $weightRows ? $weightRows[array_key_last($weightRows)] : null;
$startWeight = $weightRows ? (float)$weightRows[0]['weight'] : null;
$currentWeight = $latestWeight ? (float)$latestWeight['weight'] : null;
$lowWeight = null;
$totalLoss = null;
$percentLoss = null;
$thisWeekLoss = null;
$avgWeekLoss = null;
$maxWeekLoss = null;
$weightSparkline = [];

if ($weightRows) {
    $weightsOnly = array_map(
        static fn(array $row): float => (float)$row['weight'],
        $weightRows
    );

    $lowWeight = min($weightsOnly);
    $totalLoss = $startWeight - $currentWeight;
    $percentLoss = $startWeight > 0
        ? ($totalLoss / $startWeight) * 100
        : null;

    /*
     * Keep the last recorded weight for each ISO week.
     */
    $weeklyClosing = [];

    foreach ($weightRows as $row) {
        $dt = new DateTimeImmutable($row['measured_at']);
        $weekKey = $dt->format('o-W');

        $weeklyClosing[$weekKey] = [
            'weight' => (float)$row['weight'],
            'date' => $dt,
        ];
    }

    $weeklyClosing = array_values($weeklyClosing);
    $weeklyLosses = [];

    for ($i = 1, $count = count($weeklyClosing); $i < $count; $i++) {
        $loss = $weeklyClosing[$i - 1]['weight'] - $weeklyClosing[$i]['weight'];
        $weeklyLosses[] = $loss;
    }

    if ($weeklyLosses) {
        $avgWeekLoss = array_sum($weeklyLosses) / count($weeklyLosses);
        $maxWeekLoss = max($weeklyLosses);
        $thisWeekLoss = $weeklyLosses[array_key_last($weeklyLosses)];
    } else {
        $avgWeekLoss = 0.0;
        $maxWeekLoss = 0.0;
        $thisWeekLoss = 0.0;
    }

    /*
     * Last 12 recorded weights for a compact sparkline.
     */
    $weightSparkline = array_slice($weightRows, -12);
}

/*
 * Weekly cumulative half-life carousel.
 *
 * Each plotted point is the SUM of the estimated amount remaining from every
 * recorded administration on or before that point:
 *
 * remaining = dose * 0.5^(elapsed_hours / half_life_hours)
 *
 * This means repeat administrations visibly "stack" instead of replacing the
 * prior dose.
 *
 * Historical rows without an exact administration time use noon so that we do
 * not fabricate a more precise time than the spreadsheet contains.
 */
$todayForWeek = new DateTimeImmutable('today');
$daysSinceSaturday = ((int)$todayForWeek->format('N') + 1) % 7;
$weekStart = $todayForWeek->modify("-{$daysSinceSaturday} days");
$weekEnd = $weekStart->modify('+6 days');

/*
 * Pull enough history for long half-life compounds to contribute to the
 * current week's cumulative level.
 */
$halfLifeSourceRows = $mysqli->query("\n    SELECT\n        f.formulation_id,\n        f.name AS formulation_name,\n\n        p.peptide_id,\n        p.name AS peptide_name,\n        p.half_life_hours,\n\n        fc.amount AS component_amount,\n        fc.amount_unit AS component_amount_unit,\n\n        (\n            SELECT SUM(fc2.amount)\n            FROM formulation_components fc2\n            WHERE\n                fc2.formulation_id = f.formulation_id\n                AND fc2.amount IS NOT NULL\n                AND fc2.amount_unit = fc.amount_unit\n        ) AS formulation_component_total,\n\n        a.administration_id,\n        a.administered_date,\n        a.administered_time,\n        a.dose_amount,\n        a.dose_unit\n\n    FROM formulations f\n\n    JOIN formulation_components fc\n        ON fc.formulation_id = f.formulation_id\n\n    JOIN peptides p\n        ON p.peptide_id = fc.peptide_id\n\n    LEFT JOIN administrations a\n        ON a.formulation_id = f.formulation_id\n       AND a.administered_date <= CURDATE()\n       AND a.administered_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)\n\n    WHERE\n        p.half_life_hours IS NOT NULL\n        AND (\n            a.administration_id IS NOT NULL\n            OR EXISTS (\n                SELECT 1\n                FROM protocol_items pi\n                JOIN protocols pr\n                    ON pr.protocol_id = pi.protocol_id\n                WHERE\n                    pi.formulation_id = f.formulation_id\n                    AND pi.active = 1\n                    AND pr.active = 1\n                    AND pi.effective_from <= CURDATE()\n                    AND (pi.effective_to IS NULL OR pi.effective_to >= CURDATE())\n            )\n        )\n\n    ORDER BY\n        f.name,\n        p.name,\n        a.administered_date,\n        a.administered_time,\n        a.administration_id\n")->fetch_all(MYSQLI_ASSOC);

$normalizeDoseToMcg = static function (float $amount, string $unit): ?float {
    return match (strtolower(trim($unit))) {
        'mcg', 'ug', 'µg' => $amount,
        'mg' => $amount * 1000,
        default => null,
    };
};

$weeklyHalfLifeByFormulation = [];

foreach ($halfLifeSourceRows as $row) {
    $formulationId = (int)$row['formulation_id'];
    $peptideId = (int)$row['peptide_id'];

    if (!isset($weeklyHalfLifeByFormulation[$formulationId])) {
        $weeklyHalfLifeByFormulation[$formulationId] = [
            'formulation_name' => $row['formulation_name'],
            'components' => [],
        ];
    }

    if (!isset($weeklyHalfLifeByFormulation[$formulationId]['components'][$peptideId])) {
        $weeklyHalfLifeByFormulation[$formulationId]['components'][$peptideId] = [
            'peptide_name' => $row['peptide_name'],
            'half_life_hours' => (float)$row['half_life_hours'],
            'administrations' => [],
        ];
    }

    if ($row['administration_id'] === null) {
        continue;
    }

    $doseMcg = $normalizeDoseToMcg(
        (float)$row['dose_amount'],
        (string)$row['dose_unit']
    );

    if ($doseMcg === null) {
        continue;
    }

    /*
     * Convert the recorded formulation dose into the amount represented by
     * this individual component.
     *
     * KLOW uses a special entry convention:
     *
     *   Entered KLOW dose = dose of each 10 mg component.
     *
     * Therefore a recorded KLOW dose of 1 mg represents:
     *   GHK-Cu   = 5 mg
     *   BPC-157  = 1 mg
     *   TB-500   = 1 mg
     *   KPV      = 1 mg
     *
     * This matches the 50/10/10/10 vial composition while keeping the
     * dashboard entry simple.
     */
    $componentDoseMcg = $doseMcg;

    if (strcasecmp((string)$row['formulation_name'], 'KLOW') === 0) {
        $klowDoseMultipliers = [
            'GHK-Cu'  => 5.0,
            'BPC-157' => 1.0,
            'TB-500'  => 1.0,
            'KPV'     => 1.0,
        ];

        $peptideName = (string)$row['peptide_name'];

        if (isset($klowDoseMultipliers[$peptideName])) {
            $componentDoseMcg =
                $doseMcg * $klowDoseMultipliers[$peptideName];
        }
    } elseif (
        $row['component_amount'] !== null
        && $row['formulation_component_total'] !== null
        && (float)$row['formulation_component_total'] > 0
    ) {
        /*
         * Any other blend continues to use proportional splitting based on
         * formulation_components.
         */
        $componentDoseMcg =
            $doseMcg
            * ((float)$row['component_amount'] / (float)$row['formulation_component_total']);
    }

    $weeklyHalfLifeByFormulation[$formulationId]['components'][$peptideId]['administrations'][] = [
        'date' => $row['administered_date'],
        'time' => $row['administered_time'],
        'dose_mcg' => $componentDoseMcg,
    ];
}

$halfLifeCarousel = [];
$dayLabels = [];

for ($d = 0; $d < 7; $d++) {
    $day = $weekStart->modify("+{$d} days");
    $dayLabels[] = $day->format('D');
}

/*
 * The graph now calculates one point PER HOUR across the Saturday-Friday week.
 * That gives us 168 positions:
 *
 *   Sat 12:00 AM ... Fri 11:00 PM
 *
 * Future hours remain NULL. The current-hour point is calculated through the actual current time so a newly recorded dose appears immediately.
 */
$weekHourCount = 168;
$nowForHalfLife = new DateTimeImmutable('now');

foreach ($weeklyHalfLifeByFormulation as $formulation) {
    $rawComponents = [];
    $slidePeakMcg = 0.0;

    foreach ($formulation['components'] as $component) {
        $pointsMcg = [];

        /*
         * Work out which hourly bucket contains the current time. The normal
         * hourly points represent the top of each hour, but the current-hour
         * point must use the ACTUAL current time. Otherwise a dose recorded
         * at (for example) 9:07 AM would not appear until the 10:00 AM point.
         */
        $secondsIntoWeek =
            $nowForHalfLife->getTimestamp() - $weekStart->getTimestamp();

        $currentHourIndex = (int)floor(max(0, $secondsIntoWeek) / 3600);

        for ($hourIndex = 0; $hourIndex < $weekHourCount; $hourIndex++) {
            $hourStart = $weekStart->modify("+{$hourIndex} hours");

            if ($hourStart > $nowForHalfLife) {
                $pointsMcg[] = null;
                continue;
            }

            /*
             * For the current hour, calculate through right now so a newly
             * recorded administration is reflected immediately on refresh.
             */
            $pointTime =
                $hourIndex === $currentHourIndex
                    ? $nowForHalfLife
                    : $hourStart;

            $cumulativeMcg = 0.0;

            foreach ($component['administrations'] as $admin) {
                /*
                 * Imported/backfilled administrations that do not have a
                 * recorded clock time are treated as noon. Rows entered
                 * through the app use their actual administration time.
                 */
                $adminTime = $admin['time'] ?: '12:00:00';
                $adminDateTime = new DateTimeImmutable(
                    $admin['date'] . ' ' . $adminTime
                );

                if ($adminDateTime > $pointTime) {
                    continue;
                }

                $elapsedHours = max(
                    0,
                    ($pointTime->getTimestamp() - $adminDateTime->getTimestamp()) / 3600
                );

                $halfLifeHours = (float)$component['half_life_hours'];

                if ($halfLifeHours <= 0) {
                    continue;
                }

                $cumulativeMcg +=
                    (float)$admin['dose_mcg']
                    * pow(0.5, $elapsedHours / $halfLifeHours);
            }

            $pointsMcg[] = $cumulativeMcg;
            $slidePeakMcg = max($slidePeakMcg, $cumulativeMcg);
        }

        $rawComponents[] = [
            'peptide_name' => $component['peptide_name'],
            'half_life_hours' => $component['half_life_hours'],
            'values_mcg' => $pointsMcg,
        ];
    }

    /*
     * All lines in a blend share the same y-axis and unit. This is important
     * for KLOW so GHK-Cu, BPC-157, TB-500 and KPV can be compared on one graph.
     */
    $displayUnit = $slidePeakMcg >= 1000 ? 'mg' : 'mcg';
    $divisor = $displayUnit === 'mg' ? 1000 : 1;
    $componentCharts = [];

    foreach ($rawComponents as $component) {
        $componentCharts[] = [
            'peptide_name' => $component['peptide_name'],
            'half_life_hours' => $component['half_life_hours'],
            'values' => array_map(
                static fn($value) => $value === null ? null : $value / $divisor,
                $component['values_mcg']
            ),
            'unit' => $displayUnit,
        ];
    }

    if ($componentCharts) {
        $halfLifeCarousel[] = [
            'formulation_name' => $formulation['formulation_name'],
            'components' => $componentCharts,
            'unit' => $displayUnit,
        ];
    }
}

/*
 * Put the most familiar/current items first when present.
 */
$preferredOrder = [
    'Retatrutide',
    'Tesamorelin',
    'AOD-9604',
    '5-Amino-1MQ',
    'MOTS-C',
    'Kisspeptin',
    'ARA-290',
    'Cagrilintide',
    'Epithalon',
    'KLOW',
    'Thymosin Alpha-1',
    'VIP',
];

usort(
    $halfLifeCarousel,
    static function (array $a, array $b) use ($preferredOrder): int {
        $aPos = array_search($a['formulation_name'], $preferredOrder, true);
        $bPos = array_search($b['formulation_name'], $preferredOrder, true);

        $aPos = $aPos === false ? 999 : $aPos;
        $bPos = $bPos === false ? 999 : $bPos;

        return $aPos <=> $bPos;
    }
);
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Peptide Research</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >
    <link href="assets/app.css" rel="stylesheet">
</head>
<body>
<div class="app-layout">
    <aside class="app-sidebar">
        <a class="sidebar-brand" href="index.php">
            <span class="sidebar-brand-mark"><i class="bi bi-activity"></i></span>
            <span>
                <strong>Peptide</strong>
                <small>Research</small>
            </span>
        </a>

        <div class="sidebar-label">Workspace</div>
        <nav class="sidebar-nav" aria-label="Dashboard sections">
            <a class="sidebar-link active" href="#dashboard">
                <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
            </a>
            <a class="sidebar-link" href="history.php">
                <i class="bi bi-graph-up-arrow"></i><span>History</span>
            </a>
            <a class="sidebar-link" href="protocols.php">
                <i class="bi bi-sliders2"></i><span>Protocols</span>
            </a>
            <a class="sidebar-link" href="#schedule">
                <i class="bi bi-calendar2-check"></i><span>Schedule</span>
            </a>
            <a class="sidebar-link" href="#vials">
                <i class="bi bi-droplet-half"></i><span>Active Vials</span>
            </a>
            <a class="sidebar-link" href="#inventory">
                <i class="bi bi-box-seam"></i><span>Inventory</span>
            </a>
        </nav>

        <div class="sidebar-spacer"></div>
        <div class="sidebar-foot">
            <div class="sidebar-foot-icon"><i class="bi bi-shield-check"></i></div>
            <div>
                <strong>Research Tracker</strong>
                <small>Personal dashboard</small>
            </div>
        </div>
    </aside>

    <div class="app-workspace">
        <header class="app-topbar sticky-top">
            <div class="mobile-brand d-lg-none">
                <span class="sidebar-brand-mark"><i class="bi bi-activity"></i></span>
                <strong>Peptide Research</strong>
            </div>

            <div class="topbar-weight ms-lg-0">
                <form method="post" action="actions/add-weight.php" class="topbar-weight-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <div class="topbar-weight-current">
                        <span>Current weight</span>
                        <strong>
                            <?php if ($latestWeight): ?>
                                <?= h(format_dose($latestWeight['weight'])) ?> <small>lb</small>
                            <?php else: ?>
                                --
                            <?php endif; ?>
                        </strong>
                    </div>
                    <div class="input-group input-group-sm topbar-weight-input">
                        <input type="number" class="form-control" name="weight" step="0.1" min="1" required placeholder="Weight" aria-label="Weight">
                        <span class="input-group-text">lb</span>
                        <button class="btn btn-clinical" type="submit">Add</button>
                    </div>
                </form>
            </div>

            <div class="topbar-right ms-auto">
                <div class="dropdown reminder-bell-wrap">
                    <button
                        class="topbar-icon-button"
                        type="button"
                        id="reminderBell"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Upcoming reminders"
                    >
                        <i class="bi bi-bell"></i>
                        <?php if (count($reminders) > 0): ?>
                            <span class="reminder-count-badge"><?= count($reminders) ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end reminder-dropdown p-0" aria-labelledby="reminderBell">
                        <div class="reminder-dropdown-header d-flex justify-content-between align-items-center">
                            <div>
                                <div class="section-title">Upcoming</div>
                                <div class="fw-semibold">Reminders</div>
                            </div>
                            <?php if ($reminders): ?>
                                <span class="badge rounded-pill clinical-badge"><?= count($reminders) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="reminder-dropdown-body">
                            <?php if ($reminders): ?>
                                <?php foreach ($reminders as $reminder): ?>
                                    <div class="reminder-popup-item">
                                        <div class="d-flex justify-content-between gap-3">
                                            <div class="fw-semibold"><?= h($reminder['formulation_name']) ?></div>
                                            <?php if (!empty($reminder['target_date'])): ?>
                                                <div class="small text-body-secondary text-nowrap"><?= h(date('M j', strtotime($reminder['target_date']))) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small-muted mt-1"><?= h($reminder['message']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-3 text-body-secondary small">No upcoming reminders.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="topbar-date">
                    <span>Today</span>
                    <strong><?= h(date('l, F j')) ?></strong>
                </div>
            </div>
        </header>

        <main class="app-content" id="dashboard">


    <?php
    $formatMetric = static function (?float $value, int $decimals = 1): string {
        if ($value === null) {
            return '—';
        }

        return number_format($value, $decimals);
    };

    $formatHalfLife = static function (float $hours): string {
        if ($hours < 1) {
            return number_format($hours * 60, 0) . ' min';
        }

        if ($hours >= 48 && abs($hours / 24 - round($hours / 24)) < 0.01) {
            return number_format($hours / 24, 0) . ' days';
        }

        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.') . ' hr';
    };

    ?>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?= h($flashType) ?> alert-dismissible fade show" role="alert">
            <?= h($flashMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php
    /*
     * Dashboard counts include BOTH scheduled doses and the As Needed section.
     *
     * Scheduled:
     *   taken   => Taken today
     *   pending => Pending
     *
     * As Needed:
     *   administration recorded today => Taken today
     *   no administration today        => Pending
     *
     * This keeps the summary cards consistent with every actionable row
     * displayed on today's dashboard.
     */
    $scheduledTakenCount = count(array_filter(
        $scheduledRows,
        static fn(array $row): bool => $row['status'] === 'taken'
    ));

    $scheduledPendingCount = count(array_filter(
        $scheduledRows,
        static fn(array $row): bool => $row['status'] === 'pending'
    ));

    $asNeededTakenCount = count(array_filter(
        $asNeededRows,
        static fn(array $row): bool => !empty($row['today_administration_id'])
    ));

    $asNeededPendingCount = count(array_filter(
        $asNeededRows,
        static fn(array $row): bool => empty($row['today_administration_id'])
    ));

    $todayTakenCount = $scheduledTakenCount + $asNeededTakenCount;
    $todayPendingCount = $scheduledPendingCount + $asNeededPendingCount;

    $unopenedTotal = array_sum(array_map(static fn(array $row): int => (int)$row['unopened_qty'], $inventoryRows));
    ?>

    <section class="dashboard-hero mb-4">
        <div>
            <div class="eyebrow">Personal Research Dashboard</div>
            <h1>Daily overview</h1>
            <p>Track today's schedule, active preparations, inventory, and weekly trends in one place.</p>
        </div>
        <div class="hero-stats">
            <div class="hero-stat">
                <span class="hero-stat-icon mint"><i class="bi bi-check2-circle"></i></span>
                <span><small>Taken today</small><strong><?= $todayTakenCount ?></strong></span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-icon amber"><i class="bi bi-clock"></i></span>
                <span><small>Pending</small><strong><?= $todayPendingCount ?></strong></span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-icon teal"><i class="bi bi-droplet-half"></i></span>
                <span><small>Active vials</small><strong><?= count($activeVials) ?></strong></span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-icon blue"><i class="bi bi-box-seam"></i></span>
                <span><small>Vials in stock</small><strong><?= $unopenedTotal ?></strong></span>
            </div>
        </div>
    </section>

    <section class="mb-4">
        <div class="row g-3">
            <div class="col-12 col-lg-5">
                <div class="card h-100 dashboard-card weight-panel">
                    <div class="card-body p-0">
                        <div class="px-3 pt-3 pb-2">
                            <div class="section-title">Weight Summary</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle weight-summary-table mb-0">
                                <tbody>
                                <tr>
                                    <th>Start Weight</th>
                                    <td><?= h($formatMetric($startWeight)) ?> lb</td>
                                </tr>
                                <tr>
                                    <th>Current Weight</th>
                                    <td><?= h($formatMetric($currentWeight)) ?> lb</td>
                                </tr>
                                <tr>
                                    <th>Low Weight</th>
                                    <td><?= h($formatMetric($lowWeight)) ?> lb</td>
                                </tr>
                                <tr>
                                    <th>Loss This Week</th>
                                    <td><?= h($formatMetric($thisWeekLoss)) ?> lb</td>
                                </tr>
                                <tr>
                                    <th>Average / Week</th>
                                    <td><?= h($formatMetric($avgWeekLoss)) ?> lb</td>
                                </tr>
                                <tr>
                                    <th>Max Week</th>
                                    <td><?= h($formatMetric($maxWeekLoss)) ?> lb</td>
                                </tr>
                                <tr>
                                    <th>Total Loss</th>
                                    <td><?= h($formatMetric($totalLoss)) ?> lb</td>
                                </tr>
                                <tr>
                                    <th>% Weight Loss</th>
                                    <td><?= h($formatMetric($percentLoss, 2)) ?>%</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-7">
                <div class="card h-100 dashboard-card half-life-carousel-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="section-title">Weekly Half-Life</div>
                            </div>

                            <?php if (count($halfLifeCarousel) > 1): ?>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Half-life carousel controls">
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        id="halfLifePrev"
                                        aria-label="Previous peptide"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        id="halfLifeNext"
                                        aria-label="Next peptide"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($halfLifeCarousel): ?>
                            <div id="halfLifeCarousel">
                                <?php foreach ($halfLifeCarousel as $slideIndex => $slide): ?>
                                    <div
                                        class="half-life-slide <?= $slideIndex === 0 ? '' : 'd-none' ?>"
                                        data-half-life-slide="<?= (int)$slideIndex ?>"
                                    >
                                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                            <div>
                                                <h2 class="h5 mb-1"><?= h($slide['formulation_name']) ?></h2>

                                                <?php if (count($slide['components']) > 1): ?>
                                                    <div class="small-muted">
                                                        Blend · <?= count($slide['components']) ?> tracked components
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="small-muted">
                                                <?= ($slideIndex + 1) ?> / <?= count($halfLifeCarousel) ?>
                                            </div>
                                        </div>

                                        <?php
                                        $plotWidth = 600;
                                        $plotHeight = 190;
                                        $leftPad = 52;
                                        $rightPad = 18;
                                        $topPad = 22;
                                        $bottomPad = 34;
                                        $innerWidth = $plotWidth - $leftPad - $rightPad;
                                        $innerHeight = $plotHeight - $topPad - $bottomPad;

                                        $allValues = [];
                                        foreach ($slide['components'] as $component) {
                                            foreach ($component['values'] as $value) {
                                                if ($value !== null) {
                                                    $allValues[] = (float)$value;
                                                }
                                            }
                                        }

                                        $maxValue = $allValues ? max($allValues) : 0.0;
                                        $axisMax = $maxValue > 0 ? $maxValue * 1.15 : 1.0;
                                        $series = [];

                                        foreach ($slide['components'] as $componentIndex => $component) {
                                            $plotPoints = [];
                                            $polylineParts = [];

                                            foreach ($component['values'] as $i => $value) {
                                                if ($value === null) {
                                                    $plotPoints[] = null;
                                                    continue;
                                                }

                                                $x = $leftPad + ($i / ($weekHourCount - 1)) * $innerWidth;
                                                $y = $topPad + (1 - ((float)$value / $axisMax)) * $innerHeight;
                                                $point = [
                                                    'x' => $x,
                                                    'y' => $y,
                                                    'value' => (float)$value,
                                                ];
                                                $plotPoints[] = $point;
                                                $polylineParts[] =
                                                    number_format($x, 2, '.', '') . ',' .
                                                    number_format($y, 2, '.', '');
                                            }

                                            $series[] = [
                                                'name' => $component['peptide_name'],
                                                'half_life_hours' => $component['half_life_hours'],
                                                'points' => $plotPoints,
                                                'polyline' => implode(' ', $polylineParts),
                                                'class_index' => $componentIndex % 6,
                                            ];
                                        }
                                        ?>

                                        <?php if (count($slide['components']) > 1): ?>
                                            <div class="half-life-legend mb-2">
                                                <?php foreach ($series as $seriesItem): ?>
                                                    <span class="half-life-legend-item">
                                                        <span class="half-life-legend-line series-<?= (int)$seriesItem['class_index'] ?>"></span>
                                                        <?= h($seriesItem['name']) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="half-life-line-wrap">
                                            <svg
                                                class="half-life-line-chart"
                                                viewBox="0 0 <?= $plotWidth ?> <?= $plotHeight ?>"
                                                role="img"
                                                aria-label="<?= h($slide['formulation_name']) ?> estimated cumulative amount remaining this week"
                                            >
                                                <?php foreach ([0, 0.25, 0.50, 0.75, 1.0] as $tickFraction): ?>
                                                    <?php
                                                    $tickValue = $axisMax * $tickFraction;
                                                    $tickY = $topPad + (1 - $tickFraction) * $innerHeight;
                                                    ?>
                                                    <line
                                                        x1="<?= $leftPad ?>"
                                                        x2="<?= $plotWidth - $rightPad ?>"
                                                        y1="<?= h(number_format($tickY, 2, '.', '')) ?>"
                                                        y2="<?= h(number_format($tickY, 2, '.', '')) ?>"
                                                        class="half-life-grid-line"
                                                    ></line>

                                                    <text
                                                        x="<?= $leftPad - 8 ?>"
                                                        y="<?= h(number_format($tickY + 4, 2, '.', '')) ?>"
                                                        text-anchor="end"
                                                        class="half-life-axis-label"
                                                    ><?= h(number_format($tickValue, $slide['unit'] === 'mg' ? 2 : 0)) ?></text>
                                                <?php endforeach; ?>

                                                <?php for ($dayIndex = 0; $dayIndex < 7; $dayIndex++): ?>
                                                    <?php
                                                    /*
                                                     * Put each day label around the center of its
                                                     * 24-hour section rather than on a single daily
                                                     * data point.
                                                     */
                                                    $labelHour = ($dayIndex * 24) + 12;
                                                    $x = $leftPad
                                                        + ($labelHour / ($weekHourCount - 1))
                                                        * $innerWidth;
                                                    ?>
                                                    <text
                                                        x="<?= h(number_format($x, 2, '.', '')) ?>"
                                                        y="<?= $plotHeight - 9 ?>"
                                                        text-anchor="middle"
                                                        class="half-life-axis-label"
                                                    ><?= h($dayLabels[$dayIndex]) ?></text>
                                                <?php endfor; ?>

                                                <?php foreach ($series as $seriesItem): ?>
                                                    <?php if ($seriesItem['polyline'] !== ''): ?>
                                                        <polyline
                                                            points="<?= h($seriesItem['polyline']) ?>"
                                                            fill="none"
                                                            class="half-life-line series-<?= (int)$seriesItem['class_index'] ?>"
                                                            vector-effect="non-scaling-stroke"
                                                        ></polyline>
                                                    <?php endif; ?>

                                                    <?php
                                                    $lastKnownPointIndex = null;

                                                    foreach ($seriesItem['points'] as $candidateIndex => $candidatePoint) {
                                                        if ($candidatePoint !== null) {
                                                            $lastKnownPointIndex = $candidateIndex;
                                                        }
                                                    }
                                                    ?>

                                                    <?php foreach ($seriesItem['points'] as $pointIndex => $point): ?>
                                                        <?php
                                                        /*
                                                         * The line itself uses all 168 hourly points.
                                                         * Only mark midnight and the current/latest
                                                         * point so the graph stays readable.
                                                         */
                                                        $showPoint =
                                                            $point !== null
                                                            && (
                                                                $pointIndex % 24 === 0
                                                                || $pointIndex === $lastKnownPointIndex
                                                            );
                                                        ?>

                                                        <?php if ($showPoint): ?>
                                                            <circle
                                                                cx="<?= h(number_format($point['x'], 2, '.', '')) ?>"
                                                                cy="<?= h(number_format($point['y'], 2, '.', '')) ?>"
                                                                r="3.5"
                                                                class="half-life-point series-<?= (int)$seriesItem['class_index'] ?>"
                                                            ></circle>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>

                                                <text
                                                    x="<?= $leftPad ?>"
                                                    y="12"
                                                    class="half-life-unit-label"
                                                >Estimated amount remaining (<?= h($slide['unit']) ?>)</text>
                                            </svg>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-body-secondary py-4 text-center">
                                No half-life data available for recent administrations.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php
    $sections = [
        'morning' => ['Morning', 'bi-sunrise'],
        'afternoon' => ['Afternoon', 'bi-sun'],
        'evening' => ['Evening', 'bi-sunset'],
        'night' => ['Night', 'bi-moon-stars'],
        'any' => ['Any Time', 'bi-clock'],
    ];
    ?>

    <div id="schedule" class="d-flex justify-content-between align-items-center gap-3 mb-3 schedule-toolbar">
        <div>
            <span class="section-kicker">Today</span>
            <h2 class="management-title mb-0">Daily Doses</h2>
        </div>
        <button
            type="button"
            class="btn btn-clinical btn-sm"
            data-bs-toggle="modal"
            data-bs-target="#addTodayDoseModal"
            <?= !$manualDoseOptions ? 'disabled' : '' ?>
        >
            <i class="bi bi-plus-lg me-1"></i>Add Today's Dose
        </button>
    </div>

    <?php foreach ($sections as $key => [$label, $icon]): ?>
        <?php if (!$scheduledByTime[$key]) continue; ?>

        <section class="mb-4 schedule-section">
            <div class="section-heading d-flex align-items-center gap-2 mb-2">
                <i class="bi <?= h($icon) ?>"></i>
                <h2 class="h5 mb-0"><?= h($label) ?></h2>
            </div>

            <div class="card schedule-table-card">
                <div class="schedule-head d-none d-md-grid">
                    <div>Peptide</div>
                    <div>Scheduled</div>
                    <div>Dose Taken</div>
                    <div>Status</div>
                    <div></div>
                </div>

                <?php foreach ($scheduledByTime[$key] as $dose): ?>
                    <?php
                    $statusClass = match ($dose['status']) {
                        'taken' => 'text-bg-success',
                        'skipped' => 'text-bg-secondary',
                        'cancelled' => 'text-bg-dark',
                        default => 'text-bg-warning',
                    };
                    ?>

                    <div class="schedule-row <?= $dose['status'] === 'taken' ? 'schedule-row-taken' : '' ?>">
                        <div class="schedule-name">
                            <div class="fw-semibold"><?= h($dose['formulation_name']) ?></div>
                            <div class="small-muted d-md-none">
                                Scheduled:
                                <?= h(format_dose($dose['dose_amount'])) ?>
                                <?= h($dose['dose_unit']) ?>
                            </div>
                        </div>

                        <div class="schedule-planned d-none d-md-block">
                            <?= h(format_dose($dose['dose_amount'])) ?>
                            <?= h($dose['dose_unit']) ?>
                        </div>

                        <?php if ($dose['status'] === 'pending'): ?>
                            <form
                                method="post"
                                action="actions/take-dose.php"
                                class="dose-inline-form"
                                data-scheduled-dose="<?= h(format_dose($dose['dose_amount'])) ?>"
                                data-unit="<?= h($dose['dose_unit']) ?>"
                                onsubmit="return confirmDoseChange(this);"
                            >
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="scheduled_dose_id" value="<?= (int)$dose['scheduled_dose_id'] ?>">
                                <input type="hidden" name="dose_unit" value="<?= h($dose['dose_unit']) ?>">

                                <div class="input-group input-group-sm">
                                    <input
                                        type="number"
                                        class="form-control dose-amount-input"
                                        name="dose_amount"
                                        value="<?= h(format_dose($dose['dose_amount'])) ?>"
                                        step="0.0001"
                                        min="0.0001"
                                        required
                                        aria-label="Dose taken"
                                    >
                                    <span class="input-group-text"><?= h($dose['dose_unit']) ?></span>
                                </div>

                                <button class="btn btn-primary btn-sm take-button" type="submit">
                                    <i class="bi bi-check2 me-1"></i>Taken
                                </button>
                            </form>
                        <?php elseif ($dose['status'] === 'taken'): ?>
                            <div class="taken-dose-display">
                                <span class="taken-dose-amount">
                                    <i class="bi bi-check-circle-fill me-1"></i>
                                    <?= h(format_dose($dose['actual_dose_amount'] ?? $dose['dose_amount'])) ?>
                                    <?= h($dose['actual_dose_unit'] ?? $dose['dose_unit']) ?>
                                </span>

                                <?php if (!empty($dose['actual_administered_time'])): ?>
                                    <span class="small-muted ms-2">
                                        <?= h(date('g:i A', strtotime($dose['actual_administered_time']))) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="small-muted">—</div>
                        <?php endif; ?>

                        <div class="schedule-status">
                            <span class="badge <?= h($statusClass) ?>">
                                <?= h(ucfirst($dose['status'])) ?>
                            </span>
                        </div>

                        <div class="schedule-actions">
                            <?php if ($dose['status'] === 'pending'): ?>
                                <form method="post" action="actions/skip-dose.php">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="scheduled_dose_id" value="<?= (int)$dose['scheduled_dose_id'] ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">
                                        Skip
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if (!$scheduledRows): ?>
        <div class="card mb-4">
            <div class="card-body py-5 text-center">
                <i class="bi bi-calendar-check fs-1 text-body-secondary"></i>
                <h2 class="h5 mt-3">Nothing scheduled today</h2>
                <p class="text-body-secondary mb-0">
                    Today's scheduler has no planned doses.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($asNeededRows): ?>
        <section class="mb-4 schedule-section">
            <div class="section-heading mb-2"><div><span class="section-kicker">Flexible</span><h2>As Needed</h2></div></div>
            <div class="card schedule-table-card">
                <div class="schedule-head asneeded-head d-none d-md-grid">
                    <div>Peptide</div>
                    <div>Default</div>
                    <div>Dose Taken</div>
                    <div>Time</div>
                    <div></div>
                </div>

                <?php foreach ($asNeededRows as $row): ?>
                    <?php $asNeededTaken = !empty($row['today_administration_id']); ?>

                    <div class="schedule-row asneeded-row <?= $asNeededTaken ? 'schedule-row-taken' : '' ?>">
                        <div class="schedule-name">
                            <div class="fw-semibold"><?= h($row['formulation_name']) ?></div>
                            <div class="small-muted d-md-none">
                                Default:
                                <?= h(format_dose($row['dose_amount'])) ?>
                                <?= h($row['dose_unit']) ?>
                            </div>
                        </div>

                        <div class="schedule-planned d-none d-md-block">
                            <?= h(format_dose($row['dose_amount'])) ?>
                            <?= h($row['dose_unit']) ?>
                        </div>

                        <?php if ($asNeededTaken): ?>
                            <div class="taken-dose-display">
                                <span class="taken-dose-amount">
                                    <i class="bi bi-check-circle-fill me-1"></i>
                                    <?= h(format_dose($row['actual_dose_amount'])) ?>
                                    <?= h($row['actual_dose_unit']) ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <form
                                method="post"
                                action="actions/take-as-needed.php"
                                class="dose-inline-form asneeded-inline-form"
                                data-default-dose="<?= h(format_dose($row['dose_amount'])) ?>"
                                data-unit="<?= h($row['dose_unit']) ?>"
                                onsubmit="return confirmAsNeededDoseChange(this);"
                            >
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="protocol_item_id" value="<?= (int)$row['protocol_item_id'] ?>">
                                <input type="hidden" name="dose_unit" value="<?= h($row['dose_unit']) ?>">

                                <div class="input-group input-group-sm">
                                    <input
                                        type="number"
                                        class="form-control dose-amount-input"
                                        name="dose_amount"
                                        value="<?= h(format_dose($row['dose_amount'])) ?>"
                                        step="0.0001"
                                        min="0.0001"
                                        required
                                        aria-label="As-needed dose taken"
                                    >
                                    <span class="input-group-text"><?= h($row['dose_unit']) ?></span>
                                </div>

                                <button class="btn btn-primary btn-sm take-button" type="submit">
                                    <i class="bi bi-check2 me-1"></i>Taken
                                </button>
                            </form>
                        <?php endif; ?>

                        <div class="small-muted">
                            <?php if ($asNeededTaken && !empty($row['actual_administered_time'])): ?>
                                <?= h(date('g:i A', strtotime($row['actual_administered_time']))) ?>
                            <?php else: ?>
                                <?= h(ucfirst($row['time_of_day'])) ?>
                            <?php endif; ?>
                        </div>

                        <div class="text-end">
                            <?php if ($asNeededTaken): ?>
                                <span class="badge text-bg-success">Taken</span>
                            <?php else: ?>
                                <span class="badge badge-soft">As needed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12" id="vials">
            <div class="card management-card">
                <div class="card-body">
                    <div class="management-heading d-flex justify-content-between align-items-center gap-3 mb-3">
                        <div><span class="section-kicker">Prepared</span><h2 class="management-title mb-0">Active Vials</h2></div>
                        <button
                            type="button"
                            class="btn btn-clinical btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#addActiveVialModal"
                        >
                            <i class="bi bi-plus-lg me-1"></i>Add Active Vial
                        </button>
                    </div>

                    <?php if ($activeVials): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle active-vial-table mb-0">
                                <thead>
                                <tr>
                                    <th>Peptide</th>
                                    <th>Vial</th>
                                    <th>Mixed</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($activeVials as $vial): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h($vial['formulation_name']) ?></td>
                                        <td>
                                            <?= h(format_dose($vial['vial_amount'])) ?>
                                            <?= h($vial['vial_amount_unit']) ?>
                                        </td>
                                        <td><?= h(date('M j', strtotime($vial['prepared_at']))) ?></td>
                                        <td><span class="badge text-bg-success">Active</span></td>
                                        <td class="text-end">
                                            <form
                                                method="post"
                                                action="actions/finish-vial.php"
                                                class="d-inline"
                                                onsubmit="return confirm('Mark this <?= h($vial['formulation_name']) ?> vial as completely used?');"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="prepared_vial_id" value="<?= (int)$vial['prepared_vial_id'] ?>">
                                                <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                    <i class="bi bi-check2-circle me-1"></i>Used It All
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-body-secondary">No active prepared vials recorded.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12" id="inventory">
            <div class="card management-card">
                <div class="card-body">
                    <div class="management-heading d-flex justify-content-between align-items-center gap-3 mb-3">
                        <div><span class="section-kicker">Stock</span><h2 class="management-title mb-0">Inventory</h2></div>
                        <div class="d-flex gap-2 flex-wrap justify-content-end">
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                data-bs-toggle="modal"
                                data-bs-target="#addPeptideModal"
                            >
                                <i class="bi bi-plus-circle me-1"></i>Add Peptide / Vial Size
                            </button>

                            <button
                                type="button"
                                class="btn btn-clinical btn-sm"
                                data-bs-toggle="modal"
                                data-bs-target="#addInventoryModal"
                            >
                                <i class="bi bi-plus-lg me-1"></i>Add Inventory
                            </button>
                        </div>
                    </div>

                    <?php if ($inventoryRows): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle inventory-table mb-0">
                                <thead>
                                <tr>
                                    <th>Peptide</th>
                                    <th>Vial Size</th>
                                    <th class="text-end">Vials In Inventory</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($inventoryRows as $row): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h($row['formulation_name']) ?></td>
                                        <td>
                                            <span class="inventory-size-pill">
                                                <?= h(format_dose($row['vial_amount'])) ?>
                                                <?= h($row['vial_amount_unit']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end fw-semibold"><?= (int)$row['unopened_qty'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-body-secondary">No unopened inventory entered yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Today's Dose -->
    <div class="modal fade" id="addTodayDoseModal" tabindex="-1" aria-labelledby="addTodayDoseLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="actions/add-today-dose.php">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5" id="addTodayDoseLabel">Add Today's Dose</h2>
                            <div class="small text-body-secondary mt-1">Manually place an active peptide on today's schedule.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                        <?php if ($manualDoseOptions): ?>
                            <div class="mb-3">
                                <label for="manual_protocol_item_id" class="form-label">Peptide</label>
                                <select class="form-select" id="manual_protocol_item_id" name="protocol_item_id" required>
                                    <option value="">Choose a peptide...</option>
                                    <?php foreach ($manualDoseOptions as $option): ?>
                                        <option
                                            value="<?= (int)$option['protocol_item_id'] ?>"
                                            data-dose="<?= h(format_dose($option['dose_amount'])) ?>"
                                            data-unit="<?= h($option['dose_unit']) ?>"
                                            data-time="<?= h($option['time_of_day']) ?>"
                                        >
                                            <?= h($option['formulation_name']) ?> —
                                            <?= h(format_dose($option['dose_amount'])) ?> <?= h($option['dose_unit']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-3">
                                <div class="col-7">
                                    <label for="manual_dose_amount" class="form-label">Dose</label>
                                    <input type="number" class="form-control" id="manual_dose_amount" name="dose_amount" min="0.0001" step="0.0001" required>
                                </div>
                                <div class="col-5">
                                    <label for="manual_dose_unit" class="form-label">Unit</label>
                                    <select class="form-select" id="manual_dose_unit" name="dose_unit" required>
                                        <option value="mg">mg</option>
                                        <option value="mcg">mcg</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="manual_time_of_day" class="form-label">Time of day</label>
                                    <select class="form-select" id="manual_time_of_day" name="time_of_day" required>
                                        <option value="morning">Morning</option>
                                        <option value="afternoon">Afternoon</option>
                                        <option value="evening">Evening</option>
                                        <option value="night">Night</option>
                                    </select>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-light border mb-0">
                                Every active-vial peptide with a current protocol is already on today's schedule, or no eligible active peptide is available.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" <?= !$manualDoseOptions ? 'disabled' : '' ?>>Add to Today</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Active Vial -->
    <div class="modal fade" id="addActiveVialModal" tabindex="-1" aria-labelledby="addActiveVialLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="actions/add-active-vial.php">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="addActiveVialLabel">Add Active Vial</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                        <label for="active_vial_presentation_id" class="form-label">Peptide / vial</label>
                        <select class="form-select" id="active_vial_presentation_id" name="vial_presentation_id" required>
                            <option value="">Choose a vial...</option>
                            <?php foreach ($availableVialPresentations as $presentation): ?>
                                <option value="<?= (int)$presentation['vial_presentation_id'] ?>">
                                    <?= h($presentation['formulation_name']) ?> —
                                    <?= h(format_dose($presentation['vial_amount'])) ?>
                                    <?= h($presentation['vial_amount_unit']) ?>
                                    (<?= (int)$presentation['unopened_qty'] ?> in inventory)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (!$availableVialPresentations): ?>
                            <div class="alert alert-warning mt-3 mb-0 py-2">
                                No unopened inventory is available. Add inventory before making a vial active.
                            </div>
                        <?php else: ?>
                            <div class="form-text mt-2">
                                Starting a vial moves one unopened vial of this exact size from inventory to active. You may have more than one active vial of the same peptide.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" <?= !$availableVialPresentations ? 'disabled' : '' ?>>Add Active Vial</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Peptide / Vial Size -->
    <div class="modal fade" id="addPeptideModal" tabindex="-1" aria-labelledby="addPeptideLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="actions/add-peptide.php" id="addPeptideForm">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5" id="addPeptideLabel">Add Peptide / Vial Size</h2>
                            <div class="small text-body-secondary mt-1">
                                Create a new peptide or add another vial size to one already in the system.
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                        <div class="mb-3">
                            <label for="setup_formulation_id" class="form-label">Peptide</label>
                            <select class="form-select" id="setup_formulation_id" name="formulation_id">
                                <option value="">Create a new peptide</option>
                                <?php foreach ($existingFormulations as $formulation): ?>
                                    <option value="<?= (int)$formulation['formulation_id'] ?>">
                                        <?= h($formulation['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Select an existing peptide to add another vial size, or leave this on “Create a new peptide.”
                            </div>
                        </div>

                        <div id="newPeptideFields">
                            <div class="row g-3 mb-3">
                                <div class="col-12 col-md-8">
                                    <label for="new_peptide_name" class="form-label">Peptide name</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="new_peptide_name"
                                        name="peptide_name"
                                        maxlength="150"
                                        placeholder="Example: New Peptide"
                                    >
                                </div>

                                <div class="col-12 col-md-4">
                                    <label for="new_peptide_short_name" class="form-label">Short name</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="new_peptide_short_name"
                                        name="short_name"
                                        maxlength="50"
                                        placeholder="Optional"
                                    >
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="new_peptide_half_life" class="form-label">Half-life (hours)</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="new_peptide_half_life"
                                    name="half_life_hours"
                                    min="0"
                                    step="0.0001"
                                    placeholder="Optional"
                                >
                                <div class="form-text">
                                    Leave blank if you do not want this peptide included in half-life graphs yet.
                                </div>
                            </div>

                            <?php if ($activeProtocol): ?>
                                <div class="protocol-builder mt-4">
                                    <div class="form-check form-switch mb-3">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            id="add_to_protocol"
                                            name="add_to_protocol"
                                            value="1"
                                        >
                                        <label class="form-check-label fw-semibold" for="add_to_protocol">
                                            Add to <?= h($activeProtocol['name']) ?>
                                        </label>
                                        <div class="form-text">
                                            Set the dose and schedule now so the new peptide is immediately part of your active protocol.
                                        </div>
                                    </div>

                                    <div id="protocolFields" class="d-none">
                                        <div class="row g-3 mb-3">
                                            <div class="col-12 col-md-6">
                                                <label for="protocol_schedule_type" class="form-label">Schedule</label>
                                                <select class="form-select" id="protocol_schedule_type" name="protocol_schedule_type">
                                                    <option value="scheduled" selected>Scheduled</option>
                                                    <option value="as_needed">As needed</option>
                                                </select>
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label for="protocol_time_of_day" class="form-label">Time of day</label>
                                                <select class="form-select" id="protocol_time_of_day" name="protocol_time_of_day">
                                                    <option value="morning" selected>Morning</option>
                                                    <option value="afternoon">Afternoon</option>
                                                    <option value="evening">Evening</option>
                                                    <option value="night">Night</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row g-3 mb-3">
                                            <div class="col-7">
                                                <label for="protocol_dose_amount" class="form-label">Dose</label>
                                                <input type="number" class="form-control" id="protocol_dose_amount" name="protocol_dose_amount" min="0.0001" step="0.0001" placeholder="1">
                                            </div>
                                            <div class="col-5">
                                                <label for="protocol_dose_unit" class="form-label">Unit</label>
                                                <select class="form-select" id="protocol_dose_unit" name="protocol_dose_unit">
                                                    <option value="mg" selected>mg</option>
                                                    <option value="mcg">mcg</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row g-3 mb-3">
                                            <div class="col-12 col-md-6">
                                                <label for="protocol_effective_from" class="form-label">Starts</label>
                                                <input type="date" class="form-control" id="protocol_effective_from" name="protocol_effective_from" value="<?= h(date('Y-m-d')) ?>">
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label for="protocol_mix_reminder_days" class="form-label">Mix reminder</label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="protocol_mix_reminder_days" name="protocol_mix_reminder_days" min="0" step="1" value="2">
                                                    <span class="input-group-text">days</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="scheduledProtocolFields">
                                            <label class="form-label">Days of week</label>
                                            <div class="protocol-weekdays mb-3">
                                                <?php
                                                $weekdayOptions = [
                                                    1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
                                                    5 => 'Fri', 6 => 'Sat', 7 => 'Sun'
                                                ];
                                                ?>
                                                <?php foreach ($weekdayOptions as $weekdayNumber => $weekdayLabel): ?>
                                                    <input class="btn-check protocol-weekday" type="checkbox" name="protocol_weekdays[]" value="<?= $weekdayNumber ?>" id="protocol_day_<?= $weekdayNumber ?>">
                                                    <label class="btn btn-outline-secondary btn-sm" for="protocol_day_<?= $weekdayNumber ?>"><?= h($weekdayLabel) ?></label>
                                                <?php endforeach; ?>
                                            </div>

                                            <div class="row g-3 mb-3">
                                                <div class="col-12 col-md-5">
                                                    <label for="protocol_cycle_mode" class="form-label">Cycle</label>
                                                    <select class="form-select" id="protocol_cycle_mode" name="protocol_cycle_mode">
                                                        <option value="none" selected>No cycle</option>
                                                        <option value="repeating">Repeating on/off cycle</option>
                                                        <option value="finite">One finite run</option>
                                                    </select>
                                                </div>
                                                <div class="col-12 col-md-7 d-none" id="cycleAnchorWrap">
                                                    <label for="protocol_cycle_anchor_date" class="form-label">Cycle starts</label>
                                                    <input type="date" class="form-control" id="protocol_cycle_anchor_date" name="protocol_cycle_anchor_date" value="<?= h(date('Y-m-d')) ?>">
                                                </div>
                                            </div>

                                            <div class="row g-3 mb-3 d-none" id="cycleLengthFields">
                                                <div class="col-6">
                                                    <label for="protocol_run_days" class="form-label">Days on</label>
                                                    <input type="number" class="form-control" id="protocol_run_days" name="protocol_run_days" min="1" step="1" placeholder="42">
                                                </div>
                                                <div class="col-6" id="offDaysWrap">
                                                    <label for="protocol_off_days" class="form-label">Days off</label>
                                                    <input type="number" class="form-control" id="protocol_off_days" name="protocol_off_days" min="0" step="1" value="0">
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label for="protocol_notes" class="form-label">Protocol notes</label>
                                            <textarea class="form-control" id="protocol_notes" name="protocol_notes" rows="2" placeholder="Optional"></textarea>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-light border mt-3 mb-0 small">
                                    No active protocol was found. The peptide and vial size can still be created.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-7">
                                <label for="new_vial_amount" class="form-label">Vial size</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="new_vial_amount"
                                    name="vial_amount"
                                    min="0.0001"
                                    step="0.0001"
                                    required
                                    placeholder="10"
                                >
                            </div>

                            <div class="col-5">
                                <label for="new_vial_unit" class="form-label">Unit</label>
                                <select class="form-select" id="new_vial_unit" name="vial_amount_unit" required>
                                    <option value="mg" selected>mg</option>
                                    <option value="mcg">mcg</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Inventory -->
    <div class="modal fade" id="addInventoryModal" tabindex="-1" aria-labelledby="addInventoryLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="actions/add-inventory.php">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="addInventoryLabel">Add Inventory</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                        <div class="mb-3">
                            <label for="inventory_vial_presentation_id" class="form-label">Peptide / vial</label>
                            <select class="form-select" id="inventory_vial_presentation_id" name="vial_presentation_id" required>
                                <option value="">Choose a vial...</option>
                                <?php foreach ($vialPresentations as $presentation): ?>
                                    <option value="<?= (int)$presentation['vial_presentation_id'] ?>">
                                        <?= h($presentation['formulation_name']) ?> —
                                        <?= h(format_dose($presentation['vial_amount'])) ?>
                                        <?= h($presentation['vial_amount_unit']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="inventory_quantity" class="form-label">Number of vials</label>
                            <input
                                type="number"
                                class="form-control"
                                id="inventory_quantity"
                                name="quantity"
                                min="1"
                                step="1"
                                value="1"
                                required
                            >
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Inventory</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </div>
    </div>
</main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>
function confirmDoseChange(form) {
    const input = form.querySelector('input[name="dose_amount"]');
    const entered = parseFloat(input.value);
    const scheduled = parseFloat(form.dataset.scheduledDose);
    const unit = form.dataset.unit;

    if (!Number.isFinite(entered) || entered <= 0) {
        return false;
    }

    if (Number.isFinite(scheduled) && Math.abs(entered - scheduled) > 0.000001) {
        return window.confirm(
            'Dose changed from ' +
            form.dataset.scheduledDose + ' ' + unit +
            ' to ' + input.value + ' ' + unit +
            '.\n\nThis will record the entered dose today and update the protocol dose going forward.'
        );
    }

    return true;
}

function confirmAsNeededDoseChange(form) {
    const input = form.querySelector('input[name="dose_amount"]');
    const entered = parseFloat(input.value);
    const defaultDose = parseFloat(form.dataset.defaultDose);
    const unit = form.dataset.unit;

    if (!Number.isFinite(entered) || entered <= 0) {
        return false;
    }

    if (Number.isFinite(defaultDose) && Math.abs(entered - defaultDose) > 0.000001) {
        return window.confirm(
            'As-needed dose changed from ' +
            form.dataset.defaultDose + ' ' + unit +
            ' to ' + input.value + ' ' + unit +
            '.\n\nThis will record the entered dose today and make it the new default as-needed dose going forward.'
        );
    }

    return true;
}

const halfLifeSlides = Array.from(document.querySelectorAll('[data-half-life-slide]'));
const halfLifePrev = document.getElementById('halfLifePrev');
const halfLifeNext = document.getElementById('halfLifeNext');
let halfLifeIndex = 0;

function showHalfLifeSlide(index) {
    if (!halfLifeSlides.length) {
        return;
    }

    halfLifeIndex = (index + halfLifeSlides.length) % halfLifeSlides.length;

    halfLifeSlides.forEach((slide, i) => {
        slide.classList.toggle('d-none', i !== halfLifeIndex);
    });
}

if (halfLifePrev) {
    halfLifePrev.addEventListener('click', () => {
        showHalfLifeSlide(halfLifeIndex - 1);
    });
}

if (halfLifeNext) {
    halfLifeNext.addEventListener('click', () => {
        showHalfLifeSlide(halfLifeIndex + 1);
    });
}

const setupFormulationSelect = document.getElementById('setup_formulation_id');
const newPeptideFields = document.getElementById('newPeptideFields');
const newPeptideName = document.getElementById('new_peptide_name');

function updatePeptideSetupMode() {
    if (!setupFormulationSelect || !newPeptideFields || !newPeptideName) {
        return;
    }

    const creatingNew = setupFormulationSelect.value === '';
    newPeptideFields.classList.toggle('d-none', !creatingNew);
    newPeptideName.required = creatingNew;
}

if (setupFormulationSelect) {
    setupFormulationSelect.addEventListener('change', updatePeptideSetupMode);
    updatePeptideSetupMode();
}

const addToProtocol = document.getElementById('add_to_protocol');
const protocolFields = document.getElementById('protocolFields');
const protocolScheduleType = document.getElementById('protocol_schedule_type');
const scheduledProtocolFields = document.getElementById('scheduledProtocolFields');
const protocolDoseAmount = document.getElementById('protocol_dose_amount');
const protocolEffectiveFrom = document.getElementById('protocol_effective_from');
const protocolCycleMode = document.getElementById('protocol_cycle_mode');
const cycleAnchorWrap = document.getElementById('cycleAnchorWrap');
const cycleLengthFields = document.getElementById('cycleLengthFields');
const offDaysWrap = document.getElementById('offDaysWrap');
const protocolRunDays = document.getElementById('protocol_run_days');

function updateProtocolBuilder() {
    if (!addToProtocol || !protocolFields) return;

    const enabled = addToProtocol.checked;
    protocolFields.classList.toggle('d-none', !enabled);

    if (protocolDoseAmount) protocolDoseAmount.required = enabled;
    if (protocolEffectiveFrom) protocolEffectiveFrom.required = enabled;

    const scheduled = enabled && protocolScheduleType && protocolScheduleType.value === 'scheduled';
    if (scheduledProtocolFields) scheduledProtocolFields.classList.toggle('d-none', !scheduled);

    updateCycleFields();
}

function updateCycleFields() {
    if (!protocolCycleMode || !cycleAnchorWrap || !cycleLengthFields) return;

    const protocolEnabled = !!(addToProtocol && addToProtocol.checked);
    const scheduled = !!(protocolScheduleType && protocolScheduleType.value === 'scheduled');
    const mode = protocolCycleMode.value;
    const usesCycle = protocolEnabled && scheduled && mode !== 'none';

    cycleAnchorWrap.classList.toggle('d-none', !usesCycle);
    cycleLengthFields.classList.toggle('d-none', !usesCycle);
    if (offDaysWrap) offDaysWrap.classList.toggle('d-none', mode === 'finite');
    if (protocolRunDays) protocolRunDays.required = usesCycle;
}

if (addToProtocol) addToProtocol.addEventListener('change', updateProtocolBuilder);
if (protocolScheduleType) protocolScheduleType.addEventListener('change', updateProtocolBuilder);
if (protocolCycleMode) protocolCycleMode.addEventListener('change', updateCycleFields);
updateProtocolBuilder();


const manualDoseSelect = document.getElementById('manual_protocol_item_id');
const manualDoseAmount = document.getElementById('manual_dose_amount');
const manualDoseUnit = document.getElementById('manual_dose_unit');
const manualTimeOfDay = document.getElementById('manual_time_of_day');

function fillManualDoseDefaults() {
    if (!manualDoseSelect || !manualDoseSelect.selectedOptions.length) return;
    const option = manualDoseSelect.selectedOptions[0];
    if (!option.value) return;
    if (manualDoseAmount) manualDoseAmount.value = option.dataset.dose || '';
    if (manualDoseUnit && option.dataset.unit) manualDoseUnit.value = option.dataset.unit;
    if (manualTimeOfDay && option.dataset.time) manualTimeOfDay.value = option.dataset.time;
}

if (manualDoseSelect) {
    manualDoseSelect.addEventListener('change', fillManualDoseDefaults);
}

</script>
</body>
</html>
