<?php
function startAppSession() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set("session.use_strict_mode", "1");
        $secure = !empty($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off";
        session_set_cookie_params([
            "lifetime" => 0,
            "path" => "/weather/",
            "secure" => $secure,
            "httponly" => true,
            "samesite" => "Lax"
        ]);
        session_start();
    }

    if (empty($_SESSION["app_csrf_token"])) {
        $_SESSION["app_csrf_token"] = bin2hex(random_bytes(32));
    }
}

function validCsrfToken($token) {
    return is_string($token)
        && isset($_SESSION["app_csrf_token"])
        && hash_equals($_SESSION["app_csrf_token"], $token);
}

function requireAuthenticatedUser($conn) {
    startAppSession();
    if (empty($_SESSION["user_id"])) {
        header("Location: login.php", true, 303);
        exit;
    }

    $userId = (int)$_SESSION["user_id"];
    $stmt = $conn->prepare("SELECT id, username, role, is_active FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !(int)$user["is_active"]) {
        $_SESSION = [];
        session_regenerate_id(true);
        header("Location: login.php", true, 303);
        exit;
    }

    return $user;
}

function requireAdministrator($user) {
    if (!$user || $user["role"] !== "admin") {
        http_response_code(403);
        exit("Administrator access required.");
    }
}

function endAppSession() {
    startAppSession();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
}
?>
