<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_ADMIN', 1);

if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_ADMIN) {
    header("Location: ../login.php?session_expired=1", true, 303);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$email = $_SESSION['email'] ?? '';
$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        $t_email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $property_id = !empty($_POST['property_id']) ? intval($_POST['property_id']) : null;
        $move_in_date = $_POST['move_in_date'] ?? null;
        $move_out_date = $_POST['move_out_date'] ?? null;
        $status = trim($_POST['status'] ?? 'Active');
        if ($name && $t_email) {
            try {
                $stmt = $db->prepare("SELECT id FROM tenants WHERE email = ?");
                $stmt->execute([$t_email]);
                if ($stmt->fetch()) {
                    $error = "A tenant with this email already exists.";
                } else {
                    $db->prepare("INSERT INTO tenants (name, email, phone, property_id, move_in_date, move_out_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                       ->execute([$name, $t_email, $phone, $property_id, $move_in_date, $move_out_date, $status]);
                    $message = "Tenant added successfully!";
                }
            } catch (PDOException $e) { error_log($e->getMessage()); $error = "Failed to add tenant."; }
        } else { $error = "Please fill in all required fields."; }
    }
    elseif ($_POST['action'] === 'edit' && isset($_POST['tenant_id'])) {
        $tenant_id = intval($_POST['tenant_id']);
        $name = trim($_POST['name'] ?? '');
        $t_email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $property_id = !empty($_POST['property_id']) ? intval($_POST['property_id']) : null;
        $move_in_date = $_POST['move_in_date'] ?? null;
        $move_out_date = $_POST['move_out_date'] ?? null;
        $status = trim($_POST['status'] ?? 'Active');
        if ($name && $t_email) {
            try {
                $stmt = $db->prepare("SELECT id FROM tenants WHERE email = ? AND id != ?");
                $stmt->execute([$t_email, $tenant_id]);
                if ($stmt->fetch()) {
                    $error = "Email already exists for another tenant.";
                } else {
                    $db->prepare("UPDATE tenants SET name=?, email=?, phone=?, property_id=?, move_in_date=?, move_out_date=?, status=? WHERE id=?")
                       ->execute([$name, $t_email, $phone, $property_id, $move_in_date, $move_out_date, $status, $tenant_id]);
                    $message = "Tenant updated successfully!";
                }
            } catch (PDOException $e) { error_log($e->getMessage()); $error = "Failed to update tenant."; }
        } else { $error = "Please fill in all required fields."; }
    }
    elseif ($_POST['action'] === 'delete' && isset($_POST['tenant_id'])) {
        $tenant_id = intval($_POST['tenant_id']);
        try {
            $pay = $db->prepare("SELECT COUNT(*) FROM payments WHERE tenant_id=?"); $pay->execute([$tenant_id]);
            if ($pay->fetchColumn() > 0) { $error = "Cannot delete tenant with payment history."; }
            else {
                $mnt = $db->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id=?"); $mnt->execute([$tenant_id]);
                if ($mnt->fetchColumn() > 0) { $error = "Cannot delete tenant with maintenance requests."; }
                else { $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tenant_id]); $message = "Tenant deleted successfully!"; }
            }
        } catch (PDOException $e) { error_log($e->getMessage()); $error = "Failed to delete tenant."; }
    }
    elseif ($_POST['action'] === 'bulk' && isset($_POST['selected_ids'])) {
        $selected_ids = json_decode($_POST['selected_ids'], true);
        $bulk_action  = $_POST['bulk_action'] ?? '';
        if (!empty($selected_ids) && $bulk_action) {
            $ph = implode(',', array_fill(0, count($selected_ids), '?'));
            try {
                if ($bulk_action === 'delete') {
                    $s = $db->prepare("SELECT tenant_id, COUNT(*) FROM payments WHERE tenant_id IN ($ph) GROUP BY tenant_id");
                    $s->execute($selected_ids);
                    if ($s->fetchAll()) { $error = "Some tenants have payment history and cannot be deleted."; }
                    else { $db->prepare("DELETE FROM tenants WHERE id IN ($ph)")->execute($selected_ids); $message = "Selected tenants deleted!"; }
                } elseif (in_array($bulk_action, ['active','inactive'])) {
                    $db->prepare("UPDATE tenants SET status=? WHERE id IN ($ph)")->execute(array_merge([ucfirst($bulk_action)], $selected_ids));
                    $message = "Selected tenants updated to ".ucfirst($bulk_action)."!";
                }
            } catch (PDOException $e) { error_log($e->getMessage()); $error = "Failed to process bulk action."; }
        }
    }
}

$tenants = [];
try {
    $tenants = $db->query("
        SELECT t.*, p.title as property_name, p.location as property_location,
               COUNT(DISTINCT py.id) as payment_count,
               COALESCE(SUM(py.amount),0) as total_paid,
               COUNT(DISTINCT mr.id) as maintenance_count
        FROM tenants t
        LEFT JOIN properties p ON t.property_id = p.id
        LEFT JOIN payments py ON t.id = py.tenant_id
        LEFT JOIN maintenance_requests mr ON t.id = mr.tenant_id AND mr.status != 'completed'
        WHERE t.email NOT LIKE '%admin%' AND t.name NOT LIKE '%admin%'
        GROUP BY t.id ORDER BY t.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $tenants = array_values(array_filter($tenants, fn($t) => stripos($t['email'],'admin')===false && stripos($t['name'],'admin')===false));
} catch (PDOException $e) { error_log($e->getMessage()); $error = "Failed to load tenants."; }

$properties = [];
try { $properties = $db->query("SELECT id, title, location FROM properties ORDER BY title")->fetchAll(PDO::FETCH_ASSOC); } catch(PDOException $e){}

$edit_tenant = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try { $s=$db->prepare("SELECT * FROM tenants WHERE id=?"); $s->execute([$_GET['edit']]); $edit_tenant=$s->fetch(PDO::FETCH_ASSOC); } catch(PDOException $e){}
}

$stats = ['total'=>count($tenants),'active'=>0,'inactive'=>0,'pending'=>0,'with_property'=>0,'total_paid'=>0];
foreach ($tenants as $t) {
    $s = strtolower($t['status']??'active');
    if ($s==='active') $stats['active']++;
    elseif ($s==='inactive') $stats['inactive']++;
    else $stats['pending']++;
    if (!empty($t['property_id'])) $stats['with_property']++;
    $stats['total_paid'] += $t['total_paid'];
}

function getStatusBadge($status) {
    $badges = [
        'active'   => ['bg'=>'#d1fae5','color'=>'#065f46','icon'=>'fa-check-circle'],
        'inactive' => ['bg'=>'#fee2e2','color'=>'#991b1b','icon'=>'fa-times-circle'],
        'pending'  => ['bg'=>'#fef3c7','color'=>'#92400e','icon'=>'fa-clock'],
    ];
    $key = strtolower($status);
    $b = $badges[$key] ?? ['bg'=>'#f1f5f9','color'=>'#475569','icon'=>'fa-question-circle'];
    return "<span style='background:{$b['bg']};color:{$b['color']};padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;'><i class='fas {$b['icon']}'></i> ".ucfirst($key)."</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tenants - Alliance Realtors</title>
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
        .welcome-card { background:linear-gradient(135deg,#7c3aed,#3b82f6); color:white; padding:28px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .welcome-card h1 { font-size:22px; margin-bottom:6px; }
        .welcome-card p  { font-size:14px; opacity:0.85; }
        .admin-badge { background:rgba(255,255,255,0.15); padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px; }

        /* Stats */
        .stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:20px; margin-bottom:25px; }
        .stat-card { background:white; padding:20px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,0.08); display:flex; align-items:center; gap:15px; }
        .stat-icon { width:50px; height:50px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; }
        .stat-icon.blue   { background:#dbeafe; color:#3b82f6; }
        .stat-icon.green  { background:#d1fae5; color:#10b981; }
        .stat-icon.orange { background:#fed7aa; color:#f97316; }
        .stat-icon.purple { background:#ede9fe; color:#7c3aed; }
        .stat-content .stat-label { font-size:13px; color:#64748b; margin-bottom:4px; }
        .stat-content .stat-value { font-size:24px; font-weight:700; color:#0f172a; }
        .stat-content .stat-sub   { font-size:12px; color:#64748b; margin-top:4px; }

        /* Action bar */
        .action-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
        .action-bar h2 { font-size:18px; color:#0f172a; display:flex; align-items:center; gap:8px; }
        .btn-primary   { background:#3b82f6; color:white; border:none; padding:10px 20px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:background 0.2s; }
        .btn-primary:hover { background:#2563eb; }
        .btn-secondary { background:white; border:1px solid #e2e8f0; padding:8px 16px; border-radius:6px; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:all 0.2s; }
        .btn-secondary:hover { background:#f8fafc; }
        .btn-danger    { background:#ef4444; color:white; border:none; padding:6px 12px; border-radius:6px; font-size:12px; cursor:pointer; }
        .btn-danger:hover { background:#dc2626; }
        .btns-row { display:flex; gap:10px; }

        /* Filters */
        .filters { display:none; gap:15px; flex-wrap:wrap; background:white; padding:15px 20px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,0.08); margin-bottom:20px; }
        .filter-group { display:flex; align-items:center; gap:8px; }
        .filter-group label { font-size:13px; font-weight:600; color:#475569; }
        .filter-group select, .filter-group input { padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; min-width:150px; }

        /* Bulk actions */
        .bulk-actions { display:none; gap:15px; align-items:center; background:#f8fafc; padding:10px 15px; border-radius:8px; margin-bottom:15px; }
        .bulk-actions.show { display:flex; }
        .bulk-actions span { font-size:13px; font-weight:600; }
        .bulk-actions select { padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; }

        /* Table */
        .table-container { background:white; border-radius:10px; padding:20px; overflow-x:auto; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        table { width:100%; border-collapse:collapse; }
        th { text-align:left; padding:12px; background:#f8fafc; color:#475569; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; border-bottom:2px solid #e2e8f0; }
        td { padding:13px 12px; border-bottom:1px solid #f1f5f9; color:#334155; font-size:13px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        tr:hover td { background:#f8fafc; }
        .tenant-name { font-weight:600; color:#0f172a; }

        /* Row action buttons */
        .row-actions { display:flex; gap:8px; }
        .row-actions a, .row-actions button { background:none; border:none; color:#64748b; cursor:pointer; font-size:14px; padding:4px 6px; border-radius:5px; text-decoration:none; transition:all 0.2s; }
        .row-actions a:hover { color:#3b82f6; background:#dbeafe; }
        .row-actions .delete-btn:hover { color:#ef4444; background:#fee2e2; }

        /* Alert */
        .alert { padding:13px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:13px; }
        .alert-success { background:#d4edda; color:#155724; border-left:3px solid #28a745; }
        .alert-error   { background:#fee2e2; color:#991b1b; border-left:3px solid #ef4444; }

        /* Empty */
        .empty-state { text-align:center; padding:60px; background:white; border-radius:10px; }
        .empty-state i { font-size:48px; color:#94a3b8; display:block; margin-bottom:15px; }

        /* Modal */
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; }
        .modal-content { background:white; width:90%; max-width:580px; margin:50px auto; border-radius:10px; padding:25px; max-height:85vh; overflow-y:auto; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #e2e8f0; }
        .modal-header h3 { font-size:18px; color:#0f172a; }
        .close-modal { background:none; border:none; font-size:22px; cursor:pointer; color:#94a3b8; }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; margin-bottom:5px; font-size:13px; font-weight:600; color:#475569; }
        .form-group input, .form-group select { width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:6px; font-size:14px; }
        .form-group input:focus, .form-group select:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.1); }

        .footer { text-align:center; color:#94a3b8; font-size:12px; margin-top:30px; padding-bottom:20px; }
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
        <a href="dashboard.php"   class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php"  class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php"     class="menu-item active"><i class="fas fa-users"></i> Tenants</a>
        <a href="agents.php"      class="menu-item"><i class="fas fa-user-tie"></i> Agents</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php"    class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="reports.php"     class="menu-item"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="menu-divider"></div>
        <a href="settings.php"    class="menu-item"><i class="fas fa-cog"></i> Settings</a>
        <a href="profile.php"     class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php"   class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Tenants</span></div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <div class="welcome-card">
            <div>
                <h1>Tenant Management</h1>
                <p><i class="fas fa-users"></i> Manage all tenants across all properties</p>
            </div>
            <div class="admin-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Total Tenants</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-sub"><?= $stats['with_property'] ?> with property</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                <div class="stat-content"><div class="stat-label">Active</div><div class="stat-value"><?= $stats['active'] ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-user-clock"></i></div>
                <div class="stat-content"><div class="stat-label">Inactive</div><div class="stat-value"><?= $stats['inactive'] ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-clock"></i></div>
                <div class="stat-content"><div class="stat-label">Pending</div><div class="stat-value"><?= $stats['pending'] ?></div></div>
            </div>
        </div>

        <div class="action-bar">
            <h2><i class="fas fa-list"></i> All Tenants</h2>
            <div class="btns-row">
                <button class="btn-secondary" onclick="toggleFilters()"><i class="fas fa-filter"></i> Filters</button>
                <button class="btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Tenant</button>
            </div>
        </div>

        <div id="filters" class="filters">
            <div class="filter-group">
                <label>Status:</label>
                <select id="statusFilter" onchange="filterTable()">
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="pending">Pending</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Search:</label>
                <input type="text" id="searchInput" placeholder="Name or email..." onkeyup="filterTable()">
            </div>
        </div>

        <div id="bulkActions" class="bulk-actions">
            <span><span id="selectedCount">0</span> selected</span>
            <select id="bulkActionSelect">
                <option value="">Select Action</option>
                <option value="active">Mark Active</option>
                <option value="inactive">Mark Inactive</option>
                <option value="delete">Delete</option>
            </select>
            <button class="btn-primary" onclick="applyBulkAction()">Apply</button>
            <button class="btn-secondary" onclick="clearSelection()">Clear</button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (count($tenants) > 0): ?>
        <div class="table-container">
            <table id="tenantsTable">
                <thead>
                    <tr>
                        <th width="30"><input type="checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Property</th>
                        <th>Move In/Out</th>
                        <th>Payments</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tenants as $tenant): ?>
                    <tr data-status="<?= strtolower($tenant['status']??'active') ?>">
                        <td><input type="checkbox" class="tenant-select" value="<?= $tenant['id'] ?>" onclick="updateSelectedCount()"></td>
                        <td><div class="tenant-name"><?= htmlspecialchars($tenant['name']) ?></div></td>
                        <td>
                            <div><?= htmlspecialchars($tenant['email']) ?></div>
                            <?php if (!empty($tenant['phone'])): ?>
                            <div style="font-size:12px;color:#64748b;"><?= htmlspecialchars($tenant['phone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($tenant['property_name'])): ?>
                                <strong><?= htmlspecialchars($tenant['property_name']) ?></strong>
                                <div style="font-size:12px;color:#64748b;"><?= htmlspecialchars($tenant['property_location']??'') ?></div>
                            <?php else: ?>
                                <span style="color:#94a3b8;">No property</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tenant['move_in_date']): ?><div><?= date('M d, Y', strtotime($tenant['move_in_date'])) ?></div><?php endif; ?>
                            <?php if ($tenant['move_out_date']): ?><div style="color:#94a3b8;font-size:12px;"><?= date('M d, Y', strtotime($tenant['move_out_date'])) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <div><strong>KES <?= number_format($tenant['total_paid'],0) ?></strong></div>
                            <div style="font-size:11px;color:#64748b;"><?= $tenant['payment_count'] ?> payments</div>
                            <?php if ($tenant['maintenance_count'] > 0): ?>
                            <div style="font-size:11px;color:#f97316;"><i class="fas fa-tools"></i> <?= $tenant['maintenance_count'] ?> open</div>
                            <?php endif; ?>
                        </td>
                        <td><?= getStatusBadge($tenant['status']??'active') ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="?edit=<?= $tenant['id'] ?>" title="Edit"><i class="fas fa-edit"></i></a>
                                <button onclick="deleteTenant(<?= $tenant['id'] ?>)" class="delete-btn" title="Delete"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-users"></i>
            <h3>No Tenants Found</h3>
            <p>Click "Add Tenant" to get started.</p>
        </div>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="tenantModal" class="modal" style="display:<?= $edit_tenant ? 'block' : 'none' ?>;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= $edit_tenant ? 'Edit Tenant' : 'Add New Tenant' ?></h3>
            <button class="close-modal" onclick="closeTenantModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="<?= $edit_tenant ? 'edit' : 'add' ?>">
            <?php if ($edit_tenant): ?><input type="hidden" name="tenant_id" value="<?= $edit_tenant['id'] ?>"><?php endif; ?>
            <div class="form-group"><label>Full Name *</label><input type="text" name="name" value="<?= htmlspecialchars($edit_tenant['name']??'') ?>" required></div>
            <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?= htmlspecialchars($edit_tenant['email']??'') ?>" required></div>
            <div class="form-group"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($edit_tenant['phone']??'') ?>"></div>
            <div class="form-group">
                <label>Property</label>
                <select name="property_id">
                    <option value="">-- No Property --</option>
                    <?php foreach ($properties as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (($edit_tenant['property_id']??'')==$p['id'])?'selected':'' ?>><?= htmlspecialchars($p['title']) ?> - <?= htmlspecialchars($p['location']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Move In Date</label><input type="date" name="move_in_date" value="<?= htmlspecialchars($edit_tenant['move_in_date']??'') ?>"></div>
            <div class="form-group"><label>Move Out Date</label><input type="date" name="move_out_date" value="<?= htmlspecialchars($edit_tenant['move_out_date']??'') ?>"></div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="Active"    <?= (($edit_tenant['status']??'')==='Active')   ?'selected':'' ?>>Active</option>
                    <option value="Inactive"  <?= (($edit_tenant['status']??'')==='Inactive') ?'selected':'' ?>>Inactive</option>
                    <option value="Pending"   <?= (($edit_tenant['status']??'')==='Pending')  ?'selected':'' ?>>Pending</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn-secondary" onclick="closeTenantModal()">Cancel</button>
                <button type="submit" class="btn-primary"><?= $edit_tenant ? 'Update Tenant' : 'Add Tenant' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header"><h3>Confirm Delete</h3><button class="close-modal" onclick="closeDeleteModal()">&times;</button></div>
        <p style="margin:20px 0;color:#334155;">Are you sure you want to delete this tenant? This cannot be undone.</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="tenant_id" id="deleteTenantId">
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal()       { document.getElementById('tenantModal').style.display='block'; }
function closeTenantModal()   { window.location.href='tenants.php'; }
function deleteTenant(id)     { document.getElementById('deleteTenantId').value=id; document.getElementById('deleteModal').style.display='block'; }
function closeDeleteModal()   { document.getElementById('deleteModal').style.display='none'; }
function toggleFilters()      { const f=document.getElementById('filters'); f.style.display=f.style.display==='flex'?'none':'flex'; }

function filterTable() {
    const sf=document.getElementById('statusFilter').value;
    const si=document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#tenantsTable tbody tr').forEach(row=>{
        const statusOk = sf==='all' || row.getAttribute('data-status')===sf;
        const searchOk = si==='' || row.textContent.toLowerCase().includes(si);
        row.style.display = statusOk && searchOk ? '' : 'none';
    });
}

function toggleSelectAll() {
    const checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('.tenant-select').forEach(cb=>cb.checked=checked);
    updateSelectedCount();
}
function updateSelectedCount() {
    const selected = [...document.querySelectorAll('.tenant-select:checked')];
    document.getElementById('selectedCount').textContent = selected.length;
    document.getElementById('bulkActions').classList.toggle('show', selected.length>0);
}
function clearSelection() {
    document.querySelectorAll('.tenant-select').forEach(cb=>cb.checked=false);
    document.getElementById('selectAll').checked=false;
    updateSelectedCount();
}
function applyBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    const ids = [...document.querySelectorAll('.tenant-select:checked')].map(cb=>cb.value);
    if (!action || !ids.length) return;
    if (action==='delete' && !confirm('Delete selected tenants?')) return;
    const form = document.createElement('form'); form.method='POST';
    [['action','bulk'],['bulk_action',action],['selected_ids',JSON.stringify(ids)]].forEach(([n,v])=>{
        const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i);
    });
    document.body.appendChild(form); form.submit();
}
window.onclick = e => { if (e.target.classList.contains('modal')) { e.target.style.display='none'; } }
</script>
</body>
</html>