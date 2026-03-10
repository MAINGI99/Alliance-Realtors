<?php
// 1. Start session FIRST
session_start();

// 2. Then include helpers
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// 3. Then run auth check
check_login(ROLE_TENANT);