<?php
header("Content-Type: application/json; charset=utf-8");

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    respond(405, ["status" => "error", "message" => "Method not allowed"]);
}

$input = $_POST;
if (empty($input)) {
    $body = file_get_contents("php://input");
    $json = json_decode($body, true);
    if (is_array($json)) $input = $json;
}

$apiKey = $_SERVER["HTTP_X_API_KEY"] ?? ($input["api_key"] ?? "");
if (!is_string($apiKey) || $apiKey === "" || strlen($apiKey) > 256) {
    respond(401, ["status" => "error", "message" => "A valid device API key is required"]);
}

if (!isset($input["temp_unit"]) || !is_string($input["temp_unit"]) || strtoupper(trim($input["temp_unit"])) !== "C") {
    respond(400, ["status" => "error", "message" => "Temperature must be sent in Celsius with temp_unit=C"]);
}

$limits = [
    "wind_dir" => [0, 359],
    "wind_speed" => [0, 500],
    "rain" => [0, 10000],
    "temp" => [-100, 100],
    "humidity" => [0, 100],
    "pressure" => [300, 1200]
];
$values = [];
foreach ($limits as $field => $range) {
    if (!isset($input[$field]) || !is_numeric($input[$field])) {
        respond(400, ["status" => "error", "message" => "Missing or invalid field: " . $field]);
    }
    $value = (float)$input[$field];
    // Some weather sensors report north as 360 degrees instead of 0.
    if ($field === "wind_dir" && $value === 360.0) {
        $value = 0.0;
    }
    if (!is_finite($value) || $value < $range[0] || $value > $range[1]) {
        $message = "Value out of range for field: " . $field . " (received " . $value . "; expected " . $range[0] . " to " . $range[1] . ")";
        error_log("Weather API rejected reading: " . $message);
        respond(400, ["status" => "error", "message" => $message]);
    }
    $values[$field] = $value;
}

require_once __DIR__ . "/db_config.php";
$keyHash = hash("sha256", $apiKey);
$deviceStmt = $conn->prepare("SELECT id FROM devices WHERE api_key_hash = ? LIMIT 1");
$deviceStmt->bind_param("s", $keyHash);
$deviceStmt->execute();
$deviceResult = $deviceStmt->get_result();
$device = $deviceResult->fetch_assoc();
$deviceStmt->close();
if (!$device) {
    $conn->close();
    respond(403, ["status" => "error", "message" => "Unknown device API key"]);
}

$stmt = $conn->prepare("INSERT INTO weather_readings (device_id, wind_dir, wind_speed, rain, temp, humidity, pressure) VALUES (?, ?, ?, ?, ?, ?, ?)");
$deviceId = (int)$device["id"];
$windDir = $values["wind_dir"];
$windSpeed = $values["wind_speed"];
$rain = $values["rain"];
$temp = $values["temp"];
$humidity = $values["humidity"];
$pressure = $values["pressure"];
$stmt->bind_param("idddddd", $deviceId, $windDir, $windSpeed, $rain, $temp, $humidity, $pressure);

if ($stmt->execute()) {
    respond(201, ["status" => "ok", "id" => $stmt->insert_id, "device_id" => $deviceId]);
}

$stmt->close();
$conn->close();
respond(500, ["status" => "error", "message" => "Unable to save weather reading"]);
?>
