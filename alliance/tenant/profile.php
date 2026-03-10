<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_TENANT', 3);

// Auth check
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_TENANT) {
    header("Location: login.php", true, 303);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$success = '';
$error   = '';

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("SELECT id, full_name, email, phone FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header("Location: login.php", true, 303);
        exit();
    }

    $stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');

        if (empty($full_name) || empty($phone)) {
            $error = "Please fill in all fields.";
        } elseif (!preg_match('/^[0-9+\-\s]{7,15}$/', $phone)) {
            $error = "Invalid phone number.";
        } else {
            $stmt = $db->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
            $stmt->execute([$full_name, $phone, $user_id]);

            if ($tenant) {
                $stmt = $db->prepare("UPDATE tenants SET full_name = ?, phone = ? WHERE user_id = ?");
                $stmt->execute([$full_name, $phone, $user_id]);
            }

            $_SESSION['full_name'] = $full_name;
            $user['full_name']     = $full_name;
            $user['phone']         = $phone;
            $success = "Profile updated successfully!";
        }
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = "Something went wrong. Please try again.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: #f0f2f5; }
        .dashboard { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: #1e293b; color: white; position: fixed; height: 100vh; overflow-y: auto; }
        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid #334155; }
        .sidebar-header h2 { font-size: 20px; font-weight: 600; color: white; }
        .sidebar-header p { font-size: 12px; color: #94a3b8; }
        .user-info { padding: 20px; background: #0f1a24; }
        .user-info .name { font-weight: 600; font-size: 16px; }
        .user-info .email { color: #94a3b8; font-size: 12px; }
        .menu-item { padding: 12px 20px; display: flex; align-items: center; color: #cbd5e1; text-decoration: none; font-size: 14px; transition: background 0.2s; }
        .menu-item:hover, .menu-item.active { background: #334155; color: white; border-left: 3px solid #3b82f6; }
        .menu-item i { width: 24px; margin-right: 12px; }
        .menu-divider { height: 1px; background: #334155; margin: 15px 20px; }
        .main-content { flex: 1; margin-left: 260px; padding: 24px 32px; }
        .top-bar { background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
        .breadcrumb { color: #64748b; font-size: 14px; }
        .breadcrumb span { color: #0f172a; font-weight: 600; }
        h1 { font-size: 24px; color: #0f172a; }
        .profile-card { background: white; padding: 30px; border-radius: 10px; max-width: 600px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #334155; font-size: 14px; }
        .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; font-family: inherit; transition: border-color 0.2s; }
        .form-group input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
        .form-group input[readonly] { background: #f8fafc; color: #64748b; cursor: not-allowed; }
        .btn-update { background: #3b82f6; color: white; border: none; padding: 12px 24px; border-radius: 5px; cursor: pointer; font-size: 15px; transition: background 0.2s; }
        .btn-update:hover { background: #2563eb; }
        .alert { padding: 12px 16px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #fee2e2; color: #c33; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="sidebar">
        <div class="sidebar-header"><h2>ALLIANCE</h2><p>Realtors</p></div>
        <div class="user-info">
            <div class="name"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></div>
            <div class="email"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></div>
        </div>
        <a href="dashboard.php" class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php" class="menu-item"><i class="fas fa-building"></i> Browse Properties</a>
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="invoices.php" class="menu-item"><i class="fas fa-file-invoice"></i> Invoices</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item active"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Profile</span></div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>
        <h1 style="margin-bottom: 20px;">My Profile</h1>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div class="profile-card">
            <form method="POST" action="">
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email (cannot be changed)</label>
                    <input type="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" readonly>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Full Name</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                           placeholder="e.g. 0712345678" required>
                </div>
                <button type="submit" name="update" class="btn-update">
                    <i class="fas fa-save"></i> Update Profile
                </button>
            </form>
        </div>
    </div>
</div>
</body>
</html>