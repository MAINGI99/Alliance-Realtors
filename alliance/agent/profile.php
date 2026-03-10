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

// Fetch current user data
$user = [];
try {
    $stmt = $db->prepare("SELECT id, full_name, email, phone, profile_image, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch user error: " . $e->getMessage());
    $error = "Failed to load profile.";
}

// Handle profile update
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Update profile information
        if ($_POST['action'] === 'update_profile') {
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if ($full_name && $email) {
                try {
                    // Check if email already exists for another user
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $user_id]);
                    if ($stmt->fetch()) {
                        $error = "Email already in use by another account.";
                    } else {
                        $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
                        $stmt->execute([$full_name, $email, $phone, $user_id]);
                        
                        // Update session
                        $_SESSION['full_name'] = $full_name;
                        $_SESSION['email'] = $email;
                        
                        $message = "Profile updated successfully!";
                        
                        // Refresh user data
                        $user['full_name'] = $full_name;
                        $user['email'] = $email;
                        $user['phone'] = $phone;
                    }
                } catch (PDOException $e) {
                    error_log("Update profile error: " . $e->getMessage());
                    $error = "Failed to update profile.";
                }
            } else {
                $error = "Name and email are required.";
            }
        }
        
        // Update password
        elseif ($_POST['action'] === 'update_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if ($current_password && $new_password && $confirm_password) {
                if ($new_password !== $confirm_password) {
                    $error = "New passwords do not match.";
                } elseif (strlen($new_password) < 6) {
                    $error = "Password must be at least 6 characters long.";
                } else {
                    try {
                        // Verify current password
                        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (password_verify($current_password, $user_data['password'])) {
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $stmt->execute([$hashed_password, $user_id]);
                            $message = "Password updated successfully!";
                        } else {
                            $error = "Current password is incorrect.";
                        }
                    } catch (PDOException $e) {
                        error_log("Update password error: " . $e->getMessage());
                        $error = "Failed to update password.";
                    }
                }
            } else {
                $error = "All password fields are required.";
            }
        }
        
        // Upload profile image
        elseif ($_POST['action'] === 'upload_image') {
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $file_type = $_FILES['profile_image']['type'];
                
                if (in_array($file_type, $allowed_types)) {
                    $upload_dir = __DIR__ . '/../uploads/profiles/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Delete old profile image if exists
                    if (!empty($user['profile_image']) && file_exists(__DIR__ . '/../' . $user['profile_image'])) {
                        unlink(__DIR__ . '/../' . $user['profile_image']);
                    }
                    
                    $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                    $filename = 'agent_' . $user_id . '_' . time() . '.' . $ext;
                    $destination = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                        $image_path = 'uploads/profiles/' . $filename;
                        
                        $stmt = $db->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                        $stmt->execute([$image_path, $user_id]);
                        
                        $user['profile_image'] = $image_path;
                        $message = "Profile image updated successfully!";
                    } else {
                        $error = "Failed to upload image.";
                    }
                } else {
                    $error = "Only JPG, PNG, GIF, and WEBP images are allowed.";
                }
            } else {
                $error = "Please select an image to upload.";
            }
        }
    }
}

// Get agent statistics
$stats = [];
try {
    // Total properties managed by this agent
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM properties WHERE created_by = ?");
    $stmt->execute([$user_id]);
    $stats['properties'] = $stmt->fetchColumn();
    
    // Total tenants (through properties)
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT t.id) as total 
        FROM tenants t
        JOIN properties p ON t.property_id = p.id
        WHERE p.created_by = ?
    ");
    $stmt->execute([$user_id]);
    $stats['tenants'] = $stmt->fetchColumn();
    
    // Total payments collected
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(p.amount), 0) as total
        FROM payments p
        JOIN leases l ON p.lease_id = l.id
        JOIN properties prop ON l.property_id = prop.id
        WHERE prop.created_by = ? AND p.status = 'completed'
    ");
    $stmt->execute([$user_id]);
    $stats['payments'] = $stmt->fetchColumn();
    
    // Member since
    $stmt = $db->prepare("SELECT created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $stats['member_since'] = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Fetch stats error: " . $e->getMessage());
}

// Default avatar if no profile image
$avatar = !empty($user['profile_image']) ? '../' . $user['profile_image'] : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name'] ?? 'Agent') . '&size=128&background=3b82f6&color=fff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Agent Dashboard | Alliance Realtors</title>
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

        /* Profile Layout */
        .profile-grid { display: grid; grid-template-columns: 300px 1fr; gap: 25px; }

        /* Profile Sidebar Card */
        .profile-sidebar { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); text-align: center; }
        .profile-image-container { position: relative; width: 150px; height: 150px; margin: 0 auto 20px; }
        .profile-image { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 4px solid #e2e8f0; }
        .image-upload-label { position: absolute; bottom: 5px; right: 5px; background: #3b82f6; color: white; width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; }
        .image-upload-label:hover { background: #2563eb; }
        .image-upload-input { display: none; }
        .profile-name { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 5px; }
        .profile-role { display: inline-block; background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 15px; }
        .profile-stats { display: flex; justify-content: space-around; margin: 20px 0; padding: 15px 0; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 20px; font-weight: 700; color: #0f172a; }
        .stat-label { font-size: 12px; color: #64748b; }

        /* Profile Main Content */
        .profile-main { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .tab-buttons { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; }
        .tab-button { padding: 10px 20px; background: none; border: none; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer; border-radius: 6px; transition: all 0.2s; }
        .tab-button:hover { background: #f1f5f9; color: #334155; }
        .tab-button.active { background: #3b82f6; color: white; }

        /* Tab Content */
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Forms */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #475569; }
        .form-group input, .form-group textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; transition: all 0.2s; }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .form-group input:read-only { background: #f8fafc; color: #64748b; }

        /* Alerts */
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

        /* Buttons */
        .btn-primary { background: #3b82f6; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: white; border: 1px solid #e2e8f0; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #475569; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-secondary:hover { background: #f8fafc; border-color: #94a3b8; }

        /* Info Grid */
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px; }
        .info-item { background: #f8fafc; padding: 15px; border-radius: 8px; }
        .info-label { font-size: 12px; color: #64748b; margin-bottom: 5px; }
        .info-value { font-size: 16px; font-weight: 600; color: #0f172a; }

        /* Activity List */
        .activity-list { margin-top: 20px; }
        .activity-item { display: flex; align-items: center; gap: 15px; padding: 15px; border-bottom: 1px solid #f1f5f9; }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon { width: 40px; height: 40px; background: #e0f2fe; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #3b82f6; }
        .activity-details { flex: 1; }
        .activity-title { font-weight: 600; color: #0f172a; margin-bottom: 4px; }
        .activity-time { font-size: 12px; color: #94a3b8; }

        /* Footer */
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
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php" class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <div class="menu-divider"></div>
        <a href="profile.php" class="menu-item active"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-home"></i> Home / <span>My Profile</span>
            </div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
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

        <!-- Profile Grid -->
        <div class="profile-grid">
            <!-- Profile Sidebar -->
            <div class="profile-sidebar">
                <div class="profile-image-container">
                    <img src="<?= htmlspecialchars($avatar) ?>" alt="Profile" class="profile-image" id="profileImage">
                    <form method="POST" enctype="multipart/form-data" id="imageUploadForm">
                        <input type="hidden" name="action" value="upload_image">
                        <label for="imageUpload" class="image-upload-label">
                            <i class="fas fa-camera"></i>
                        </label>
                        <input type="file" id="imageUpload" name="profile_image" class="image-upload-input" accept="image/*" onchange="this.form.submit()">
                    </form>
                </div>
                <div class="profile-name"><?= htmlspecialchars($user['full_name'] ?? 'Agent') ?></div>
                <div class="profile-role">Agent</div>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['properties'] ?? 0 ?></div>
                        <div class="stat-label">Properties</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['tenants'] ?? 0 ?></div>
                        <div class="stat-label">Tenants</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">KES <?= number_format($stats['payments'] ?? 0, 0) ?></div>
                        <div class="stat-label">Collected</div>
                    </div>
                </div>

                <div style="text-align: left; padding: 10px;">
                    <p style="color: #64748b; margin-bottom: 10px;"><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email'] ?? '') ?></p>
                    <p style="color: #64748b; margin-bottom: 10px;"><i class="fas fa-phone"></i> <?= htmlspecialchars($user['phone'] ?? 'Not provided') ?></p>
                    <p style="color: #64748b;"><i class="fas fa-calendar-alt"></i> Member since <?= date('M Y', strtotime($stats['member_since'] ?? 'now')) ?></p>
                </div>
            </div>

            <!-- Profile Main -->
            <div class="profile-main">
                <div class="tab-buttons">
                    <button class="tab-button active" onclick="openTab('personal')">Personal Information</button>
                    <button class="tab-button" onclick="openTab('security')">Security</button>
                    <button class="tab-button" onclick="openTab('activity')">Recent Activity</button>
                </div>

                <!-- Personal Information Tab -->
                <div id="personal" class="tab-content active">
                    <h3 style="margin-bottom: 20px; color: #0f172a;">Edit Personal Information</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="e.g., 0712 345 678">
                        </div>
                        
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>

                    <hr style="margin: 30px 0; border: none; border-top: 1px solid #e2e8f0;">

                    <h3 style="margin-bottom: 20px; color: #0f172a;">Account Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">User ID</div>
                            <div class="info-value">#<?= $user['id'] ?? '' ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Account Type</div>
                            <div class="info-value">Agent</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Member Since</div>
                            <div class="info-value"><?= date('F j, Y', strtotime($user['created_at'] ?? 'now')) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Last Login</div>
                            <div class="info-value">Today</div>
                        </div>
                    </div>
                </div>

                <!-- Security Tab -->
                <div id="security" class="tab-content">
                    <h3 style="margin-bottom: 20px; color: #0f172a;">Change Password</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_password">
                        
                        <div class="form-group">
                            <label>Current Password *</label>
                            <input type="password" name="current_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label>New Password *</label>
                            <input type="password" name="new_password" required>
                            <small style="color: #64748b;">Minimum 6 characters</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Confirm New Password *</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                        
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </form>

                    <hr style="margin: 30px 0; border: none; border-top: 1px solid #e2e8f0;">

                    <h3 style="margin-bottom: 20px; color: #0f172a;">Two-Factor Authentication</h3>
                    <div style="background: #f8fafc; padding: 20px; border-radius: 8px;">
                        <p style="color: #64748b; margin-bottom: 15px;">Enhance your account security by enabling two-factor authentication.</p>
                        <button class="btn-secondary" onclick="alert('2FA feature coming soon!')">
                            <i class="fas fa-shield-alt"></i> Enable 2FA
                        </button>
                    </div>
                </div>

                <!-- Activity Tab -->
                <div id="activity" class="tab-content">
                    <h3 style="margin-bottom: 20px; color: #0f172a;">Recent Activity</h3>
                    
                    <div class="activity-list">
                        <?php
                        // Fetch recent activity (you'll need to create an activity_log table for this)
                        // For now, showing placeholder data
                        $activities = [
                            ['icon' => 'fa-building', 'title' => 'Added new property "Sunset Apartments"', 'time' => '2 hours ago'],
                            ['icon' => 'fa-users', 'title' => 'Added new tenant "John Doe"', 'time' => '1 day ago'],
                            ['icon' => 'fa-credit-card', 'title' => 'Recorded payment of KES 25,000', 'time' => '2 days ago'],
                            ['icon' => 'fa-tools', 'title' => 'Updated maintenance request #123', 'time' => '3 days ago'],
                            ['icon' => 'fa-user-edit', 'title' => 'Updated profile information', 'time' => '1 week ago'],
                        ];
                        
                        foreach ($activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas <?= $activity['icon'] ?>"></i>
                                </div>
                                <div class="activity-details">
                                    <div class="activity-title"><?= $activity['title'] ?></div>
                                    <div class="activity-time"><?= $activity['time'] ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<script>
// Tab switching
function openTab(tabName) {
    // Hide all tab content
    const tabContents = document.getElementsByClassName('tab-content');
    for (let i = 0; i < tabContents.length; i++) {
        tabContents[i].classList.remove('active');
    }
    
    // Remove active class from all buttons
    const tabButtons = document.getElementsByClassName('tab-button');
    for (let i = 0; i < tabButtons.length; i++) {
        tabButtons[i].classList.remove('active');
    }
    
    // Show selected tab and mark button as active
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
}

// Preview image before upload
document.getElementById('imageUpload').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('profileImage').src = e.target.result;
        }
        reader.readAsDataURL(file);
    }
});

// Confirm password match
document.querySelector('form[action="update_password"]')?.addEventListener('submit', function(e) {
    const newPass = document.querySelector('input[name="new_password"]').value;
    const confirmPass = document.querySelector('input[name="confirm_password"]').value;
    
    if (newPass !== confirmPass) {
        e.preventDefault();
        alert('New passwords do not match!');
    }
});
</script>

</body>
</html>