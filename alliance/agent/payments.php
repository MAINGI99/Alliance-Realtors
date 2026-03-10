<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_AGENT', 2);

// Auth check
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_AGENT) {
    header("Location: ../login.php?session_expired=1", true, 303);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Agent';
$email = $_SESSION['email'] ?? '';

$db = Database::getInstance()->getConnection();

// Handle payment actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Update payment status
        if ($_POST['action'] === 'update_status' && isset($_POST['payment_id'])) {
            $payment_id = intval($_POST['payment_id']);
            $status = trim($_POST['status'] ?? '');
            
            if ($status) {
                try {
                    $stmt = $db->prepare("UPDATE payments SET status = ? WHERE id = ?");
                    $stmt->execute([$status, $payment_id]);
                    $message = "Payment status updated successfully!";
                } catch (PDOException $e) {
                    error_log("Update payment status error: " . $e->getMessage());
                    $error = "Failed to update payment status.";
                }
            }
        }
        
        // Generate receipt number
        elseif ($_POST['action'] === 'generate_receipt' && isset($_POST['payment_id'])) {
            $payment_id = intval($_POST['payment_id']);
            
            try {
                // Generate a unique receipt number
                $year = date('Y');
                $month = date('m');
                
                // Get the latest receipt number for this year/month
                $stmt = $db->prepare("
                    SELECT receipt_number FROM payments 
                    WHERE receipt_number LIKE 'RCP-$year$month%'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute();
                $last_receipt = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($last_receipt) {
                    // Extract the sequence number and increment
                    $last_num = intval(substr($last_receipt['receipt_number'], -4));
                    $new_num = str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
                } else {
                    $new_num = '0001';
                }
                
                $receipt_number = "RCP-$year$month-$new_num";
                
                $stmt = $db->prepare("UPDATE payments SET receipt_number = ? WHERE id = ?");
                $stmt->execute([$receipt_number, $payment_id]);
                
                $message = "Receipt number generated: " . $receipt_number;
            } catch (PDOException $e) {
                error_log("Generate receipt error: " . $e->getMessage());
                $error = "Failed to generate receipt number.";
            }
        }
        
        // Delete payment
        elseif ($_POST['action'] === 'delete' && isset($_POST['payment_id'])) {
            $payment_id = intval($_POST['payment_id']);
            
            try {
                $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
                $stmt->execute([$payment_id]);
                $message = "Payment record deleted successfully!";
            } catch (PDOException $e) {
                error_log("Delete payment error: " . $e->getMessage());
                $error = "Failed to delete payment.";
            }
        }
    }
}

// Fetch all payments with tenant and lease details
$payments = [];
try {
    $stmt = $db->query("
        SELECT 
            p.*,
            t.name as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            l.start_date as lease_start,
            l.end_date as lease_end,
            l.monthly_rent,
            prop.title as property_title,
            prop.location as property_location,
            u.unit_number
        FROM payments p
        LEFT JOIN tenants t ON p.tenant_id = t.id
        LEFT JOIN leases l ON p.lease_id = l.id
        LEFT JOIN properties prop ON l.property_id = prop.id
        LEFT JOIN units u ON l.unit_id = u.id
        ORDER BY p.payment_date DESC, p.created_at DESC
    ");
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch payments error: " . $e->getMessage());
    $error = "Failed to load payments.";
}

// Calculate statistics
$total_payments = count($payments);
$total_amount = 0;
$completed_amount = 0;
$pending_amount = 0;
$failed_amount = 0;
$completed_count = 0;
$pending_count = 0;
$failed_count = 0;

foreach ($payments as $payment) {
    $amount = floatval($payment['amount']);
    $total_amount += $amount;
    
    switch ($payment['status']) {
        case 'completed':
            $completed_amount += $amount;
            $completed_count++;
            break;
        case 'pending':
            $pending_amount += $amount;
            $pending_count++;
            break;
        case 'failed':
            $failed_amount += $amount;
            $failed_count++;
            break;
    }
}

// Get payment method stats
$payment_methods = [];
try {
    $stmt = $db->query("
        SELECT payment_method, COUNT(*) as count, SUM(amount) as total
        FROM payments
        GROUP BY payment_method
    ");
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch payment methods error: " . $e->getMessage());
}

// Status colors
function getStatusColor($status) {
    switch ($status) {
        case 'completed':
            return ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => 'fa-check-circle'];
        case 'pending':
            return ['bg' => '#fef3c7', 'color' => '#92400e', 'icon' => 'fa-clock'];
        case 'failed':
            return ['bg' => '#fee2e2', 'color' => '#991b1b', 'icon' => 'fa-times-circle'];
        default:
            return ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-question-circle'];
    }
}

// Payment method icons
function getPaymentMethodIcon($method) {
    switch ($method) {
        case 'cash':
            return 'fa-money-bill-wave';
        case 'mpesa':
            return 'fa-mobile-alt';
        case 'bank_transfer':
            return 'fa-university';
        case 'cheque':
            return 'fa-money-check';
        default:
            return 'fa-credit-card';
    }
}

// Format currency
function formatMoney($amount) {
    return 'KES ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - Agent Dashboard | Alliance Realtors</title>
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

        /* Welcome Card */
        .welcome-card { background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; padding: 28px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .welcome-card h1 { font-size: 22px; margin-bottom: 6px; }
        .welcome-card p { font-size: 14px; opacity: 0.85; }
        .agent-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .stat-icon { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 10px; margin-bottom: 12px; }
        .stat-icon i { font-size: 22px; }
        .stat-icon.blue   { background: #dbeafe; } .stat-icon.blue i   { color: #2563eb; }
        .stat-icon.green  { background: #d1fae5; } .stat-icon.green i  { color: #059669; }
        .stat-icon.yellow { background: #fef3c7; } .stat-icon.yellow i { color: #d97706; }
        .stat-icon.red    { background: #fee2e2; } .stat-icon.red i    { color: #dc2626; }
        .stat-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 22px; font-weight: 700; color: #0f172a; }

        /* Summary Cards */
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
        .summary-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); border-left: 4px solid; }
        .summary-card h3 { font-size: 14px; color: #64748b; margin-bottom: 5px; }
        .summary-card .amount { font-size: 24px; font-weight: 700; margin-bottom: 5px; }
        .summary-card .count { font-size: 13px; color: #64748b; }
        .summary-card.completed { border-left-color: #059669; }
        .summary-card.pending { border-left-color: #d97706; }
        .summary-card.failed { border-left-color: #dc2626; }

        /* Filters */
        .filters { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .filter-group { display: flex; align-items: center; gap: 10px; background: white; padding: 5px 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .filter-group label { font-size: 13px; font-weight: 600; color: #475569; }
        .filter-group select, .filter-group input { padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; min-width: 150px; }
        .filter-group select:focus, .filter-group input:focus { outline: none; border-color: #3b82f6; }
        .filter-group input { min-width: 200px; }

        /* Table */
        .table-container { background: white; border-radius: 10px; padding: 20px; overflow-x: auto; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 12px; background: #f8fafc; color: #475569; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px 12px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; }
        tr:hover td { background: #f8fafc; }

        /* Badges */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .method-badge { background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 20px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; }

        /* Action buttons */
        .action-buttons { display: flex; gap: 8px; }
        .action-buttons button, .action-buttons a { background: none; border: none; color: #64748b; cursor: pointer; font-size: 14px; padding: 4px; text-decoration: none; }
        .action-buttons button:hover, .action-buttons a:hover { color: #3b82f6; }
        .action-buttons .delete-btn:hover { color: #ef4444; }
        .action-buttons .print-btn:hover { color: #059669; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { background: white; width: 90%; max-width: 600px; margin: 50px auto; border-radius: 10px; padding: 25px; max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .modal-header h3 { font-size: 18px; color: #0f172a; }
        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; }

        /* Payment details */
        .detail-row { display: flex; margin-bottom: 15px; }
        .detail-label { width: 140px; font-weight: 600; color: #475569; }
        .detail-value { flex: 1; color: #334155; }

        /* Receipt */
        .receipt-container {
            background: white;
            padding: 30px;
            max-width: 500px;
            margin: 0 auto;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px dashed #e2e8f0;
        }
        .receipt-header h2 {
            color: #1e293b;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 2px;
        }
        .receipt-header p {
            color: #64748b;
            font-size: 12px;
        }
        .receipt-title {
            text-align: center;
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 20px;
            text-transform: uppercase;
        }
        .receipt-info {
            margin-bottom: 20px;
        }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 5px 0;
            border-bottom: 1px dotted #e2e8f0;
        }
        .receipt-label {
            color: #64748b;
            font-weight: 500;
        }
        .receipt-value {
            color: #1e293b;
            font-weight: 600;
        }
        .receipt-amount {
            font-size: 20px;
            color: #059669;
            font-weight: 700;
        }
        .receipt-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px dashed #e2e8f0;
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
        }
        .receipt-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        .btn-print {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-print:hover {
            background: #2563eb;
        }

        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; background: white; border-radius: 10px; }
        .empty-state i { font-size: 48px; color: #94a3b8; margin-bottom: 16px; }
        .empty-state h3 { color: #334155; margin-bottom: 8px; }
        .empty-state p { color: #94a3b8; font-size: 14px; }

        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 30px; padding-bottom: 20px; }

        /* Print styles */
        @media print {
            body * {
                visibility: hidden;
            }
            #printableReceipt, #printableReceipt * {
                visibility: visible;
            }
            #printableReceipt {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 20px;
            }
            .receipt-actions {
                display: none;
            }
        }
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
        <a href="dashboard.php" class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php" class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php" class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php" class="menu-item active"><i class="fas fa-credit-card"></i> Payments</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-home"></i> Home / <span>Payments</span>
            </div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Payment Management, <?= htmlspecialchars(explode(' ', $full_name)[0]) ?>!</h1>
                <p><i class="fas fa-credit-card"></i> Track and manage all tenant payments</p>
            </div>
            <div class="agent-badge"><i class="fas fa-user-tie"></i> Agent</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-credit-card"></i></div>
                <div class="stat-label">Total Payments</div>
                <div class="stat-value"><?= $total_payments ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $completed_count ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= $pending_count ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                <div class="stat-label">Failed</div>
                <div class="stat-value"><?= $failed_count ?></div>
            </div>
        </div>

        <!-- Amount Summary -->
        <div class="summary-grid">
            <div class="summary-card completed">
                <h3>Completed Payments</h3>
                <div class="amount"><?= formatMoney($completed_amount) ?></div>
                <div class="count"><?= $completed_count ?> transactions</div>
            </div>
            <div class="summary-card pending">
                <h3>Pending Payments</h3>
                <div class="amount"><?= formatMoney($pending_amount) ?></div>
                <div class="count"><?= $pending_count ?> transactions</div>
            </div>
            <div class="summary-card failed">
                <h3>Failed Payments</h3>
                <div class="amount"><?= formatMoney($failed_amount) ?></div>
                <div class="count"><?= $failed_count ?> transactions</div>
            </div>
        </div>

        <!-- Payment Methods Summary (if available) -->
        <?php if (!empty($payment_methods)): ?>
        <div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.08);">
            <h3 style="margin-bottom: 15px; font-size: 16px; color: #0f172a;">Payment Methods</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <?php foreach ($payment_methods as $method): ?>
                    <?php 
                        $method_name = ucfirst(str_replace('_', ' ', $method['payment_method']));
                        $icon = getPaymentMethodIcon($method['payment_method']);
                    ?>
                    <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 8px;">
                        <div style="width: 40px; height: 40px; background: #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #475569;">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?= $method_name ?></div>
                            <div style="font-size: 13px; color: #64748b;">
                                <?= $method['count'] ?> payments · <?= formatMoney($method['total']) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filters">
            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Status:</label>
                <select id="statusFilter" onchange="filterTable()">
                    <option value="all">All Statuses</option>
                    <option value="completed">Completed</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-money-bill"></i> Method:</label>
                <select id="methodFilter" onchange="filterTable()">
                    <option value="all">All Methods</option>
                    <option value="cash">Cash</option>
                    <option value="mpesa">M-Pesa</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search:</label>
                <input type="text" id="searchInput" placeholder="Search tenant or transaction..." onkeyup="filterTable()">
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Payments Table -->
        <?php if (count($payments) > 0): ?>
            <div class="table-container">
                <table id="paymentsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Tenant</th>
                            <th>Property/Unit</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Receipt</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <?php $status = getStatusColor($payment['status']); ?>
                            <tr data-status="<?= $payment['status'] ?>" data-method="<?= $payment['payment_method'] ?>">
                                <td>
                                    <?= date('M d, Y', strtotime($payment['payment_date'])) ?>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <?= date('h:i A', strtotime($payment['created_at'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($payment['tenant_name'] ?? 'Unknown') ?></strong>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <?= htmlspecialchars($payment['tenant_email'] ?? '') ?>
                                    </div>
                                </td>
                                <td>
                                    <?= htmlspecialchars($payment['property_title'] ?? 'N/A') ?>
                                    <?php if (!empty($payment['unit_number'])): ?>
                                        <div style="font-size: 11px; color: #64748b;">
                                            Unit <?= htmlspecialchars($payment['unit_number']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= formatMoney($payment['amount']) ?></strong>
                                </td>
                                <td>
                                    <span class="method-badge">
                                        <i class="fas <?= getPaymentMethodIcon($payment['payment_method']) ?>"></i>
                                        <?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($payment['receipt_number'])): ?>
                                        <span style="font-family: monospace; background: #f1f5f9; padding: 3px 6px; border-radius: 4px;">
                                            <?= htmlspecialchars($payment['receipt_number']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?= $status['bg'] ?>; color: <?= $status['color'] ?>;">
                                        <i class="fas <?= $status['icon'] ?>"></i>
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="viewPayment(<?= htmlspecialchars(json_encode($payment)) ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (empty($payment['receipt_number']) && $payment['status'] === 'completed'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="generate_receipt">
                                                <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                <button type="submit" class="print-btn" title="Generate Receipt">
                                                    <i class="fas fa-receipt"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['receipt_number'])): ?>
                                            <button onclick="printReceipt(<?= htmlspecialchars(json_encode($payment)) ?>)" class="print-btn" title="Print Receipt">
                                                <i class="fas fa-print"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button onclick="updateStatus(<?= $payment['id'] ?>, '<?= $payment['status'] ?>')" title="Update Status">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="deletePayment(<?= $payment['id'] ?>)" class="delete-btn" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-credit-card"></i>
                <h3>No Payments Found</h3>
                <p>There are no payment records in the system.</p>
            </div>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<!-- View Payment Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Payment Details</h3>
            <button class="close-modal" onclick="closeViewModal()">&times;</button>
        </div>
        <div id="paymentDetails" style="padding: 10px 0;">
            <!-- Details will be loaded here -->
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div id="receiptModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Payment Receipt</h3>
            <button class="close-modal" onclick="closeReceiptModal()">&times;</button>
        </div>
        <div id="receiptContent">
            <!-- Receipt will be loaded here -->
        </div>
        <div class="receipt-actions">
            <button class="btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Receipt
            </button>
        </div>
    </div>
</div>

<!-- Printable Receipt (hidden) -->
<div id="printableReceipt" style="display: none;"></div>

<!-- Update Status Modal -->
<div id="statusModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Update Payment Status</h3>
            <button class="close-modal" onclick="closeStatusModal()">&times;</button>
        </div>
        <form method="POST" id="statusForm">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="payment_id" id="statusPaymentId" value="">
            
            <div class="form-group">
                <label>Select New Status</label>
                <select name="status" id="statusSelect" required>
                    <option value="completed">Completed</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn-secondary" onclick="closeStatusModal()">Cancel</button>
                <button type="submit" class="btn-primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
        </div>
        <p style="margin: 20px 0; color: #334155;">Are you sure you want to delete this payment record? This action cannot be undone.</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="payment_id" id="deletePaymentId" value="">
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-danger">Delete Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter table by status, method, and search
function filterTable() {
    const statusFilter = document.getElementById('statusFilter').value;
    const methodFilter = document.getElementById('methodFilter').value;
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#paymentsTable tbody tr');
    
    rows.forEach(row => {
        const status = row.getAttribute('data-status');
        const method = row.getAttribute('data-method');
        const text = row.textContent.toLowerCase();
        
        const statusMatch = statusFilter === 'all' || status === statusFilter;
        const methodMatch = methodFilter === 'all' || method === methodFilter;
        const searchMatch = searchInput === '' || text.includes(searchInput);
        
        row.style.display = statusMatch && methodMatch && searchMatch ? '' : 'none';
    });
}

// View payment details
function viewPayment(payment) {
    const statusColors = {
        'completed': { bg: '#d1fae5', color: '#065f46' },
        'pending': { bg: '#fef3c7', color: '#92400e' },
        'failed': { bg: '#fee2e2', color: '#991b1b' }
    };
    
    const status = statusColors[payment.status] || statusColors.pending;
    
    const details = `
        <div style="margin-bottom: 20px;">
            <h4 style="color: #0f172a; margin-bottom: 10px; font-size: 16px;">Payment Information</h4>
            <div class="detail-row">
                <div class="detail-label">Amount:</div>
                <div class="detail-value"><strong>${formatMoney(payment.amount)}</strong></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Payment Date:</div>
                <div class="detail-value">${new Date(payment.payment_date).toLocaleDateString()}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Payment Method:</div>
                <div class="detail-value">${escapeHtml(payment.payment_method).replace('_', ' ').toUpperCase()}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Status:</div>
                <div class="detail-value">
                    <span style="background: ${status.bg}; color: ${status.color}; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <i class="fas fa-circle"></i> ${escapeHtml(payment.status).toUpperCase()}
                    </span>
                </div>
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #0f172a; margin-bottom: 10px; font-size: 16px;">Transaction Details</h4>
            <div class="detail-row">
                <div class="detail-label">Transaction Code:</div>
                <div class="detail-value">${escapeHtml(payment.transaction_code || 'N/A')}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Receipt Number:</div>
                <div class="detail-value">${escapeHtml(payment.receipt_number || 'N/A')}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Created:</div>
                <div class="detail-value">${new Date(payment.created_at).toLocaleString()}</div>
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #0f172a; margin-bottom: 10px; font-size: 16px;">Tenant Information</h4>
            <div class="detail-row">
                <div class="detail-label">Name:</div>
                <div class="detail-value">${escapeHtml(payment.tenant_name || 'N/A')}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Email:</div>
                <div class="detail-value">${escapeHtml(payment.tenant_email || 'N/A')}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Phone:</div>
                <div class="detail-value">${escapeHtml(payment.tenant_phone || 'N/A')}</div>
            </div>
        </div>
        
        <div>
            <h4 style="color: #0f172a; margin-bottom: 10px; font-size: 16px;">Lease Information</h4>
            <div class="detail-row">
                <div class="detail-label">Property:</div>
                <div class="detail-value">${escapeHtml(payment.property_title || 'N/A')}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Unit:</div>
                <div class="detail-value">${payment.unit_number ? 'Unit ' + escapeHtml(payment.unit_number) : 'N/A'}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Lease Period:</div>
                <div class="detail-value">
                    ${payment.lease_start ? new Date(payment.lease_start).toLocaleDateString() : 'N/A'} 
                    ${payment.lease_end ? ' - ' + new Date(payment.lease_end).toLocaleDateString() : ''}
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Monthly Rent:</div>
                <div class="detail-value">${formatMoney(payment.monthly_rent || 0)}</div>
            </div>
        </div>
    `;
    
    document.getElementById('paymentDetails').innerHTML = details;
    document.getElementById('viewModal').style.display = 'block';
}

// Print receipt
function printReceipt(payment) {
    const receiptHTML = generateReceiptHTML(payment);
    document.getElementById('receiptContent').innerHTML = receiptHTML;
    document.getElementById('printableReceipt').innerHTML = receiptHTML;
    document.getElementById('receiptModal').style.display = 'block';
}

// Generate receipt HTML
function generateReceiptHTML(payment) {
    const date = new Date(payment.payment_date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    return `
        <div class="receipt-container" id="printableReceipt">
            <div class="receipt-header">
                <h2>ALLIANCE REALTORS</h2>
                <p>Official Payment Receipt</p>
            </div>
            
            <div class="receipt-title">
                RECEIPT OF PAYMENT
            </div>
            
            <div class="receipt-info">
                <div class="receipt-row">
                    <span class="receipt-label">Receipt No:</span>
                    <span class="receipt-value">${escapeHtml(payment.receipt_number || 'N/A')}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Date:</span>
                    <span class="receipt-value">${date}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Tenant Name:</span>
                    <span class="receipt-value">${escapeHtml(payment.tenant_name || 'N/A')}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Property:</span>
                    <span class="receipt-value">${escapeHtml(payment.property_title || 'N/A')} ${payment.unit_number ? '(Unit ' + payment.unit_number + ')' : ''}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Payment Method:</span>
                    <span class="receipt-value">${escapeHtml(payment.payment_method).replace('_', ' ').toUpperCase()}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Transaction Code:</span>
                    <span class="receipt-value">${escapeHtml(payment.transaction_code || 'N/A')}</span>
                </div>
                <div class="receipt-row">
                    <span class="receipt-label">Amount Paid:</span>
                    <span class="receipt-value receipt-amount">${formatMoney(payment.amount)}</span>
                </div>
            </div>
            
            <div style="margin: 20px 0; padding: 15px; background: #f8fafc; border-radius: 8px; text-align: center;">
                <p style="color: #475569; font-style: italic;">Received with thanks from ${escapeHtml(payment.tenant_name || 'Tenant')}</p>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-top: 30px;">
                <div style="text-align: center; width: 45%;">
                    <div style="border-top: 1px solid #e2e8f0; padding-top: 10px; margin-top: 10px;">
                        <p style="color: #475569; font-weight: 600;">Authorized Signature</p>
                    </div>
                </div>
                <div style="text-align: center; width: 45%;">
                    <div style="border-top: 1px solid #e2e8f0; padding-top: 10px; margin-top: 10px;">
                        <p style="color: #475569; font-weight: 600;">Agent Stamp</p>
                    </div>
                </div>
            </div>
            
            <div class="receipt-footer">
                <p>This is a computer generated receipt and is valid without signature.</p>
                <p>Thank you for your payment!</p>
            </div>
        </div>
    `;
}

// Format money
function formatMoney(amount) {
    return 'KES ' + Number(amount).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Update status
function updateStatus(paymentId, currentStatus) {
    document.getElementById('statusPaymentId').value = paymentId;
    document.getElementById('statusSelect').value = currentStatus;
    document.getElementById('statusModal').style.display = 'block';
}

// Delete payment
function deletePayment(paymentId) {
    document.getElementById('deletePaymentId').value = paymentId;
    document.getElementById('deleteModal').style.display = 'block';
}

// Close modals
function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

function closeReceiptModal() {
    document.getElementById('receiptModal').style.display = 'none';
}

function closeStatusModal() {
    document.getElementById('statusModal').style.display = 'none';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

</body>
</html>