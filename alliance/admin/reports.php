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

$db = Database::getInstance()->getConnection();

// ── Date range filter ────────────────────────────────────────
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? 0); // 0 = all months

// ── Revenue by month (current year) ─────────────────────────
$monthly_revenue = array_fill(1, 12, 0);
try {
    $rows = $db->prepare("
        SELECT MONTH(payment_date) as m, COALESCE(SUM(amount),0) as total
        FROM payments
        WHERE LOWER(status)='completed' AND YEAR(payment_date) = ?
        GROUP BY MONTH(payment_date)
    ");
    $rows->execute([$year]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $monthly_revenue[(int)$r['m']] = (float)$r['total'];
    }
} catch (PDOException $e) { error_log($e->getMessage()); }

// ── Payments by method ───────────────────────────────────────
$by_method = [];
try {
    $by_method = $db->query("
        SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total
        FROM payments WHERE LOWER(status)='completed'
        GROUP BY payment_method
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }

// ── Maintenance by priority ──────────────────────────────────
$by_priority = [];
try {
    $by_priority = $db->query("
        SELECT priority, COUNT(*) as cnt
        FROM maintenance_requests
        GROUP BY priority
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }

// ── Property occupancy ───────────────────────────────────────
$occupancy = ['available'=>0,'occupied'=>0,'maintenance'=>0];
try {
    $rows = $db->query("SELECT LOWER(status) as s, COUNT(*) as cnt FROM properties GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { if (isset($occupancy[$r['s']])) $occupancy[$r['s']] = (int)$r['cnt']; }
} catch (PDOException $e) { error_log($e->getMessage()); }

// ── Summary KPIs ─────────────────────────────────────────────
$kpi = ['revenue'=>0,'payments'=>0,'tenants'=>0,'properties'=>0,'open_maintenance'=>0,'unpaid_invoices'=>0];
try {
    $kpi['revenue']          = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE LOWER(status)='completed'")->fetchColumn();
    $kpi['payments']         = (int)$db->query("SELECT COUNT(*) FROM payments WHERE LOWER(status)='completed'")->fetchColumn();
    $kpi['tenants']          = (int)$db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $kpi['properties']       = (int)$db->query("SELECT COUNT(*) FROM properties")->fetchColumn();
    $kpi['open_maintenance'] = (int)$db->query("SELECT COUNT(*) FROM maintenance_requests WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
    $kpi['unpaid_invoices']  = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();
} catch (PDOException $e) { error_log($e->getMessage()); }

// ── Top paying tenants ───────────────────────────────────────
$top_tenants = [];
try {
    $top_tenants = $db->query("
        SELECT t.name, t.email, COUNT(p.id) as payments, COALESCE(SUM(p.amount),0) as total
        FROM tenants t
        JOIN payments p ON p.tenant_id = t.id AND LOWER(p.status)='completed'
        GROUP BY t.id ORDER BY total DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }

// ── Recent activity ──────────────────────────────────────────
$recent_activity = [];
try {
    $recent_activity = $db->query("
        SELECT 'payment' as type, CONCAT('Payment of KES ',FORMAT(p.amount,0),' from ',t.name) as description, p.created_at as dt
        FROM payments p LEFT JOIN tenants t ON t.id=p.tenant_id WHERE LOWER(p.status)='completed'
        UNION ALL
        SELECT 'maintenance', CONCAT('Maintenance: ',mr.title,' (',mr.status,')'), mr.created_at
        FROM maintenance_requests mr
        ORDER BY dt DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }

$month_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$max_revenue = max(array_values($monthly_revenue)) ?: 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin | Alliance Realtors</title>
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

        /* KPI grid */
        .kpi-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; margin-bottom:25px; }
        .kpi-card { background:white; border-radius:10px; padding:20px 22px; display:flex; align-items:center; gap:15px; box-shadow:0 2px 4px rgba(0,0,0,0.08); border-left:4px solid transparent; }
        .kpi-card.green  { border-left-color:#10b981; }
        .kpi-card.blue   { border-left-color:#3b82f6; }
        .kpi-card.purple { border-left-color:#7c3aed; }
        .kpi-card.orange { border-left-color:#f97316; }
        .kpi-card.yellow { border-left-color:#f59e0b; }
        .kpi-card.red    { border-left-color:#ef4444; }
        .kpi-icon { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .kpi-card.green  .kpi-icon { background:#d1fae5; color:#10b981; }
        .kpi-card.blue   .kpi-icon { background:#dbeafe; color:#3b82f6; }
        .kpi-card.purple .kpi-icon { background:#ede9fe; color:#7c3aed; }
        .kpi-card.orange .kpi-icon { background:#ffedd5; color:#f97316; }
        .kpi-card.yellow .kpi-icon { background:#fef3c7; color:#f59e0b; }
        .kpi-card.red    .kpi-icon { background:#fee2e2; color:#ef4444; }
        .kpi-label { font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:0.04em; font-weight:600; margin-bottom:4px; }
        .kpi-value { font-size:22px; font-weight:800; color:#0f172a; }
        .kpi-value.sm { font-size:16px; }

        /* Charts row */
        .charts-row { display:grid; grid-template-columns:2fr 1fr; gap:22px; margin-bottom:25px; }
        .chart-card { background:white; border-radius:10px; padding:22px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        .chart-title { font-size:15px; font-weight:700; color:#0f172a; margin-bottom:18px; display:flex; align-items:center; gap:8px; }

        /* Bar chart */
        .bar-chart { display:flex; align-items:flex-end; gap:8px; height:160px; }
        .bar-wrap { flex:1; align-items:center; gap:5px; height:100%; justify-content:flex-end; }
        .bar { width:100%; border-radius:4px 4px 0 0; background:linear-gradient(180deg,#7c3aed,#3b82f6); transition:opacity 0.2s; min-height:4px; }
        .bar:hover { opacity:0.8; }
        .bar-label { font-size:10px; color:#94a3b8; font-weight:600; }
        .bar-val   { font-size:9px; color:#64748b; }

        /* Donut charts */
        .donut-row { gap:12px; }
        .donut-item { display:flex; align-items:center; gap:10px; }
        .donut-dot  { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
        .donut-name { font-size:13px; color:#334155; flex:1; }
        .donut-bar-wrap { flex:2; height:8px; background:#f1f5f9; border-radius:4px; overflow:hidden; }
        .donut-bar-fill { height:100%; border-radius:4px; }
        .donut-count { font-size:12px; font-weight:700; color:#0f172a; min-width:30px; text-align:right; }

        /* Bottom row */
        .bottom-row { display:grid; grid-template-columns:1fr 1fr; gap:22px; margin-bottom:25px; }
        .section-card { background:white; border-radius:10px; padding:22px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        .section-title { font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
        table { width:100%; border-collapse:collapse; }
        th { text-align:left; padding:10px 12px; background:#f8fafc; color:#475569; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; border-bottom:2px solid #e2e8f0; }
        td { padding:12px; border-bottom:1px solid #f8fafc; font-size:13px; color:#334155; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        tr:hover td { background:#f8fafc; }

        /* Activity */
        .activity-list { gap:12px; }
        .activity-item { display:flex; align-items:flex-start; gap:12px; }
        .activity-icon { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
        .activity-icon.payment     { background:#d1fae5; color:#10b981; }
        .activity-icon.maintenance { background:#fef3c7; color:#f59e0b; }
        .activity-text { flex:1; }
        .activity-desc { font-size:13px; color:#334155; }
        .activity-time { font-size:11px; color:#94a3b8; margin-top:2px; }

        /* Year filter */
        .year-filter { display:flex; align-items:center; gap:10px; }
        .year-filter select { padding:6px 12px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; }
        .year-filter select:focus { outline:none; border-color:#3b82f6; }

        .footer { text-align:center; color:#94a3b8; font-size:12px; margin-top:20px; padding-bottom:20px; }
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
        <a href="reports.php"     class="menu-item active"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="menu-divider"></div>
        <a href="settings.php"    class="menu-item"><i class="fas fa-cog"></i> Settings</a>
        <a href="profile.php"     class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php"   class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Reports</span></div>
            <div class="year-filter">
                <i class="far fa-calendar-alt"></i>
                <form method="GET" style="display:inline;">
                    <select name="year" onchange="this.form.submit()">
                        <?php for ($y = date('Y'); $y >= date('Y')-4; $y--): ?>
                            <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Reports & Analytics</h1>
                <p><i class="fas fa-chart-bar"></i> Financial and operational overview for <?= $year ?></p>
            </div>
            <div class="admin-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card green">
                <div class="kpi-icon"><i class="fas fa-coins"></i></div>
                <div>
                    <div class="kpi-label">Total Revenue</div>
                    <div class="kpi-value sm">KES <?= number_format($kpi['revenue'], 0) ?></div>
                </div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
                <div>
                    <div class="kpi-label">Completed Payments</div>
                    <div class="kpi-value"><?= $kpi['payments'] ?></div>
                </div>
            </div>
            <div class="kpi-card purple">
                <div class="kpi-icon"><i class="fas fa-users"></i></div>
                <div>
                    <div class="kpi-label">Total Tenants</div>
                    <div class="kpi-value"><?= $kpi['tenants'] ?></div>
                </div>
            </div>
            <div class="kpi-card orange">
                <div class="kpi-icon"><i class="fas fa-building"></i></div>
                <div>
                    <div class="kpi-label">Properties</div>
                    <div class="kpi-value"><?= $kpi['properties'] ?></div>
                </div>
            </div>
            <div class="kpi-card yellow">
                <div class="kpi-icon"><i class="fas fa-tools"></i></div>
                <div>
                    <div class="kpi-label">Open Maintenance</div>
                    <div class="kpi-value"><?= $kpi['open_maintenance'] ?></div>
                </div>
            </div>
            <div class="kpi-card red">
                <div class="kpi-icon"><i class="fas fa-file-invoice"></i></div>
                <div>
                    <div class="kpi-label">Unpaid Invoices</div>
                    <div class="kpi-value"><?= $kpi['unpaid_invoices'] ?></div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-row">
            <!-- Monthly Revenue Bar Chart -->
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-bar" style="color:#7c3aed"></i> Monthly Revenue — <?= $year ?></div>
                <div class="bar-chart">
                    <?php foreach ($monthly_revenue as $m => $val): ?>
                    <div class="bar-wrap" title="<?= $month_names[$m-1] ?>: KES <?= number_format($val,0) ?>">
                        <div class="bar-val">KES <?= $val > 0 ? number_format($val/1000,0).'k' : '0' ?></div>
                        <div class="bar" style="height:<?= $max_revenue > 0 ? round(($val/$max_revenue)*140) : 4 ?>px;"></div>
                        <div class="bar-label"><?= $month_names[$m-1] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Breakdowns -->
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-pie" style="color:#3b82f6"></i> Breakdowns</div>

                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:10px;">Payment Methods</div>
                <div class="donut-row" style="margin-bottom:18px;">
                    <?php
                    $method_colors = ['mpesa'=>'#10b981','cash'=>'#3b82f6','bank_transfer'=>'#7c3aed','cheque'=>'#f59e0b'];
                    $method_labels = ['mpesa'=>'M-Pesa','cash'=>'Cash','bank_transfer'=>'Bank Transfer','cheque'=>'Cheque'];
                    $method_total  = array_sum(array_column($by_method,'cnt')) ?: 1;
                    foreach ($by_method as $bm):
                        $k   = strtolower($bm['payment_method']);
                        $pct = round($bm['cnt']/$method_total*100);
                    ?>
                    <div class="donut-item">
                        <div class="donut-dot" style="background:<?= $method_colors[$k]??'#94a3b8' ?>"></div>
                        <div class="donut-name"><?= $method_labels[$k] ?? ucfirst($k) ?></div>
                        <div class="donut-bar-wrap"><div class="donut-bar-fill" style="width:<?= $pct ?>%;background:<?= $method_colors[$k]??'#94a3b8' ?>"></div></div>
                        <div class="donut-count"><?= $bm['cnt'] ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($by_method)): ?><div style="color:#94a3b8;font-size:13px;">No data</div><?php endif; ?>
                </div>

                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:10px;">Maintenance Priority</div>
                <div class="donut-row">
                    <?php
                    $pri_colors = ['low'=>'#10b981','medium'=>'#f59e0b','high'=>'#ef4444','urgent'=>'#7f1d1d'];
                    $pri_total  = array_sum(array_column($by_priority,'cnt')) ?: 1;
                    foreach ($by_priority as $bp):
                        $k   = strtolower($bp['priority']);
                        $pct = round($bp['cnt']/$pri_total*100);
                    ?>
                    <div class="donut-item">
                        <div class="donut-dot" style="background:<?= $pri_colors[$k]??'#94a3b8' ?>"></div>
                        <div class="donut-name"><?= ucfirst($k) ?></div>
                        <div class="donut-bar-wrap"><div class="donut-bar-fill" style="width:<?= $pct ?>%;background:<?= $pri_colors[$k]??'#94a3b8' ?>"></div></div>
                        <div class="donut-count"><?= $bp['cnt'] ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($by_priority)): ?><div style="color:#94a3b8;font-size:13px;">No data</div><?php endif; ?>
                </div>

                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;margin:18px 0 10px;">Property Occupancy</div>
                <div class="donut-row">
                    <?php
                    $occ_colors = ['available'=>'#10b981','occupied'=>'#3b82f6','maintenance'=>'#f59e0b'];
                    $occ_total  = array_sum($occupancy) ?: 1;
                    foreach ($occupancy as $k => $cnt):
                        $pct = round($cnt/$occ_total*100);
                    ?>
                    <div class="donut-item">
                        <div class="donut-dot" style="background:<?= $occ_colors[$k] ?>"></div>
                        <div class="donut-name"><?= ucfirst($k) ?></div>
                        <div class="donut-bar-wrap"><div class="donut-bar-fill" style="width:<?= $pct ?>%;background:<?= $occ_colors[$k] ?>"></div></div>
                        <div class="donut-count"><?= $cnt ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Bottom row -->
        <div class="bottom-row">
            <!-- Top tenants -->
            <div class="section-card">
                <div class="section-title"><i class="fas fa-trophy" style="color:#f59e0b"></i> Top Paying Tenants</div>
                <table>
                    <thead><tr><th>#</th><th>Tenant</th><th>Payments</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php if (!empty($top_tenants)): ?>
                        <?php foreach ($top_tenants as $i => $t): ?>
                        <tr>
                            <td>
                                <span style="background:<?= ['#fef3c7','#f1f5f9','#fef3c7'][$i] ?? '#f1f5f9' ?>;color:<?= ['#92400e','#475569','#92400e'][$i] ?? '#475569' ?>;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">#<?= $i+1 ?></span>
                            </td>
                            <td>
                                <div style="font-weight:600;"><?= htmlspecialchars($t['name']) ?></div>
                                <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($t['email']) ?></div>
                            </td>
                            <td style="color:#64748b;"><?= $t['payments'] ?></td>
                            <td><strong>KES <?= number_format($t['total'],0) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:30px;">No payment data</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent activity -->
            <div class="section-card">
                <div class="section-title"><i class="fas fa-history" style="color:#3b82f6"></i> Recent Activity</div>
                <div class="activity-list">
                    <?php if (!empty($recent_activity)): ?>
                        <?php foreach ($recent_activity as $act): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?= $act['type'] ?>">
                                <i class="fas <?= $act['type']==='payment' ? 'fa-credit-card' : 'fa-tools' ?>"></i>
                            </div>
                            <div class="activity-text">
                                <div class="activity-desc"><?= htmlspecialchars($act['description']) ?></div>
                                <div class="activity-time"><?= date('M j, Y g:i A', strtotime($act['dt'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center;color:#94a3b8;padding:30px;">No recent activity</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>
</body>
</html>