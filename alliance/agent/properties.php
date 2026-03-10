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

// Handle property addition
$message = '';
$error = '';

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

                    // REMOVED created_by from INSERT
                    $stmt = $db->prepare("
                        INSERT INTO properties (
                            title, description, price, location, 
                            type, status, image, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $title, $description, $price, $location,
                        $type, $status, $image_path
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

            if ($title && $price > 0 && $location) {
                try {
                    // Check if new image uploaded
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
                            
                            // Update with new image - REMOVED created_by condition
                            $stmt = $db->prepare("
                                UPDATE properties 
                                SET title = ?, description = ?, price = ?, location = ?,
                                    type = ?, status = ?, image = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([
                                $title, $description, $price, $location,
                                $type, $status, $image_path, $property_id
                            ]);
                        }
                    } else {
                        // Update without changing image - REMOVED created_by condition
                        $stmt = $db->prepare("
                            UPDATE properties 
                            SET title = ?, description = ?, price = ?, location = ?,
                                type = ?, status = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $title, $description, $price, $location,
                            $type, $status, $property_id
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
                // Get image path to delete file - REMOVED created_by condition
                $stmt = $db->prepare("SELECT image FROM properties WHERE id = ?");
                $stmt->execute([$property_id]);
                $property = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($property && $property['image'] !== 'default.jpg') {
                    $file_path = __DIR__ . '/../' . $property['image'];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
                
                // REMOVED created_by condition
                $stmt = $db->prepare("DELETE FROM properties WHERE id = ?");
                $stmt->execute([$property_id]);
                
                $message = "Property deleted successfully!";
            } catch (PDOException $e) {
                error_log("Delete property error: " . $e->getMessage());
                $error = "Failed to delete property.";
            }
        }
    }
}

// Fetch properties - REMOVED created_by filter
$properties = [];
try {
    // Get ALL properties (since created_by doesn't exist)
    $stmt = $db->prepare("
        SELECT * FROM properties 
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch properties error: " . $e->getMessage());
    $error = "Failed to load properties. Database error: " . $e->getMessage();
}

// Get property for editing if requested
$edit_property = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        // REMOVED created_by condition
        $stmt = $db->prepare("SELECT * FROM properties WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $edit_property = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Fetch edit property error: " . $e->getMessage());
    }
}

// Stats for agent dashboard
$stats = [
    'total_properties' => count($properties),
    'available_properties' => 0,
    'occupied_properties' => 0,
    'total_value' => 0
];

foreach ($properties as $prop) {
    if (strtolower($prop['status'] ?? '') === 'available' || strtolower($prop['status'] ?? '') === 'available') {
        $stats['available_properties']++;
    } else {
        $stats['occupied_properties']++;
    }
    $stats['total_value'] += floatval($prop['price']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Properties - Agent Dashboard | Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* (keep all your existing styles) */
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
        .form-group textarea { resize: vertical; min-height: 80px; }
        .current-image { margin: 10px 0; padding: 10px; background: #f8fafc; border-radius: 6px; display: flex; align-items: center; gap: 10px; }
        .current-image img { width: 60px; height: 60px; object-fit: cover; border-radius: 4px; }

        /* Properties Grid */
        .properties-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .property-card { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.08); transition: transform 0.2s, box-shadow 0.2s; }
        .property-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); }
        .property-image { height: 180px; background: #e2e8f0; position: relative; }
        .property-image img { width: 100%; height: 100%; object-fit: cover; }
        .property-status { position: absolute; top: 10px; right: 10px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .status-available { background: #d1fae5; color: #065f46; }
        .status-occupied { background: #fee2e2; color: #991b1b; }
        .property-details { padding: 16px; }
        .property-title { font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .property-location { font-size: 13px; color: #64748b; margin-bottom: 8px; display: flex; align-items: center; gap: 4px; }
        .property-price { font-size: 18px; font-weight: 700; color: #3b82f6; margin-bottom: 8px; }
        .property-type { font-size: 12px; color: #64748b; margin-bottom: 8px; display: flex; align-items: center; gap: 4px; }
        .property-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #f1f5f9; }
        .action-buttons { display: flex; gap: 8px; }
        .action-buttons button { background: none; border: none; color: #64748b; cursor: pointer; font-size: 14px; padding: 4px; }
        .action-buttons button:hover { color: #3b82f6; }
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
        <a href="properties.php" class="menu-item active"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php" class="menu-item"><i class="fas fa-users"></i> Tenants</a>
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
                <i class="fas fa-home"></i> Home / <span>Properties</span>
            </div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Property Management, <?= htmlspecialchars(explode(' ', $full_name)[0]) ?>!</h1>
                <p><i class="fas fa-chart-line"></i> Manage all your listed properties in one place</p>
            </div>
            <div class="agent-badge"><i class="fas fa-user-tie"></i> Agent</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                <div class="stat-label">Total Properties</div>
                <div class="stat-value"><?= $stats['total_properties'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-label">Available</div>
                <div class="stat-value"><?= $stats['available_properties'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-user-friends"></i></div>
                <div class="stat-label">Occupied</div>
                <div class="stat-value"><?= $stats['occupied_properties'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-coins"></i></div>
                <div class="stat-label">Total Value</div>
                <div class="stat-value">KES <?= number_format($stats['total_value'], 0) ?></div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <h2><i class="fas fa-list"></i> My Properties</h2>
            <button class="btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add New Property
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

        <!-- Properties Grid -->
        <?php if (count($properties) > 0): ?>
            <div class="properties-grid">
                <?php foreach ($properties as $property): ?>
                    <div class="property-card">
                        <div class="property-image">
                            <?php if (!empty($property['image']) && $property['image'] !== 'default.jpg'): ?>
                                <img src="../<?= htmlspecialchars($property['image']) ?>" alt="<?= htmlspecialchars($property['title']) ?>">
                            <?php else: ?>
                                <img src="https://via.placeholder.com/300x180?text=No+Image" alt="No image">
                            <?php endif; ?>
                            <span class="property-status status-<?= strtolower($property['status'] ?? 'available') ?>">
                                <?= htmlspecialchars($property['status'] ?? 'Available') ?>
                            </span>
                        </div>
                        <div class="property-details">
                            <h3 class="property-title"><?= htmlspecialchars($property['title']) ?></h3>
                            <div class="property-location">
                                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($property['location']) ?>
                            </div>
                            <div class="property-price">KES <?= number_format($property['price'], 2) ?></div>
                            <div class="property-type">
                                <i class="fas fa-tag"></i> <?= htmlspecialchars($property['type'] ?? 'Apartment') ?>
                            </div>
                            <div class="property-footer">
                                <span class="property-type">
                                    <i class="far fa-calendar-alt"></i> <?= date('M d, Y', strtotime($property['created_at'])) ?>
                                </span>
                                <div class="action-buttons">
                                    <a href="?edit=<?= $property['id'] ?>" style="color: inherit;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button onclick="deleteProperty(<?= $property['id'] ?>)" class="delete-btn" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-building"></i>
                <h3>No Properties Yet</h3>
                <p>Click the "Add New Property" button to list your first property.</p>
            </div>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<!-- Add Property Modal -->
<div id="addModal" class="modal" style="display: <?= $edit_property ? 'block' : 'none' ?>;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= $edit_property ? 'Edit Property' : 'Add New Property' ?></h3>
            <button class="close-modal" onclick="closeAddModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= $edit_property ? 'edit' : 'add' ?>">
            <?php if ($edit_property): ?>
                <input type="hidden" name="property_id" value="<?= $edit_property['id'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Property Title *</label>
                <input type="text" name="title" value="<?= htmlspecialchars($edit_property['title'] ?? '') ?>" required>
            </div>
            
            <div class="form-group">
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
                </select>
            </div>
            
            <?php if ($edit_property && !empty($edit_property['image']) && $edit_property['image'] !== 'default.jpg'): ?>
                <div class="current-image">
                    <img src="../<?= htmlspecialchars($edit_property['image']) ?>" alt="Current image">
                    <span>Current Image</span>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label><?= $edit_property ? 'Change Image (Optional)' : 'Property Image' ?></label>
                <input type="file" name="image" accept="image/*">
                <small style="color: #64748b; display: block; margin-top: 5px;">Upload a photo of the property</small>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn-secondary" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn-primary" style="padding: 10px 25px;">
                    <?= $edit_property ? 'Update Property' : 'Save Property' ?>
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

function closeAddModal() {
    window.location.href = 'properties.php';
}

function deleteProperty(propertyId) {
    document.getElementById('deletePropertyId').value = propertyId;
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
        }
    }
}

// Auto-show edit modal if edit parameter is in URL
<?php if ($edit_property): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('addModal').style.display = 'block';
});
<?php endif; ?>
</script>

</body>
</html>