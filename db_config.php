<?php
date_default_timezone_set("Asia/Manila");

$host = "up-de-fra1-mysql-1.db.run-on-seenode.comt";
$username = "db_rk0u6v7c7ypq";
$password = "fWbfqYo8IH82HZU2IQSUfIOj";
$database = "db_rk0u6v7c7ypq";
$port = "11550";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $username, $password, $database,$port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '" . date("P") . "'");
?>
