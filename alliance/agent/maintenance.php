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

// Handle maintenance actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Update status
        if ($_POST['action'] === 'update_status' && isset($_POST['request_id'])) {
            $request_id = intval($_POST['request_id']);
            $status = trim($_POST['status'] ?? '');
            
            if ($status) {
                try {
                    $stmt = $db->prepare("UPDATE maintenance_requests SET status = ? WHERE id = ?");
                    $stmt->execute([$status, $request_id]);
                    $message = "Maintenance request status updated!";
                } catch (PDOException $e) {
                    error_log("Update status error: " . $e->getMessage());
                    $error = "Failed to update status.";
                }
            }
        }
        
        // Delete request
        elseif ($_POST['action'] === 'delete' && isset($_POST['request_id'])) {
            $request_id = intval($_POST['request_id']);
            
            try {
                $stmt = $db->prepare("DELETE FROM maintenance_requests WHERE id = ?");
                $stmt->execute([$request_id]);
                $message = "Maintenance request deleted!";
            } catch (PDOException $e) {
                error_log("Delete error: " . $e->getMessage());
                $error = "Failed to delete request.";
            }
        }
    }
}

// Fetch all maintenance requests with tenant and property details
$requests = [];
try {
    $stmt = $db->query("
        SELECT 
            mr.*,
            t.name as tenant_name,
            t.email as tenant_email,
            t.phone as tenant_phone,
            p.title as property_title,
            p.location as property_location,
            u.unit_number
        FROM maintenance_requests mr
        LEFT JOIN tenants t ON mr.tenant_id = t.id
        LEFT JOIN properties p ON mr.property_id = p.id
        LEFT JOIN units u ON mr.unit_id = u.id
        ORDER BY 
            FIELD(mr.priority, 'urgent', 'high', 'medium', 'low'),
            mr.created_at DESC
    ");
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch maintenance requests error: " . $e->getMessage());
    $error = "Failed to load maintenance requests.";
}

// Stats
$total_requests = count($requests);
$pending_requests = 0;
$in_progress_requests = 0;
$completed_requests = 0;
$urgent_requests = 0;

foreach ($requests as $req) {
    switch ($req['status']) {
        case 'pending':
            $pending_requests++;
            break;
        case 'in_progress':
            $in_progress_requests++;
            break;
        case 'completed':
            $completed_requests++;
            break;
    }
    
    if ($req['priority'] === 'urgent') {
        $urgent_requests++;
    }
}

// Priority colors
function getPriorityColor($priority) {
    switch ($priority) {
        case 'urgent':
            return ['bg' => '#7f1d1d', 'color' => 'white', 'icon' => 'fa-exclamation-circle'];
        case 'high':
            return ['bg' => '#fee2e2', 'color' => '#991b1b', 'icon' => 'fa-arrow-up'];
        case 'medium':
            return ['bg' => '#fef3c7', 'color' => '#92400e', 'icon' => 'fa-minus'];
        case 'low':
            return ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => 'fa-arrow-down'];
        default:
            return ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-circle'];
    }
}

// Status colors
function getStatusColor($status) {
    switch ($status) {
        case 'pending':
            return ['bg' => '#fef3c7', 'color' => '#92400e', 'icon' => 'fa-clock'];
        case 'in_progress':
            return ['bg' => '#dbeafe', 'color' => '#1e40af', 'icon' => 'fa-spinner'];
        case 'completed':
            return ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => 'fa-check-circle'];
        case 'cancelled':
            return ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-times-circle'];
        default:
            return ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-question-circle'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - Agent Dashboard | Alliance Realtors</title>
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
        .stat-icon.red    { background: #fee2e2; } .stat-icon.red i    { color: #dc2626; }
        .stat-icon.yellow { background: #fef3c7; } .stat-icon.yellow i { color: #d97706; }
        .stat-icon.blue   { background: #dbeafe; } .stat-icon.blue i   { color: #2563eb; }
        .stat-icon.green  { background: #d1fae5; } .stat-icon.green i  { color: #059669; }
        .stat-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 22px; font-weight: 700; color: #0f172a; }

        /* Filters */
        .filters { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .filter-group { display: flex; align-items: center; gap: 10px; background: white; padding: 5px 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .filter-group label { font-size: 13px; font-weight: 600; color: #475569; }
        .filter-group select { padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; min-width: 150px; }
        .filter-group select:focus { outline: none; border-color: #3b82f6; }

        /* Table */
        .table-container { background: white; border-radius: 10px; padding: 20px; overflow-x: auto; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 12px; background: #f8fafc; color: #475569; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px 12px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; }
        tr:hover td { background: #f8fafc; }

        /* Badges */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .priority-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }

        /* Action buttons */
        .action-buttons { display: flex; gap: 8px; }
        .action-buttons button { background: none; border: none; color: #64748b; cursor: pointer; font-size: 14px; padding: 4px; }
        .action-buttons button:hover { color: #3b82f6; }
        .action-buttons .delete-btn:hover { color: #ef4444; }

        /* Status update form */
        .status-form { display: flex; gap: 5px; }
        .status-form select { padding: 5px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; }
        .status-form button { padding: 5px 10px; background: #3b82f6; color: white; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; }
        .status-form button:hover { background: #2563eb; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { background: white; width: 90%; max-width: 600px; margin: 50px auto; border-radius: 10px; padding: 25px; max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .modal-header h3 { font-size: 18px; color: #0f172a; }
        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; }

        /* Request details */
        .detail-row { display: flex; margin-bottom: 15px; }
        .detail-label { width: 120px; font-weight: 600; color: #475569; }
        .detail-value { flex: 1; color: #334155; }

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
        <a href="tenants.php" class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="maintenance.php" class="menu-item active"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-home"></i> Home / <span>Maintenance Requests</span>
            </div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Maintenance Management, <?= htmlspecialchars(explode(' ', $full_name)[0]) ?>!</h1>
                <p><i class="fas fa-tools"></i> Track and manage all maintenance requests</p>
            </div>
            <div class="agent-badge"><i class="fas fa-user-tie"></i> Agent</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-label">Urgent</div>
                <div class="stat-value"><?= $urgent_requests ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= $pending_requests ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-spinner"></i></div>
                <div class="stat-label">In Progress</div>
                <div class="stat-value"><?= $in_progress_requests ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $completed_requests ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Priority:</label>
                <select id="priorityFilter" onchange="filterTable()">
                    <option value="all">All Priorities</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tasks"></i> Status:</label>
                <select id="statusFilter" onchange="filterTable()">
                    <option value="all">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
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

        <!-- Maintenance Requests Table -->
        <?php if (count($requests) > 0): ?>
            <div class="table-container">
                <table id="requestsTable">
                    <thead>
                        <tr>
                            <th>Priority</th>
                            <th>Title</th>
                            <th>Tenant</th>
                            <th>Property</th>
                            <th>Unit</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request): ?>
                            <?php 
                                $priority = getPriorityColor($request['priority']);
                                $status = getStatusColor($request['status']);
                            ?>
                            <tr data-priority="<?= $request['priority'] ?>" data-status="<?= $request['status'] ?>">
                                <td>
                                    <span class="priority-badge" style="background: <?= $priority['bg'] ?>; color: <?= $priority['color'] ?>;">
                                        <i class="fas <?= $priority['icon'] ?>"></i>
                                        <?= ucfirst($request['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($request['title']) ?></strong>
                                    <div style="font-size: 12px; color: #64748b; max-width: 250px; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars(substr($request['description'], 0, 50)) ?>...
                                    </div>
                                </td>
                                <td>
                                    <?= htmlspecialchars($request['tenant_name'] ?? 'Unknown') ?>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <?= htmlspecialchars($request['tenant_email'] ?? '') ?>
                                    </div>
                                </td>
                                <td>
                                    <?= htmlspecialchars($request['property_title'] ?? 'N/A') ?>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <?= htmlspecialchars($request['property_location'] ?? '') ?>
                                    </div>
                                </td>
                                <td>
                                    <?= $request['unit_number'] ? 'Unit '.htmlspecialchars($request['unit_number']) : '—' ?>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?= $status['bg'] ?>; color: <?= $status['color'] ?>;">
                                        <i class="fas <?= $status['icon'] ?>"></i>
                                        <?= str_replace('_', ' ', ucfirst($request['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('M d, Y', strtotime($request['created_at'])) ?>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <?= date('h:i A', strtotime($request['created_at'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="viewRequest(<?= htmlspecialchars(json_encode($request)) ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="updateStatus(<?= $request['id'] ?>, '<?= $request['status'] ?>')" title="Update Status">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="deleteRequest(<?= $request['id'] ?>)" class="delete-btn" title="Delete">
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
                <i class="fas fa-tools"></i>
                <h3>No Maintenance Requests</h3>
                <p>There are no maintenance requests in the system.</p>
            </div>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<!-- View Request Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Maintenance Request Details</h3>
            <button class="close-modal" onclick="closeViewModal()">&times;</button>
        </div>
        <div id="requestDetails" style="padding: 10px 0;">
            <!-- Details will be loaded here -->
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div id="statusModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Update Status</h3>
            <button class="close-modal" onclick="closeStatusModal()">&times;</button>
        </div>
        <form method="POST" id="statusForm">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="request_id" id="statusRequestId" value="">
            
            <div class="form-group">
                <label>Select New Status</label>
                <select name="status" id="statusSelect" required>
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
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
        <p style="margin: 20px 0; color: #334155;">Are you sure you want to delete this maintenance request? This action cannot be undone.</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="request_id" id="deleteRequestId" value="">
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-danger">Delete Request</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter table by priority and status
function filterTable() {
    const priorityFilter = document.getElementById('priorityFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('#requestsTable tbody tr');
    
    rows.forEach(row => {
        const priority = row.getAttribute('data-priority');
        const status = row.getAttribute('data-status');
        
        const priorityMatch = priorityFilter === 'all' || priority === priorityFilter;
        const statusMatch = statusFilter === 'all' || status === statusFilter;
        
        row.style.display = priorityMatch && statusMatch ? '' : 'none';
    });
}

// View request details
function viewRequest(request) {
    const priorityColors = {
        'urgent': { bg: '#7f1d1d', color: 'white' },
        'high': { bg: '#fee2e2', color: '#991b1b' },
        'medium': { bg: '#fef3c7', color: '#92400e' },
        'low': { bg: '#d1fae5', color: '#065f46' }
    };
    
    const statusColors = {
        'pending': { bg: '#fef3c7', color: '#92400e' },
        'in_progress': { bg: '#dbeafe', color: '#1e40af' },
        'completed': { bg: '#d1fae5', color: '#065f46' },
        'cancelled': { bg: '#f1f5f9', color: '#475569' }
    };
    
    const priority = priorityColors[request.priority] || priorityColors.medium;
    const status = statusColors[request.status] || statusColors.pending;
    
    const details = `
        <div style="margin-bottom: 20px;">
            <h4 style="color: #0f172a; margin-bottom: 10px; font-size: 16px;">${escapeHtml(request.title)}</h4>
            <p style="color: #475569; line-height: 1.6;">${escapeHtml(request.description)}</p>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
            <div>
                <div style="font-size: 12px; color: #64748b; margin-bottom: 5px;">Priority</div>
                <span style="background: ${priority.bg}; color: ${priority.color}; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                    <i class="fas fa-flag"></i> ${escapeHtml(request.priority).toUpperCase()}
                </span>
            </div>
            <div>
                <div style="font-size: 12px; color: #64748b; margin-bottom: 5px;">Status</div>
                <span style="background: ${status.bg}; color: ${status.color}; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                    <i class="fas fa-circle"></i> ${escapeHtml(request.status).replace('_', ' ').toUpperCase()}
                </span>
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #0f172a; margin-bottom: 10px; font-size: 14px;">Tenant Information</h4>
            <p><strong>Name:</strong> ${escapeHtml(request.tenant_name || 'N/A')}</p>
            <p><strong>Email:</strong> ${escapeHtml(request.tenant_email || 'N/A')}</p>
            <p><strong>Phone:</strong> ${escapeHtml(request.tenant_phone || 'N/A')}</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: #0f172a; margin-bottom: 10px; font-size: 14px;">Property Information</h4>
            <p><strong>Property:</strong> ${escapeHtml(request.property_title || 'N/A')}</p>
            <p><strong>Location:</strong> ${escapeHtml(request.property_location || 'N/A')}</p>
            <p><strong>Unit:</strong> ${request.unit_number ? 'Unit ' + escapeHtml(request.unit_number) : 'N/A'}</p>
        </div>
        
        <div>
            <p><strong>Created:</strong> ${new Date(request.created_at).toLocaleString()}</p>
            <p><strong>Last Updated:</strong> ${new Date(request.updated_at).toLocaleString()}</p>
        </div>
    `;
    
    document.getElementById('requestDetails').innerHTML = details;
    document.getElementById('viewModal').style.display = 'block';
}

// Update status
function updateStatus(requestId, currentStatus) {
    document.getElementById('statusRequestId').value = requestId;
    document.getElementById('statusSelect').value = currentStatus;
    document.getElementById('statusModal').style.display = 'block';
}

// Delete request
function deleteRequest(requestId) {
    document.getElementById('deleteRequestId').value = requestId;
    document.getElementById('deleteModal').style.display = 'block';
}

// Close modals
function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
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