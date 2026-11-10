<?php
require_once __DIR__ . "/auth.php";
startAppSession();
require_once __DIR__ . "/db_config.php";
$currentUser = requireAuthenticatedUser($conn);

function reportEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function reportDateIsValid($value) {
    if (!is_string($value)) return false;
    $date = DateTime::createFromFormat("!Y-m-d", $value);
    $errors = DateTime::getLastErrors();
    return $date
        && (!$errors || ($errors["warning_count"] === 0 && $errors["error_count"] === 0))
        && $date->format("Y-m-d") === $value;
}

function reportNumber($value, $decimals) {
    return $value === null ? "—" : number_format((float)$value, $decimals);
}

function reportRange($row, $field, $decimals) {
    if ($row[$field . "_min"] === null) return "—";
    return reportNumber($row[$field . "_min"], $decimals) . " / "
        . reportNumber($row[$field . "_avg"], $decimals) . " / "
        . reportNumber($row[$field . "_max"], $decimals);
}

$today = date("Y-m-d");
$fromDate = $_GET["from"] ?? date("Y-m-d", strtotime("-6 days"));
$toDate = $_GET["to"] ?? $today;
$selectedDeviceId = isset($_GET["device"]) && ctype_digit((string)$_GET["device"]) ? (int)$_GET["device"] : null;
$devices = [];
$summary = null;
$dailyRows = [];
$reportError = null;

try {
    $deviceResult = $conn->query("SELECT id, name FROM devices ORDER BY name");
    while ($device = $deviceResult->fetch_assoc()) $devices[] = $device;

    if ($selectedDeviceId !== null) {
        $deviceExists = false;
        foreach ($devices as $device) {
            if ((int)$device["id"] === $selectedDeviceId) $deviceExists = true;
        }
        if (!$deviceExists) $reportError = "The selected device does not exist.";
    }

    if (!reportDateIsValid($fromDate) || !reportDateIsValid($toDate)) {
        $reportError = "Choose valid start and end dates.";
    } elseif ($fromDate > $toDate) {
        $reportError = "The start date must be on or before the end date.";
    } elseif ((new DateTimeImmutable($fromDate))->diff(new DateTimeImmutable($toDate))->days > 366) {
        $reportError = "Choose a date range of no more than 366 days.";
    }

    if (!$reportError) {
        $startTimestamp = $fromDate . " 00:00:00";
        $endExclusive = (new DateTimeImmutable($toDate))->modify("+1 day")->format("Y-m-d") . " 00:00:00";
        $aggregateFields = "COUNT(*) AS reading_count, "
            . "MIN(r.temp) AS temp_min, AVG(r.temp) AS temp_avg, MAX(r.temp) AS temp_max, "
            . "MIN(r.humidity) AS humidity_min, AVG(r.humidity) AS humidity_avg, MAX(r.humidity) AS humidity_max, "
            . "MIN(r.pressure) AS pressure_min, AVG(r.pressure) AS pressure_avg, MAX(r.pressure) AS pressure_max, "
            . "MIN(r.wind_speed) AS wind_speed_min, AVG(r.wind_speed) AS wind_speed_avg, MAX(r.wind_speed) AS wind_speed_max, "
            . "MIN(r.rain) AS rain_min, AVG(r.rain) AS rain_avg, MAX(r.rain) AS rain_max, SUM(r.rain) AS rain_total ";

        if ($selectedDeviceId !== null) {
            $summaryStmt = $conn->prepare("SELECT " . $aggregateFields . "FROM weather_readings r WHERE r.created_at >= ? AND r.created_at < ? AND r.device_id = ?");
            $summaryStmt->bind_param("ssi", $startTimestamp, $endExclusive, $selectedDeviceId);
        } else {
            $summaryStmt = $conn->prepare("SELECT " . $aggregateFields . "FROM weather_readings r WHERE r.created_at >= ? AND r.created_at < ?");
            $summaryStmt->bind_param("ss", $startTimestamp, $endExclusive);
        }
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc();
        $summaryStmt->close();

        $dailySql = "SELECT DATE(r.created_at) AS report_day, d.name AS device_name, COUNT(*) AS reading_count, "
            . "MIN(r.temp) AS temp_min, AVG(r.temp) AS temp_avg, MAX(r.temp) AS temp_max, "
            . "MIN(r.humidity) AS humidity_min, AVG(r.humidity) AS humidity_avg, MAX(r.humidity) AS humidity_max, "
            . "MIN(r.pressure) AS pressure_min, AVG(r.pressure) AS pressure_avg, MAX(r.pressure) AS pressure_max, "
            . "MIN(r.wind_speed) AS wind_speed_min, AVG(r.wind_speed) AS wind_speed_avg, MAX(r.wind_speed) AS wind_speed_max, "
            . "MIN(r.rain) AS rain_min, AVG(r.rain) AS rain_avg, MAX(r.rain) AS rain_max, SUM(r.rain) AS rain_total "
            . "FROM weather_readings r JOIN devices d ON d.id = r.device_id "
            . "WHERE r.created_at >= ? AND r.created_at < ?";

        if ($selectedDeviceId !== null) {
            $dailyStmt = $conn->prepare($dailySql . " AND r.device_id = ? GROUP BY DATE(r.created_at), d.id, d.name ORDER BY report_day DESC, d.name");
            $dailyStmt->bind_param("ssi", $startTimestamp, $endExclusive, $selectedDeviceId);
        } else {
            $dailyStmt = $conn->prepare($dailySql . " GROUP BY DATE(r.created_at), d.id, d.name ORDER BY report_day DESC, d.name");
            $dailyStmt->bind_param("ss", $startTimestamp, $endExclusive);
        }
        $dailyStmt->execute();
        $dailyResult = $dailyStmt->get_result();
        while ($row = $dailyResult->fetch_assoc()) $dailyRows[] = $row;
        $dailyStmt->close();
    }
} catch (mysqli_sql_exception $exception) {
    error_log("Unable to build weather report: " . $exception->getMessage());
    $reportError = "Could not load the report. Check the database setup and try again.";
}

$selectedDeviceName = "All devices";
if ($selectedDeviceId !== null) {
    foreach ($devices as $device) {
        if ((int)$device["id"] === $selectedDeviceId) $selectedDeviceName = $device["name"];
    }
}
$fromDisplay = reportDateIsValid($fromDate) ? date("M j, Y", strtotime($fromDate)) : "Invalid date";
$toDisplay = reportDateIsValid($toDate) ? date("M j, Y", strtotime($toDate)) : "Invalid date";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable weather report | IoT Based Weather Monitoring System</title>
    <style>
        :root{--ink:#182334;--muted:#718096;--line:#e5eaf0;--paper:#fff;--bg:#f4f7fb;--blue:#3578e5}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.report-shell{max-width:1240px;margin:auto;padding:28px 30px 42px}.screen-only{margin-bottom:16px}.screen-nav{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:14px}.screen-nav a{color:var(--blue);font-size:13px;text-decoration:none;font-weight:650}.filters{display:flex;flex-wrap:wrap;align-items:end;gap:12px;padding:16px;background:#fff;border:1px solid var(--line);border-radius:12px}.filters label{display:grid;gap:6px;color:var(--muted);font-size:11px;font-weight:650}.filters input,.filters select{min-width:150px;padding:9px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--ink);font:inherit;font-size:13px}.button{padding:10px 14px;border:0;border-radius:8px;background:#1f2e43;color:white;font:inherit;font-size:13px;font-weight:650;cursor:pointer}.button.secondary{border:1px solid var(--line);background:#fff;color:#344255}.report-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:16px 0 18px}.report-header h1{margin:0;font-size:24px;letter-spacing:-.5px}.report-header p{margin:6px 0 0;color:var(--muted);font-size:13px}.generated{color:var(--muted);font-size:11px;text-align:right}.report-card{background:var(--paper);border:1px solid var(--line);border-radius:14px;box-shadow:0 4px 18px #25375208;margin-bottom:14px;padding:18px}.report-card h2{margin:0 0 14px;font-size:14px}.summary-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.summary-metric{padding:13px;border:1px solid var(--line);border-radius:10px}.summary-metric span{display:block;color:var(--muted);font-size:11px;font-weight:650}.summary-metric strong{display:block;margin-top:9px;font-size:17px}.summary-metric small{display:block;margin-top:5px;color:#8995a4;font-size:10px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:1060px}th{text-align:left;padding:10px 11px;background:#f8fafc;color:#718096;font-size:9px;letter-spacing:.4px;text-transform:uppercase}td{padding:10px 11px;border-top:1px solid #eff2f6;font-size:11px;white-space:nowrap}th small,td small{display:block;margin-top:3px;color:#8995a4;font-size:9px;font-weight:500;text-transform:none;letter-spacing:0}.empty{padding:20px;text-align:center;color:var(--muted);font-size:13px}.error{padding:13px 15px;border:1px solid #f0c9c5;border-radius:10px;background:#fff0ee;color:#852d28;font-size:13px}.report-note{margin-top:10px;color:var(--muted);font-size:10px}@media(max-width:800px){.report-shell{padding:20px 14px}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.report-header{flex-direction:column}.generated{text-align:left}}@media print{@page{size:auto;margin:12mm}body{background:#fff;color:#000;font-size:10px}.report-shell{max-width:none;padding:0}.screen-only{display:none!important}.report-header{margin:0 0 12px}.report-header h1{font-size:19px}.report-card{box-shadow:none;border:1px solid #ccc;border-radius:0;margin-bottom:10px;padding:11px;break-inside:avoid}.summary-grid{grid-template-columns:repeat(5,minmax(0,1fr));gap:6px}.summary-metric{padding:8px}.summary-metric strong{font-size:13px}table{min-width:0}th,td{padding:6px 5px;font-size:8px;white-space:normal}.table-wrap{overflow:visible}.report-note{font-size:8px}}
    </style>
</head>
<body>
<main class="report-shell">
    <section class="screen-only">
        <nav class="screen-nav"><a href="index.php">← Back to dashboard</a><span><?php echo reportEscape($currentUser["username"]); ?> · <?php echo $currentUser["role"] === "admin" ? "Admin" : "Viewer"; ?></span></nav>
        <form class="filters" method="get" action="report.php">
            <label>Device<select name="device"><option value="all"<?php echo $selectedDeviceId === null ? " selected" : ""; ?>>All devices</option><?php foreach ($devices as $device): ?><option value="<?php echo (int)$device["id"]; ?>"<?php echo $selectedDeviceId === (int)$device["id"] ? " selected" : ""; ?>><?php echo reportEscape($device["name"]); ?></option><?php endforeach; ?></select></label>
            <label>From<input type="date" name="from" value="<?php echo reportEscape($fromDate); ?>" required></label>
            <label>To<input type="date" name="to" value="<?php echo reportEscape($toDate); ?>" required></label>
            <button class="button" type="submit">Generate report</button>
            <button class="button secondary" type="button" onclick="window.print()">Print report</button>
        </form>
    </section>

    <header class="report-header">
        <div><h1>IoT Based Weather Monitoring System</h1><p>Weather report · <?php echo reportEscape($selectedDeviceName); ?> · <?php echo reportEscape($fromDisplay); ?> to <?php echo reportEscape($toDisplay); ?></p></div>
        <div class="generated">Generated <?php echo reportEscape(date("M j, Y · g:i a")); ?><br>Times shown in Philippine time (UTC+08:00)</div>
    </header>

    <?php if ($reportError): ?><div class="error" role="alert"><?php echo reportEscape($reportError); ?></div>
    <?php else: ?>
    <section class="report-card">
        <h2>Period summary</h2>
        <?php if ((int)$summary["reading_count"] > 0): ?>
        <div class="summary-grid">
            <div class="summary-metric"><span>Readings</span><strong><?php echo number_format((int)$summary["reading_count"]); ?></strong><small>Within selected date range</small></div>
            <div class="summary-metric"><span>Temperature °C</span><strong><?php echo reportRange($summary, "temp", 1); ?></strong><small>Min / average / max</small></div>
            <div class="summary-metric"><span>Humidity %</span><strong><?php echo reportRange($summary, "humidity", 0); ?></strong><small>Min / average / max</small></div>
            <div class="summary-metric"><span>Pressure hPa</span><strong><?php echo reportRange($summary, "pressure", 1); ?></strong><small>Min / average / max</small></div>
            <div class="summary-metric"><span>Wind speed km/h</span><strong><?php echo reportRange($summary, "wind_speed", 1); ?></strong><small>Min / average / max</small></div>
        </div>
        <p class="report-note">Accumulated rainfall: <strong><?php echo number_format((float)$summary["rain_total"], 2); ?> mm</strong>. Rainfall per report min / average / max: <?php echo reportRange($summary, "rain", 2); ?> mm. Wind direction is omitted from averages because compass degrees wrap at north.</p>
        <?php else: ?><div class="empty">No readings were recorded for this device and date range.</div><?php endif; ?>
    </section>

    <section class="report-card">
        <h2>Daily breakdown</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Day</th><th>Device</th><th>Samples</th><th>Temperature °C<small>Min / avg / max</small></th><th>Humidity %<small>Min / avg / max</small></th><th>Pressure hPa<small>Min / avg / max</small></th><th>Wind km/h<small>Min / avg / max</small></th><th>Rain mm<small>Min / avg / max / total</small></th></tr></thead>
            <tbody>
            <?php if ($dailyRows): foreach ($dailyRows as $row): ?>
                <tr>
                    <td><?php echo reportEscape(date("M j, Y", strtotime($row["report_day"]))); ?></td>
                    <td><?php echo reportEscape($row["device_name"]); ?></td>
                    <td><?php echo number_format((int)$row["reading_count"]); ?></td>
                    <td><?php echo reportEscape(reportRange($row, "temp", 1)); ?></td>
                    <td><?php echo reportEscape(reportRange($row, "humidity", 0)); ?></td>
                    <td><?php echo reportEscape(reportRange($row, "pressure", 1)); ?></td>
                    <td><?php echo reportEscape(reportRange($row, "wind_speed", 1)); ?></td>
                    <td><?php echo reportEscape(reportRange($row, "rain", 2)); ?><small>Total <?php echo number_format((float)$row["rain_total"], 2); ?></small></td>
                </tr>
            <?php endforeach; else: ?><tr><td class="empty" colspan="8">No daily data for this report period.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
<?php $conn->close(); ?>
