
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

$stats = ['users' => 0, 'properties' => 0, 'tenants' => 0, 'revenue' => 0, 'maintenance' => 0, 'invoices' => 0];
$recent_users    = [];
$recent_payments = [];

try {
    $db = Database::getInstance()->getConnection();
    $stats['users']       = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['properties']  = (int)$db->query("SELECT COUNT(*) FROM properties")->fetchColumn();
    $stats['tenants']     = (int)$db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $stats['revenue']     = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE LOWER(status)='completed'")->fetchColumn();
    $stats['maintenance'] = (int)$db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
    $stats['invoices']    = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();

    $recent_users = $db->query("
        SELECT u.id, u.full_name, u.email, u.status,
               r.name as role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        ORDER BY u.id DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    $recent_payments = $db->query("
        SELECT p.amount, p.payment_date, p.payment_method, p.status,
               t.name as tenant_name
        FROM payments p
        LEFT JOIN tenants t ON t.id = p.tenant_id
        ORDER BY p.payment_date DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: #f0f2f5; }
        .dashboard { display: flex; min-height: 100vh; }

        /* ── Sidebar (identical to properties.php) ── */
        .sidebar { width: 260px; background: #1e293b; color: white; position: fixed; height: 100vh; overflow-y: auto; }
        .sidebar-header { padding: 14px 20px; border-bottom: 1px solid #334155; }
        .sidebar-header h2 { font-size: 20px; font-weight: 600; color: white; }
        .sidebar-header p  { font-size: 12px; color: #94a3b8; }
        .user-info { padding: 12px 20px; background: #0f1a24; }
        .user-info .name  { font-weight: 600; font-size: 16px; }
        .user-info .email { color: #94a3b8; font-size: 12px; margin-top: 2px; }
        .menu-item { padding: 9px 20px; display: flex; align-items: center; color: #cbd5e1; text-decoration: none; font-size: 13px; transition: background 0.2s; }
        .menu-item:hover, .menu-item.active { background: #334155; color: white; border-left: 3px solid #3b82f6; }
        .menu-item i { width: 24px; margin-right: 12px; }
        .menu-divider { height: 1px; background: #334155; margin: 8px 20px; }

        /* ── Main ── */
        .main-content { margin-left: 260px; flex: 1; padding: 24px 32px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .breadcrumb { color: #64748b; font-size: 14px; }
        .breadcrumb span { color: #0f172a; font-weight: 600; }

        /* ── Welcome (purple→blue, matching properties.php) ── */
        .welcome-card { background: linear-gradient(135deg, #7c3aed, #3b82f6); color: white; padding: 28px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .welcome-card h1 { font-size: 22px; margin-bottom: 6px; }
        .welcome-card p  { font-size: 14px; opacity: 0.85; }
        .admin-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }

        /* ── Stats (matching properties.php 4-col grid) ── */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-icon.blue   { background: #dbeafe; color: #3b82f6; }
        .stat-icon.green  { background: #d1fae5; color: #10b981; }
        .stat-icon.orange { background: #fed7aa; color: #f97316; }
        .stat-icon.red    { background: #fee2e2; color: #ef4444; }
        .stat-icon.yellow { background: #fef3c7; color: #f59e0b; }
        .stat-content { flex: 1; }
        .stat-label { font-size: 13px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 700; color: #0f172a; }
        .stat-sub { font-size: 12px; color: #64748b; margin-top: 4px; }

        /* ── Tables ── */
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
        .table-container { background: white; border-radius: 10px; padding: 20px; overflow-x: auto; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .table-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .table-head h3 { font-size: 15px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        .table-head a { font-size: 13px; color: #3b82f6; text-decoration: none; font-weight: 500; }
        .table-head a:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; background: #f8fafc; color: #475569; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        td { padding: 13px 12px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 13px; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }

        .user-cell { display: flex; align-items: center; gap: 10px; }
        .avatar-sm { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: white; flex-shrink: 0; }
        .user-cell-info .name { font-weight: 600; font-size: 13px; }
        .user-cell-info .sub  { font-size: 11px; color: #94a3b8; }

        .badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-admin     { background: #ede9fe; color: #5b21b6; }
        .badge-agent     { background: #dbeafe; color: #1e40af; }
        .badge-tenant    { background: #d1fae5; color: #065f46; }
        .badge-active    { background: #d1fae5; color: #065f46; }
        .badge-inactive  { background: #f1f5f9; color: #64748b; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-pending   { background: #fef3c7; color: #92400e; }
        .badge-failed    { background: #fee2e2; color: #dc2626; }
        .empty td { text-align: center; color: #94a3b8; padding: 28px; font-size: 13px; }

        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 30px; padding-bottom: 20px; }
    </style>
</head>
<body>
<div class="dashboard">

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>ALLIANCE</h2>
            <p>Realtors</p>
        </div>
        <div class="user-info">
            <div class="name"><?= htmlspecialchars($full_name) ?></div>
            <div class="email"><?= htmlspecialchars($email) ?></div>
        </div>
        <a href="dashboard.php" class="menu-item active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php" class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php" class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="agents.php" class="menu-item"><i class="fas fa-user-tie"></i> Agents</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="reports.php" class="menu-item"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="menu-divider"></div>
        <a href="settings.php" class="menu-item"><i class="fas fa-cog"></i> Settings</a>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Dashboard</span></div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Good <?= (date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening')) ?>, <?= htmlspecialchars(explode(' ', $full_name)[0]) ?>!</h1>
                <p><i class="fas fa-tachometer-alt"></i> Here's a full overview of Alliance Realtors today.</p>
            </div>
            <div class="admin-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-users-cog"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?= $stats['users'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Properties</div>
                    <div class="stat-value"><?= $stats['properties'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Tenants</div>
                    <div class="stat-value"><?= $stats['tenants'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-coins"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value" style="font-size:18px;">KES <?= number_format($stats['revenue'], 0) ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fas fa-tools"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Open Requests</div>
                    <div class="stat-value"><?= $stats['maintenance'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-file-invoice"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Unpaid Invoices</div>
                    <div class="stat-value"><?= $stats['invoices'] ?></div>
                </div>
            </div>
            <!-- Two empty spacer cards to keep 4-col grid -->
            <div></div><div></div>
        </div>

        <!-- Tables -->
        <div class="grid2">
            <!-- Recent Users -->
            <div class="table-container">
                <div class="table-head">
                    <h3><i class="fas fa-users-cog" style="color:#7c3aed"></i> Recent Users</h3>
                    <a href="agents.php">View all →</a>
                </div>
                <table>
                    <thead><tr><th>User</th><th>Role</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php
                        $colors = ['#7c3aed','#3b82f6','#10b981','#f59e0b','#ef4444','#f97316'];
                        foreach ($recent_users as $i => $u):
                            $role = strtolower($u['role_name'] ?? 'tenant');
                        ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="avatar-sm" style="background:<?= $colors[$i % 6] ?>"><?= strtoupper(substr($u['full_name'],0,1)) ?></div>
                                    <div class="user-cell-info">
                                        <div class="name"><?= htmlspecialchars($u['full_name']) ?></div>
                                        <div class="sub"><?= htmlspecialchars($u['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge badge-<?= $role ?>"><?= ucfirst($role) ?></span></td>
                            <td><span class="badge badge-<?= strtolower($u['status'] ?? 'active') ?>"><?= ucfirst($u['status'] ?? 'active') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_users)): ?>
                        <tr class="empty"><td colspan="3">No users found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Payments -->
            <div class="table-container">
                <div class="table-head">
                    <h3><i class="fas fa-credit-card" style="color:#10b981"></i> Recent Payments</h3>
                    <a href="payments.php">View all →</a>
                </div>
                <table>
                    <thead><tr><th>Tenant</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_payments as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['tenant_name'] ?? '—') ?></td>
                            <td><strong>KES <?= number_format($p['amount'], 0) ?></strong></td>
                            <td><?= ucwords(str_replace('_', ' ', $p['payment_method'] ?? '')) ?></td>
                            <td><span class="badge badge-<?= strtolower($p['status'] ?? 'pending') ?>"><?= ucfirst($p['status'] ?? 'pending') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_payments)): ?>
                        <tr class="empty"><td colspan="4">No payments found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>
</body>
</html>