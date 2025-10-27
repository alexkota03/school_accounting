<?php
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
}

function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header("Location: ../login.php");
        exit();
    }
}

function checkPermission($requiredRole) {
    if (!isLoggedIn() || $_SESSION['role'] !== $requiredRole) {
        header("Location: ../unauthorized.php");
        exit();
    }
}
?>