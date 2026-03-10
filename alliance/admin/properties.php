<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_ADMIN', 1);

// Auth check
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_ADMIN) {
    header("Location: ../login.php?session_expired=1", true, 303);
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$email = $_SESSION['email'] ?? '';

$db = Database::getInstance()->getConnection();

// Handle property actions
$message = '';
$error = '';

// First, let's check if status column exists and add it if needed
try {
    $db->query("SELECT status FROM properties LIMIT 1");
} catch (PDOException $e) {
    // Status column doesn't exist, add it
    try {
        $db->exec("ALTER TABLE properties ADD COLUMN status VARCHAR(20) DEFAULT 'Available' AFTER price");
    } catch (PDOException $ex) {
        // Column might already exist or other error
    }
}

// Also check for created_by column
try {
    $db->query("SELECT created_by FROM properties LIMIT 1");
} catch (PDOException $e) {
    // created_by column doesn't exist, add it
    try {
        $db->exec("ALTER TABLE properties ADD COLUMN created_by INT(11) NULL AFTER image");
    } catch (PDOException $ex) {
        // Column might already exist
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new property
        if ($_POST['action'] === 'add') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = floatval($_POST['price'] ?? 0);
            $location = trim($_POST['location'] ?? '');
            $type = trim($_POST['type'] ?? 'Apartment');
            $status = trim($_POST['status'] ?? 'Available');
            $agent_id = !empty($_POST['agent_id']) ? intval($_POST['agent_id']) : null;

            if ($title && $price > 0 && $location) {
                try {
                    // Handle image upload
                    $image_path = 'default.jpg';
                    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                        $upload_dir = __DIR__ . '/../uploads/properties/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                        $filename = time() . '_' . uniqid() . '.' . $ext;
                        $destination = $upload_dir . $filename;
                        
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                            $image_path = 'uploads/properties/' . $filename;
                        }
                    }

                    $stmt = $db->prepare("
                        INSERT INTO properties (
                            title, description, price, location, 
                            type, status, image, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $title, $description, $price, $location,
                        $type, $status, $image_path, $agent_id
                    ]);
                    
                    $message = "Property added successfully!";
                } catch (PDOException $e) {
                    error_log("Add property error: " . $e->getMessage());
                    $error = "Failed to add property. Please try again.";
                }
            } else {
                $error = "Please fill in all required fields.";
            }
        }
        
        // Edit property
        elseif ($_POST['action'] === 'edit' && isset($_POST['property_id'])) {
            $property_id = intval($_POST['property_id']);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = floatval($_POST['price'] ?? 0);
            $location = trim($_POST['location'] ?? '');
            $type = trim($_POST['type'] ?? 'Apartment');
            $status = trim($_POST['status'] ?? 'Available');
            $agent_id = !empty($_POST['agent_id']) ? intval($_POST['agent_id']) : null;

            if ($title && $price > 0 && $location) {
                try {
                    // Check if new image uploaded
                    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                        $upload_dir = __DIR__ . '/../uploads/properties/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        // Get old image to delete
                        $stmt = $db->prepare("SELECT image FROM properties WHERE id = ?");
                        $stmt->execute([$property_id]);
                        $old_image = $stmt->fetchColumn();
                        
                        if ($old_image && $old_image !== 'default.jpg') {
                            $old_file = __DIR__ . '/../' . $old_image;
                            if (file_exists($old_file)) {
                                unlink($old_file);
                            }
                        }
                        
                        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                        $filename = time() . '_' . uniqid() . '.' . $ext;
                        $destination = $upload_dir . $filename;
                        
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                            $image_path = 'uploads/properties/' . $filename;
                            
                            // Update with new image
                            $stmt = $db->prepare("
                                UPDATE properties 
                                SET title = ?, description = ?, price = ?, location = ?,
                                    type = ?, status = ?, image = ?, created_by = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([
                                $title, $description, $price, $location,
                                $type, $status, $image_path, $agent_id, $property_id
                            ]);
                        }
                    } else {
                        // Update without changing image
                        $stmt = $db->prepare("
                            UPDATE properties 
                            SET title = ?, description = ?, price = ?, location = ?,
                                type = ?, status = ?, created_by = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $title, $description, $price, $location,
                            $type, $status, $agent_id, $property_id
                        ]);
                    }
                    
                    $message = "Property updated successfully!";
                } catch (PDOException $e) {
                    error_log("Edit property error: " . $e->getMessage());
                    $error = "Failed to update property.";
                }
            } else {
                $error = "Please fill in all required fields.";
            }
        }
        
        // Delete property
        elseif ($_POST['action'] === 'delete' && isset($_POST['property_id'])) {
            $property_id = intval($_POST['property_id']);
            
            try {
                // Check if property has tenants
                $stmt = $db->prepare("SELECT COUNT(*) FROM tenants WHERE property_id = ?");
                $stmt->execute([$property_id]);
                $tenant_count = $stmt->fetchColumn();
                
                if ($tenant_count > 0) {
                    $error = "Cannot delete property with active tenants. Please reassign tenants first.";
                } else {
                    // Get image path to delete file
                    $stmt = $db->prepare("SELECT image FROM properties WHERE id = ?");
                    $stmt->execute([$property_id]);
                    $image = $stmt->fetchColumn();
                    
                    if ($image && $image !== 'default.jpg') {
                        $file_path = __DIR__ . '/../' . $image;
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                    }
                    
                    $stmt = $db->prepare("DELETE FROM properties WHERE id = ?");
                    $stmt->execute([$property_id]);
                    
                    $message = "Property deleted successfully!";
                }
            } catch (PDOException $e) {
                error_log("Delete property error: " . $e->getMessage());
                $error = "Failed to delete property.";
            }
        }
        
        // Bulk action
        elseif ($_POST['action'] === 'bulk' && isset($_POST['selected_ids'])) {
            $selected_ids = json_decode($_POST['selected_ids'], true);
            $bulk_action = $_POST['bulk_action'] ?? '';
            
            if (!empty($selected_ids) && $bulk_action) {
                try {
                    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                    
                    if ($bulk_action === 'delete') {
                        // Check for tenants
                        $stmt = $db->prepare("SELECT property_id, COUNT(*) FROM tenants WHERE property_id IN ($placeholders) GROUP BY property_id");
                        $stmt->execute($selected_ids);
                        $properties_with_tenants = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                        
                        if (!empty($properties_with_tenants)) {
                            $error = "Some properties have active tenants and cannot be deleted.";
                        } else {
                            // Delete images first
                            $stmt = $db->prepare("SELECT image FROM properties WHERE id IN ($placeholders)");
                            $stmt->execute($selected_ids);
                            $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            foreach ($images as $image) {
                                if ($image && $image !== 'default.jpg') {
                                    $file_path = __DIR__ . '/../' . $image;
                                    if (file_exists($file_path)) {
                                        unlink($file_path);
                                    }
                                }
                            }
                            
                            $stmt = $db->prepare("DELETE FROM properties WHERE id IN ($placeholders)");
                            $stmt->execute($selected_ids);
                            $message = "Selected properties deleted successfully!";
                        }
                    } elseif (in_array($bulk_action, ['Available', 'Occupied', 'Maintenance'])) {
                        $stmt = $db->prepare("UPDATE properties SET status = ? WHERE id IN ($placeholders)");
                        $stmt->execute(array_merge([$bulk_action], $selected_ids));
                        $message = "Selected properties updated to " . $bulk_action . "!";
                    }
                } catch (PDOException $e) {
                    error_log("Bulk action error: " . $e->getMessage());
                    $error = "Failed to process bulk action.";
                }
            }
        }
    }
}

// Fetch all properties with agent details and tenant counts
$properties = [];
try {
    $stmt = $db->query("
        SELECT 
            p.*,
            u.full_name as agent_name,
            u.email as agent_email,
            COUNT(DISTINCT t.id) as tenant_count,
            COUNT(DISTINCT mr.id) as maintenance_count
        FROM properties p
        LEFT JOIN users u ON p.created_by = u.id
        LEFT JOIN tenants t ON p.id = t.property_id
        LEFT JOIN maintenance_requests mr ON p.id = mr.property_id AND mr.status != 'completed'
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch properties error: " . $e->getMessage());
    $error = "Failed to load properties: " . $e->getMessage();
}

// Fetch all agents for dropdown
$agents = [];
try {
    $stmt = $db->query("SELECT id, full_name, email FROM users WHERE role_id = 2 ORDER BY full_name ASC");
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch agents error: " . $e->getMessage());
}

// Get property for editing if requested
$edit_property = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM properties WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $edit_property = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Fetch edit property error: " . $e->getMessage());
    }
}

// Calculate statistics
$stats = [
    'total' => count($properties),
    'available' => 0,
    'occupied' => 0,
    'maintenance' => 0,
    'total_value' => 0,
    'avg_price' => 0,
    'with_tenants' => 0
];

foreach ($properties as $prop) {
    $status = strtolower($prop['status'] ?? 'available');
    if ($status === 'available') $stats['available']++;
    elseif ($status === 'occupied') $stats['occupied']++;
    elseif ($status === 'maintenance') $stats['maintenance']++;
    
    $stats['total_value'] += floatval($prop['price']);
    if ($prop['tenant_count'] > 0) $stats['with_tenants']++;
}

$stats['avg_price'] = $stats['total'] > 0 ? $stats['total_value'] / $stats['total'] : 0;

// Helper function for status badges
function getStatusBadge($status) {
    $badges = [
        'available' => ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => 'fa-check-circle'],
        'occupied' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'icon' => 'fa-home'],
        'maintenance' => ['bg' => '#fed7aa', 'color' => '#c2410c', 'icon' => 'fa-tools'],
        'house' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'icon' => 'fa-home'],
        'apartment' => ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => 'fa-building'],
        'office' => ['bg' => '#ede9fe', 'color' => '#5b21b6', 'icon' => 'fa-building'],
        'other' => ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-question-circle']
    ];
    
    $key = strtolower($status);
    $badge = $badges[$key] ?? ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-question-circle'];
    
    return "<span style='background: {$badge['bg']}; color: {$badge['color']}; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;'>
        <i class='fas {$badge['icon']}'></i> " . ucfirst($key) . "
    </span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Properties - Admin | Alliance Realtors</title>
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
        .welcome-card { background: linear-gradient(135deg, #7c3aed, #3b82f6); color: white; padding: 28px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .welcome-card h1 { font-size: 22px; margin-bottom: 6px; }
        .welcome-card p { font-size: 14px; opacity: 0.85; }
        .admin-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-icon.blue { background: #dbeafe; color: #3b82f6; }
        .stat-icon.green { background: #d1fae5; color: #10b981; }
        .stat-icon.orange { background: #fed7aa; color: #f97316; }
        .stat-content { flex: 1; }
        .stat-label { font-size: 13px; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 700; color: #0f172a; }
        .stat-sub { font-size: 12px; color: #64748b; margin-top: 4px; }

        /* Action Bar */
        .action-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .action-bar h2 { font-size: 18px; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        .action-buttons { display: flex; gap: 10px; }
        .btn-primary { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: white; border: 1px solid #e2e8f0; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-secondary:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn-danger { background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; }
        .btn-danger:hover { background: #dc2626; }

        /* Filters */
        .filters { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; background: white; padding: 15px 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .filter-group { display: flex; align-items: center; gap: 8px; }
        .filter-group label { font-size: 13px; font-weight: 600; color: #475569; }
        .filter-group select, .filter-group input { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; min-width: 150px; }
        .filter-group select:focus, .filter-group input:focus { outline: none; border-color: #3b82f6; }

        /* Bulk Actions */
        .bulk-actions { background: #f8fafc; padding: 10px 15px; border-radius: 8px; display: flex; align-items: center; gap: 15px; margin-bottom: 15px; display: none; }
        .bulk-actions.show { display: flex; }
        .bulk-actions span { font-size: 13px; font-weight: 600; color: #0f172a; }
        .bulk-actions select { padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; }
        .bulk-actions button { padding: 6px 15px; }

        /* Table */
        .table-container { background: white; border-radius: 10px; padding: 20px; overflow-x: auto; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 12px; background: #f8fafc; color: #475569; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px 12px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 13px; vertical-align: middle; }
        tr:hover td { background: #f8fafc; }
        .property-image { width: 50px; height: 50px; border-radius: 6px; object-fit: cover; }
        .agent-info { font-size: 12px; color: #64748b; }
        .action-buttons { display: flex; gap: 8px; }
        .action-buttons button, .action-buttons a { background: none; border: none; color: #64748b; cursor: pointer; font-size: 14px; padding: 4px; text-decoration: none; }
        .action-buttons button:hover, .action-buttons a:hover { color: #3b82f6; }
        .action-buttons .delete-btn:hover { color: #ef4444; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { background: white; width: 90%; max-width: 700px; margin: 50px auto; border-radius: 10px; padding: 25px; max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .modal-header h3 { font-size: 18px; color: #0f172a; }
        .close-modal { background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; }

        /* Form */
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #475569; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .current-image { margin: 10px 0; padding: 10px; background: #f8fafc; border-radius: 6px; display: flex; align-items: center; gap: 10px; }
        .current-image img { width: 60px; height: 60px; object-fit: cover; border-radius: 4px; }

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
        <a href="properties.php" class="menu-item active"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php" class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="agents.php" class="menu-item"><i class="fas fa-user-tie"></i> Agents</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="reports.php" class="menu-item"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="menu-divider"></div>
        <a href="settings.php" class="menu-item"><i class="fas fa-cog"></i> Settings</a>
        <a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-home"></i> Home / <span>Properties</span>
            </div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Property Management</h1>
                <p><i class="fas fa-building"></i> Manage all properties across the system</p>
            </div>
            <div class="admin-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-building"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Total Properties</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-sub"><?= $stats['with_tenants'] ?> with tenants</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Available</div>
                    <div class="stat-value"><?= $stats['available'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-home"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Occupied</div>
                    <div class="stat-value"><?= $stats['occupied'] ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-coins"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Avg. Price</div>
                    <div class="stat-value">KES <?= number_format($stats['avg_price'], 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <h2><i class="fas fa-list"></i> All Properties</h2>
            <div class="action-buttons">
                <button class="btn-secondary" onclick="toggleFilters()">
                    <i class="fas fa-filter"></i> Filters
                </button>
                <button class="btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add Property
                </button>
            </div>
        </div>

        <!-- Filters (hidden by default) -->
        <div id="filters" class="filters" style="display: none;">
            <div class="filter-group">
                <label>Status:</label>
                <select id="statusFilter" onchange="filterTable()">
                    <option value="all">All Statuses</option>
                    <option value="available">Available</option>
                    <option value="occupied">Occupied</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Type:</label>
                <select id="typeFilter" onchange="filterTable()">
                    <option value="all">All Types</option>
                    <option value="house">House</option>
                    <option value="apartment">Apartment</option>
                    <option value="office">Office</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Agent:</label>
                <select id="agentFilter" onchange="filterTable()">
                    <option value="all">All Agents</option>
                    <?php foreach ($agents as $agent): ?>
                        <option value="<?= $agent['id'] ?>"><?= htmlspecialchars($agent['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Search:</label>
                <input type="text" id="searchInput" placeholder="Title or location..." onkeyup="filterTable()">
            </div>
        </div>

        <!-- Bulk Actions -->
        <div id="bulkActions" class="bulk-actions">
            <span><span id="selectedCount">0</span> properties selected</span>
            <select id="bulkActionSelect">
                <option value="">Select Action</option>
                <option value="Available">Mark as Available</option>
                <option value="Occupied">Mark as Occupied</option>
                <option value="Maintenance">Mark as Maintenance</option>
                <option value="delete">Delete Selected</option>
            </select>
            <button class="btn-primary" onclick="applyBulkAction()">Apply</button>
            <button class="btn-secondary" onclick="clearSelection()">Clear</button>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div style="background: #d4edda; color: #155724; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Properties Table -->
        <?php if (count($properties) > 0): ?>
            <div class="table-container">
                <table id="propertiesTable">
                    <thead>
                        <tr>
                            <th width="30"><input type="checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                            <th>Image</th>
                            <th>Property</th>
                            <th>Location</th>
                            <th>Price</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Agent</th>
                            <th>Tenants</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($properties as $property): ?>
                            <tr data-status="<?= strtolower($property['status'] ?? 'available') ?>" 
                                data-type="<?= strtolower($property['type'] ?? 'house') ?>"
                                data-agent="<?= $property['created_by'] ?? '' ?>">
                                <td><input type="checkbox" class="property-select" value="<?= $property['id'] ?>" onclick="updateSelectedCount()"></td>
                                <td>
                                    <?php if (!empty($property['image']) && $property['image'] !== 'default.jpg'): ?>
                                        <img src="../<?= htmlspecialchars($property['image']) ?>" alt="<?= htmlspecialchars($property['title']) ?>" class="property-image">
                                    <?php else: ?>
                                        <img src="https://via.placeholder.com/50x50?text=No+Img" alt="No image" class="property-image">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($property['title']) ?></strong>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <?= htmlspecialchars(substr($property['description'] ?? '', 0, 50)) ?>...
                                    </div>
                                </td>
                                <td>
                                    <i class="fas fa-map-marker-alt" style="color: #64748b;"></i>
                                    <?= htmlspecialchars($property['location']) ?>
                                </td>
                                <td>
                                    <strong>KES <?= number_format($property['price'], 2) ?></strong>
                                </td>
                                <td>
                                    <?= getStatusBadge($property['type'] ?? 'House') ?>
                                </td>
                                <td>
                                    <?= getStatusBadge($property['status'] ?? 'Available') ?>
                                </td>
                                <td>
                                    <?php if (!empty($property['agent_name'])): ?>
                                        <?= htmlspecialchars($property['agent_name']) ?>
                                        <div class="agent-info"><?= htmlspecialchars($property['agent_email']) ?></div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="background: #f1f5f9; padding: 3px 8px; border-radius: 12px; font-size: 12px;">
                                        <?= $property['tenant_count'] ?> tenants
                                    </span>
                                    <?php if ($property['maintenance_count'] > 0): ?>
                                        <div style="font-size: 11px; color: #f97316; margin-top: 4px;">
                                            <i class="fas fa-tools"></i> <?= $property['maintenance_count'] ?> open
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit=<?= $property['id'] ?>" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button onclick="viewProperty(<?= htmlspecialchars(json_encode($property)) ?>)" title="View">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="deleteProperty(<?= $property['id'] ?>)" class="delete-btn" title="Delete">
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
                <i class="fas fa-building"></i>
                <h3>No Properties Found</h3>
                <p>Click the "Add Property" button to add your first property.</p>
            </div>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<!-- Add/Edit Property Modal -->
<div id="propertyModal" class="modal" style="display: <?= $edit_property ? 'block' : 'none' ?>;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= $edit_property ? 'Edit Property' : 'Add New Property' ?></h3>
            <button class="close-modal" onclick="closePropertyModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= $edit_property ? 'edit' : 'add' ?>">
            <?php if ($edit_property): ?>
                <input type="hidden" name="property_id" value="<?= $edit_property['id'] ?>">
            <?php endif; ?>
            
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Property Title *</label>
                    <input type="text" name="title" value="<?= htmlspecialchars($edit_property['title'] ?? '') ?>" required>
                </div>
                
                <div class="form-group full-width">
                    <label>Description</label>
                    <textarea name="description" rows="3"><?= htmlspecialchars($edit_property['description'] ?? '') ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Price (KES) *</label>
                    <input type="number" name="price" step="0.01" min="0" value="<?= htmlspecialchars($edit_property['price'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Location *</label>
                    <input type="text" name="location" value="<?= htmlspecialchars($edit_property['location'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Property Type</label>
                    <select name="type">
                        <option value="House" <?= (($edit_property['type'] ?? '') == 'House') ? 'selected' : '' ?>>House</option>
                        <option value="Apartment" <?= (($edit_property['type'] ?? '') == 'Apartment') ? 'selected' : '' ?>>Apartment</option>
                        <option value="Office" <?= (($edit_property['type'] ?? '') == 'Office') ? 'selected' : '' ?>>Office</option>
                        <option value="Other" <?= (($edit_property['type'] ?? '') == 'Other') ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="Available" <?= (($edit_property['status'] ?? '') == 'Available') ? 'selected' : '' ?>>Available</option>
                        <option value="Occupied" <?= (($edit_property['status'] ?? '') == 'Occupied') ? 'selected' : '' ?>>Occupied</option>
                        <option value="Maintenance" <?= (($edit_property['status'] ?? '') == 'Maintenance') ? 'selected' : '' ?>>Under Maintenance</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Assign to Agent</label>
                    <select name="agent_id">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?= $agent['id'] ?>" <?= (($edit_property['created_by'] ?? '') == $agent['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($agent['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($edit_property && !empty($edit_property['image']) && $edit_property['image'] !== 'default.jpg'): ?>
                    <div class="form-group full-width">
                        <div class="current-image">
                            <img src="../<?= htmlspecialchars($edit_property['image']) ?>" alt="Current image">
                            <span>Current Image</span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="form-group full-width">
                    <label><?= $edit_property ? 'Change Image (Optional)' : 'Property Image' ?></label>
                    <input type="file" name="image" accept="image/*">
                    <small style="color: #64748b; display: block; margin-top: 5px;">Upload a photo of the property</small>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn-secondary" onclick="closePropertyModal()">Cancel</button>
                <button type="submit" class="btn-primary" style="padding: 10px 25px;">
                    <?= $edit_property ? 'Update Property' : 'Save Property' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Property Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Property Details</h3>
            <button class="close-modal" onclick="closeViewModal()">&times;</button>
        </div>
        <div id="propertyDetails" style="padding: 10px 0;">
            <!-- Details will be loaded here -->
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
        </div>
        <p style="margin: 20px 0; color: #334155;">Are you sure you want to delete this property? This action cannot be undone.</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="property_id" id="deletePropertyId" value="">
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-danger">Delete Property</button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal functions
function openAddModal() {
    window.location.href = 'properties.php';
}

function closePropertyModal() {
    window.location.href = 'properties.php';
}

function viewProperty(property) {
    const details = `
        <div style="display: flex; gap: 20px; margin-bottom: 20px;">
            <div style="width: 200px;">
                ${property.image && property.image !== 'default.jpg' 
                    ? `<img src="../${escapeHtml(property.image)}" style="width: 100%; border-radius: 8px;">` 
                    : `<div style="width: 100%; height: 150px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #94a3b8;">No Image</div>`
                }
            </div>
            <div style="flex: 1;">
                <h2 style="color: #0f172a; margin-bottom: 10px;">${escapeHtml(property.title)}</h2>
                <p style="color: #475569; margin-bottom: 15px;">${escapeHtml(property.description || 'No description')}</p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div><strong>Price:</strong> KES ${Number(property.price).toLocaleString()}</div>
                    <div><strong>Location:</strong> ${escapeHtml(property.location)}</div>
                    <div><strong>Type:</strong> ${escapeHtml(property.type || 'House')}</div>
                    <div><strong>Status:</strong> ${escapeHtml(property.status || 'Available')}</div>
                    <div><strong>Agent:</strong> ${escapeHtml(property.agent_name || 'Unassigned')}</div>
                </div>
            </div>
        </div>
        <div style="border-top: 1px solid #e2e8f0; padding-top: 20px;">
            <h3 style="margin-bottom: 10px;">Additional Information</h3>
            <p><strong>Created:</strong> ${new Date(property.created_at).toLocaleString()}</p>
            <p><strong>Tenants:</strong> ${property.tenant_count || 0}</p>
            <p><strong>Maintenance Requests:</strong> ${property.maintenance_count || 0}</p>
        </div>
    `;
    document.getElementById('propertyDetails').innerHTML = details;
    document.getElementById('viewModal').style.display = 'block';
}

function deleteProperty(propertyId) {
    document.getElementById('deletePropertyId').value = propertyId;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Filter functions
function toggleFilters() {
    const filters = document.getElementById('filters');
    filters.style.display = filters.style.display === 'none' ? 'flex' : 'none';
}

function filterTable() {
    const statusFilter = document.getElementById('statusFilter').value;
    const typeFilter = document.getElementById('typeFilter').value;
    const agentFilter = document.getElementById('agentFilter').value;
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#propertiesTable tbody tr');
    
    rows.forEach(row => {
        const status = row.getAttribute('data-status');
        const type = row.getAttribute('data-type');
        const agent = row.getAttribute('data-agent');
        const text = row.textContent.toLowerCase();
        
        const statusMatch = statusFilter === 'all' || status === statusFilter;
        const typeMatch = typeFilter === 'all' || type === typeFilter;
        const agentMatch = agentFilter === 'all' || agent === agentFilter;
        const searchMatch = searchInput === '' || text.includes(searchInput);
        
        row.style.display = statusMatch && typeMatch && agentMatch && searchMatch ? '' : 'none';
    });
}

// Bulk actions
let selectedProperties = [];

function toggleSelectAll() {
    const checkboxes = document.querySelectorAll('.property-select');
    const selectAll = document.getElementById('selectAll').checked;
    
    checkboxes.forEach(cb => {
        cb.checked = selectAll;
        if (selectAll) {
            selectedProperties.push(cb.value);
        } else {
            selectedProperties = [];
        }
    });
    
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.property-select:checked');
    selectedProperties = Array.from(checkboxes).map(cb => cb.value);
    document.getElementById('selectedCount').textContent = selectedProperties.length;
    
    const bulkActions = document.getElementById('bulkActions');
    if (selectedProperties.length > 0) {
        bulkActions.classList.add('show');
    } else {
        bulkActions.classList.remove('show');
    }
}

function clearSelection() {
    document.querySelectorAll('.property-select').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    selectedProperties = [];
    updateSelectedCount();
}

function applyBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    if (!action || selectedProperties.length === 0) return;
    
    if (action === 'delete') {
        if (!confirm('Are you sure you want to delete the selected properties?')) return;
    }
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'bulk';
    
    const bulkActionInput = document.createElement('input');
    bulkActionInput.type = 'hidden';
    bulkActionInput.name = 'bulk_action';
    bulkActionInput.value = action;
    
    const selectedInput = document.createElement('input');
    selectedInput.type = 'hidden';
    selectedInput.name = 'selected_ids';
    selectedInput.value = JSON.stringify(selectedProperties);
    
    form.appendChild(actionInput);
    form.appendChild(bulkActionInput);
    form.appendChild(selectedInput);
    
    document.body.appendChild(form);
    form.submit();
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
        if (event.target.id === 'deleteModal') {
            closeDeleteModal();
        } else if (event.target.id === 'viewModal') {
            closeViewModal();
        } else {
            window.location.href = 'properties.php';
        }
    }
}
</script>

</body>
</html>