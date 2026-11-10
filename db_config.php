<?php
date_default_timezone_set("Asia/Manila");

$host = "localhost";
$username = "root";
$password = "";
$database = "weather_db";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '" . date("P") . "'");
?>
