<?php
session_start();

// Redirect if not logged in
function check_login($role_id = null) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ./login.php");
        exit();
    }

    // Optional: restrict to a specific role
    if ($role_id !== null && ($_SESSION['role_id'] ?? 0) != $role_id) {
        // Redirect to login or dashboard if role mismatch
        header("Location: ./login.php");
        exit();
    }
}

// Logout helper
function logout() {
    session_start();
    session_destroy();
    header("Location: ./login.php");
    exit();
}