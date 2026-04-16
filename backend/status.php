<?php
require_once 'utils.php';
require_once 'db.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
if (!empty($_SESSION['user_id'])) {
    echo json_encode([
        'logged_in' => true,
        'username' => $_SESSION['username'] ?? '',
    ]);
} else {
    echo json_encode(['logged_in' => false]);
}
?>