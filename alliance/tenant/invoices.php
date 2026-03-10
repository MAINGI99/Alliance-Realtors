<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_TENANT', 3);

// Auth check
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_TENANT) {
    header("Location: login.php", true, 303);
    exit();
}

$user_id  = (int)$_SESSION['user_id'];
$invoices = [];
$db_error = '';

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $tenant    = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $tenant ? (int)$tenant['id'] : 0;

    if ($tenant_id > 0) {
        // DB column is invoice_number not invoice_no
        $stmt = $db->prepare("
            SELECT id, invoice_number, due_date, amount, status
            FROM invoices
            WHERE tenant_id = ?
            ORDER BY due_date DESC
        ");
        $stmt->execute([$tenant_id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    $db_error = "Unable to load invoices. Please try again later.";
}

// Summary totals
$total_paid   = array_sum(array_column(
    array_filter($invoices, fn($i) => strtolower($i['status'] ?? '') === 'paid'), 'amount'));
$total_unpaid = array_sum(array_column(
    array_filter($invoices, fn($i) => strtolower($i['status'] ?? '') === 'unpaid'), 'amount'));
$total_overdue = array_sum(array_column(
    array_filter($invoices, fn($i) => strtolower($i['status'] ?? '') === 'overdue'), 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - Alliance Realtors</title>
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
        h1 { font-size: 24px; color: #0f172a; margin-bottom: 20px; }

        /* Summary cards */
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .summary-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .summary-card .label { font-size: 13px; color: #64748b; margin-bottom: 6px; }
        .summary-card .value { font-size: 22px; font-weight: 700; color: #0f172a; }
        .summary-card .value.green  { color: #10b981; }
        .summary-card .value.yellow { color: #f59e0b; }
        .summary-card .value.red    { color: #ef4444; }

        /* Table */
        .table-wrapper { background: white; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8fafc; }
        th { text-align: left; padding: 14px 16px; color: #64748b; font-size: 13px; font-weight: 600; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; letter-spacing: 0.04em; }
        td { padding: 14px 16px; color: #334155; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-paid    { background: #d1fae5; color: #065f46; }
        .status-unpaid  { background: #fef3c7; color: #92400e; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        .invoice-number { font-family: monospace; font-size: 13px; font-weight: 600; color: #3b82f6; }
        .overdue-row td { background: #fff8f8; }

        /* Empty / error */
        .empty-state { text-align: center; padding: 60px; background: white; border-radius: 10px; color: #64748b; }
        .empty-state i { font-size: 48px; color: #94a3b8; margin-bottom: 15px; display: block; }
        .alert-error { background: #fee2e2; color: #c33; padding: 12px 16px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid #dc3545; }
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
        <a href="invoices.php" class="menu-item active"><i class="fas fa-file-invoice"></i> Invoices</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Invoices</span></div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <h1>My Invoices</h1>

        <?php if ($db_error): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($db_error) ?></div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label"><i class="fas fa-file-invoice"></i> Total Invoices</div>
                <div class="value"><?= count($invoices) ?></div>
            </div>
            <div class="summary-card">
                <div class="label"><i class="fas fa-check-circle"></i> Total Paid</div>
                <div class="value green">KES <?= number_format($total_paid, 2) ?></div>
            </div>
            <div class="summary-card">
                <div class="label"><i class="fas fa-clock"></i> Unpaid</div>
                <div class="value yellow">KES <?= number_format($total_unpaid, 2) ?></div>
            </div>
            <div class="summary-card">
                <div class="label"><i class="fas fa-exclamation-circle"></i> Overdue</div>
                <div class="value red">KES <?= number_format($total_overdue, 2) ?></div>
            </div>
        </div>

        <?php if (count($invoices) > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Invoice No</th>
                            <th>Due Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $i => $inv): ?>
                            <?php
                                $status  = strtolower($inv['status'] ?? 'unpaid');
                                $dueDate = !empty($inv['due_date']) ? date('M d, Y', strtotime($inv['due_date'])) : '—';
                                $invNo   = htmlspecialchars($inv['invoice_number'] ?? 'INV-' . $inv['id']);
                                $isOverdue = $status === 'overdue';
                            ?>
                            <tr <?= $isOverdue ? 'class="overdue-row"' : '' ?>>
                                <td><?= $i + 1 ?></td>
                                <td><span class="invoice-number"><?= $invNo ?></span></td>
                                <td><?= $dueDate ?></td>
                                <td><strong>KES <?= number_format((float)$inv['amount'], 2) ?></strong></td>
                                <td>
                                    <span class="badge status-<?= $status ?>">
                                        <?php if ($status === 'paid'): ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php elseif ($status === 'overdue'): ?>
                                            <i class="fas fa-exclamation-circle"></i>
                                        <?php else: ?>
                                            <i class="fas fa-clock"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars(ucfirst($status)) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-file-invoice"></i>
                <h3>No invoices found</h3>
                <p>Your invoices will appear here once generated.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>