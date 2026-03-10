<?php
// pages/index.php
session_start();

// Check if user is logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role_id'])) {
    
    // Redirect based on role
    switch ($_SESSION['role_id']) {
        case 1: // Admin
            header("Location: admin/dashboard.php");
            break;
        case 2: // Agent
            header("Location: agent/dashboard.php");
            break;
        case 3: // Tenant
            header("Location: tenant/dashboard.php");
            break;
        default: // Unknown role
            header("Location: login.php");
            break;
    }
} else {
    // Not logged in
    header("Location: login.php");
}
exit();
?>