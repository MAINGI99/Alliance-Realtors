<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_AGENT', 2);
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_AGENT) {
    header("Location: ../login.php", true, 303);
    exit();
}

$full_name = $_SESSION['full_name'] ?? 'Agent';
$email     = $_SESSION['email'] ?? '';

$stats = ['properties' => 0, 'tenants' => 0, 'maintenance' => 0, 'payments' => 0];
$recent_tenants     = [];
$recent_maintenance = [];

try {
    $db = Database::getInstance()->getConnection();
    $stats['properties']  = $db->query("SELECT COUNT(*) FROM properties")->fetchColumn();
    $stats['tenants']     = $db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $stats['maintenance'] = $db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status != 'completed'")->fetchColumn();
    $stats['payments']    = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE LOWER(status)='completed'")->fetchColumn();

    $recent_tenants = $db->query("
        SELECT t.name, t.email, t.status, p.title as property
        FROM tenants t LEFT JOIN properties p ON p.id = t.property_id
        ORDER BY t.id DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $recent_maintenance = $db->query("
        SELECT mr.title, mr.priority, mr.status, mr.created_at, t.name as tenant
        FROM maintenance_requests mr LEFT JOIN tenants t ON t.id = mr.tenant_id
        ORDER BY mr.created_at DESC LIMIT 5
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
    <title>Agent Dashboard - Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: #f0f2f5; }
        .dashboard { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { width: 260px; background: #1e293b; color: white; position: fixed; height: 100vh; overflow-y: auto; }
        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid #334155; }
        .sidebar-header h2 { font-size: 20px; font-weight: 600; color: white; }
        .sidebar-header p { font-size: 12px; color: #94a3b8; }
        .user-info { padding: 20px; background: #0f1a24; }
        .user-info .name { font-weight: 600; font-size: 16px; }
        .user-info .email { color: #94a3b8; font-size: 12px; }
        .menu-item { padding: 12px 20px; display: flex; align-items: center; color: #cbd5e1; text-decoration: none; font-size: 14px; transition: background 0.2s; gap: 12px; }
        .menu-item:hover, .menu-item.active { background: #334155; color: white; border-left: 3px solid #3b82f6; padding-left: 17px; }
        .menu-item i { width: 20px; text-align: center; }
        .menu-divider { height: 1px; background: #334155; margin: 15px 20px; }

        /* Main */
        .main-content { flex: 1; margin-left: 260px; padding: 24px 32px; }
        .top-bar { background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
        .breadcrumb { color: #64748b; font-size: 14px; }
        .breadcrumb span { color: #0f172a; font-weight: 600; }

        /* Welcome */
        .welcome-card { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; padding: 24px 28px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; box-shadow: 0 4px 15px rgba(59,130,246,0.3); }
        .welcome-card h1 { font-size: 22px; margin-bottom: 4px; }
        .welcome-card p { font-size: 13px; opacity: 0.85; }
        .role-badge { background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; white-space: nowrap; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        .stat-card { background: white; padding: 22px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
        .stat-icon.blue   { background: #e0f2fe; color: #3b82f6; }
        .stat-icon.green  { background: #d1fae5; color: #10b981; }
        .stat-icon.yellow { background: #fef3c7; color: #f59e0b; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-label { font-size: 13px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 700; color: #0f172a; }

        /* Tables */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .section-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        .section-head { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .section-head h2 { font-size: 15px; color: #0f172a; font-weight: 600; }
        .section-head a { font-size: 13px; color: #3b82f6; text-decoration: none; }
        .section-head a:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8fafc; }
        th { padding: 11px 16px; text-align: left; font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid #e2e8f0; font-weight: 600; }
        td { padding: 13px 16px; font-size: 13px; color: #334155; border-bottom: 1px solid #f8fafc; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-active, .badge-completed   { background: #d1fae5; color: #065f46; }
        .badge-inactive                   { background: #f1f5f9; color: #475569; }
        .badge-pending, .badge-medium     { background: #fef3c7; color: #92400e; }
        .badge-high                       { background: #fee2e2; color: #991b1b; }
        .badge-low                        { background: #f1f5f9; color: #475569; }
        .badge-urgent                     { background: #7f1d1d; color: white; }
        .badge-in_progress                { background: #dbeafe; color: #1e40af; }
        .empty-row td { text-align: center; color: #94a3b8; padding: 30px; }
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
        <a href="dashboard.php" class="menu-item active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php" class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php" class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <!-- FIXED: Changed from logout.php to ../logout.php -->
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
                <h1>Welcome back, <?= htmlspecialchars($full_name) ?>!</h1>
                <p>Here's what's happening with your properties today.</p>
            </div>
            <div class="role-badge"><i class="fas fa-user-tie"></i> Agent</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                <div>
                    <div class="stat-label">Total Properties</div>
                    <div class="stat-value"><?= $stats['properties'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-label">Total Tenants</div>
                    <div class="stat-value"><?= $stats['tenants'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fas fa-tools"></i></div>
                <div>
                    <div class="stat-label">Open Requests</div>
                    <div class="stat-value"><?= $stats['maintenance'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-money-bill-wave"></i></div>
                <div>
                    <div class="stat-label">Total Collected</div>
                    <div class="stat-value" style="font-size:18px;">KES <?= number_format((float)$stats['payments'], 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Recent Tables -->
        <div class="two-col">
            <!-- Recent Tenants -->
            <div class="section-card">
                <div class="section-head">
                    <h2><i class="fas fa-users" style="color:#3b82f6;"></i> Recent Tenants</h2>
                    <a href="tenants.php">View all →</a>
                </div>
                <table>
                    <thead><tr><th>Name</th><th>Property</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_tenants as $t): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;"><?= htmlspecialchars($t['name']) ?></div>
                                <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($t['email']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($t['property'] ?? '—') ?></td>
                            <td>
                                <span class="badge badge-<?= strtolower($t['status'] ?? 'active') ?>">
                                    <?= htmlspecialchars(ucfirst($t['status'] ?? 'Active')) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_tenants)): ?>
                        <tr class="empty-row"><td colspan="3">No tenants yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Maintenance -->
            <div class="section-card">
                <div class="section-head">
                    <h2><i class="fas fa-tools" style="color:#f59e0b;"></i> Recent Maintenance</h2>
                    <a href="maintenance.php">View all →</a>
                </div>
                <table>
                    <thead><tr><th>Title</th><th>Tenant</th><th>Priority</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_maintenance as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['title']) ?></td>
                            <td><?= htmlspecialchars($m['tenant'] ?? '—') ?></td>
                            <td>
                                <span class="badge badge-<?= strtolower($m['priority'] ?? 'medium') ?>">
                                    <?= ucfirst($m['priority'] ?? 'medium') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= strtolower($m['status'] ?? 'pending') ?>">
                                    <?= ucwords(str_replace('_', ' ', $m['status'] ?? 'pending')) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recent_maintenance)): ?>
                        <tr class="empty-row"><td colspan="4">No requests yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
</body>
</html>