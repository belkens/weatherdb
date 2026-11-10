<?php
require_once __DIR__ . "/auth.php";
startAppSession();
require_once __DIR__ . "/db_config.php";
$currentUser = requireAuthenticatedUser($conn);
requireAdministrator($currentUser);

function redirectUsers($message, $type = "success") {
    $_SESSION["users_flash"] = ["message" => $message, "type" => $type];
    header("Location: users.php", true, 303);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!validCsrfToken($_POST["csrf_token"] ?? "")) {
        redirectUsers("The form expired. Reload the page and try again.", "error");
    }

    $action = $_POST["action"] ?? "";
    try {
        if ($action === "create") {
            $username = isset($_POST["username"]) && is_string($_POST["username"]) ? trim($_POST["username"]) : "";
            $password = isset($_POST["password"]) && is_string($_POST["password"]) ? $_POST["password"] : "";
            $confirmPassword = isset($_POST["confirm_password"]) && is_string($_POST["confirm_password"]) ? $_POST["confirm_password"] : "";
            $role = $_POST["role"] ?? "viewer";

            if (!preg_match("/^[A-Za-z0-9_.-]{3,50}$/", $username)) {
                redirectUsers("Use 3–50 letters, numbers, dots, underscores, or hyphens for the username.", "error");
            }
            if (!in_array($role, ["admin", "viewer"], true)) {
                redirectUsers("Choose a valid access level.", "error");
            }
            if (strlen($password) < 12 || strlen($password) > 256) {
                redirectUsers("Choose a password between 12 and 256 characters.", "error");
            }
            if ($password !== $confirmPassword) {
                redirectUsers("The passwords do not match.", "error");
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)");
            $stmt->bind_param("sss", $username, $passwordHash, $role);
            $stmt->execute();
            $stmt->close();
            redirectUsers("Account created.");
        }

        if ($action === "update") {
            $targetId = isset($_POST["user_id"]) && ctype_digit((string)$_POST["user_id"]) ? (int)$_POST["user_id"] : 0;
            $role = $_POST["role"] ?? "";
            $activeValue = $_POST["is_active"] ?? "";
            $newPassword = isset($_POST["new_password"]) && is_string($_POST["new_password"]) ? $_POST["new_password"] : "";
            $selfUpdate = $targetId === (int)$currentUser["id"];

            if ($targetId < 1 || !in_array($role, ["admin", "viewer"], true) || !in_array($activeValue, ["0", "1", 0, 1], true)) {
                redirectUsers("Choose a valid account, role, and status.", "error");
            }
            if ($selfUpdate && ($role !== "admin" || (int)$activeValue !== 1)) {
                redirectUsers("You cannot disable or demote your own administrator account.", "error");
            }
            if ($selfUpdate && $newPassword !== "") {
                redirectUsers("Use another administrator account to reset your own password.", "error");
            }
            if ($newPassword !== "" && (strlen($newPassword) < 12 || strlen($newPassword) > 256)) {
                redirectUsers("A replacement password must be between 12 and 256 characters.", "error");
            }

            $lockResult = $conn->query("SELECT GET_LOCK('weather_user_admin_update', 10) AS lock_acquired")->fetch_assoc();
            if ((int)($lockResult["lock_acquired"] ?? 0) !== 1) {
                redirectUsers("Could not safely update accounts. Please try again.", "error");
            }

            try {
                $targetStmt = $conn->prepare("SELECT role, is_active FROM users WHERE id = ? LIMIT 1");
                $targetStmt->bind_param("i", $targetId);
                $targetStmt->execute();
                $target = $targetStmt->get_result()->fetch_assoc();
                $targetStmt->close();
                if (!$target) redirectUsers("That account no longer exists.", "error");

                $deactivatingAdmin = $target["role"] === "admin" && (int)$target["is_active"] === 1 && ($role !== "admin" || (int)$activeValue !== 1);
                if ($deactivatingAdmin) {
                    $admins = $conn->query("SELECT COUNT(*) AS admin_count FROM users WHERE role = 'admin' AND is_active = 1")->fetch_assoc();
                    if ((int)$admins["admin_count"] <= 1) {
                        redirectUsers("At least one active administrator must remain.", "error");
                    }
                }

                $isActive = (int)$activeValue;
                if ($newPassword !== "") {
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateStmt = $conn->prepare("UPDATE users SET role = ?, is_active = ?, password_hash = ? WHERE id = ?");
                    $updateStmt->bind_param("sisi", $role, $isActive, $passwordHash, $targetId);
                } else {
                    $updateStmt = $conn->prepare("UPDATE users SET role = ?, is_active = ? WHERE id = ?");
                    $updateStmt->bind_param("sii", $role, $isActive, $targetId);
                }
                $updateStmt->execute();
                $updateStmt->close();
                $conn->query("SELECT RELEASE_LOCK('weather_user_admin_update')");
                redirectUsers("Account updated.");
            } finally {
                $conn->query("SELECT RELEASE_LOCK('weather_user_admin_update')");
            }
        }

        redirectUsers("Unknown account action.", "error");
    } catch (mysqli_sql_exception $exception) {
        error_log("User account update failed: " . $exception->getMessage());
        if ((int)$exception->getCode() === 1062) {
            redirectUsers("That username is already in use.", "error");
        }
        redirectUsers("Could not save the account change. Check the database and try again.", "error");
    }
}

$flash = $_SESSION["users_flash"] ?? null;
unset($_SESSION["users_flash"]);
$users = [];
$result = $conn->query("SELECT id, username, role, is_active, created_at FROM users ORDER BY created_at, id");
while ($row = $result->fetch_assoc()) $users[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage accounts | IoT Based Weather Monitoring System</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#182334;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{max-width:1000px;margin:auto;padding:28px 24px 42px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px}.topbar h1{margin:0;font-size:22px}.topbar p{margin:5px 0 0;color:#7a8798;font-size:13px}.links{display:flex;gap:10px;align-items:center}.links a{color:#3578e5;text-decoration:none;font-size:13px;font-weight:650}.card{padding:20px;background:white;border:1px solid #e8edf3;border-radius:14px;box-shadow:0 4px 18px #25375208;margin-bottom:14px}.card h2{margin:0 0 14px;font-size:15px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form-grid label,.account-form label{display:grid;gap:6px;color:#718096;font-size:11px;font-weight:650}.form-grid input,.form-grid select,.account-form input,.account-form select{width:100%;padding:10px;border:1px solid #e1e7ef;border-radius:8px;background:#fff;color:#253248;font:inherit;font-size:13px}.button{margin-top:14px;padding:10px 14px;border:0;border-radius:8px;background:#1f2e43;color:#fff;font:inherit;font-weight:650;cursor:pointer}.flash{padding:12px 14px;margin-bottom:14px;border-radius:9px;background:#eaf8f0;border:1px solid #c7ead5;color:#216c4d;font-size:13px}.flash.error{background:#fff0ee;border-color:#f0c9c5;color:#8b302b}.account-list{display:grid;gap:12px}.account-card{padding:15px;border:1px solid #e8edf3;border-radius:10px}.account-heading{display:flex;justify-content:space-between;gap:12px;margin-bottom:12px;font-size:13px}.account-heading span{color:#7a8798}.account-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:10px;align-items:end}.account-form .password-field{grid-column:span 1}.account-form .button{margin:0;white-space:nowrap}.self-note{color:#7a8798;font-size:11px}@media(max-width:700px){.shell{padding:20px 14px}.topbar{align-items:flex-start;flex-direction:column}.account-form{grid-template-columns:repeat(2,minmax(0,1fr))}.account-form .password-field{grid-column:span 2}.account-form .button{grid-column:span 2}.form-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar"><div><h1>Manage accounts</h1><p>Create users and choose their dashboard access level.</p></div><nav class="links"><a href="index.php">Dashboard</a><a href="report.php">Printable report</a><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["app_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><button class="button" type="submit">Sign out</button></form></nav></header>
    <?php if ($flash): ?><div class="flash<?php echo ($flash["type"] ?? "") === "error" ? " error" : ""; ?>" role="status"><?php echo htmlspecialchars($flash["message"] ?? "", ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
    <section class="card">
        <h2>Create an account</h2>
        <form method="post" action="users.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["app_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <label>Username<input name="username" type="text" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]+" autocomplete="off" required></label>
                <label>Access level<select name="role"><option value="viewer">Viewer — view readings</option><option value="admin">Admin — manage dashboard</option></select></label>
                <label>Password (12 characters or more)<input name="password" type="password" minlength="12" maxlength="256" autocomplete="new-password" required></label>
                <label>Confirm password<input name="confirm_password" type="password" minlength="12" maxlength="256" autocomplete="new-password" required></label>
            </div>
            <button class="button" type="submit">Create account</button>
        </form>
    </section>
    <section class="card">
        <h2>Existing accounts</h2>
        <div class="account-list">
            <?php foreach ($users as $account): $isSelf = (int)$account["id"] === (int)$currentUser["id"]; ?>
                <article class="account-card">
                    <div class="account-heading"><strong><?php echo htmlspecialchars($account["username"], ENT_QUOTES, "UTF-8"); ?><?php echo $isSelf ? " (you)" : ""; ?></strong><span>Created <?php echo htmlspecialchars(date("M j, Y", strtotime($account["created_at"])), ENT_QUOTES, "UTF-8"); ?></span></div>
                    <form class="account-form" method="post" action="users.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["app_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="user_id" value="<?php echo (int)$account["id"]; ?>">
                        <label>Role<select name="role"<?php echo $isSelf ? " disabled" : ""; ?>><option value="viewer"<?php echo $account["role"] === "viewer" ? " selected" : ""; ?>>Viewer</option><option value="admin"<?php echo $account["role"] === "admin" ? " selected" : ""; ?>>Admin</option></select></label>
                        <?php if ($isSelf): ?><input type="hidden" name="role" value="admin"><?php endif; ?>
                        <label>Status<select name="is_active"<?php echo $isSelf ? " disabled" : ""; ?>><option value="1"<?php echo (int)$account["is_active"] === 1 ? " selected" : ""; ?>>Active</option><option value="0"<?php echo (int)$account["is_active"] === 0 ? " selected" : ""; ?>>Disabled</option></select></label>
                        <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><span class="self-note">Your role and status are protected.</span><?php else: ?>
                            <label class="password-field">Reset password (optional)<input name="new_password" type="password" minlength="12" maxlength="256" autocomplete="new-password" placeholder="Leave blank to keep"></label>
                        <?php endif; ?>
                        <button class="button" type="submit">Save</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
<?php $conn->close(); ?>
