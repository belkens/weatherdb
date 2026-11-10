<?php
require_once __DIR__ . "/auth.php";
startAppSession();
require_once __DIR__ . "/db_config.php";
$currentUser = requireAuthenticatedUser($conn);

$alertFlash = $_SESSION["alert_flash"] ?? null;
unset($_SESSION["alert_flash"]);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function compassPoint($degrees) {
    $points = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"];
    return $points[(int)floor((((float)$degrees % 360) + 22.5) / 45) % 8];
}

function timeSince($timestamp) {
    if (!$timestamp) return "Waiting for first reading";
    $age = max(0, time() - strtotime($timestamp));
    if ($age < 60) return "Just now";
    if ($age < 3600) return floor($age / 60) . " min ago";
    if ($age < 86400) return floor($age / 3600) . " hr ago";
    return date("M j, g:i a", strtotime($timestamp));
}

function summaryRange($summary, $field, $decimals) {
    return number_format((float)$summary[$field . "_min"], $decimals) . " / "
        . number_format((float)$summary[$field . "_avg"], $decimals) . " / "
        . number_format((float)$summary[$field . "_max"], $decimals);
}

function fetchWeatherSummaries($conn, $period, $deviceId) {
    if ($period === "hourly") {
        $periodExpression = "DATE_FORMAT(r.created_at, '%Y-%m-%d %H:00:00')";
        $lookback = "24 HOUR";
        $limit = 24;
    } else {
        $periodExpression = "DATE(r.created_at)";
        $lookback = "7 DAY";
        $limit = 7;
    }

    $select = "SELECT " . $periodExpression . " AS period_start, COUNT(*) AS reading_count, "
        . "MIN(r.temp) AS temp_min, AVG(r.temp) AS temp_avg, MAX(r.temp) AS temp_max, "
        . "MIN(r.humidity) AS humidity_min, AVG(r.humidity) AS humidity_avg, MAX(r.humidity) AS humidity_max, "
        . "MIN(r.pressure) AS pressure_min, AVG(r.pressure) AS pressure_avg, MAX(r.pressure) AS pressure_max, "
        . "MIN(r.wind_speed) AS wind_speed_min, AVG(r.wind_speed) AS wind_speed_avg, MAX(r.wind_speed) AS wind_speed_max, "
        . "MIN(r.rain) AS rain_min, AVG(r.rain) AS rain_avg, MAX(r.rain) AS rain_max, SUM(r.rain) AS rain_total "
        . "FROM weather_readings r WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL " . $lookback . ")";

    if ($deviceId !== null) {
        $stmt = $conn->prepare($select . " AND r.device_id = ? GROUP BY period_start ORDER BY period_start DESC LIMIT " . $limit);
        $stmt->bind_param("i", $deviceId);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($select . " GROUP BY period_start ORDER BY period_start DESC LIMIT " . $limit);
        $stmt = null;
    }

    $summaries = [];
    while ($summary = $result->fetch_assoc()) {
        $summaries[] = $summary;
    }
    if ($stmt) $stmt->close();
    return $summaries;
}

$latest = null;
$readings = [];
$history = [];
$hourlySummaries = [];
$dailySummaries = [];
$devices = [];
$latestByDevice = [];
$dbError = null;
$alertSettings = ["low_temp" => 0, "high_temp" => 37.8, "high_humidity" => 95, "high_wind_speed" => 50, "heavy_rain" => 5];
$activeAlerts = [];
$hasFreshAlertReadings = false;
$selectedDeviceId = isset($_GET["device"]) && ctype_digit((string)$_GET["device"]) ? (int)$_GET["device"] : null;
$selectedDevice = null;

try {
    $settingsResult = $conn->query("SELECT low_temp, high_temp, high_humidity, high_wind_speed, heavy_rain FROM weather_alert_settings WHERE id = 1");
    $savedSettings = $settingsResult->fetch_assoc();
    if ($savedSettings) {
        foreach ($alertSettings as $setting => $default) {
            $alertSettings[$setting] = (float)$savedSettings[$setting];
        }
    }

    $deviceResult = $conn->query("SELECT d.id AS device_id, d.name AS device_name, d.created_at AS device_created_at, w.id, w.temp, w.humidity, w.pressure, w.wind_dir, w.wind_speed, w.rain, w.created_at FROM devices d LEFT JOIN weather_readings w ON w.id = (SELECT MAX(w2.id) FROM weather_readings w2 WHERE w2.device_id = d.id) ORDER BY d.name");
    while ($device = $deviceResult->fetch_assoc()) {
        $devices[] = $device;
        if ($device["id"] !== null) $latestByDevice[(int)$device["device_id"]] = $device;
        if ($selectedDeviceId === (int)$device["device_id"]) $selectedDevice = $device;
    }

    if ($selectedDeviceId !== null && !$selectedDevice) $selectedDeviceId = null;
    if ($selectedDeviceId !== null) {
        $latest = $latestByDevice[$selectedDeviceId] ?? null;
        $historyStmt = $conn->prepare("SELECT r.id, r.device_id, d.name AS device_name, r.temp, r.humidity, r.pressure, r.wind_dir, r.wind_speed, r.rain, r.created_at FROM weather_readings r JOIN devices d ON d.id = r.device_id WHERE r.device_id = ? ORDER BY r.id DESC LIMIT 50");
        $historyStmt->bind_param("i", $selectedDeviceId);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
    } else {
        $latestResult = $conn->query("SELECT r.*, d.name AS device_name FROM weather_readings r JOIN devices d ON d.id = r.device_id ORDER BY r.id DESC LIMIT 1");
        if ($latestResult && $latestResult->num_rows > 0) $latest = $latestResult->fetch_assoc();
        $historyResult = $conn->query("SELECT r.id, r.device_id, d.name AS device_name, r.temp, r.humidity, r.pressure, r.wind_dir, r.wind_speed, r.rain, r.created_at FROM weather_readings r JOIN devices d ON d.id = r.device_id ORDER BY r.id DESC LIMIT 50");
    }
    if ($historyResult) {
        while ($reading = $historyResult->fetch_assoc()) {
            $readings[] = $reading;
        }
    }
    $history = array_reverse(array_slice($readings, 0, 24));
    $hourlySummaries = fetchWeatherSummaries($conn, "hourly", $selectedDeviceId);
    $dailySummaries = fetchWeatherSummaries($conn, "daily", $selectedDeviceId);
} catch (mysqli_sql_exception $exception) {
    $dbError = "A required database table is missing. Follow the setup or migration steps in README.md, then reload this page.";
}

$offlineAfterSeconds = 300;
$onlineCount = 0;
foreach ($latestByDevice as $device) {
    if (time() - strtotime($device["created_at"]) <= $offlineAfterSeconds) $onlineCount++;
}
$offlineDevices = [];
foreach ($devices as $device) {
    if ($device["id"] === null) {
        $offlineDevices[] = ["name" => $device["device_name"], "last_seen" => null];
        continue;
    }
    if (time() - strtotime($device["created_at"]) > $offlineAfterSeconds) {
        $offlineDevices[] = ["name" => $device["device_name"], "last_seen" => $device["created_at"]];
    }
}
$isOnline = $latest && time() - strtotime($latest["created_at"]) <= $offlineAfterSeconds;
$lastSeen = timeSince($latest["created_at"] ?? null);

$alertReadings = $selectedDeviceId !== null ? ($latest ? [$latest] : []) : array_values($latestByDevice);
foreach ($alertReadings as $deviceReading) {
    if ($deviceReading["id"] === null || time() - strtotime($deviceReading["created_at"]) > $offlineAfterSeconds) continue;
    $hasFreshAlertReadings = true;
    $deviceName = $deviceReading["device_name"] ?? "Weather station";
    $temp = (float)$deviceReading["temp"];
    $humidity = (float)$deviceReading["humidity"];
    $windSpeed = (float)$deviceReading["wind_speed"];
    $rain = (float)$deviceReading["rain"];

    if ($temp <= $alertSettings["low_temp"]) {
        $activeAlerts[] = $deviceName . ": freezing temperature (" . number_format($temp, 1) . " °C)";
    }
    if ($temp >= $alertSettings["high_temp"]) {
        $activeAlerts[] = $deviceName . ": high temperature (" . number_format($temp, 1) . " °C)";
    }
    if ($humidity >= $alertSettings["high_humidity"]) {
        $activeAlerts[] = $deviceName . ": high humidity (" . number_format($humidity, 0) . "%)";
    }
    if ($windSpeed >= $alertSettings["high_wind_speed"]) {
        $activeAlerts[] = $deviceName . ": high wind speed (" . number_format($windSpeed, 1) . " km/h)";
    }
    if ($rain >= $alertSettings["heavy_rain"]) {
        $activeAlerts[] = $deviceName . ": heavy rainfall (" . number_format($rain, 2) . " mm in the latest report)";
    }
}

$temps = array_map(function ($reading) { return (float)$reading["temp"]; }, $history);
$chartMin = count($temps) ? floor(min($temps) - 2) : 0;
$chartMax = count($temps) ? ceil(max($temps) + 2) : 10;
if ($chartMax <= $chartMin) $chartMax = $chartMin + 1;
$chartPoints = [];
foreach ($temps as $index => $temp) {
    $x = count($temps) === 1 ? 50 : 50 + ($index * 700 / (count($temps) - 1));
    $y = 155 - (($temp - $chartMin) / ($chartMax - $chartMin) * 125);
    $chartPoints[] = round($x, 1) . "," . round($y, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f4f7fb">
    <title>IoT Based Weather Monitoring System</title>
    <style>
        :root { color-scheme: light; --ink:#182334; --muted:#7a8798; --line:#e8edf3; --paper:#fff; --bg:#f4f7fb; --blue:#3578e5; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font-family:Inter, ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        .shell { max-width:1240px; margin:auto; padding:28px 30px 42px; }
        .topbar { display:flex; justify-content:space-between; align-items:center; gap:20px; margin-bottom:28px; }
        .brand { display:flex; align-items:center; gap:13px; }
        .brand-mark { width:44px; height:44px; display:grid; place-items:center; border-radius:14px; background:#e5efff; color:#3478df; font-size:24px; }
        .brand h1 { font-size:18px; margin:0; letter-spacing:-.3px; }
        .brand p { margin:3px 0 0; color:var(--muted); font-size:12px; }
        .toolbar { display:flex; align-items:center; gap:15px; color:var(--muted); font-size:12px; }
        .account-controls { display:flex; align-items:center; gap:10px; }
        .account-name { color:#637187; white-space:nowrap; }
        .account-link { color:#3578e5; text-decoration:none; font-weight:650; white-space:nowrap; }
        .logout-form { margin:0; }
        .logout-button { padding:9px 11px; border:1px solid var(--line); border-radius:8px; background:#fff; color:#344255; font:inherit; font-weight:650; cursor:pointer; }
        .status { display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border:1px solid var(--line); background:var(--paper); border-radius:30px; color:#344255; font-weight:650; }
        .dot { width:8px; height:8px; background:#19ad78; border-radius:50%; box-shadow:0 0 0 4px #19ad781a; }
        .status.offline .dot { background:#f0a43a; box-shadow:0 0 0 4px #f0a43a22; }
        .refresh { border:0; padding:10px 14px; border-radius:9px; background:#1f2e43; color:#fff; font-weight:650; cursor:pointer; }
        .refresh:hover { background:#30445e; }
        .welcome { display:flex; justify-content:space-between; align-items:end; gap:20px; margin:0 0 20px; }
        .welcome h2 { margin:0; font-size:25px; letter-spacing:-.8px; }
        .welcome p { margin:7px 0 0; color:var(--muted); font-size:13px; }
        .date { color:var(--muted); font-size:12px; white-space:nowrap; }
        .notice { padding:14px 16px; border-radius:12px; margin-bottom:18px; color:#795a13; background:#fff6df; border:1px solid #f2e4b8; font-size:13px; }
        .device-connectivity { padding:15px 18px; border-radius:12px; margin-bottom:14px; border:1px solid; font-size:13px; }
        .device-connectivity.offline { color:#852d28; background:#fff0ee; border-color:#f0c9c5; }
        .device-connectivity.online { color:#216c4d; background:#eaf8f0; border-color:#c7ead5; }
        .device-connectivity strong { font-size:14px; }
        .device-connectivity p { margin:6px 0 0; }
        .device-connectivity ul { margin:9px 0 0; padding-left:20px; }
        .device-connectivity li + li { margin-top:5px; }
        .alert-panel { padding:16px 18px; border-radius:12px; margin-bottom:14px; color:#8b302b; background:#fff0ee; border:1px solid #f0c9c5; font-size:13px; }
        .alert-panel.clear { color:#216c4d; background:#eaf8f0; border-color:#c7ead5; }
        .alert-panel.waiting { color:#657286; background:#fff; border-color:var(--line); }
        .alert-panel strong { font-size:14px; }
        .alert-panel ul { margin:10px 0 0; padding-left:20px; }
        .alert-panel li + li { margin-top:5px; }
        .alert-flash { margin-bottom:14px; }
        .alert-flash.error { color:#8b302b; background:#fff0ee; border-color:#f0c9c5; }
        .alert-settings { margin-bottom:14px; }
        .alert-settings details > summary { cursor:pointer; font-size:13px; font-weight:700; }
        .alert-settings details[open] > summary { margin-bottom:16px; }
        .threshold-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; }
        .threshold-grid label { display:grid; gap:6px; color:var(--muted); font-size:11px; font-weight:650; }
        .threshold-grid input { width:100%; min-width:0; padding:9px 10px; border:1px solid var(--line); border-radius:8px; color:var(--ink); font:inherit; font-size:13px; }
        .threshold-submit { margin-top:14px; }
        .metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
        .card { background:var(--paper); border:1px solid var(--line); border-radius:15px; box-shadow:0 4px 18px #25375208; }
        .metric { padding:19px 20px 18px; min-height:132px; }
        .metric-head { display:flex; align-items:center; justify-content:space-between; }
        .metric-label { color:var(--muted); font-size:12px; font-weight:650; }
        .metric-icon { width:31px; height:31px; border-radius:10px; display:grid; place-items:center; font-size:15px; background:var(--tint); color:var(--accent); }
        .metric-value { margin-top:13px; font-size:31px; font-weight:720; letter-spacing:-1.2px; color:var(--accent); line-height:1; }
        .metric-value small { font-size:14px; letter-spacing:0; font-weight:600; color:#7b8795; }
        .metric-foot { margin-top:10px; font-size:11px; color:#929dab; }
        .content-grid { display:grid; grid-template-columns:minmax(0,1.55fr) minmax(260px,.8fr); gap:14px; margin-top:14px; }
        .panel { padding:20px; }
        .panel-heading { display:flex; justify-content:space-between; align-items:start; gap:12px; }
        .panel-heading h3 { margin:0; font-size:14px; }
        .panel-heading p { margin:5px 0 0; color:var(--muted); font-size:11px; }
        .period { padding:6px 9px; color:#637187; border-radius:7px; background:#f3f6fa; font-size:10px; font-weight:650; }
        .chart { height:205px; margin-top:17px; position:relative; }
        .chart svg { width:100%; height:100%; overflow:visible; }
        .gridline { stroke:#edf1f5; stroke-width:1; }
        .chart-area { fill:url(#fill); }
        .chart-line { fill:none; stroke:#347be5; stroke-width:3; stroke-linecap:round; stroke-linejoin:round; }
        .chart-label { fill:#9aa5b3; font-size:10px; }
        .empty-chart { display:grid; place-items:center; height:100%; color:#9aa5b3; font-size:13px; }
        .station-list { margin-top:17px; }
        .station-row { display:flex; justify-content:space-between; padding:13px 0; border-bottom:1px solid #eff2f6; font-size:12px; }
        .station-row:last-child { border-bottom:0; }
        .station-row span:first-child { color:var(--muted); }
        .station-row strong { font-weight:650; }
        .station-list { max-height:220px; overflow:auto; }
        .device-select { min-width:190px; padding:10px 34px 10px 12px; border:1px solid var(--line); border-radius:9px; background:#fff; color:#344255; font:inherit; font-size:12px; }
        .table-panel { margin-top:14px; padding:20px 0 0; overflow:hidden; }
        .table-panel .panel-heading { padding:0 20px 15px; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:720px; }
        .summary-table { min-width:1050px; }
        .summary-table th small, .summary-table td small { display:block; margin-top:3px; color:#929dab; font-size:10px; font-weight:500; text-transform:none; letter-spacing:0; }
        th { text-align:left; padding:11px 16px; background:#f8fafc; color:#8491a1; font-size:10px; text-transform:uppercase; letter-spacing:.65px; font-weight:700; }
        td { padding:12px 16px; border-top:1px solid #eff2f6; font-size:12px; white-space:nowrap; }
        tbody tr:hover { background:#fbfcfe; }
        .reading-time { color:#758398; }
        .empty-row { text-align:center; color:#9aa5b3; padding:28px; }
        .footer { display:flex; justify-content:space-between; color:#9aa5b3; font-size:10px; margin-top:18px; }
        @media(max-width:900px) { .threshold-grid{grid-template-columns:repeat(3,minmax(0,1fr))} }
        @media(max-width:800px) { .shell{padding:22px 18px 32px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.content-grid{grid-template-columns:1fr}.date{display:none} }
        @media(max-width:700px) { .topbar{align-items:flex-start;flex-direction:column}.toolbar{width:100%;flex-wrap:wrap}.account-controls{flex-wrap:wrap} }
        @media(max-width:480px) { .shell{padding:18px 13px 25px}.topbar{align-items:flex-start}.toolbar{gap:8px}.toolbar>span{display:none}.brand p{font-size:11px}.welcome h2{font-size:22px}.metrics{gap:9px}.metric{padding:15px;min-height:120px}.metric-value{font-size:27px}.panel{padding:16px}.threshold-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.table-panel{padding:16px 0 0}.table-panel .panel-heading{padding:0 16px 13px} }
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <div class="brand">
            <div class="brand-mark" aria-hidden="true">☼</div>
            <div><h1>IoT Based Weather Monitoring System</h1></div>
        </div>
        <div class="toolbar">
            <div class="status<?php echo $onlineCount > 0 ? "" : " offline"; ?>"><span class="dot"></span><?php echo count($devices) ? number_format($onlineCount) . " / " . number_format(count($devices)) . " devices online" : "No devices registered"; ?></div>
            <span>Updated <?php echo h($lastSeen); ?></span>
            <button class="refresh" type="button" onclick="window.location.reload()">Refresh</button>
            <div class="account-controls">
                <span class="account-name"><?php echo h($currentUser["username"]); ?> · <?php echo $currentUser["role"] === "admin" ? "Admin" : "Viewer"; ?></span>
                <a class="account-link" href="report.php">Printable report</a>
                <?php if ($currentUser["role"] === "admin"): ?><a class="account-link" href="users.php">Manage accounts</a><?php endif; ?>
                <form class="logout-form" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["app_csrf_token"]); ?>"><button class="logout-button" type="submit">Sign out</button></form>
            </div>
        </div>
    </header>

    <section class="welcome">
        <div><h2><?php echo $selectedDevice ? h($selectedDevice["device_name"]) : "All devices"; ?></h2><p>Live conditions and recent reports from your weather stations.</p></div>
        <form method="get"><label class="date" for="device">View&nbsp;</label><select class="device-select" name="device" id="device" onchange="this.form.submit()"><option value="all"<?php echo $selectedDeviceId === null ? " selected" : ""; ?>>All devices</option><?php foreach ($devices as $device): ?><option value="<?php echo (int)$device["device_id"]; ?>"<?php echo $selectedDeviceId === (int)$device["device_id"] ? " selected" : ""; ?>><?php echo h($device["device_name"]); ?></option><?php endforeach; ?></select></form>
    </section>

    <?php if ($dbError): ?><div class="notice"><?php echo h($dbError); ?></div><?php endif; ?>
    <?php if ($alertFlash): ?><div class="notice alert-flash<?php echo ($alertFlash["type"] ?? "") === "error" ? " error" : ""; ?>"><?php echo h($alertFlash["message"] ?? ""); ?></div><?php endif; ?>

    <?php if ($devices): ?>
    <section class="device-connectivity<?php echo $offlineDevices ? " offline" : " online"; ?>" aria-live="polite">
        <strong>Device connectivity</strong>
        <?php if ($offlineDevices): ?>
            <p><?php echo number_format(count($offlineDevices)); ?> of <?php echo number_format(count($devices)); ?> devices offline. A device is considered offline after five minutes without a reading.</p>
            <ul><?php foreach ($offlineDevices as $offlineDevice): ?><li><strong><?php echo h($offlineDevice["name"]); ?></strong> — <?php echo $offlineDevice["last_seen"] ? "Last seen " . h(timeSince($offlineDevice["last_seen"])) : "No readings received yet"; ?></li><?php endforeach; ?></ul>
        <?php else: ?>
            <p>All <?php echo number_format(count($devices)); ?> devices reported within the last five minutes.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="alert-panel<?php echo $activeAlerts ? "" : ($hasFreshAlertReadings ? " clear" : " waiting"); ?>" aria-live="polite">
        <strong>Weather alerts</strong>
        <?php if ($activeAlerts): ?><ul><?php foreach ($activeAlerts as $alert): ?><li><?php echo h($alert); ?></li><?php endforeach; ?></ul>
        <?php elseif ($hasFreshAlertReadings): ?><div>No readings are currently beyond their alert thresholds.</div>
        <?php else: ?><div>No readings received in the last five minutes to evaluate.</div><?php endif; ?>
    </section>

    <?php if ($currentUser["role"] === "admin"): ?><section class="card panel alert-settings">
        <details>
            <summary>Alert thresholds</summary>
            <form method="post" action="save_alert_settings.php">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["app_csrf_token"]); ?>">
                <?php if ($selectedDeviceId !== null): ?><input type="hidden" name="device" value="<?php echo (int)$selectedDeviceId; ?>"><?php endif; ?>
                <div class="threshold-grid">
                    <label>Freezing temperature (°C)<input type="number" name="low_temp" min="-100" max="99.9" step="0.1" required value="<?php echo h($alertSettings["low_temp"]); ?>"></label>
                    <label>High temperature (°C)<input type="number" name="high_temp" min="-99.9" max="100" step="0.1" required value="<?php echo h($alertSettings["high_temp"]); ?>"></label>
                    <label>High humidity (%)<input type="number" name="high_humidity" min="0" max="100" step="1" required value="<?php echo h($alertSettings["high_humidity"]); ?>"></label>
                    <label>High wind (km/h)<input type="number" name="high_wind_speed" min="0" max="500" step="0.1" required value="<?php echo h($alertSettings["high_wind_speed"]); ?>"></label>
                    <label>Heavy rain per report (mm)<input type="number" name="heavy_rain" min="0" max="10000" step="0.1" required value="<?php echo h($alertSettings["heavy_rain"]); ?>"></label>
                </div>
                <button class="refresh threshold-submit" type="submit">Save thresholds</button>
            </form>
        </details>
    </section><?php endif; ?>

    <section class="metrics" aria-label="Latest weather measurements">
        <?php
        $cards = [
            ["Temperature", "🌡", "temp", "°C", "#eb7045", "#fff0e9", 1],
            ["Humidity", "◌", "humidity", "%", "#2695cf", "#e8f7ff", 0],
            ["Barometric pressure", "◉", "pressure", "hPa", "#8168d5", "#f1edff", 1],
            ["Wind direction", "➤", "wind_dir", "°", "#249c77", "#e8f8f1", 0],
            ["Wind speed", "〰", "wind_speed", "km/h", "#cf9b25", "#fff7e2", 1],
            ["Rainfall", "☂", "rain", "mm", "#4289d8", "#eaf3ff", 2]
        ];
        foreach ($cards as $card):
            $value = $latest ? (float)$latest[$card[2]] : null;
            if ($value !== null && $card[2] === "wind_dir") $value = (int)round($value) . " <small>° " . h(compassPoint($value)) . "</small>";
            elseif ($value !== null) $value = number_format($value, $card[6]) . " <small>" . h($card[3]) . "</small>";
        ?>
        <article class="card metric" style="--accent:<?php echo $card[4]; ?>;--tint:<?php echo $card[5]; ?>">
            <div class="metric-head"><span class="metric-label"><?php echo h($card[0]); ?></span><span class="metric-icon" aria-hidden="true"><?php echo h($card[1]); ?></span></div>
            <div class="metric-value"><?php echo $value !== null ? $value : "— <small>" . h($card[3]) . "</small>"; ?></div>
            <div class="metric-foot"><?php echo $latest ? "Latest reading · " . h($latest["device_name"] ?? $selectedDevice["device_name"] ?? "Station") : "No measurement received"; ?></div>
        </article>
        <?php endforeach; ?>
    </section>

    <section class="content-grid">
        <article class="card panel">
            <div class="panel-heading"><div><h3>Temperature trend (°C)</h3><p><?php echo $selectedDevice ? "Recent readings from " . h($selectedDevice["device_name"]) : "Recent readings across all devices"; ?></p></div><span class="period">LAST 24 READINGS</span></div>
            <div class="chart">
                <?php if ($chartPoints): ?>
                <svg viewBox="0 0 750 180" role="img" aria-label="Temperature trend chart">
                    <defs><linearGradient id="fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#347be5" stop-opacity=".18"/><stop offset="100%" stop-color="#347be5" stop-opacity="0"/></linearGradient></defs>
                    <?php for ($line = 0; $line < 4; $line++): $y = 30 + $line * 42; ?>
                        <line class="gridline" x1="45" y1="<?php echo $y; ?>" x2="750" y2="<?php echo $y; ?>" />
                        <text class="chart-label" x="0" y="<?php echo $y + 4; ?>"><?php echo number_format($chartMax - ($line * ($chartMax - $chartMin) / 3), 0); ?>°</text>
                    <?php endfor; ?>
                    <?php if (count($chartPoints) > 1): ?><polygon class="chart-area" points="<?php echo "45,155 " . implode(" ", $chartPoints) . " 750,155"; ?>" /><?php endif; ?>
                    <polyline class="chart-line" points="<?php echo h(implode(" ", $chartPoints)); ?>" />
                </svg>
                <?php else: ?><div class="empty-chart">Temperature trend will appear when readings arrive.</div><?php endif; ?>
            </div>
        </article>
        <article class="card panel">
            <div class="panel-heading"><div><h3>Device status</h3><p>Online means a reading arrived within five minutes</p></div></div>
            <div class="station-list">
                <?php if ($devices): foreach ($devices as $device): $deviceOnline = isset($latestByDevice[(int)$device["device_id"]]) && time() - strtotime($latestByDevice[(int)$device["device_id"]]["created_at"]) <= $offlineAfterSeconds; ?>
                <div class="station-row"><span><?php echo h($device["device_name"]); ?></span><strong><?php echo $deviceOnline ? "Online · " . h(timeSince($device["created_at"])) : ($device["id"] === null ? "No readings" : "Offline · " . h(timeSince($device["created_at"]))); ?></strong></div>
                <?php endforeach; else: ?><div class="station-row"><span>No devices registered</span><strong>See README.md</strong></div><?php endif; ?>
                <div class="station-row"><span>Recent readings shown</span><strong><?php echo number_format(count($readings)); ?> / 50</strong></div>
                <div class="station-row"><span>Refresh interval</span><strong>30 seconds</strong></div>
            </div>
        </article>
    </section>

    <section class="card table-panel summary-panel">
        <div class="panel-heading"><div><h3>Hourly summaries</h3><p>Readings grouped by hour over the last 24 hours<?php echo $selectedDevice ? " · " . h($selectedDevice["device_name"]) : " · All devices"; ?></p></div></div>
        <div class="table-wrap"><table class="summary-table">
            <thead><tr><th>Hour</th><th>Samples</th><th>Temperature °C<small>Min / avg / max</small></th><th>Humidity %<small>Min / avg / max</small></th><th>Pressure hPa<small>Min / avg / max</small></th><th>Wind speed km/h<small>Min / avg / max</small></th><th>Rainfall mm<small>Min / avg / max / total</small></th></tr></thead>
            <tbody>
            <?php if ($hourlySummaries): foreach ($hourlySummaries as $summary): ?>
                <tr>
                    <td><?php echo h(date("M j, g a", strtotime($summary["period_start"]))); ?></td>
                    <td><?php echo number_format((int)$summary["reading_count"]); ?></td>
                    <td><?php echo h(summaryRange($summary, "temp", 1)); ?></td>
                    <td><?php echo h(summaryRange($summary, "humidity", 0)); ?></td>
                    <td><?php echo h(summaryRange($summary, "pressure", 1)); ?></td>
                    <td><?php echo h(summaryRange($summary, "wind_speed", 1)); ?></td>
                    <td><?php echo h(summaryRange($summary, "rain", 2)); ?><small>Total <?php echo number_format((float)$summary["rain_total"], 2); ?></small></td>
                </tr>
            <?php endforeach; else: ?><tr><td class="empty-row" colspan="7">Hourly summaries will appear when readings arrive.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

    <section class="card table-panel summary-panel">
        <div class="panel-heading"><div><h3>Daily summaries</h3><p>Readings grouped by day over the last 7 days<?php echo $selectedDevice ? " · " . h($selectedDevice["device_name"]) : " · All devices"; ?></p></div></div>
        <div class="table-wrap"><table class="summary-table">
            <thead><tr><th>Day</th><th>Samples</th><th>Temperature °C<small>Min / avg / max</small></th><th>Humidity %<small>Min / avg / max</small></th><th>Pressure hPa<small>Min / avg / max</small></th><th>Wind speed km/h<small>Min / avg / max</small></th><th>Rainfall mm<small>Min / avg / max / total</small></th></tr></thead>
            <tbody>
            <?php if ($dailySummaries): foreach ($dailySummaries as $summary): ?>
                <tr>
                    <td><?php echo h(date("M j, Y", strtotime($summary["period_start"]))); ?></td>
                    <td><?php echo number_format((int)$summary["reading_count"]); ?></td>
                    <td><?php echo h(summaryRange($summary, "temp", 1)); ?></td>
                    <td><?php echo h(summaryRange($summary, "humidity", 0)); ?></td>
                    <td><?php echo h(summaryRange($summary, "pressure", 1)); ?></td>
                    <td><?php echo h(summaryRange($summary, "wind_speed", 1)); ?></td>
                    <td><?php echo h(summaryRange($summary, "rain", 2)); ?><small>Total <?php echo number_format((float)$summary["rain_total"], 2); ?></small></td>
                </tr>
            <?php endforeach; else: ?><tr><td class="empty-row" colspan="7">Daily summaries will appear when readings arrive.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <p class="panel-heading"><small>Wind direction is omitted from min/average/max because compass angles wrap at north. Rainfall total is accumulated across reports.</small></p>

    <section class="card table-panel">
        <div class="panel-heading"><div><h3>Recent readings</h3><p>Latest 50 measurements <?php echo $selectedDevice ? "from " . h($selectedDevice["device_name"]) : "across all devices"; ?></p></div></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Device</th><th>Received</th><th>Temperature</th><th>Humidity</th><th>Pressure</th><th>Wind direction</th><th>Wind speed</th><th>Rainfall</th></tr></thead>
            <tbody>
            <?php if ($readings): foreach ($readings as $reading): ?>
                <tr>
                    <td><?php echo h($reading["device_name"]); ?></td>
                    <td class="reading-time"><?php echo h(date("M j, Y · g:i:s a", strtotime($reading["created_at"]))); ?></td>
                    <td><?php echo number_format((float)$reading["temp"], 1); ?> °C</td>
                    <td><?php echo number_format((float)$reading["humidity"], 0); ?>%</td>
                    <td><?php echo number_format((float)$reading["pressure"], 1); ?> hPa</td>
                    <td><?php echo (int)$reading["wind_dir"] . "° " . h(compassPoint($reading["wind_dir"])); ?></td>
                    <td><?php echo number_format((float)$reading["wind_speed"], 1); ?> km/h</td>
                    <td><?php echo number_format((float)$reading["rain"], 2); ?> mm</td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td class="empty-row" colspan="8">No readings yet. Register a device and send its first measurement.</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <footer class="footer"><span>IoT Based Weather Monitoring System</span><span>Dashboard refreshes automatically every 30 seconds</span></footer>
</main>
<script>window.setTimeout(function () { window.location.reload(); }, 30000);</script>
</body>
</html>
<?php $conn->close(); ?>
