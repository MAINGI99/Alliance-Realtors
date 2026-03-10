<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_ADMIN', 1);
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_ADMIN) {
    header("Location: ../login.php?session_expired=1", true, 303);
    exit();
}

$full_name = $_SESSION['full_name'] ?? 'Admin';
$email     = $_SESSION['email'] ?? '';
$user_id   = (int)$_SESSION['user_id'];

$db      = Database::getInstance()->getConnection();
$message = '';
$error   = '';

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_profile') {
        $new_name  = trim($_POST['full_name'] ?? '');
        $new_email = trim($_POST['email'] ?? '');
        $new_phone = trim($_POST['phone'] ?? '');

        if ($new_name && $new_email) {
            try {
                $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $check->execute([$new_email, $user_id]);
                if ($check->fetch()) {
                    $error = "That email is already in use by another account.";
                } else {
                    $db->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
                       ->execute([$new_name, $new_email, $new_phone, $user_id]);
                    $_SESSION['full_name'] = $new_name;
                    $_SESSION['email']     = $new_email;
                    $full_name = $new_name;
                    $email     = $new_email;
                    $message   = "Profile updated successfully.";
                }
            } catch (PDOException $e) { $error = "Failed to update profile."; }
        } else { $error = "Name and email are required."; }
    }

    elseif ($_POST['action'] === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($current && $new_pass && $confirm) {
            if ($new_pass !== $confirm) {
                $error = "New passwords do not match.";
            } elseif (strlen($new_pass) < 8) {
                $error = "Password must be at least 8 characters.";
            } else {
                try {
                    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $hash = $stmt->fetchColumn();
                    if (password_verify($current, $hash)) {
                        $db->prepare("UPDATE users SET password=? WHERE id=?")
                           ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $user_id]);
                        $message = "Password changed successfully.";
                    } else { $error = "Current password is incorrect."; }
                } catch (PDOException $e) { $error = "Failed to change password."; }
            }
        } else { $error = "Please fill in all password fields."; }
    }
}

// ── Load user ────────────────────────────────────────────────
$user = [];
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }

// ── Activity stats ───────────────────────────────────────────
$activity = ['logins'=>0,'payments'=>0,'maintenance'=>0];
try {
    $activity['payments']    = (int)$db->query("SELECT COUNT(*) FROM payments")->fetchColumn();
    $activity['maintenance'] = (int)$db->query("SELECT COUNT(*) FROM maintenance_requests")->fetchColumn();
} catch (PDOException $e) {}

$initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($full_name)))));
$initials = substr($initials, 0, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Admin | Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',sans-serif; }
        body { background:#f0f2f5; }
        .dashboard { display:flex; min-height:100vh; }

        /* Sidebar */
        .sidebar { width:260px; background:#1e293b; color:white; position:fixed; height:100vh; overflow-y:auto; }
        .sidebar-header { padding:14px 20px; border-bottom:1px solid #334155; }
        .sidebar-header h2 { font-size:20px; font-weight:600; color:white; }
        .sidebar-header p  { font-size:12px; color:#94a3b8; }
        .user-info { padding:12px 20px; background:#0f1a24; }
        .user-info .name  { font-weight:600; font-size:16px; }
        .user-info .email { color:#94a3b8; font-size:12px; margin-top:2px; }
        .menu-item { padding:9px 20px; display:flex; align-items:center; color:#cbd5e1; text-decoration:none; font-size:13px; transition:background 0.2s; }
        .menu-item:hover, .menu-item.active { background:#334155; color:white; border-left:3px solid #3b82f6; }
        .menu-item i { width:24px; margin-right:12px; }
        .menu-divider { height:1px; background:#334155; margin:8px 20px; }

        /* Main */
        .main-content { margin-left:260px; flex:1; padding:24px 32px; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; background:white; padding:15px 25px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
        .breadcrumb { color:#64748b; font-size:14px; }
        .breadcrumb span { color:#0f172a; font-weight:600; }

        /* Welcome */
        .welcome-card { background:linear-gradient(135deg,#7c3aed,#3b82f6); color:white; padding:28px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .welcome-card h1 { font-size:22px; margin-bottom:6px; }
        .welcome-card p  { font-size:14px; opacity:0.85; }
        .admin-badge { background:rgba(255,255,255,0.15); padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px; }

        /* Layout */
        .profile-layout { display:grid; grid-template-columns:300px 1fr; gap:22px; }

        /* Profile card */
        .profile-card { background:white; border-radius:12px; padding:28px; box-shadow:0 2px 8px rgba(0,0,0,0.08); text-align:center; height:fit-content; }
        .avatar { width:90px; height:90px; border-radius:50%; background:linear-gradient(135deg,#7c3aed,#3b82f6); display:flex; align-items:center; justify-content:center; font-size:32px; font-weight:800; color:white; margin:0 auto 16px; box-shadow:0 4px 15px rgba(124,58,237,0.3); }
        .profile-name { font-size:18px; font-weight:700; color:#0f172a; margin-bottom:4px; }
        .profile-email { font-size:13px; color:#64748b; margin-bottom:12px; }
        .role-pill { display:inline-flex; align-items:center; gap:6px; background:#ede9fe; color:#7c3aed; padding:5px 14px; border-radius:20px; font-size:12px; font-weight:700; margin-bottom:20px; }
        .divider { height:1px; background:#f1f5f9; margin:20px 0; }
        .info-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; font-size:13px; border-bottom:1px solid #f8fafc; }
        .info-row:last-child { border-bottom:none; }
        .info-label { color:#64748b; font-weight:600; display:flex; align-items:center; gap:7px; }
        .info-value { color:#0f172a; font-weight:500; }
        .status-dot { width:8px; height:8px; border-radius:50%; background:#10b981; display:inline-block; margin-right:4px; }

        /* Stats row */
        .stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:22px; }
        .mini-stat { background:white; border-radius:10px; padding:16px 18px; box-shadow:0 2px 4px rgba(0,0,0,0.08); display:flex; align-items:center; gap:12px; }
        .mini-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .mini-icon.purple { background:#ede9fe; color:#7c3aed; }
        .mini-icon.green  { background:#d1fae5; color:#10b981; }
        .mini-icon.yellow { background:#fef3c7; color:#f59e0b; }
        .mini-label { font-size:12px; color:#64748b; }
        .mini-value { font-size:20px; font-weight:700; color:#0f172a; }

        /* Forms */
        .form-card { background:white; border-radius:12px; padding:24px; box-shadow:0 2px 8px rgba(0,0,0,0.08); margin-bottom:22px; }
        .form-card h3 { font-size:15px; font-weight:700; color:#0f172a; margin-bottom:4px; display:flex; align-items:center; gap:8px; }
        .form-card .sub { font-size:13px; color:#64748b; margin-bottom:20px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .form-group { margin-bottom:2px; }
        .form-group.full { grid-column:span 2; }
        .form-group label { display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:#475569; }
        .form-group input { width:100%; padding:11px 14px; border:1px solid #e2e8f0; border-radius:8px; font-size:14px; color:#334155; transition:border-color 0.2s; }
        .form-group input:focus { outline:none; border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,0.1); }
        .form-group input:disabled { background:#f8fafc; color:#94a3b8; cursor:not-allowed; }
        .form-hint { font-size:12px; color:#94a3b8; margin-top:4px; }
        .form-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
        .btn-primary { background:linear-gradient(135deg,#7c3aed,#3b82f6); color:white; border:none; padding:11px 24px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:opacity 0.2s; }
        .btn-primary:hover { opacity:0.9; }
        .btn-secondary { background:white; border:1px solid #e2e8f0; padding:11px 24px; border-radius:8px; font-size:14px; cursor:pointer; color:#64748b; }
        .btn-secondary:hover { background:#f8fafc; }

        /* Password strength */
        #strengthBar { height:4px; border-radius:2px; margin-top:6px; background:#e2e8f0; overflow:hidden; }
        #strengthFill { height:100%; width:0%; border-radius:2px; transition:all 0.3s; }

        /* Alerts */
        .alert { padding:13px 18px; border-radius:8px; margin-bottom:20px; font-size:13px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:#d4edda; color:#155724; border-left:3px solid #28a745; }
        .alert-error   { background:#fee2e2; color:#991b1b; border-left:3px solid #dc2626; }

        .footer { text-align:center; color:#94a3b8; font-size:12px; margin-top:10px; padding-bottom:20px; }
    </style>
</head>
<body>
<div class="dashboard">

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header"><h2>ALLIANCE</h2><p>Realtors</p></div>
        <div class="user-info">
            <div class="name"><?= htmlspecialchars($full_name) ?></div>
            <div class="email"><?= htmlspecialchars($email) ?></div>
        </div>
        <a href="dashboard.php"   class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php"  class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php"     class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="agents.php"      class="menu-item"><i class="fas fa-user-tie"></i> Agents</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php"    class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="reports.php"     class="menu-item"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="menu-divider"></div>
        <a href="settings.php"    class="menu-item"><i class="fas fa-cog"></i> Settings</a>
        <a href="profile.php"     class="menu-item active"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php"   class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Profile</span></div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>My Profile</h1>
                <p><i class="fas fa-user-circle"></i> View and manage your account information</p>
            </div>
            <div class="admin-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Mini stats -->
        <div class="stats-row">
            <div class="mini-stat">
                <div class="mini-icon purple"><i class="fas fa-crown"></i></div>
                <div>
                    <div class="mini-label">Role</div>
                    <div class="mini-value" style="font-size:14px;margin-top:2px;">Administrator</div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-icon green"><i class="fas fa-credit-card"></i></div>
                <div>
                    <div class="mini-label">Total Payments</div>
                    <div class="mini-value"><?= $activity['payments'] ?></div>
                </div>
            </div>
            <div class="mini-stat">
                <div class="mini-icon yellow"><i class="fas fa-tools"></i></div>
                <div>
                    <div class="mini-label">Maintenance Requests</div>
                    <div class="mini-value"><?= $activity['maintenance'] ?></div>
                </div>
            </div>
        </div>

        <div class="profile-layout">

            <!-- Left: Profile card -->
            <div>
                <div class="profile-card">
                    <div class="avatar"><?= htmlspecialchars($initials) ?></div>
                    <div class="profile-name"><?= htmlspecialchars($full_name) ?></div>
                    <div class="profile-email"><?= htmlspecialchars($email) ?></div>
                    <div class="role-pill"><i class="fas fa-crown"></i> Administrator</div>

                    <div class="divider"></div>

                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                        <span class="info-value"><?= htmlspecialchars($user['phone'] ?? 'Not set') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user"></i> Username</span>
                        <span class="info-value"><?= htmlspecialchars($user['username'] ?? '—') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-circle"></i> Status</span>
                        <span class="info-value">
                            <span class="status-dot"></span>
                            <?= ucfirst($user['status'] ?? 'active') ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-calendar"></i> Member Since</span>
                        <span class="info-value">
                            <?= isset($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : '—' ?>
                        </span>
                    </div>

                    <div class="divider"></div>

                    <a href="settings.php" style="display:inline-flex;align-items:center;gap:7px;background:#f8fafc;color:#475569;text-decoration:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;width:100%;justify-content:center;transition:background 0.2s;">
                        <i class="fas fa-cog"></i> Go to Settings
                    </a>
                </div>
            </div>

            <!-- Right: Forms -->
            <div>
                <!-- Edit Profile -->
                <div class="form-card">
                    <h3><i class="fas fa-user-edit" style="color:#7c3aed"></i> Edit Profile</h3>
                    <p class="sub">Update your personal information</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? $full_name) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? $email) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="e.g. 0700000000">
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled>
                                <div class="form-hint">Username cannot be changed</div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="reset" class="btn-secondary">Reset</button>
                            <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>

                <!-- Change Password -->
                <div class="form-card">
                    <h3><i class="fas fa-lock" style="color:#3b82f6"></i> Change Password</h3>
                    <p class="sub">Keep your account secure with a strong password</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-grid">
                            <div class="form-group full">
                                <label>Current Password *</label>
                                <input type="password" name="current_password" required placeholder="Enter current password">
                            </div>
                            <div class="form-group">
                                <label>New Password *</label>
                                <input type="password" name="new_password" required placeholder="Min. 8 characters" oninput="checkStrength(this.value)">
                                <div id="strengthBar"><div id="strengthFill"></div></div>
                                <div id="strengthText" style="font-size:12px;margin-top:4px;"></div>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password *</label>
                                <input type="password" name="confirm_password" required placeholder="Re-enter new password">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="reset" class="btn-secondary">Clear</button>
                            <button type="submit" class="btn-primary"><i class="fas fa-lock"></i> Update Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<script>
function checkStrength(val) {
    const fill = document.getElementById('strengthFill');
    const text = document.getElementById('strengthText');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        {w:'0%',  c:'#e2e8f0', t:''},
        {w:'25%', c:'#ef4444', t:'Weak'},
        {w:'50%', c:'#f59e0b', t:'Fair'},
        {w:'75%', c:'#3b82f6', t:'Good'},
        {w:'100%',c:'#10b981', t:'Strong'},
    ];
    const l = levels[score];
    fill.style.width = l.w;
    fill.style.background = l.c;
    text.textContent = l.t;
    text.style.color = l.c;
}
</script>
</body>
</html>