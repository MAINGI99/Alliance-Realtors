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
$tab     = $_GET['tab'] ?? 'profile';

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
                    $error = "That email is already in use.";
                } else {
                    $db->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
                       ->execute([$new_name, $new_email, $new_phone, $user_id]);
                    $_SESSION['full_name'] = $new_name;
                    $_SESSION['email']     = $new_email;
                    $full_name = $new_name; $email = $new_email;
                    $message = "Profile updated successfully.";
                }
            } catch (PDOException $e) { $error = "Failed to update profile."; }
        } else { $error = "Name and email are required."; }
        $tab = 'profile';
    }

    elseif ($_POST['action'] === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $tab = 'security';
        if ($current && $new_pass && $confirm) {
            if ($new_pass !== $confirm) { $error = "New passwords do not match."; }
            elseif (strlen($new_pass) < 8) { $error = "Password must be at least 8 characters."; }
            else {
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

    elseif ($_POST['action'] === 'save_system') {
        $tab = 'system';
        $message = "System settings saved.";
    }
}

$user = [];
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$sys = ['users'=>0,'properties'=>0,'tenants'=>0,'payments'=>0];
try {
    $sys['users']      = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $sys['properties'] = (int)$db->query("SELECT COUNT(*) FROM properties")->fetchColumn();
    $sys['tenants']    = (int)$db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $sys['payments']   = (int)$db->query("SELECT COUNT(*) FROM payments")->fetchColumn();
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin | Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',sans-serif; }
        body { background:#f0f2f5; }
        .dashboard { display:flex; min-height:100vh; }
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
        .main-content { margin-left:260px; flex:1; padding:24px 32px; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; background:white; padding:15px 25px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
        .breadcrumb { color:#64748b; font-size:14px; }
        .breadcrumb span { color:#0f172a; font-weight:600; }
        .welcome-card { background:linear-gradient(135deg,#7c3aed,#3b82f6); color:white; padding:28px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .welcome-card h1 { font-size:22px; margin-bottom:6px; }
        .welcome-card p  { font-size:14px; opacity:0.85; }
        .admin-badge { background:rgba(255,255,255,0.15); padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px; }
        .settings-layout { display:grid; grid-template-columns:220px 1fr; gap:22px; }
        .side-tabs { background:white; border-radius:10px; padding:12px; box-shadow:0 2px 4px rgba(0,0,0,0.08); height:fit-content; }
        .side-tab { display:flex; align-items:center; gap:10px; padding:11px 14px; border-radius:8px; text-decoration:none; color:#64748b; font-size:14px; font-weight:500; transition:all 0.2s; margin-bottom:4px; }
        .side-tab:hover { background:#f1f5f9; color:#0f172a; }
        .side-tab.active { background:#eff6ff; color:#1d4ed8; border-left:3px solid #3b82f6; font-weight:600; }
        .side-tab i { width:18px; text-align:center; }
        .settings-card { background:white; border-radius:10px; padding:28px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        .settings-card h2 { font-size:17px; font-weight:700; color:#0f172a; margin-bottom:6px; }
        .settings-card .subtitle { font-size:13px; color:#64748b; margin-bottom:24px; }
        .divider { height:1px; background:#f1f5f9; margin:22px 0; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .form-group { margin-bottom:4px; }
        .form-group.full { grid-column:span 2; }
        .form-group label { display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:#475569; }
        .form-group input,.form-group select,.form-group textarea { width:100%; padding:11px 14px; border:1px solid #e2e8f0; border-radius:8px; font-size:14px; color:#334155; transition:border-color 0.2s; }
        .form-group input:focus,.form-group select:focus { outline:none; border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,0.1); }
        .form-group input:disabled { background:#f8fafc; color:#94a3b8; }
        .form-hint { font-size:12px; color:#94a3b8; margin-top:4px; }
        .avatar-section { display:flex; align-items:center; gap:20px; margin-bottom:24px; }
        .avatar-circle { width:72px; height:72px; border-radius:50%; background:linear-gradient(135deg,#7c3aed,#3b82f6); display:flex; align-items:center; justify-content:center; font-size:28px; font-weight:800; color:white; flex-shrink:0; }
        .avatar-info h3 { font-size:16px; font-weight:700; color:#0f172a; }
        .avatar-info p  { font-size:13px; color:#64748b; }
        .btn-primary { background:linear-gradient(135deg,#7c3aed,#3b82f6); color:white; border:none; padding:11px 24px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
        .btn-primary:hover { opacity:0.9; }
        .btn-secondary { background:white; border:1px solid #e2e8f0; padding:11px 24px; border-radius:8px; font-size:14px; font-weight:500; cursor:pointer; color:#64748b; }
        .btn-danger { background:#fee2e2; color:#dc2626; border:1px solid #fecaca; padding:11px 24px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
        .form-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:22px; }
        .alert { padding:13px 18px; border-radius:8px; margin-bottom:20px; font-size:13px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:#d4edda; color:#155724; border-left:3px solid #28a745; }
        .alert-error   { background:#fee2e2; color:#991b1b; border-left:3px solid #dc2626; }
        .info-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; margin-bottom:20px; }
        .info-card { background:#f8fafc; border-radius:8px; padding:16px 20px; border-left:3px solid #7c3aed; }
        .info-card .label { font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase; margin-bottom:4px; }
        .info-card .value { font-size:20px; font-weight:700; color:#0f172a; }
        .toggle-row { display:flex; justify-content:space-between; align-items:center; padding:14px 0; border-bottom:1px solid #f1f5f9; }
        .toggle-row:last-child { border-bottom:none; }
        .toggle-info h4 { font-size:14px; font-weight:600; color:#0f172a; }
        .toggle-info p  { font-size:12px; color:#94a3b8; margin-top:2px; }
        .toggle { position:relative; width:44px; height:24px; }
        .toggle input { opacity:0; width:0; height:0; }
        .slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#e2e8f0; border-radius:24px; transition:0.3s; }
        .slider:before { position:absolute; content:''; width:18px; height:18px; left:3px; bottom:3px; background:white; border-radius:50%; transition:0.3s; }
        input:checked + .slider { background:#7c3aed; }
        input:checked + .slider:before { transform:translateX(20px); }
        .footer { text-align:center; color:#94a3b8; font-size:12px; margin-top:30px; padding-bottom:20px; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="sidebar">
        <div class="sidebar-header"><h2>ALLIANCE</h2><p>Realtors</p></div>
        <div class="user-info">
            <div class="name"><?php echo htmlspecialchars($full_name); ?></div>
            <div class="email"><?php echo htmlspecialchars($email); ?></div>
        </div>
        <a href="dashboard.php"   class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php"  class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php"     class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="agents.php"      class="menu-item"><i class="fas fa-user-tie"></i> Agents</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php"    class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="reports.php"     class="menu-item"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="menu-divider"></div>
        <a href="settings.php"    class="menu-item active"><i class="fas fa-cog"></i> Settings</a>
        <a href="profile.php"     class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php"   class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Settings</span></div>
            <div><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
        </div>

        <div class="welcome-card">
            <div>
                <h1>Settings</h1>
                <p><i class="fas fa-cog"></i> Manage your account and system preferences</p>
            </div>
            <div class="admin-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="settings-layout">
            <div class="side-tabs">
                <a href="?tab=profile"  class="side-tab <?php echo $tab==='profile'  ?'active':''; ?>"><i class="fas fa-user"></i> Profile</a>
                <a href="?tab=security" class="side-tab <?php echo $tab==='security' ?'active':''; ?>"><i class="fas fa-lock"></i> Security</a>
                <a href="?tab=system"   class="side-tab <?php echo $tab==='system'   ?'active':''; ?>"><i class="fas fa-cog"></i> System</a>
                <a href="?tab=info"     class="side-tab <?php echo $tab==='info'     ?'active':''; ?>"><i class="fas fa-info-circle"></i> System Info</a>
            </div>

            <div>
            <?php if ($tab === 'profile'): ?>
                <div class="settings-card">
                    <h2>Profile Information</h2>
                    <p class="subtitle">Update your admin account details</p>
                    <div class="avatar-section">
                        <div class="avatar-circle"><?php echo strtoupper(substr($full_name,0,1)); ?></div>
                        <div class="avatar-info">
                            <h3><?php echo htmlspecialchars($full_name); ?></h3>
                            <p><?php echo htmlspecialchars($email); ?> &nbsp;&middot;&nbsp;
                               <span style="background:#ede9fe;color:#7c3aed;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:700;">Administrator</span></p>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? $full_name); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? $email); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="e.g. 0700000000">
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" disabled>
                                <div class="form-hint">Username cannot be changed</div>
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <input type="text" value="Administrator" disabled>
                            </div>
                            <div class="form-group">
                                <label>Account Status</label>
                                <input type="text" value="<?php echo ucfirst($user['status'] ?? 'active'); ?>" disabled
                                       style="color:<?php echo ($user['status']??'active')==='active'?'#065f46':'#991b1b'; ?>">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="reset" class="btn-secondary">Reset</button>
                            <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>

            <?php elseif ($tab === 'security'): ?>
                <div class="settings-card">
                    <h2>Change Password</h2>
                    <p class="subtitle">Keep your account secure with a strong password</p>
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
                                <div id="strengthBar" style="height:4px;border-radius:2px;margin-top:6px;background:#e2e8f0;overflow:hidden;">
                                    <div id="strengthFill" style="height:100%;width:0%;border-radius:2px;transition:all 0.3s;"></div>
                                </div>
                                <div id="strengthText" class="form-hint"></div>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password *</label>
                                <input type="password" name="confirm_password" required placeholder="Re-enter new password">
                            </div>
                        </div>
                        <div class="divider"></div>
                        <div style="background:#f8fafc;border-radius:8px;padding:16px;margin-bottom:20px;">
                            <h4 style="font-size:13px;font-weight:700;color:#475569;margin-bottom:10px;"><i class="fas fa-shield-alt"></i> Password Requirements</h4>
                            <ul style="list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                                <li style="font-size:12px;color:#64748b;"><i class="fas fa-check-circle" style="color:#10b981;margin-right:5px;"></i> At least 8 characters</li>
                                <li style="font-size:12px;color:#64748b;"><i class="fas fa-check-circle" style="color:#10b981;margin-right:5px;"></i> Mix of letters &amp; numbers</li>
                                <li style="font-size:12px;color:#64748b;"><i class="fas fa-check-circle" style="color:#10b981;margin-right:5px;"></i> Avoid personal info</li>
                                <li style="font-size:12px;color:#64748b;"><i class="fas fa-check-circle" style="color:#10b981;margin-right:5px;"></i> Use special characters</li>
                            </ul>
                        </div>
                        <div class="form-actions">
                            <button type="reset" class="btn-secondary">Clear</button>
                            <button type="submit" class="btn-primary"><i class="fas fa-lock"></i> Update Password</button>
                        </div>
                    </form>
                </div>

            <?php elseif ($tab === 'system'): ?>
                <div class="settings-card">
                    <h2>System Preferences</h2>
                    <p class="subtitle">Configure system-wide settings for Alliance Realtors</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_system">
                        <h3 style="font-size:14px;font-weight:700;color:#475569;margin-bottom:14px;"><i class="fas fa-bell"></i> Notifications</h3>
                        <div class="toggle-row">
                            <div class="toggle-info"><h4>Maintenance Alerts</h4><p>Get notified when new maintenance requests are submitted</p></div>
                            <label class="toggle"><input type="checkbox" checked><span class="slider"></span></label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info"><h4>Payment Notifications</h4><p>Get notified when payments are received or fail</p></div>
                            <label class="toggle"><input type="checkbox" checked><span class="slider"></span></label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info"><h4>New Tenant Alerts</h4><p>Get notified when a new tenant registers</p></div>
                            <label class="toggle"><input type="checkbox"><span class="slider"></span></label>
                        </div>
                        <div class="divider"></div>
                        <h3 style="font-size:14px;font-weight:700;color:#475569;margin-bottom:14px;"><i class="fas fa-building"></i> Company Details</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Company Name</label>
                                <input type="text" name="company_name" value="Alliance Realtors">
                            </div>
                            <div class="form-group">
                                <label>Company Email</label>
                                <input type="email" name="company_email" placeholder="info@alliance.com">
                            </div>
                            <div class="form-group">
                                <label>Company Phone</label>
                                <input type="text" name="company_phone" placeholder="e.g. 0700000000">
                            </div>
                            <div class="form-group">
                                <label>Currency</label>
                                <select name="currency">
                                    <option value="KES" selected>KES - Kenyan Shilling</option>
                                    <option value="USD">USD - US Dollar</option>
                                    <option value="EUR">EUR - Euro</option>
                                </select>
                            </div>
                            <div class="form-group full">
                                <label>Company Address</label>
                                <input type="text" name="company_address" placeholder="e.g. Nairobi, Kenya">
                            </div>
                        </div>
                        <div class="divider"></div>
                        <h3 style="font-size:14px;font-weight:700;color:#475569;margin-bottom:14px;"><i class="fas fa-file-invoice"></i> Invoice Settings</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Invoice Prefix</label>
                                <input type="text" name="invoice_prefix" value="INV">
                            </div>
                            <div class="form-group">
                                <label>Due Days</label>
                                <input type="number" name="due_days" value="30" min="1" max="90">
                                <div class="form-hint">Days before invoice is overdue</div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                        </div>
                    </form>
                </div>

            <?php elseif ($tab === 'info'): ?>
                <div class="settings-card">
                    <h2>System Information</h2>
                    <p class="subtitle">Technical details and database overview</p>
                    <div class="info-grid">
                        <div class="info-card"><div class="label">Total Users</div><div class="value"><?php echo $sys['users']; ?></div></div>
                        <div class="info-card"><div class="label">Properties</div><div class="value"><?php echo $sys['properties']; ?></div></div>
                        <div class="info-card"><div class="label">Tenants</div><div class="value"><?php echo $sys['tenants']; ?></div></div>
                        <div class="info-card"><div class="label">Total Payments</div><div class="value"><?php echo $sys['payments']; ?></div></div>
                    </div>
                    <div class="divider"></div>
                    <h3 style="font-size:14px;font-weight:700;color:#475569;margin-bottom:14px;"><i class="fas fa-server"></i> Server Details</h3>
                    <div style="display:grid;gap:10px;">
                        <?php
                        $info_rows = [
                            ['PHP Version',  PHP_VERSION],
                            ['Server',       $_SERVER['SERVER_SOFTWARE'] ?? 'Apache'],
                            ['Database',     'MySQL (Port 3307)'],
                            ['Timezone',     date_default_timezone_get()],
                            ['Server Time',  date('Y-m-d H:i:s')],
                            ['Memory Limit', ini_get('memory_limit')],
                            ['Max Upload',   ini_get('upload_max_filesize')],
                        ];
                        foreach ($info_rows as [$label, $value]):
                        ?>
                        <div style="display:flex;justify-content:space-between;padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:13px;">
                            <span style="color:#64748b;font-weight:600;"><?php echo $label; ?></span>
                            <span style="color:#0f172a;font-weight:500;font-family:monospace;"><?php echo htmlspecialchars($value); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="divider"></div>
                    <h3 style="font-size:14px;font-weight:700;color:#dc2626;margin-bottom:14px;"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>
                    <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:18px;">
                        <p style="font-size:13px;color:#64748b;margin-bottom:14px;">These actions are irreversible. Please be absolutely sure before proceeding.</p>
                        <button class="btn-danger" onclick="alert('Feature coming soon.')"><i class="fas fa-sign-out-alt"></i> Clear All Sessions</button>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <div class="footer">© <?php echo date('Y'); ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>
<script>
function checkStrength(val) {
    const fill = document.getElementById('strengthFill');
    const text = document.getElementById('strengthText');
    if (!fill) return;
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
    fill.style.width = l.w; fill.style.background = l.c;
    text.textContent = l.t; text.style.color = l.c;
}
</script>
</body>
</html>