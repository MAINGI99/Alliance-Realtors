<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_TENANT', 3);
define('ALLOWED_PRIORITIES', ['low', 'medium', 'high', 'urgent']);

// Auth check
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_TENANT) {
    header("Location: login.php", true, 303);
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Tenant';
$email     = $_SESSION['email'] ?? '';

$success  = '';
$error    = '';
$requests = [];

function statusClass(string $status): string {
    return 'status-' . strtolower($status);
}

function priorityClass(string $priority): string {
    return match(strtolower($priority)) {
        'low'    => 'priority-low',
        'medium' => 'priority-medium',
        'high'   => 'priority-high',
        'urgent' => 'priority-urgent',
        default  => 'priority-medium',
    };
}

function statusIcon(string $status): string {
    return match(strtolower($status)) {
        'pending'     => '<i class="fas fa-clock"></i>',
        'in_progress' => '<i class="fas fa-spinner fa-spin"></i>',
        'completed'   => '<i class="fas fa-check-circle"></i>',
        'cancelled'   => '<i class="fas fa-times-circle"></i>',
        default       => '<i class="fas fa-question-circle"></i>',
    };
}

function formatStatus(string $status): string {
    return ucwords(str_replace('_', ' ', $status));
}

try {
    $db = Database::getInstance()->getConnection();

    // Get tenant record including unit_id if it exists
    $stmt = $db->prepare("SELECT id, property_id FROM tenants WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $tenant      = $stmt->fetch(PDO::FETCH_ASSOC);
    $tenant_id   = $tenant ? (int)$tenant['id'] : 0;
    $property_id = $tenant ? (int)($tenant['property_id'] ?? 0) : 0;

    // Try to get a valid unit_id for this property (first available unit)
    $unit_id = null;
    if ($property_id > 0) {
        $stmt = $db->prepare("SELECT id FROM units WHERE property_id = ? LIMIT 1");
        $stmt->execute([$property_id]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($unit) {
            $unit_id = (int)$unit['id'];
        }
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority    = strtolower($_POST['priority'] ?? 'medium');

        if (!in_array($priority, ALLOWED_PRIORITIES, true)) {
            $priority = 'medium';
        }

        if (empty($title) || empty($description)) {
            $error = "Please fill in all fields.";
        } elseif (mb_strlen($title) > 200) {
            $error = "Title must be 200 characters or fewer.";
        } elseif ($tenant_id === 0) {
            $error = "Tenant record not found. Please contact support.";
        } elseif ($unit_id === null) {
            $error = "No unit assigned to your account. Please contact support.";
        } else {
            $stmt = $db->prepare("
                INSERT INTO maintenance_requests
                    (tenant_id, property_id, unit_id, title, description, priority, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$tenant_id, $property_id, $unit_id, $title, $description, $priority]);
            $success = "Maintenance request submitted successfully!";
            $_POST   = [];
        }
    }

    // Fetch existing requests
    if ($tenant_id > 0) {
        $stmt = $db->prepare("
            SELECT id, title, priority, status, created_at
            FROM maintenance_requests
            WHERE tenant_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$tenant_id]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Maintenance error: " . $e->getMessage());
    $error = "Something went wrong: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - Alliance Realtors</title>
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
        .alert { padding: 12px 16px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error   { background: #fee2e2; color: #c33; }
        .form-card { background: white; padding: 24px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #334155; font-size: 14px; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; font-family: inherit; transition: border-color 0.2s; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
        .form-group textarea { height: 110px; resize: vertical; }
        .char-count { font-size: 12px; color: #94a3b8; text-align: right; margin-top: 4px; }
        .btn-submit { background: #3b82f6; color: white; border: none; padding: 12px 24px; border-radius: 5px; cursor: pointer; font-size: 15px; transition: background 0.2s; }
        .btn-submit:hover { background: #2563eb; }
        .table-wrapper { background: white; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); overflow: hidden; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8fafc; }
        th { text-align: left; padding: 14px 16px; color: #64748b; font-size: 13px; font-weight: 600; border-bottom: 2px solid #e2e8f0; text-transform: uppercase; letter-spacing: 0.04em; }
        td { padding: 14px 16px; color: #334155; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-pending     { background: #fef3c7; color: #92400e; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-completed   { background: #d1fae5; color: #065f46; }
        .status-cancelled   { background: #f1f5f9; color: #475569; }
        .priority-low    { background: #f1f5f9; color: #475569; }
        .priority-medium { background: #fef3c7; color: #92400e; }
        .priority-high   { background: #fee2e2; color: #991b1b; }
        .priority-urgent { background: #7f1d1d; color: white; }
        .empty-state { text-align: center; padding: 50px; background: white; border-radius: 10px; color: #64748b; }
        .empty-state i { font-size: 48px; color: #94a3b8; margin-bottom: 15px; display: block; }
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
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="invoices.php" class="menu-item"><i class="fas fa-file-invoice"></i> Invoices</a>
        <a href="maintenance.php" class="menu-item active"><i class="fas fa-tools"></i> Maintenance</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Maintenance</span></div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <h1>Maintenance Requests</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="form-card">
            <h2><i class="fas fa-plus-circle"></i> Submit New Request</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title"
                           placeholder="e.g., Leaking faucet" maxlength="200" required
                           value="<?= isset($_POST['title']) && $error ? htmlspecialchars($_POST['title']) : '' ?>">
                    <div class="char-count">Max 200 characters</div>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description"
                              placeholder="Describe the issue in detail..." required><?= isset($_POST['description']) && $error ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                </div>
                <div class="form-group">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <?php foreach (ALLOWED_PRIORITIES as $p): ?>
                            <option value="<?= $p ?>" <?= (($_POST['priority'] ?? 'medium') === $p) ? 'selected' : '' ?>>
                                <?= ucfirst($p) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </form>
        </div>

        <h2>Your Requests (<?= count($requests) ?>)</h2>

        <?php if (count($requests) > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Title</th>
                            <th>Priority</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $i => $req): ?>
                            <?php
                                $status   = $req['status'] ?? 'pending';
                                $priority = $req['priority'] ?? 'medium';
                                $dateStr  = !empty($req['created_at'])
                                            ? date('M d, Y', strtotime($req['created_at']))
                                            : '—';
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($dateStr) ?></td>
                                <td><?= htmlspecialchars($req['title']) ?></td>
                                <td>
                                    <span class="badge <?= priorityClass($priority) ?>">
                                        <?= htmlspecialchars(ucfirst($priority)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= statusClass($status) ?>">
                                        <?= statusIcon($status) ?>
                                        <?= htmlspecialchars(formatStatus($status)) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-tools"></i>
                <h3>No requests yet</h3>
                <p>Submit your first maintenance request using the form above.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>