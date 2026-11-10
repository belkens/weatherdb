<?php
require_once __DIR__ . "/auth.php";
startAppSession();
require_once __DIR__ . "/db_config.php";

if (!empty($_SESSION["user_id"])) {
    requireAuthenticatedUser($conn);
    header("Location: index.php", true, 303);
    exit;
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $username = isset($_POST["username"]) && is_string($_POST["username"]) ? trim($_POST["username"]) : "";
    $password = isset($_POST["password"]) && is_string($_POST["password"]) ? $_POST["password"] : "";

    if (!validCsrfToken($token)) {
        $error = "This sign-in form expired. Reload the page and try again.";
    } elseif ($username === "" || $password === "") {
        $error = "Enter your username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($account && password_verify($password, $account["password_hash"])) {
            session_regenerate_id(true);
            $_SESSION["user_id"] = (int)$account["id"];
            $_SESSION["app_csrf_token"] = bin2hex(random_bytes(32));
            header("Location: index.php", true, 303);
            exit;
        }
        $error = "Username or password is incorrect.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | IoT Based Weather Monitoring System</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;color:#182334;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.auth-card{width:min(420px,calc(100% - 32px));padding:30px;background:#fff;border:1px solid #e8edf3;border-radius:16px;box-shadow:0 8px 30px #2537520d}.brand-mark{width:44px;height:44px;display:grid;place-items:center;border-radius:14px;background:#e5efff;color:#3478df;font-size:24px}.auth-card h1{margin:18px 0 6px;font-size:22px}.auth-card p{margin:0 0 22px;color:#7a8798;font-size:13px}.auth-card label{display:grid;gap:7px;margin:14px 0;color:#526176;font-size:12px;font-weight:650}.auth-card input{width:100%;padding:11px 12px;border:1px solid #dfe6ef;border-radius:9px;font:inherit;font-size:14px}.auth-card button{width:100%;margin-top:8px;padding:11px;border:0;border-radius:9px;background:#1f2e43;color:white;font:inherit;font-weight:700;cursor:pointer}.error{padding:11px 12px;border:1px solid #f0c9c5;border-radius:9px;background:#fff0ee;color:#8b302b;font-size:13px}.auth-link{display:block;margin-top:18px;color:#3578e5;text-align:center;font-size:12px}
    </style>
</head>
<body>
<main class="auth-card">
    <div class="brand-mark" aria-hidden="true">☼</div>
    <h1>Sign in</h1>
    <p>Access the IoT Based Weather Monitoring System dashboard.</p>
    <?php if ($error): ?><div class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
    <form method="post" action="login.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["app_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
        <label>Username<input name="username" type="text" autocomplete="username" maxlength="50" required autofocus></label>
        <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
        <button type="submit">Sign in</button>
    </form>
    <a class="auth-link" href="setup_admin.php">First-time setup: create the administrator account</a>
</main>
</body>
</html>
<?php $conn->close(); ?>
