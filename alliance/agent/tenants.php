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

// Handle tenant actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new tenant
        if ($_POST['action'] === 'add') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $property_id = !empty($_POST['property_id']) ? intval($_POST['property_id']) : null;
            $move_in_date = $_POST['move_in_date'] ?? null;
            $move_out_date = $_POST['move_out_date'] ?? null;
            $status = trim($_POST['status'] ?? 'Active');

            if ($name) {
                try {
                    // Check if email already exists (if provided)
                    if ($email) {
                        $stmt = $db->prepare("SELECT id FROM tenants WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) {
                            $error = "A tenant with this email already exists.";
                            return;
                        }
                    }

                    $stmt = $db->prepare("
                        INSERT INTO tenants (
                            name, email, phone, property_id, 
                            move_in_date, move_out_date, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $name, $email, $phone, $property_id,
                        $move_in_date, $move_out_date, $status
                    ]);
                    
                    $message = "Tenant added successfully!";
                } catch (PDOException $e) {
                    error_log("Add tenant error: " . $e->getMessage());
                    $error = "Failed to add tenant. Please try again.";
                }
            } else {
                $error = "Tenant name is required.";
            }
        }
        
        // Delete tenant
        elseif ($_POST['action'] === 'delete' && isset($_POST['tenant_id'])) {
            $tenant_id = intval($_POST['tenant_id']);
            
            try {
                $stmt = $db->prepare("DELETE FROM tenants WHERE id = ?");
                $stmt->execute([$tenant_id]);
                
                $message = "Tenant deleted successfully!";
            } catch (PDOException $e) {
                error_log("Delete tenant error: " . $e->getMessage());
                $error = "Failed to delete tenant.";
            }
        }
        
        // Edit tenant
        elseif ($_POST['action'] === 'edit' && isset($_POST['tenant_id'])) {
            $tenant_id = intval($_POST['tenant_id']);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $property_id = !empty($_POST['property_id']) ? intval($_POST['property_id']) : null;
            $move_in_date = $_POST['move_in_date'] ?? null;
            $move_out_date = $_POST['move_out_date'] ?? null;
            $status = trim($_POST['status'] ?? 'Active');

            if ($name) {
                try {
                    $stmt = $db->prepare("
                        UPDATE tenants 
                        SET name = ?, email = ?, phone = ?, property_id = ?,
                            move_in_date = ?, move_out_date = ?, status = ?
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $name, $email, $phone, $property_id,
                        $move_in_date, $move_out_date, $status, $tenant_id
                    ]);
                    
                    $message = "Tenant updated successfully!";
                } catch (PDOException $e) {
                    error_log("Edit tenant error: " . $e->getMessage());
                    $error = "Failed to update tenant.";
                }
            } else {
                $error = "Tenant name is required.";
            }
        }
    }
}

// Fetch all tenants with property details
$tenants = [];
try {
    $stmt = $db->query("
        SELECT t.*, p.title as property_name, p.location as property_location
        FROM tenants t
        LEFT JOIN properties p ON t.property_id = p.id
        ORDER BY t.id DESC
    ");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch tenants error: " . $e->getMessage());
    $error = "Failed to load tenants.";
}

// Fetch properties for dropdown
$properties = [];
try {
    $stmt = $db->query("SELECT id, title, location FROM properties ORDER BY title ASC");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch properties error: " . $e->getMessage());
}

// Get tenant for editing if requested
$edit_tenant = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM tenants WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $edit_tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Fetch edit tenant error: " . $e->getMessage());
    }
}

// Stats
$total_tenants = count($tenants);
$active_tenants = 0;
$inactive_tenants = 0;
$tenants_with_property = 0;

foreach ($tenants as $tenant) {
    if (strtolower($tenant['status'] ?? '') === 'active') {
        $active_tenants++;
    } else {
        $inactive_tenants++;
    }
    if (!empty($tenant['property_id'])) {
        $tenants_with_property++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenants - Agent Dashboard | Alliance Realtors</title>
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
        .stat-icon.blue   { background: #e0f2fe; } .stat-icon.blue i   { color: #3b82f6; }
        .stat-icon.green  { background: #d1fae5; } .stat-icon.green i  { color: #10b981; }
        .stat-icon.orange { background: #fed7aa; } .stat-icon.orange i { color: #f97316; }
        .stat-icon.purple { background: #ede9fe; } .stat-icon.purple i { color: #7c3aed; }
        .stat-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 22px; font-weight: 700; color: #0f172a; }

        /* Action Bar */
        .action-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .action-bar h2 { font-size: 18px; color: #0f172a; }
        .btn-primary { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: white; border: 1px solid #e2e8f0; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-secondary:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn-danger { background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: #f59e0b; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; }
        .btn-warning:hover { background: #d97706; }

        /* Alerts */
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { background: white; width: 90%; max-width: 600px; margin: 50px auto; border-radius: 10px; padding: 25px; max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .modal-header h3 { font-size: 18px; color: #0f172a; }
        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; }

        /* Form */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #475569; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }

        /* Table */
        .table-container { background: white; border-radius: 10px; padding: 20px; overflow-x: auto; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 12px; background: #f8fafc; color: #475569; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px 12px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; }
        tr:hover td { background: #f8fafc; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .action-buttons { display: flex; gap: 8px; }
        .action-buttons a, .action-buttons button { background: none; border: none; color: #64748b; cursor: pointer; font-size: 14px; padding: 4px; text-decoration: none; }
        .action-buttons a:hover, .action-buttons button:hover { color: #3b82f6; }
        .action-buttons .delete-btn:hover { color: #ef4444; }

        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; background: white; border-radius: 10px; }
        .empty-state i { font-size: 48px; color: #94a3b8; margin-bottom: 16px; }
        .empty-state h3 { color: #334155; margin-bottom: 8px; }
        .empty-state p { color: #94a3b8; font-size: 14px; }

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
        <a href="dashboard.php" class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php" class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php" class="menu-item active"><i class="fas fa-users"></i> Tenants</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-home"></i> Home / <span>Tenants</span>
            </div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Tenant Management, <?= htmlspecialchars(explode(' ', $full_name)[0]) ?>!</h1>
                <p><i class="fas fa-users"></i> Manage all tenants across your properties</p>
            </div>
            <div class="agent-badge"><i class="fas fa-user-tie"></i> Agent</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div class="stat-label">Total Tenants</div>
                <div class="stat-value"><?= $total_tenants ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?= $active_tenants ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-user-clock"></i></div>
                <div class="stat-label">Inactive</div>
                <div class="stat-value"><?= $inactive_tenants ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-building"></i></div>
                <div class="stat-label">With Property</div>
                <div class="stat-value"><?= $tenants_with_property ?></div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <h2><i class="fas fa-list"></i> All Tenants</h2>
            <button class="btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add New Tenant
            </button>
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

        <!-- Tenants Table -->
        <?php if (count($tenants) > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Property</th>
                            <th>Move In/Out</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $tenant): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($tenant['name']) ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($tenant['email'])): ?>
                                        <div><i class="fas fa-envelope"></i> <?= htmlspecialchars($tenant['email']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($tenant['phone'])): ?>
                                        <div><i class="fas fa-phone"></i> <?= htmlspecialchars($tenant['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($tenant['property_name'])): ?>
                                        <strong><?= htmlspecialchars($tenant['property_name']) ?></strong>
                                        <div style="font-size: 12px; color: #64748b;">
                                            <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($tenant['property_location'] ?? '') ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">No property assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tenant['move_in_date']): ?>
                                        <div><i class="fas fa-calendar-plus"></i> <?= date('M d, Y', strtotime($tenant['move_in_date'])) ?></div>
                                    <?php endif; ?>
                                    <?php if ($tenant['move_out_date']): ?>
                                        <div><i class="fas fa-calendar-minus"></i> <?= date('M d, Y', strtotime($tenant['move_out_date'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status = strtolower($tenant['status'] ?? 'active');
                                    $status_class = $status === 'active' ? 'status-active' : 'status-inactive';
                                    ?>
                                    <span class="status-badge <?= $status_class ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit=<?= $tenant['id'] ?>" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button onclick="deleteTenant(<?= $tenant['id'] ?>)" class="delete-btn" title="Delete">
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
                <i class="fas fa-users"></i>
                <h3>No Tenants Yet</h3>
                <p>Click the "Add New Tenant" button to add your first tenant.</p>
            </div>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<!-- Add/Edit Tenant Modal -->
<div id="tenantModal" class="modal" style="display: <?= $edit_tenant ? 'block' : 'none' ?>;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= $edit_tenant ? 'Edit Tenant' : 'Add New Tenant' ?></h3>
            <button class="close-modal" onclick="closeTenantModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="<?= $edit_tenant ? 'edit' : 'add' ?>">
            <?php if ($edit_tenant): ?>
                <input type="hidden" name="tenant_id" value="<?= $edit_tenant['id'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($edit_tenant['name'] ?? '') ?>" required>
            </div>
            
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($edit_tenant['email'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($edit_tenant['phone'] ?? '') ?>" placeholder="e.g., 0712 345 678">
            </div>
            
            <div class="form-group">
                <label>Assign Property</label>
                <select name="property_id">
                    <option value="">-- No Property --</option>
                    <?php foreach ($properties as $property): ?>
                        <option value="<?= $property['id'] ?>" <?= (($edit_tenant['property_id'] ?? '') == $property['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($property['title']) ?> - <?= htmlspecialchars($property['location']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Move In Date</label>
                <input type="date" name="move_in_date" value="<?= htmlspecialchars($edit_tenant['move_in_date'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Move Out Date</label>
                <input type="date" name="move_out_date" value="<?= htmlspecialchars($edit_tenant['move_out_date'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="Active" <?= (($edit_tenant['status'] ?? '') == 'Active') ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= (($edit_tenant['status'] ?? '') == 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn-secondary" onclick="closeTenantModal()">Cancel</button>
                <button type="submit" class="btn-primary" style="padding: 10px 25px;">
                    <?= $edit_tenant ? 'Update Tenant' : 'Add Tenant' ?>
                </button>
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
        <p style="margin: 20px 0; color: #334155;">Are you sure you want to delete this tenant? This action cannot be undone.</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="tenant_id" id="deleteTenantId" value="">
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-danger">Delete Tenant</button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal functions
function openAddModal() {
    window.location.href = 'tenants.php';
}

function closeTenantModal() {
    window.location.href = 'tenants.php';
}

function deleteTenant(tenantId) {
    document.getElementById('deleteTenantId').value = tenantId;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        if (event.target.id === 'deleteModal') {
            closeDeleteModal();
        } else {
            window.location.href = 'tenants.php';
        }
    }
}
</script>

</body>
</html>