<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_TENANT', 3);
define('ALLOWED_METHODS', ['mpesa', 'cash', 'bank_transfer', 'cheque']);

// Auth check
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_TENANT) {
    header("Location: login.php", true, 303);
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Tenant';
$email     = $_SESSION['email'] ?? '';

$payments  = [];
$db_error  = '';
$success   = '';
$error     = '';

// Helper: CSS-safe status class
function statusClass(string $status): string {
    return 'status-' . strtolower($status);
}

// Helper: status icon
function statusIcon(string $status): string {
    return match(strtolower($status)) {
        'completed' => '<i class="fas fa-check-circle"></i>',
        'pending'   => '<i class="fas fa-clock"></i>',
        'failed'    => '<i class="fas fa-times-circle"></i>',
        default     => '<i class="fas fa-question-circle"></i>',
    };
}

try {
    $db = Database::getInstance()->getConnection();

    // Get tenant record
    $stmt = $db->prepare("SELECT id, property_id FROM tenants WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $tenant    = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id = $tenant ? (int)$tenant['id'] : 0;

    // Get lease record
    $lease    = null;
    $lease_id = null;
    if ($tenant_id > 0) {
        $stmt = $db->prepare("SELECT id, monthly_rent FROM leases WHERE tenant_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lease) {
            $lease_id     = (int)$lease['id'];
            $monthly_rent = (float)$lease['monthly_rent'];
        }
    }

    // Handle payment form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_payment'])) {
        $amount           = trim($_POST['amount'] ?? '');
        $payment_method   = strtolower(trim($_POST['payment_method'] ?? 'mpesa'));
        $transaction_code = trim($_POST['transaction_code'] ?? '');
        $payment_date     = trim($_POST['payment_date'] ?? date('Y-m-d'));

        // Validate
        if (empty($amount) || !is_numeric($amount) || (float)$amount <= 0) {
            $error = "Please enter a valid payment amount.";
        } elseif (!in_array($payment_method, ALLOWED_METHODS, true)) {
            $error = "Invalid payment method.";
        } elseif ($tenant_id === 0) {
            $error = "Tenant record not found. Please contact support.";
        } elseif ($lease_id === null) {
            $error = "No active lease found. Please contact support.";
        } else {
            $stmt = $db->prepare("
                INSERT INTO payments
                    (tenant_id, lease_id, amount, payment_date, payment_method, transaction_code, status)
                VALUES (?, ?, ?, ?, ?, ?, 'completed')
            ");
            $stmt->execute([
                $tenant_id,
                $lease_id,
                (float)$amount,
                $payment_date,
                $payment_method,
                $transaction_code ?: null,
            ]);
            $success = "Payment of KES " . number_format((float)$amount, 2) . " recorded successfully!";
            $_POST   = [];
        }
    }

    // Fetch all payments
    if ($tenant_id > 0) {
        $stmt = $db->prepare("
            SELECT id, payment_date, amount, payment_method, transaction_code, status
            FROM payments
            WHERE tenant_id = ?
            ORDER BY payment_date DESC
        ");
        $stmt->execute([$tenant_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Payments error: " . $e->getMessage());
    $db_error = "Unable to load payment records. Please try again later.";
}

// Summary stats
$total_paid    = array_sum(array_column(
    array_filter($payments, fn($p) => strtolower($p['status'] ?? '') === 'completed'),
    'amount'
));
$total_pending = array_sum(array_column(
    array_filter($payments, fn($p) => strtolower($p['status'] ?? '') === 'pending'),
    'amount'
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - Alliance Realtors</title>
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
        h2 { font-size: 18px; color: #0f172a; margin-bottom: 16px; }

        /* Alerts */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error   { background: #fee2e2; color: #c33;    border-left: 4px solid #dc3545; }

        /* Payment form */
        .payment-form-card {
            background: white;
            padding: 28px;
            border-radius: 12px;
            margin-bottom: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-top: 4px solid #3b82f6;
        }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #334155; font-size: 14px; }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid #ddd;
            border-radius: 8px; font-size: 14px; font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        .rent-hint {
            font-size: 12px; color: #64748b;
            margin-top: 5px;
        }
        .rent-hint strong { color: #3b82f6; }
        .btn-pay {
            background: #3b82f6; color: white; border: none;
            padding: 12px 32px; border-radius: 8px; cursor: pointer;
            font-size: 15px; font-weight: 600; transition: background 0.2s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-pay:hover { background: #2563eb; }

        /* Summary cards */
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .summary-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .summary-card .label { font-size: 13px; color: #64748b; margin-bottom: 6px; }
        .summary-card .value { font-size: 22px; font-weight: 700; color: #0f172a; }
        .summary-card .value.green  { color: #10b981; }
        .summary-card .value.yellow { color: #f59e0b; }

        /* Table */
        .table-wrapper { background: white; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8fafc; }
        th { text-align: left; padding: 14px 16px; color: #64748b; font-size: 13px; font-weight: 600; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; letter-spacing: 0.04em; }
        td { padding: 14px 16px; color: #334155; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-pending   { background: #fef3c7; color: #92400e; }
        .status-failed    { background: #fee2e2; color: #991b1b; }
        .empty-state { text-align: center; padding: 60px; background: white; border-radius: 10px; color: #64748b; }
        .empty-state i { font-size: 48px; color: #94a3b8; margin-bottom: 15px; display: block; }

        /* Method badge */
        .method-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: #f1f5f9; color: #475569; border-radius: 6px; font-size: 12px; font-weight: 500; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="sidebar">
        <div class="sidebar-header"><h2>ALLIANCE</h2><p>Realtors</p></div>
        <div class="user-info">
            <div class="name"><?= htmlspecialchars($full_name) ?></div>
            <div class="email"><?= htmlspecialchars($email) ?></div>
        </div>
        <a href="dashboard.php" class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php" class="menu-item"><i class="fas fa-building"></i> Browse Properties</a>
        <a href="payments.php" class="menu-item active"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="invoices.php" class="menu-item"><i class="fas fa-file-invoice"></i> Invoices</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Payments</span></div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <h1>Payments</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($db_error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($db_error) ?></div>
        <?php endif; ?>

        <!-- Make Payment Form -->
        <?php if ($lease_id): ?>
        <div class="payment-form-card">
            <h2><i class="fas fa-credit-card"></i> Make a Payment</h2>
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="amount"><i class="fas fa-money-bill"></i> Amount (KES)</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="1"
                               placeholder="e.g. <?= number_format($monthly_rent ?? 0, 2) ?>"
                               value="<?= isset($_POST['amount']) && $error ? htmlspecialchars($_POST['amount']) : ($monthly_rent ?? '') ?>"
                               required>
                        <?php if (!empty($monthly_rent)): ?>
                            <div class="rent-hint">Monthly rent: <strong>KES <?= number_format($monthly_rent, 2) ?></strong></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="payment_date"><i class="fas fa-calendar"></i> Payment Date</label>
                        <input type="date" id="payment_date" name="payment_date"
                               value="<?= isset($_POST['payment_date']) && $error ? htmlspecialchars($_POST['payment_date']) : date('Y-m-d') ?>"
                               max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="payment_method"><i class="fas fa-wallet"></i> Payment Method</label>
                        <select id="payment_method" name="payment_method">
                            <option value="mpesa"         <?= (($_POST['payment_method'] ?? 'mpesa') === 'mpesa')         ? 'selected' : '' ?>>M-Pesa</option>
                            <option value="cash"          <?= (($_POST['payment_method'] ?? '') === 'cash')          ? 'selected' : '' ?>>Cash</option>
                            <option value="bank_transfer" <?= (($_POST['payment_method'] ?? '') === 'bank_transfer') ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="cheque"        <?= (($_POST['payment_method'] ?? '') === 'cheque')        ? 'selected' : '' ?>>Cheque</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="transaction_code"><i class="fas fa-hashtag"></i> Transaction Code <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
                        <input type="text" id="transaction_code" name="transaction_code"
                               placeholder="e.g. QHX7Y2Z9K1"
                               value="<?= isset($_POST['transaction_code']) ? htmlspecialchars($_POST['transaction_code']) : '' ?>">
                    </div>
                </div>
                <button type="submit" name="make_payment" class="btn-pay">
                    <i class="fas fa-paper-plane"></i> Submit Payment
                </button>
            </form>
        </div>
        <?php else: ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> No active lease found. Please contact support to enable payments.</div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label"><i class="fas fa-list"></i> Total Records</div>
                <div class="value"><?= count($payments) ?></div>
            </div>
            <div class="summary-card">
                <div class="label"><i class="fas fa-check-circle"></i> Total Paid</div>
                <div class="value green">KES <?= number_format($total_paid, 2) ?></div>
            </div>
            <div class="summary-card">
                <div class="label"><i class="fas fa-clock"></i> Pending</div>
                <div class="value yellow">KES <?= number_format($total_pending, 2) ?></div>
            </div>
        </div>

        <!-- Payment History Table -->
        <h2><i class="fas fa-history"></i> Payment History</h2>
        <?php if (count($payments) > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Transaction Code</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $i => $p): ?>
                            <?php
                                $status    = $p['status'] ?? 'completed';
                                $cssClass  = statusClass($status);
                                $icon      = statusIcon($status);
                                $txCode    = !empty($p['transaction_code']) ? $p['transaction_code'] : '—';
                                $dateStr   = !empty($p['payment_date'])
                                             ? date('M d, Y', strtotime($p['payment_date']))
                                             : '—';
                                $methodIcons = [
                                    'mpesa'         => 'fa-mobile-alt',
                                    'cash'          => 'fa-money-bill-wave',
                                    'bank_transfer' => 'fa-university',
                                    'cheque'        => 'fa-file-alt',
                                ];
                                $method     = strtolower($p['payment_method'] ?? 'mpesa');
                                $methodIcon = $methodIcons[$method] ?? 'fa-credit-card';
                                $methodLabel = ucwords(str_replace('_', ' ', $method));
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($dateStr) ?></td>
                                <td><strong>KES <?= number_format((float)$p['amount'], 2) ?></strong></td>
                                <td>
                                    <span class="method-badge">
                                        <i class="fas <?= $methodIcon ?>"></i>
                                        <?= htmlspecialchars($methodLabel) ?>
                                    </span>
                                </td>
                                <td style="font-family:monospace;font-size:13px;"><?= htmlspecialchars($txCode) ?></td>
                                <td>
                                    <span class="badge <?= $cssClass ?>">
                                        <?= $icon ?> <?= htmlspecialchars(ucfirst($status)) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-credit-card"></i>
                <h3>No payment records found</h3>
                <p>Your payment history will appear here once transactions are made.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>