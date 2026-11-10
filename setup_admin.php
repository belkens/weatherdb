<?php
require_once __DIR__ . "/auth.php";
startAppSession();
require_once __DIR__ . "/db_config.php";

$error = "";
$setupComplete = false;

try {
    $countResult = $conn->query("SELECT COUNT(*) AS user_count FROM users");
    $setupComplete = (int)$countResult->fetch_assoc()["user_count"] > 0;
} catch (mysqli_sql_exception $exception) {
    $error = "The user table is not installed yet. Apply migrate_user_accounts.sql, then reload this page.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$error) {
    if (!validCsrfToken($_POST["csrf_token"] ?? "")) {
        $error = "This setup form expired. Reload the page and try again.";
    } else {
        $username = isset($_POST["username"]) && is_string($_POST["username"]) ? trim($_POST["username"]) : "";
        $password = isset($_POST["password"]) && is_string($_POST["password"]) ? $_POST["password"] : "";
        $confirmPassword = isset($_POST["confirm_password"]) && is_string($_POST["confirm_password"]) ? $_POST["confirm_password"] : "";

        if (!preg_match("/^[A-Za-z0-9_.-]{3,50}$/", $username)) {
            $error = "Use 3–50 letters, numbers, dots, underscores, or hyphens for the username.";
        } elseif (strlen($password) < 12 || strlen($password) > 256) {
            $error = "Choose a password between 12 and 256 characters.";
        } elseif ($password !== $confirmPassword) {
            $error = "The passwords do not match.";
        } else {
            $lockResult = $conn->query("SELECT GET_LOCK('weather_first_admin_setup', 10) AS lock_acquired")->fetch_assoc();
            if ((int)($lockResult["lock_acquired"] ?? 0) !== 1) {
                $error = "Could not safely start first-time setup. Please try again.";
            } else {
                try {
                    $countResult = $conn->query("SELECT COUNT(*) AS user_count FROM users");
                    if ((int)$countResult->fetch_assoc()["user_count"] > 0) {
                        $setupComplete = true;
                    } else {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES (?, ?, 'admin', 1)");
                        $stmt->bind_param("ss", $username, $passwordHash);
                        $stmt->execute();
                        $userId = (int)$stmt->insert_id;
                        $stmt->close();

                        $conn->query("SELECT RELEASE_LOCK('weather_first_admin_setup')");
                        session_regenerate_id(true);
                        $_SESSION["user_id"] = $userId;
                        $_SESSION["app_csrf_token"] = bin2hex(random_bytes(32));
                        header("Location: index.php", true, 303);
                        exit;
                    }
                } catch (mysqli_sql_exception $exception) {
                    error_log("First administrator setup failed: " . $exception->getMessage());
                    $error = "Could not create the administrator account. Check the username and try again.";
                } finally {
                    $conn->query("SELECT RELEASE_LOCK('weather_first_admin_setup')");
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator setup | IoT Based Weather Monitoring System</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;color:#182334;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.auth-card{width:min(460px,calc(100% - 32px));padding:30px;background:#fff;border:1px solid #e8edf3;border-radius:16px;box-shadow:0 8px 30px #2537520d}.brand-mark{width:44px;height:44px;display:grid;place-items:center;border-radius:14px;background:#e5efff;color:#3478df;font-size:24px}.auth-card h1{margin:18px 0 6px;font-size:22px}.auth-card p{margin:0 0 22px;color:#7a8798;font-size:13px}.auth-card label{display:grid;gap:7px;margin:14px 0;color:#526176;font-size:12px;font-weight:650}.auth-card input{width:100%;padding:11px 12px;border:1px solid #dfe6ef;border-radius:9px;font:inherit;font-size:14px}.auth-card button{width:100%;margin-top:8px;padding:11px;border:0;border-radius:9px;background:#1f2e43;color:white;font:inherit;font-weight:700;cursor:pointer}.message{padding:11px 12px;border:1px solid #f0c9c5;border-radius:9px;background:#fff0ee;color:#8b302b;font-size:13px}.auth-link{display:block;margin-top:18px;color:#3578e5;text-align:center;font-size:12px}
    </style>
</head>
<body>
<main class="auth-card">
    <div class="brand-mark" aria-hidden="true">☼</div>
    <h1>Administrator setup</h1>
    <p>Create the first administrator account. This page is disabled after an account has been created.</p>
    <?php if ($error): ?><div class="message" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
    <?php if ($setupComplete && !$error): ?>
        <div class="message">An account already exists. Sign in to continue.</div>
        <a class="auth-link" href="login.php">Go to sign in</a>
    <?php elseif (!$error || strpos($error, "user table is not installed") === false): ?>
        <form method="post" action="setup_admin.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["app_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
            <label>Username<input name="username" type="text" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]+" autocomplete="username" required></label>
            <label>Password (12 characters or more)<input name="password" type="password" minlength="12" maxlength="256" autocomplete="new-password" required></label>
            <label>Confirm password<input name="confirm_password" type="password" minlength="12" maxlength="256" autocomplete="new-password" required></label>
            <button type="submit">Create administrator</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
<?php $conn->close(); ?>
