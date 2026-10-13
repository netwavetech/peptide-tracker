<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
app_require_auth();

session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$today = date('Y-m-d');

/* -----------------------------
 * Weight history
 * ----------------------------- */
$weightRows = $mysqli->query("
    SELECT weight, measured_at
    FROM weight_log
    ORDER BY measured_at ASC
")->fetch_all(MYSQLI_ASSOC);

$startWeight = $weightRows ? (float)$weightRows[0]['weight'] : null;
$currentWeight = $weightRows ? (float)$weightRows[array_key_last($weightRows)]['weight'] : null;
$lowWeight = null;
$totalLoss = null;
$percentLoss = null;
$weightChart = [];

if ($weightRows) {
    $lowWeight = min(array_map(static fn(array $row): float => (float)$row['weight'], $weightRows));
    $totalLoss = $startWeight - $currentWeight;
    $percentLoss = $startWeight > 0 ? ($totalLoss / $startWeight) * 100 : null;

    foreach ($weightRows as $row) {
        $weight = (float)$row['weight'];
        $loss = $startWeight - $weight;
        $weightChart[] = [
            'date' => date('Y-m-d', strtotime($row['measured_at'])),
            'label' => date('M j', strtotime($row['measured_at'])),
            'weight' => round($weight, 2),
            'loss' => round($loss, 2),
            'percent_loss' => $startWeight > 0 ? round(($loss / $startWeight) * 100, 3) : 0,
        ];
    }
}

/* Saturday-Friday weekly closing weight and weekly change. */
$weeklyClosing = [];
foreach ($weightRows as $row) {
    $dt = new DateTimeImmutable($row['measured_at']);
    $daysSinceSaturday = ((int)$dt->format('N') + 1) % 7;
    $weekStart = $dt->modify("-{$daysSinceSaturday} days")->setTime(0, 0, 0);
    $key = $weekStart->format('Y-m-d');

    if (!isset($weeklyClosing[$key]) || $dt > $weeklyClosing[$key]['measured_at']) {
        $weeklyClosing[$key] = [
            'week_start' => $weekStart,
            'measured_at' => $dt,
            'weight' => (float)$row['weight'],
        ];
    }
}

ksort($weeklyClosing);
$weeklyRows = array_values($weeklyClosing);
$weeklyLossChart = [];
for ($i = 1, $count = count($weeklyRows); $i < $count; $i++) {
    $weeklyLossChart[] = [
        'label' => $weeklyRows[$i]['week_start']->format('M j'),
        'loss' => round($weeklyRows[$i - 1]['weight'] - $weeklyRows[$i]['weight'], 2),
        'closing_weight' => round($weeklyRows[$i]['weight'], 2),
    ];
}

/* -----------------------------
 * Administration / consumption history
 * ----------------------------- */
$administrationRows = $mysqli->query("
    SELECT
        a.administration_id,
        a.administered_date,
        a.administered_time,
        a.dose_amount,
        a.dose_unit,
        a.time_of_day,
        f.formulation_id,
        f.name AS formulation_name
    FROM administrations a
    JOIN formulations f
        ON f.formulation_id = a.formulation_id
    ORDER BY a.administered_date ASC, COALESCE(a.administered_time, '00:00:00') ASC, a.administration_id ASC
")->fetch_all(MYSQLI_ASSOC);

$normalizeToMg = static function (float $amount, string $unit): ?float {
    return match (strtolower(trim($unit))) {
        'mg' => $amount,
        'mcg', 'ug', 'µg' => $amount / 1000,
        default => null,
    };
};

$consumptionByFormulation = [];
$administrationCounts = [];
$administrationCounts30 = [];
$uniqueAdministrationDates = [];
$cutoff30 = (new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');

foreach ($administrationRows as $row) {
    $name = (string)$row['formulation_name'];
    $date = (string)$row['administered_date'];
    $uniqueAdministrationDates[$date] = true;
    $administrationCounts[$name] = ($administrationCounts[$name] ?? 0) + 1;

    if ($date >= $cutoff30) {
        $administrationCounts30[$name] = ($administrationCounts30[$name] ?? 0) + 1;
    }

    $mg = $normalizeToMg((float)$row['dose_amount'], (string)$row['dose_unit']);
    if ($mg === null) {
        continue;
    }

    if (!isset($consumptionByFormulation[$name])) {
        $consumptionByFormulation[$name] = [];
    }

    if (!isset($consumptionByFormulation[$name][$date])) {
        $consumptionByFormulation[$name][$date] = 0.0;
    }

    $consumptionByFormulation[$name][$date] += $mg;
}

ksort($consumptionByFormulation);

$consumptionChartData = [];
foreach ($consumptionByFormulation as $name => $byDate) {
    ksort($byDate);
    $cumulative = 0.0;
    $rows = [];

    foreach ($byDate as $date => $mg) {
        $cumulative += $mg;
        $rows[] = [
            'date' => $date,
            'label' => date('M j', strtotime($date)),
            'dose_mg' => round($mg, 4),
            'cumulative_mg' => round($cumulative, 4),
        ];
    }

    $consumptionChartData[$name] = $rows;
}


/* -----------------------------
 * Long-term cumulative half-life history
 * -----------------------------
 *
 * Uses up to the previous six months of actual administrations. Each daily
 * point is the sum of the estimated amount remaining from every prior dose:
 *
 * remaining = dose * 0.5^(elapsed_hours / half_life_hours)
 *
 * Single-component formulations display one line. Blends display one line per
 * component. KLOW preserves the app's 5/1/1/1 entry mapping.
 */
$halfLifeHistoryStart = (new DateTimeImmutable('today'))->modify('-6 months');
$halfLifeHistoryEnd = new DateTimeImmutable('now');

$stmt = $mysqli->prepare("
    SELECT
        f.formulation_id,
        f.name AS formulation_name,
        p.peptide_id,
        p.name AS peptide_name,
        p.half_life_hours,
        fc.amount AS component_amount,
        fc.amount_unit AS component_amount_unit,
        (
            SELECT SUM(fc2.amount)
            FROM formulation_components fc2
            WHERE fc2.formulation_id = f.formulation_id
              AND fc2.amount IS NOT NULL
              AND fc2.amount_unit = fc.amount_unit
        ) AS formulation_component_total,
        a.administration_id,
        a.administered_date,
        a.administered_time,
        a.dose_amount,
        a.dose_unit
    FROM administrations a
    JOIN formulations f
        ON f.formulation_id = a.formulation_id
    JOIN formulation_components fc
        ON fc.formulation_id = f.formulation_id
    JOIN peptides p
        ON p.peptide_id = fc.peptide_id
    WHERE a.administered_date >= ?
      AND a.administered_date <= CURDATE()
      AND p.half_life_hours IS NOT NULL
    ORDER BY
        f.name,
        p.name,
        a.administered_date,
        COALESCE(a.administered_time, '12:00:00'),
        a.administration_id
");
$halfLifeStartSql = $halfLifeHistoryStart->format('Y-m-d');
$stmt->bind_param('s', $halfLifeStartSql);
$stmt->execute();
$longHalfLifeRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$normalizeDoseToMcgHistory = static function (float $amount, string $unit): ?float {
    return match (strtolower(trim($unit))) {
        'mcg', 'ug', 'µg' => $amount,
        'mg' => $amount * 1000,
        default => null,
    };
};

$longHalfLifeByFormulation = [];

foreach ($longHalfLifeRows as $row) {
    $formulationId = (int)$row['formulation_id'];
    $peptideId = (int)$row['peptide_id'];

    if (!isset($longHalfLifeByFormulation[$formulationId])) {
        $longHalfLifeByFormulation[$formulationId] = [
            'formulation_name' => (string)$row['formulation_name'],
            'components' => [],
        ];
    }

    if (!isset($longHalfLifeByFormulation[$formulationId]['components'][$peptideId])) {
        $longHalfLifeByFormulation[$formulationId]['components'][$peptideId] = [
            'peptide_name' => (string)$row['peptide_name'],
            'half_life_hours' => (float)$row['half_life_hours'],
            'administrations' => [],
        ];
    }

    $doseMcg = $normalizeDoseToMcgHistory(
        (float)$row['dose_amount'],
        (string)$row['dose_unit']
    );

    if ($doseMcg === null) {
        continue;
    }

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
            $componentDoseMcg = $doseMcg * $klowDoseMultipliers[$peptideName];
        }
    } elseif (
        $row['component_amount'] !== null
        && $row['formulation_component_total'] !== null
        && (float)$row['formulation_component_total'] > 0
    ) {
        $componentDoseMcg =
            $doseMcg
            * ((float)$row['component_amount'] / (float)$row['formulation_component_total']);
    }

    $longHalfLifeByFormulation[$formulationId]['components'][$peptideId]['administrations'][] = [
        'date' => (string)$row['administered_date'],
        'time' => $row['administered_time'] ?: '12:00:00',
        'dose_mcg' => $componentDoseMcg,
    ];
}

/*
 * One point per day is enough for a six-month view while still showing the
 * cumulative stacking/decay shape clearly. Today's point uses the current
 * clock time so the most recent administration is reflected immediately.
 */
$longHalfLifeChartData = [];
$cursor = $halfLifeHistoryStart->setTime(12, 0, 0);
$todayDate = new DateTimeImmutable('today');

while ($cursor->format('Y-m-d') <= $todayDate->format('Y-m-d')) {
    $dateKey = $cursor->format('Y-m-d');
    $pointTime =
        $dateKey === $todayDate->format('Y-m-d')
            ? $halfLifeHistoryEnd
            : $cursor;

    foreach ($longHalfLifeByFormulation as $formulationId => $formulation) {
        if (!isset($longHalfLifeChartData[$formulationId])) {
            $longHalfLifeChartData[$formulationId] = [
                'formulation_name' => $formulation['formulation_name'],
                'labels' => [],
                'dates' => [],
                'series' => [],
            ];

            foreach ($formulation['components'] as $peptideId => $component) {
                $longHalfLifeChartData[$formulationId]['series'][$peptideId] = [
                    'name' => $component['peptide_name'],
                    'half_life_hours' => $component['half_life_hours'],
                    'values_mcg' => [],
                ];
            }
        }

        $longHalfLifeChartData[$formulationId]['dates'][] = $dateKey;
        $longHalfLifeChartData[$formulationId]['labels'][] = $pointTime->format('M j');

        foreach ($formulation['components'] as $peptideId => $component) {
            $remainingMcg = 0.0;

            foreach ($component['administrations'] as $admin) {
                $adminDateTime = new DateTimeImmutable(
                    $admin['date'] . ' ' . $admin['time']
                );

                if ($adminDateTime > $pointTime) {
                    continue;
                }

                $elapsedHours =
                    ($pointTime->getTimestamp() - $adminDateTime->getTimestamp()) / 3600;

                $remainingMcg +=
                    (float)$admin['dose_mcg']
                    * pow(0.5, $elapsedHours / (float)$component['half_life_hours']);
            }

            $longHalfLifeChartData[$formulationId]['series'][$peptideId]['values_mcg'][] =
                round($remainingMcg, 4);
        }
    }

    $cursor = $cursor->modify('+1 day');
}

/* Remove formulations that somehow ended up without usable component data. */
$longHalfLifeChartData = array_values(array_filter(
    $longHalfLifeChartData,
    static fn(array $row): bool => !empty($row['series'])
));

arsort($administrationCounts);
$mostUsedFormulation = $administrationCounts ? array_key_first($administrationCounts) : null;
$totalAdministrations = count($administrationRows);
$researchDays = count($uniqueAdministrationDates);

$frequencyChart = [];
foreach ($administrationCounts as $name => $count) {
    $frequencyChart[] = [
        'name' => $name,
        'all_time' => $count,
        'last_30' => $administrationCounts30[$name] ?? 0,
    ];
}

/* -----------------------------
 * Scheduled-dose adherence history
 * ----------------------------- */
$adherenceRows = $mysqli->query("
    SELECT scheduled_date, status
    FROM scheduled_doses
    WHERE scheduled_date <= CURDATE()
    ORDER BY scheduled_date ASC
")->fetch_all(MYSQLI_ASSOC);

$adherenceWeekly = [];
foreach ($adherenceRows as $row) {
    $dt = new DateTimeImmutable($row['scheduled_date']);
    $daysSinceSaturday = ((int)$dt->format('N') + 1) % 7;
    $weekStart = $dt->modify("-{$daysSinceSaturday} days")->format('Y-m-d');

    if (!isset($adherenceWeekly[$weekStart])) {
        $adherenceWeekly[$weekStart] = ['taken' => 0, 'skipped' => 0, 'cancelled' => 0, 'pending' => 0];
    }

    $status = (string)$row['status'];
    if (isset($adherenceWeekly[$weekStart][$status])) {
        $adherenceWeekly[$weekStart][$status]++;
    }
}

$adherenceChart = [];
foreach ($adherenceWeekly as $weekStart => $counts) {
    $adherenceChart[] = [
        'label' => date('M j', strtotime($weekStart)),
        'taken' => $counts['taken'],
        'skipped' => $counts['skipped'],
        'cancelled' => $counts['cancelled'],
        'pending' => $counts['pending'],
    ];
}
$adherenceChart = array_slice($adherenceChart, -12);

/* Recent administration table. */
$recentAdministrations = array_reverse(array_slice($administrationRows, -20));

$formatMetric = static function (?float $value, int $decimals = 1): string {
    return $value === null ? '—' : number_format($value, $decimals);
};
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>History | Peptide Research</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/app.css?v=20261013-history-half-life" rel="stylesheet">
</head>
<body>
<div class="app-layout">
    <aside class="app-sidebar">
        <a class="sidebar-brand" href="index.php">
            <span class="sidebar-brand-mark"><i class="bi bi-activity"></i></span>
            <span><strong>Peptide</strong><small>Research</small></span>
        </a>

        <div class="sidebar-label">Workspace</div>
        <nav class="sidebar-nav" aria-label="Research sections">
            <a class="sidebar-link" href="index.php">
                <i class="bi bi-grid-1x2"></i><span>Dashboard</span>
            </a>
            <a class="sidebar-link active" href="history.php">
                <i class="bi bi-graph-up-arrow"></i><span>History</span>
            </a>
            <a class="sidebar-link" href="protocols.php">
                <i class="bi bi-sliders2"></i><span>Protocols</span>
            </a>
            <a class="sidebar-link" href="index.php#schedule">
                <i class="bi bi-calendar2-check"></i><span>Schedule</span>
            </a>
            <a class="sidebar-link" href="index.php#vials">
                <i class="bi bi-droplet-half"></i><span>Active Vials</span>
            </a>
            <a class="sidebar-link" href="index.php#inventory">
                <i class="bi bi-box-seam"></i><span>Inventory</span>
            </a>
        </nav>

        <div class="sidebar-spacer"></div>
        <div class="sidebar-foot">
            <div class="sidebar-foot-icon"><i class="bi bi-shield-check"></i></div>
            <div><strong>Research Tracker</strong><small>Personal dashboard</small></div>
        </div>
    </aside>

    <div class="app-workspace">
        <header class="app-topbar sticky-top">
            <div class="mobile-brand d-lg-none">
                <span class="sidebar-brand-mark"><i class="bi bi-activity"></i></span>
                <strong>Peptide Research</strong>
            </div>

            <a href="index.php" class="btn btn-sm btn-outline-secondary history-back-button">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>

            <div class="topbar-right ms-auto">
                <div class="topbar-date">
                    <span>History</span>
                    <strong><?= h(date('l, F j')) ?></strong>
                </div>
            </div>
        </header>

        <main class="app-content history-page">
            <section class="dashboard-hero history-hero mb-4">
                <div>
                    <div class="eyebrow">Research History</div>
                    <h1>Progress & activity</h1>
                    <p>Long-term weight trends, administration history, usage patterns, and schedule activity.</p>
                </div>
                <div class="hero-stats history-hero-stats">
                    <div class="hero-stat">
                        <span class="hero-stat-icon mint"><i class="bi bi-arrow-down-right"></i></span>
                        <span><small>Total loss</small><strong><?= h($formatMetric($totalLoss)) ?> lb</strong></span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat-icon teal"><i class="bi bi-percent"></i></span>
                        <span><small>Weight loss</small><strong><?= h($formatMetric($percentLoss, 2)) ?>%</strong></span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat-icon blue"><i class="bi bi-capsule-pill"></i></span>
                        <span><small>Administrations</small><strong><?= $totalAdministrations ?></strong></span>
                    </div>
                    <div class="hero-stat">
                        <span class="hero-stat-icon amber"><i class="bi bi-calendar3"></i></span>
                        <span><small>Research days</small><strong><?= $researchDays ?></strong></span>
                    </div>
                </div>
            </section>

            <section class="history-kpi-row mb-4">
                <div class="history-kpi-card">
                    <span>Start Weight</span>
                    <strong><?= h($formatMetric($startWeight)) ?> <small>lb</small></strong>
                </div>
                <div class="history-kpi-card">
                    <span>Current Weight</span>
                    <strong><?= h($formatMetric($currentWeight)) ?> <small>lb</small></strong>
                </div>
                <div class="history-kpi-card">
                    <span>Low Weight</span>
                    <strong><?= h($formatMetric($lowWeight)) ?> <small>lb</small></strong>
                </div>
                <div class="history-kpi-card">
                    <span>Most Recorded</span>
                    <strong class="history-kpi-text"><?= h($mostUsedFormulation ?? '—') ?></strong>
                </div>
            </section>

            <section class="row g-3 mb-4">
                <div class="col-12 col-xl-7">
                    <div class="card dashboard-card history-chart-card h-100">
                        <div class="card-body">
                            <div class="history-card-heading">
                                <div><span class="section-kicker">Weight</span><h2>Body Weight</h2></div>
                                <span class="history-card-chip">All entries</span>
                            </div>
                            <div class="history-chart-wrap history-chart-lg"><canvas id="weightChart"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-5">
                    <div class="card dashboard-card history-chart-card h-100">
                        <div class="card-body">
                            <div class="history-card-heading">
                                <div><span class="section-kicker">Progress</span><h2>Total Weight Loss</h2></div>
                                <span class="history-card-chip">lb + %</span>
                            </div>
                            <div class="history-chart-wrap history-chart-lg"><canvas id="lossChart"></canvas></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3 mb-4">
                <div class="col-12 col-xl-5">
                    <div class="card dashboard-card history-chart-card h-100">
                        <div class="card-body">
                            <div class="history-card-heading">
                                <div><span class="section-kicker">Saturday-Friday</span><h2>Weekly Weight Loss</h2></div>
                                <span class="history-card-chip">Weekly close</span>
                            </div>
                            <div class="history-chart-wrap"><canvas id="weeklyLossChart"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-7">
                    <div class="card dashboard-card history-chart-card h-100">
                        <div class="card-body">
                            <div class="history-card-heading flex-wrap gap-2">
                                <div><span class="section-kicker">Administrations</span><h2>Peptide Consumption History</h2></div>
                                <select id="peptideHistorySelect" class="form-select form-select-sm history-peptide-select" aria-label="Select peptide">
                                    <?php foreach (array_keys($consumptionChartData) as $name): ?>
                                        <option value="<?= h($name) ?>"><?= h($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="history-chart-subtitle" id="peptideChartSubtitle">Recorded dose by date and cumulative recorded amount.</div>
                            <div class="history-chart-wrap"><canvas id="peptideConsumptionChart"></canvas></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3 mb-4">
                <div class="col-12 col-xl-7">
                    <div class="card dashboard-card history-chart-card h-100">
                        <div class="card-body">
                            <div class="history-card-heading flex-wrap gap-2">
                                <div>
                                    <span class="section-kicker">Cumulative half-life</span>
                                    <h2>Long-Term Peptide Levels</h2>
                                </div>
                                <select
                                    id="longHalfLifeSelect"
                                    class="form-select form-select-sm history-peptide-select"
                                    aria-label="Select peptide for long-term half-life history"
                                >
                                    <?php foreach ($longHalfLifeChartData as $chart): ?>
                                        <option value="<?= h($chart['formulation_name']) ?>">
                                            <?= h($chart['formulation_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="history-chart-subtitle" id="longHalfLifeSubtitle">
                                Estimated cumulative amount remaining from up to six months of recorded administrations.
                            </div>
                            <div class="history-chart-wrap history-chart-frequency">
                                <canvas id="longHalfLifeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-5">
                    <div class="card dashboard-card history-chart-card h-100">
                        <div class="card-body">
                            <div class="history-card-heading">
                                <div><span class="section-kicker">Schedule</span><h2>Weekly Completion</h2></div>
                                <span class="history-card-chip">Last 12 weeks</span>
                            </div>
                            <div class="history-chart-wrap history-chart-frequency"><canvas id="adherenceChart"></canvas></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card management-card history-table-card">
                <div class="card-body">
                    <div class="management-heading d-flex justify-content-between align-items-center gap-3">
                        <div><span class="section-kicker">Recent</span><h2 class="management-title mb-0">Administration History</h2></div>
                        <span class="history-card-chip">Latest 20</span>
                    </div>

                    <div class="table-responsive mt-3">
                        <table class="table align-middle history-table mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Peptide</th>
                                    <th>Dose</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($recentAdministrations): ?>
                                <?php foreach ($recentAdministrations as $row): ?>
                                    <tr>
                                        <td><?= h(date('M j, Y', strtotime($row['administered_date']))) ?></td>
                                        <td class="fw-semibold"><?= h($row['formulation_name']) ?></td>
                                        <td><?= h(format_dose($row['dose_amount'])) ?> <?= h($row['dose_unit']) ?></td>
                                        <td>
                                            <?php if (!empty($row['administered_time'])): ?>
                                                <?= h(date('g:i A', strtotime($row['administered_time']))) ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary"><?= h(ucfirst($row['time_of_day'] ?? 'unknown')) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-body-secondary py-4">No administration history yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const weightData = <?= json_encode($weightChart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const weeklyLossData = <?= json_encode($weeklyLossChart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const consumptionData = <?= json_encode($consumptionChartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const longHalfLifeData = <?= json_encode($longHalfLifeChartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const adherenceData = <?= json_encode($adherenceChart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

Chart.defaults.font.family = 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
Chart.defaults.color = '#6c837c';
Chart.defaults.borderColor = 'rgba(210, 226, 220, .72)';

const gridColor = 'rgba(210, 226, 220, .66)';
const teal = '#34796f';
const mint = '#42c97a';
const blue = '#4d8fc4';
const amber = '#dda33a';
const rose = '#d96b7e';

const commonLineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: { backgroundColor: '#17322d', padding: 10, cornerRadius: 10 }
    },
    scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 9 } },
        y: { grid: { color: gridColor } }
    }
};

new Chart(document.getElementById('weightChart'), {
    type: 'line',
    data: {
        labels: weightData.map(r => r.label),
        datasets: [{
            label: 'Weight',
            data: weightData.map(r => r.weight),
            borderColor: teal,
            backgroundColor: 'rgba(52, 121, 111, .10)',
            fill: true,
            tension: .28,
            pointRadius: 2.5,
            pointHoverRadius: 5,
            borderWidth: 2.5
        }]
    },
    options: {
        ...commonLineOptions,
        scales: {
            x: commonLineOptions.scales.x,
            y: {
                grid: { color: gridColor },
                title: { display: true, text: 'Weight (lb)' }
            }
        }
    }
});

new Chart(document.getElementById('lossChart'), {
    type: 'line',
    data: {
        labels: weightData.map(r => r.label),
        datasets: [
            {
                label: 'Total loss (lb)',
                data: weightData.map(r => r.loss),
                borderColor: mint,
                backgroundColor: 'rgba(66, 201, 122, .09)',
                fill: true,
                tension: .28,
                yAxisID: 'y',
                pointRadius: 2,
                borderWidth: 2.4
            },
            {
                label: 'Weight loss (%)',
                data: weightData.map(r => r.percent_loss),
                borderColor: blue,
                tension: .28,
                yAxisID: 'y1',
                pointRadius: 2,
                borderWidth: 2.2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
            tooltip: { backgroundColor: '#17322d', padding: 10, cornerRadius: 10 }
        },
        scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
            y: { position: 'left', grid: { color: gridColor }, title: { display: true, text: 'Pounds lost' } },
            y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '% lost' } }
        }
    }
});

new Chart(document.getElementById('weeklyLossChart'), {
    type: 'bar',
    data: {
        labels: weeklyLossData.map(r => r.label),
        datasets: [{
            label: 'Weekly loss',
            data: weeklyLossData.map(r => r.loss),
            backgroundColor: weeklyLossData.map(r => r.loss >= 0 ? 'rgba(66, 201, 122, .72)' : 'rgba(217, 107, 126, .72)'),
            borderRadius: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#17322d', padding: 10, cornerRadius: 10 } },
        scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
            y: { grid: { color: gridColor }, title: { display: true, text: 'lb' } }
        }
    }
});

let peptideChart = null;
const peptideSelect = document.getElementById('peptideHistorySelect');

function renderPeptideHistory(name) {
    const rows = consumptionData[name] || [];
    const canvas = document.getElementById('peptideConsumptionChart');

    if (peptideChart) peptideChart.destroy();

    peptideChart = new Chart(canvas, {
        data: {
            labels: rows.map(r => r.label),
            datasets: [
                {
                    type: 'bar',
                    label: 'Recorded dose (mg)',
                    data: rows.map(r => r.dose_mg),
                    backgroundColor: 'rgba(52, 121, 111, .55)',
                    borderRadius: 6,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Cumulative recorded amount (mg)',
                    data: rows.map(r => r.cumulative_mg),
                    borderColor: mint,
                    backgroundColor: mint,
                    tension: .25,
                    pointRadius: 2.5,
                    borderWidth: 2.3,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: { backgroundColor: '#17322d', padding: 10, cornerRadius: 10 }
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                y: { position: 'left', grid: { color: gridColor }, title: { display: true, text: 'Daily mg' } },
                y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Cumulative mg' } }
            }
        }
    });

    document.getElementById('peptideChartSubtitle').textContent =
        name === 'KLOW'
            ? 'KLOW uses the recorded entry amount (your 1 mg entry convention) for history totals.'
            : 'Recorded dose by date and cumulative recorded amount, normalized to mg.';
}

if (peptideSelect && peptideSelect.options.length) {
    renderPeptideHistory(peptideSelect.value);
    peptideSelect.addEventListener('change', () => renderPeptideHistory(peptideSelect.value));
}

let longHalfLifeChart = null;
const longHalfLifeSelect = document.getElementById('longHalfLifeSelect');

const halfLifeSeriesColors = [
    '#34796f',
    '#42c97a',
    '#4d8fc4',
    '#dda33a',
    '#8b6ccf',
    '#d96b7e'
];

function renderLongHalfLifeHistory(formulationName) {
    const chartData = longHalfLifeData.find(
        row => row.formulation_name === formulationName
    );

    const canvas = document.getElementById('longHalfLifeChart');
    const subtitle = document.getElementById('longHalfLifeSubtitle');

    if (!canvas || !chartData) return;
    if (longHalfLifeChart) longHalfLifeChart.destroy();

    const rawSeries = Object.values(chartData.series || {});
    let peakMcg = 0;

    rawSeries.forEach(series => {
        (series.values_mcg || []).forEach(value => {
            if (value !== null && Number(value) > peakMcg) {
                peakMcg = Number(value);
            }
        });
    });

    const useMg = peakMcg >= 1000;
    const divisor = useMg ? 1000 : 1;
    const unit = useMg ? 'mg' : 'mcg';

    const datasets = rawSeries.map((series, index) => ({
        label: series.name,
        data: (series.values_mcg || []).map(value =>
            value === null ? null : Number(value) / divisor
        ),
        borderColor: halfLifeSeriesColors[index % halfLifeSeriesColors.length],
        backgroundColor: halfLifeSeriesColors[index % halfLifeSeriesColors.length],
        pointRadius: 0,
        pointHoverRadius: 3,
        borderWidth: 2.2,
        tension: .22,
        fill: false
    }));

    longHalfLifeChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: chartData.labels || [],
            datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: datasets.length > 1,
                    position: 'bottom',
                    labels: { usePointStyle: true, boxWidth: 8 }
                },
                tooltip: {
                    backgroundColor: '#17322d',
                    padding: 10,
                    cornerRadius: 10,
                    callbacks: {
                        label: context => {
                            const value = Number(context.parsed.y || 0);
                            return `${context.dataset.label}: ${value.toLocaleString(undefined, {
                                maximumFractionDigits: useMg ? 3 : 1
                            })} ${unit}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        maxTicksLimit: 8,
                        maxRotation: 0
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    title: {
                        display: true,
                        text: `Estimated amount remaining (${unit})`
                    }
                }
            }
        }
    });

    const halfLives = rawSeries
        .map(series => `${series.name}: ${Number(series.half_life_hours).toLocaleString(undefined, { maximumFractionDigits: 2 })} hr`)
        .join(' · ');

    subtitle.textContent =
        `Six-month cumulative decay from recorded doses. ${halfLives}`;
}

if (longHalfLifeSelect && longHalfLifeSelect.options.length) {
    renderLongHalfLifeHistory(longHalfLifeSelect.value);
    longHalfLifeSelect.addEventListener('change', () =>
        renderLongHalfLifeHistory(longHalfLifeSelect.value)
    );
}

new Chart(document.getElementById('adherenceChart'), {
    type: 'bar',
    data: {
        labels: adherenceData.map(r => r.label),
        datasets: [
            { label: 'Taken', data: adherenceData.map(r => r.taken), backgroundColor: 'rgba(66, 201, 122, .76)', stack: 'status' },
            { label: 'Skipped', data: adherenceData.map(r => r.skipped), backgroundColor: 'rgba(221, 163, 58, .72)', stack: 'status' },
            { label: 'Cancelled', data: adherenceData.map(r => r.cancelled), backgroundColor: 'rgba(217, 107, 126, .65)', stack: 'status' }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
            tooltip: { backgroundColor: '#17322d', padding: 10, cornerRadius: 10 }
        },
        scales: {
            x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 8 } },
            y: { stacked: true, grid: { color: gridColor }, beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});
</script>
</body>
</html>
