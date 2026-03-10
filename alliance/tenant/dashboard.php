<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_TENANT', 3);

// Auth check
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_TENANT) {
    header("Location: login.php", true, 303);
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Tenant';
$email     = $_SESSION['email'] ?? '';

$payments_count  = 0;
$total_paid      = 0;
$pending_amount  = 0;
$property_name   = 'Not assigned';
$monthly_rent    = 0;
$recent_payments = [];
$open_requests   = 0;

try {
    $db = Database::getInstance()->getConnection();

    // Fetch tenant record
    $stmt = $db->prepare("SELECT id, property_id FROM tenants WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tenant) {
        $tenant_id = (int)$tenant['id'];

        // Payments count
        $stmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE tenant_id = ?");
        $stmt->execute([$tenant_id]);
        $payments_count = (int)$stmt->fetchColumn();

        // Total paid (completed) — lowercase to match DB enum
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = ? AND LOWER(status) = 'completed'");
        $stmt->execute([$tenant_id]);
        $total_paid = (float)$stmt->fetchColumn();

        // Pending amount — lowercase to match DB enum
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = ? AND LOWER(status) = 'pending'");
        $stmt->execute([$tenant_id]);
        $pending_amount = (float)$stmt->fetchColumn();

        // Open maintenance requests — lowercase to match DB enum
        $stmt = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = ? AND status != 'completed'");
        $stmt->execute([$tenant_id]);
        $open_requests = (int)$stmt->fetchColumn();

        // Property info
        if (!empty($tenant['property_id'])) {
            $stmt = $db->prepare("SELECT title, price FROM properties WHERE id = ?");
            $stmt->execute([(int)$tenant['property_id']]);
            $property = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($property) {
                $property_name = $property['title'];
                $monthly_rent  = (float)$property['price'];
            }
        }

        // Recent payments (last 5)
        $stmt = $db->prepare("
            SELECT payment_date, amount, method, status
            FROM payments
            WHERE tenant_id = ?
            ORDER BY payment_date DESC
            LIMIT 5
        ");
        $stmt->execute([$tenant_id]);
        $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Dashboard Error: " . $e->getMessage());
}

// Helper: status badge — handles both lowercase and mixed case
function statusBadge(string $status): string {
    $map = [
        'completed' => ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => 'fa-check-circle'],
        'pending'   => ['bg' => '#fef3c7', 'color' => '#92400e', 'icon' => 'fa-clock'],
        'failed'    => ['bg' => '#fee2e2', 'color' => '#991b1b', 'icon' => 'fa-times-circle'],
        'cancelled' => ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-times-circle'],
    ];
    $key   = strtolower($status);
    $style = $map[$key] ?? ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-question-circle'];
    return sprintf(
        '<span style="background:%s;color:%s;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
            <i class="fas %s"></i> %s
        </span>',
        $style['bg'], $style['color'], $style['icon'],
        htmlspecialchars(ucfirst($key))
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Dashboard - Alliance Realtors</title>
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
        .user-info .email { color: #94a3b8; font-size: 12px; margin-top: 2px; }
        .menu-item { padding: 12px 20px; display: flex; align-items: center; color: #cbd5e1; text-decoration: none; font-size: 14px; transition: background 0.2s; }
        .menu-item:hover, .menu-item.active { background: #334155; color: white; border-left: 3px solid #3b82f6; }
        .menu-item i { width: 24px; margin-right: 12px; }
        .menu-divider { height: 1px; background: #334155; margin: 15px 20px; }

        /* Main */
        .main-content { margin-left: 260px; flex: 1; padding: 24px 32px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .breadcrumb { color: #64748b; font-size: 14px; }
        .breadcrumb span { color: #0f172a; font-weight: 600; }

        /* Welcome */
        .welcome-card { background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; padding: 28px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .welcome-card h1 { font-size: 22px; margin-bottom: 6px; }
        .welcome-card p { font-size: 14px; opacity: 0.85; }
        .tenant-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .stat-icon { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 10px; margin-bottom: 12px; }
        .stat-icon i { font-size: 22px; }
        .stat-icon.blue   { background: #e0f2fe; } .stat-icon.blue i   { color: #3b82f6; }
        .stat-icon.green  { background: #d1fae5; } .stat-icon.green i  { color: #10b981; }
        .stat-icon.yellow { background: #fef3c7; } .stat-icon.yellow i { color: #f59e0b; }
        .stat-icon.purple { background: #ede9fe; } .stat-icon.purple i { color: #7c3aed; }
        .stat-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 22px; font-weight: 700; color: #0f172a; }

        /* Recent payments */
        .recent-section { background: white; padding: 24px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); margin-bottom: 30px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .section-header h2 { font-size: 18px; color: #0f172a; }
        .section-header a { font-size: 13px; color: #3b82f6; text-decoration: none; }
        .section-header a:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8fafc; }
        th { text-align: left; padding: 12px 14px; color: #64748b; font-size: 12px; font-weight: 600; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; letter-spacing: 0.04em; }
        td { padding: 12px 14px; color: #334155; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }
        .empty-msg { color: #94a3b8; font-size: 14px; text-align: center; padding: 30px 0; }

        /* Maintenance summary */
        .maintenance-section { background: white; padding: 24px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); margin-bottom: 30px; }

        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 10px; padding-bottom: 20px; }
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
        <a href="properties.php" class="menu-item"><i class="fas fa-building"></i> Browse Properties</a>
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="invoices.php" class="menu-item"><i class="fas fa-file-invoice"></i> Invoices</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-home"></i> Home / <span>Dashboard</span>
            </div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Welcome back, <?= htmlspecialchars($full_name) ?>!</h1>
                <p><i class="fas fa-building"></i> <?= htmlspecialchars($property_name) ?></p>
            </div>
            <div class="tenant-badge"><i class="fas fa-user"></i> Tenant</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-home"></i></div>
                <div class="stat-label">Monthly Rent</div>
                <div class="stat-value">KES <?= number_format($monthly_rent, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Total Paid</div>
                <div class="stat-value">KES <?= number_format($total_paid, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Pending Amount</div>
                <div class="stat-value">KES <?= number_format($pending_amount, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-tools"></i></div>
                <div class="stat-label">Open Requests</div>
                <div class="stat-value"><?= $open_requests ?></div>
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="recent-section">
            <div class="section-header">
                <h2><i class="fas fa-history"></i> Recent Payments</h2>
                <a href="payments.php">View all →</a>
            </div>
            <?php if (count($recent_payments) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $payment): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($payment['payment_date']))) ?></td>
                                <td>KES <?= number_format((float)$payment['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($payment['method'] ?? 'M-Pesa') ?></td>
                                <td><?= statusBadge($payment['status'] ?? 'pending') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty-msg"><i class="fas fa-inbox"></i> No recent payments found.</p>
            <?php endif; ?>
        </div>

        <!-- Recent Maintenance Requests -->
        <div class="maintenance-section">
            <div class="section-header">
                <h2><i class="fas fa-tools"></i> Recent Maintenance Requests</h2>
                <a href="maintenance.php">View all →</a>
            </div>
            <?php
            // Fetch last 3 maintenance requests for dashboard preview
            $maint_preview = [];
            if (!empty($tenant_id)) {
                try {
                    $stmt = $db->prepare("
                        SELECT title, priority, status, created_at
                        FROM maintenance_requests
                        WHERE tenant_id = ?
                        ORDER BY created_at DESC
                        LIMIT 3
                    ");
                    $stmt->execute([$tenant_id]);
                    $maint_preview = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log("Maintenance preview error: " . $e->getMessage());
                }
            }
            ?>
            <?php if (count($maint_preview) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Title</th>
                            <th>Priority</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($maint_preview as $req): ?>
                            <?php
                                $st  = strtolower($req['status'] ?? 'pending');
                                $pri = strtolower($req['priority'] ?? 'medium');
                                $stColors = [
                                    'pending'     => ['bg' => '#fef3c7', 'color' => '#92400e', 'icon' => 'fa-clock'],
                                    'in_progress' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'icon' => 'fa-spinner'],
                                    'completed'   => ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => 'fa-check-circle'],
                                    'cancelled'   => ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-times-circle'],
                                ];
                                $priColors = [
                                    'low'    => ['bg' => '#f1f5f9', 'color' => '#475569'],
                                    'medium' => ['bg' => '#fef3c7', 'color' => '#92400e'],
                                    'high'   => ['bg' => '#fee2e2', 'color' => '#991b1b'],
                                    'urgent' => ['bg' => '#7f1d1d', 'color' => 'white'],
                                ];
                                $sc = $stColors[$st]  ?? ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-question-circle'];
                                $pc = $priColors[$pri] ?? ['bg' => '#fef3c7', 'color' => '#92400e'];
                            ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($req['created_at'])) ?></td>
                                <td><?= htmlspecialchars($req['title']) ?></td>
                                <td>
                                    <span style="background:<?= $pc['bg'] ?>;color:<?= $pc['color'] ?>;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">
                                        <?= ucfirst($pri) ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                                        <i class="fas <?= $sc['icon'] ?>"></i>
                                        <?= ucwords(str_replace('_', ' ', $st)) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty-msg"><i class="fas fa-tools"></i> No maintenance requests yet.</p>
            <?php endif; ?>
        </div>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>
</body>
</html>