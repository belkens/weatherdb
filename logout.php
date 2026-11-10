<?php
require_once __DIR__ . "/auth.php";
startAppSession();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);
    exit("Method not allowed");
}

if (!validCsrfToken($_POST["csrf_token"] ?? "")) {
    http_response_code(400);
    exit("Invalid request token.");
}

endAppSession();
header("Location: login.php", true, 303);
exit;
?>
