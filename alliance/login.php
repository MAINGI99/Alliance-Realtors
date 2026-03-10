<?php
session_start();
require_once __DIR__ . '/includes/Database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        try {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, full_name, email, role_id, password, is_active FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                if ((int)($user['is_active'] ?? 1) === 0) {
                    $error = "Your account has been deactivated. Please contact support.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['role_id']   = $user['role_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email']     = $user['email'];

                    switch ((int)$user['role_id']) {
                        case 1:
                            header("Location: admin/dashboard.php", true, 303);
                            break;
                        case 2:
                            header("Location: agent/dashboard.php", true, 303);
                            break;
                        case 3:
                            header("Location: tenant/dashboard.php", true, 303);
                            break;
                        default:
                            $error = "Unknown role. Please contact support.";
                            break;
                    }
                    exit();
                }
            } else {
                $error = "Incorrect email or password.";
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = "Something went wrong. Please try again.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            display: flex; justify-content: center; align-items: center;
            min-height: 100vh; padding: 20px;
        }
        .card {
            background: white; padding: 40px; border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 400px;
        }
        .brand { text-align: center; margin-bottom: 8px; }
        .brand h2 { font-size: 26px; color: #1e293b; font-weight: 800; letter-spacing: 2px; }
        .brand p { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        .divider { height: 1px; background: #e2e8f0; margin: 16px 0; }
        h1 { text-align: center; font-size: 18px; color: #1e293b; margin-bottom: 24px; font-weight: 600; }
        .form-group { margin-bottom: 14px; position: relative; }
        .form-group i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; pointer-events: none; }
        input { width: 100%; padding: 11px 12px 11px 38px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 14px; font-family: inherit; color: #334155; transition: border-color 0.2s; }
        input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
        button { width: 100%; padding: 13px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 15px; font-weight: 600; margin-top: 4px; transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; }
        button:hover { background: #2563eb; }
        .alert { padding: 11px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .alert-error   { background: #fee2e2; color: #c33; border-left: 3px solid #dc3545; }
        .alert-success { background: #d4edda; color: #155724; border-left: 3px solid #28a745; }
        .register-link { text-align: center; margin-top: 20px; font-size: 14px; color: #64748b; }
        .register-link a { color: #3b82f6; text-decoration: none; font-weight: 500; }
        .register-link a:hover { text-decoration: underline; }
        .roles-hint { display: flex; justify-content: center; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .role-chip { display: flex; align-items: center; gap: 5px; background: #f1f5f9; padding: 5px 12px; border-radius: 20px; font-size: 12px; color: #475569; font-weight: 500; }
        .role-chip i { font-size: 11px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <h2>ALLIANCE</h2>
            <p>Realtors</p>
        </div>
        <div class="divider"></div>
        <h1>Welcome Back</h1>

        <div class="roles-hint">
            <div class="role-chip"><i class="fas fa-shield-alt"></i> Admin</div>
            <div class="role-chip"><i class="fas fa-user-tie"></i> Agent</div>
            <div class="role-chip"><i class="fas fa-user"></i> Tenant</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['logged_out'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> You have been logged out successfully.</div>
        <?php endif; ?>
        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> Account created! Please login.</div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" placeholder="Email Address"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="Password" required>
            </div>
            <button type="submit">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>

        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</body>
</html>