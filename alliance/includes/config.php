<?php
// C:\xampp\htdocs\alliance\includes\config.php

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'alliance_db');  // your database name
define('DB_USER', 'root');         // default XAMPP user
define('DB_PASS', 'maish');             // default XAMPP password is empty

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}