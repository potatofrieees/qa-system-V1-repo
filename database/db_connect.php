<?php
$host     = 'localhost';
$db_name  = 'qa_system';
$username = 'root';
$password = '';

$conn = new mysqli($host, $username, $password, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Alias for legacy compatibility
$data = $conn;
?>
