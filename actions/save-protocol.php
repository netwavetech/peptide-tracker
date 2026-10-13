<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_require_auth();
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_csrf();

function protocol_redirect(string $message, string $type='success'): never {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ../protocols.php');
    exit;
}
function valid_date(string $s): bool {
    if ($s === '') return false;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

$itemId = filter_input(INPUT_POST, 'protocol_item_id', FILTER_VALIDATE_INT) ?: null;
$formulationId = filter_input(INPUT_POST, 'formulation_id', FILTER_VALIDATE_INT);
$scheduleType = trim((string)($_POST['schedule_type'] ?? ''));
$doseRaw = trim((string)($_POST['dose_amount'] ?? ''));
$doseUnit = trim((string)($_POST['dose_unit'] ?? ''));
$timeOfDay = trim((string)($_POST['time_of_day'] ?? ''));
$effectiveFrom = trim((string)($_POST['effective_from'] ?? ''));
$effectiveTo = trim((string)($_POST['effective_to'] ?? ''));
$mixRaw = trim((string)($_POST['mix_reminder_days'] ?? '2'));
$cycleMode = trim((string)($_POST['cycle_mode'] ?? 'none'));
$cycleAnchor = trim((string)($_POST['cycle_anchor_date'] ?? $effectiveFrom));
$runRaw = trim((string)($_POST['run_days'] ?? ''));
$offRaw = trim((string)($_POST['off_days'] ?? '0'));
$notes = trim((string)($_POST['notes'] ?? ''));
$active = isset($_POST['active']) ? 1 : 0;
$weekdays = $_POST['weekdays'] ?? [];

if (!$formulationId || !in_array($scheduleType, ['scheduled','as_needed'], true) || !is_numeric($doseRaw) || (float)$doseRaw <= 0) protocol_redirect('Enter a valid protocol dose and schedule.', 'danger');
if (!in_array($doseUnit, ['mg','mcg'], true) || !in_array($timeOfDay, ['morning','afternoon','evening','night'], true)) protocol_redirect('Choose valid protocol units and time.', 'danger');
if (!valid_date($effectiveFrom) || ($effectiveTo !== '' && !valid_date($effectiveTo))) protocol_redirect('Choose valid protocol dates.', 'danger');
if ($effectiveTo !== '' && $effectiveTo < $effectiveFrom) protocol_redirect('Protocol end date cannot be before its start date.', 'danger');
if (!ctype_digit($mixRaw)) protocol_redirect('Mix reminder days must be zero or greater.', 'danger');

$doseAmount=(float)$doseRaw; $mixDays=(int)$mixRaw; $runDays=null; $offDays=0; $repeat=0;
if ($scheduleType === 'scheduled') {
    if (!is_array($weekdays)) $weekdays=[];
    $weekdays=array_values(array_unique(array_filter(array_map('intval',$weekdays), fn($d)=>$d>=1&&$d<=7)));
    sort($weekdays);
    if (!$weekdays) protocol_redirect('Select at least one day of the week for a scheduled protocol.', 'danger');
    if (!in_array($cycleMode,['none','repeating','finite'],true)) protocol_redirect('Choose a valid cycle type.', 'danger');
    if ($cycleMode !== 'none') {
        if (!valid_date($cycleAnchor) || !ctype_digit($runRaw) || (int)$runRaw<1) protocol_redirect('Enter a valid cycle start and days-on value.', 'danger');
        $runDays=(int)$runRaw;
        if ($cycleMode==='repeating') {
            if (!ctype_digit($offRaw)) protocol_redirect('Days off must be zero or greater.', 'danger');
            $offDays=(int)$offRaw; $repeat=1;
        }
    } else $cycleAnchor=$effectiveFrom;
} else {
    $weekdays=[]; $cycleAnchor=$effectiveFrom;
}

try {
    $mysqli->begin_transaction();
    $protocol=$mysqli->query("SELECT protocol_id FROM protocols WHERE active=1 ORDER BY protocol_id DESC LIMIT 1 FOR UPDATE")->fetch_assoc();
    if (!$protocol) throw new RuntimeException('No active master protocol was found.');
    $protocolId=(int)$protocol['protocol_id'];

    if ($itemId) {
        $stmt=$mysqli->prepare("SELECT protocol_item_id FROM protocol_items WHERE protocol_item_id=? AND protocol_id=? AND formulation_id=? FOR UPDATE");
        $stmt->bind_param('iii',$itemId,$protocolId,$formulationId); $stmt->execute(); $exists=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$exists) throw new RuntimeException('Protocol item was not found.');

        $stmt=$mysqli->prepare("UPDATE protocol_items SET schedule_type=?, dose_amount=?, dose_unit=?, time_of_day=?, effective_from=?, effective_to=NULLIF(?,''), cycle_anchor_date=?, run_days=?, off_days=?, repeat_cycle=?, mix_reminder_days=?, active=?, notes=NULLIF(?,'') WHERE protocol_item_id=?");
        $stmt->bind_param('sdsssssiiiiisi',$scheduleType,$doseAmount,$doseUnit,$timeOfDay,$effectiveFrom,$effectiveTo,$cycleAnchor,$runDays,$offDays,$repeat,$mixDays,$active,$notes,$itemId);
        $stmt->execute(); $stmt->close();
    } else {
        if (!$active) throw new RuntimeException('A new protocol must be active when it is created.');
        $stmt=$mysqli->prepare("INSERT INTO protocol_items (protocol_id, formulation_id, schedule_type, dose_amount, dose_unit, time_of_day, effective_from, effective_to, cycle_anchor_date, run_days, off_days, repeat_cycle, mix_reminder_days, active, notes) VALUES (?,?,?,?,?,?,?,NULLIF(?,''),?,?,?,?,?,1,NULLIF(?,''))");
        $stmt->bind_param('iisdsssssiiiis',$protocolId,$formulationId,$scheduleType,$doseAmount,$doseUnit,$timeOfDay,$effectiveFrom,$effectiveTo,$cycleAnchor,$runDays,$offDays,$repeat,$mixDays,$notes);
        $stmt->execute(); $itemId=$mysqli->insert_id; $stmt->close();
    }

    $stmt=$mysqli->prepare("DELETE FROM protocol_item_weekdays WHERE protocol_item_id=?"); $stmt->bind_param('i',$itemId); $stmt->execute(); $stmt->close();
    if ($scheduleType==='scheduled' && $active) {
        $stmt=$mysqli->prepare("INSERT INTO protocol_item_weekdays (protocol_item_id,weekday) VALUES (?,?)");
        foreach($weekdays as $weekday){$stmt->bind_param('ii',$itemId,$weekday);$stmt->execute();} $stmt->close();
    }

    if (!$active) {
        $today=date('Y-m-d');
        $stmt=$mysqli->prepare("UPDATE scheduled_doses SET status='cancelled', updated_at=NOW() WHERE protocol_item_id=? AND status='pending' AND scheduled_date>=?"); $stmt->bind_param('is',$itemId,$today); $stmt->execute(); $stmt->close();
        $stmt=$mysqli->prepare("UPDATE reminders SET status='completed', updated_at=NOW() WHERE protocol_item_id=? AND status='pending'"); $stmt->bind_param('i',$itemId); $stmt->execute(); $stmt->close();
    }

    $mysqli->commit(); protocol_redirect('Protocol saved.');
} catch(Throwable $e) {
    $mysqli->rollback(); error_log('save-protocol.php: '.$e->getMessage()); protocol_redirect($e->getMessage(),'danger');
}
