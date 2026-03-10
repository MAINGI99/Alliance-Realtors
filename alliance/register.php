<?php
session_start();
require_once __DIR__ . '/includes/Database.php';

$error   = '';
$success = '';

$form = [
    'full_name' => '',
    'email'     => '',
    'phone'     => '',
    'role_id'   => 3,
];

$roles = [
    2 => ['name' => 'Agent',  'icon' => 'fa-user-tie',  'desc' => 'Manage properties & tenants'],
    3 => ['name' => 'Tenant', 'icon' => 'fa-user',      'desc' => 'View your lease & payments'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $role_id          = (int)($_POST['role_id'] ?? 3);
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $form = compact('full_name', 'email', 'phone', 'role_id');

    if ($full_name && $email && $phone && $password && $confirm_password && $role_id) {
        if (!array_key_exists($role_id, $roles)) {
            $error = "Please select a valid role.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email address.";
        } elseif (!preg_match('/^[0-9+\-\s]{7,15}$/', $phone)) {
            $error = "Invalid phone number.";
        } else {
            try {
                $db = Database::getInstance()->getConnection();

                $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);

                if ($stmt->fetch()) {
                    $error = "Email already registered.";
                } else {
                    $db->beginTransaction();

                    // 1. Insert into users
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("
                        INSERT INTO users (full_name, email, phone, password, role_id)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$full_name, $email, $phone, $hashed_password, $role_id]);
                    $user_id = (int)$db->lastInsertId();

                    // 2. If tenant, auto-create tenant record
                    if ($role_id === 3) {
                        $stmt = $db->prepare("
                            INSERT INTO tenants (user_id, name, email, phone, status)
                            VALUES (?, ?, ?, ?, 'Active')
                        ");
                        $stmt->execute([$user_id, $full_name, $email, $phone]);
                    }

                    $db->commit();

                    $role_name = $roles[$role_id]['name'];
                    $success   = "Account created as <strong>{$role_name}</strong>! You can now <a href='login.php'>login</a>.";
                    $form      = ['full_name' => '', 'email' => '', 'phone' => '', 'role_id' => 3];
                }
            } catch (PDOException $e) {
                $db->rollBack();
                error_log($e->getMessage());
                $error = "Something went wrong. Please try again.";
            }
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
    <title>Register - Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 440px;
        }
        .brand { text-align: center; margin-bottom: 8px; }
        .brand h2 { font-size: 26px; color: #1e293b; font-weight: 800; letter-spacing: 2px; }
        .brand p  { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        .divider { height: 1px; background: #e2e8f0; margin: 16px 0; }
        h1 { text-align: center; font-size: 18px; color: #1e293b; margin-bottom: 20px; font-weight: 600; }

        /* Role Cards */
        .role-label { font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.05em; }
        .role-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        .role-option input[type="radio"] { display: none; }
        .role-option label {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 16px 12px; border: 2px solid #e2e8f0; border-radius: 12px;
            cursor: pointer; transition: all 0.2s; text-align: center; gap: 8px;
        }
        .role-option label .role-icon {
            width: 44px; height: 44px; border-radius: 12px;
            background: #f1f5f9; display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: #94a3b8; transition: all 0.2s;
        }
        .role-option label .role-name { font-size: 14px; font-weight: 600; color: #334155; }
        .role-option label .role-desc { font-size: 11px; color: #94a3b8; }
        .role-option input[type="radio"]:checked + label { border-color: #3b82f6; background: #eff6ff; }
        .role-option input[type="radio"]:checked + label .role-icon { background: #3b82f6; color: white; }
        .role-option input[type="radio"]:checked + label .role-name { color: #1d4ed8; }

        /* Form */
        .form-group { margin-bottom: 12px; position: relative; }
        .form-group i.icon {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%); color: #94a3b8; font-size: 14px;
            pointer-events: none;
        }
        input {
            width: 100%; padding: 11px 12px 11px 38px;
            border-radius: 8px; border: 1px solid #e2e8f0;
            font-size: 14px; font-family: inherit; color: #334155;
            transition: border-color 0.2s;
        }
        input:focus {
            outline: none; border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        button {
            width: 100%; padding: 13px;
            background: #3b82f6; color: white;
            border: none; border-radius: 8px;
            cursor: pointer; font-size: 15px; font-weight: 600;
            margin-top: 6px; transition: background 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        button:hover { background: #2563eb; }
        .alert {
            padding: 11px 14px; border-radius: 8px;
            margin-bottom: 16px; font-size: 13px; text-align: center;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .alert-error   { background: #fee2e2; color: #c33;    border-left: 3px solid #dc3545; }
        .alert-success { background: #d4edda; color: #155724; border-left: 3px solid #28a745; }
        .alert-success a { color: #155724; font-weight: bold; }
        .login-link { text-align: center; margin-top: 16px; font-size: 14px; color: #64748b; }
        .login-link a { color: #3b82f6; text-decoration: none; font-weight: 500; }
        .login-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <h2>ALLIANCE</h2>
            <p>Realtors</p>
        </div>
        <div class="divider"></div>
        <h1>Create Your Account</h1>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
        <?php endif; ?>

        <form method="POST">
            <!-- Role Selection -->
            <div class="role-label">I am registering as</div>
            <div class="role-selector">
                <?php foreach ($roles as $id => $role): ?>
                <div class="role-option">
                    <input type="radio" name="role_id" id="role_<?= $id ?>" value="<?= $id ?>"
                           <?= ($form['role_id'] == $id) ? 'checked' : '' ?> required>
                    <label for="role_<?= $id ?>">
                        <div class="role-icon"><i class="fas <?= $role['icon'] ?>"></i></div>
                        <div class="role-name"><?= $role['name'] ?></div>
                        <div class="role-desc"><?= $role['desc'] ?></div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="form-group">
                <i class="fas fa-user icon"></i>
                <input type="text" name="full_name" placeholder="Full Name"
                       value="<?= htmlspecialchars($form['full_name']) ?>" required>
            </div>
            <div class="form-group">
                <i class="fas fa-envelope icon"></i>
                <input type="email" name="email" placeholder="Email Address"
                       value="<?= htmlspecialchars($form['email']) ?>" required>
            </div>
            <div class="form-group">
                <i class="fas fa-phone icon"></i>
                <input type="text" name="phone" placeholder="Phone Number"
                       value="<?= htmlspecialchars($form['phone']) ?>" required>
            </div>
            <div class="form-group">
                <i class="fas fa-lock icon"></i>
                <input type="password" name="password" placeholder="Password (min. 8 characters)" required>
            </div>
            <div class="form-group">
                <i class="fas fa-lock icon"></i>
                <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            </div>

            <button type="submit">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
</body>
</html>