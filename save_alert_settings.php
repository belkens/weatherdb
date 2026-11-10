<?php
require_once __DIR__ . "/auth.php";
startAppSession();
require_once __DIR__ . "/db_config.php";
$currentUser = requireAuthenticatedUser($conn);
requireAdministrator($currentUser);

function redirectBack($message, $type, $deviceId = null) {
    $_SESSION["alert_flash"] = ["message" => $message, "type" => $type];
    $url = "index.php";
    if ($deviceId !== null) $url .= "?device=" . (int)$deviceId;
    header("Location: " . $url, true, 303);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);
    exit("Method not allowed");
}

$postedToken = $_POST["csrf_token"] ?? "";
if (!validCsrfToken($postedToken)) {
    redirectBack("The settings form expired. Reload the page and try again.", "error");
}

$deviceId = isset($_POST["device"]) && ctype_digit((string)$_POST["device"]) ? (int)$_POST["device"] : null;
$ranges = [
    "low_temp" => [-100, 100],
    "high_temp" => [-100, 100],
    "high_humidity" => [0, 100],
    "high_wind_speed" => [0, 500],
    "heavy_rain" => [0, 10000]
];
$values = [];

foreach ($ranges as $field => $range) {
    if (!isset($_POST[$field]) || !is_scalar($_POST[$field]) || !is_numeric($_POST[$field])) {
        redirectBack("Enter a valid number for every alert threshold.", "error", $deviceId);
    }
    $value = (float)$_POST[$field];
    if (!is_finite($value) || $value < $range[0] || $value > $range[1]) {
        redirectBack("One or more thresholds are outside the allowed range.", "error", $deviceId);
    }
    $values[$field] = $value;
}

if ($values["low_temp"] >= $values["high_temp"]) {
    redirectBack("The freezing temperature must be lower than the high-temperature threshold.", "error", $deviceId);
}

try {
    $stmt = $conn->prepare("UPDATE weather_alert_settings SET low_temp = ?, high_temp = ?, high_humidity = ?, high_wind_speed = ?, heavy_rain = ? WHERE id = 1");
    $stmt->bind_param("ddddd", $values["low_temp"], $values["high_temp"], $values["high_humidity"], $values["high_wind_speed"], $values["heavy_rain"]);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    redirectBack("Alert thresholds saved.", "success", $deviceId);
} catch (mysqli_sql_exception $exception) {
    error_log("Unable to save weather alert settings: " . $exception->getMessage());
    redirectBack("Could not save alert thresholds. Check the database setup in README.md.", "error", $deviceId);
}
?>
