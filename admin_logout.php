<?php
session_start();
if (!empty($_SESSION['admin_email'])) {
    require 'db_connect.php';
    $email = $_SESSION['admin_email'];
    $log = $conn->prepare("INSERT INTO logs (admin_email, action) VALUES (?, 'Admin logout')");
    $log->bind_param("s", $email);
    $log->execute();
    $log->close();
}
session_unset();
session_destroy();
header("Location: index.php");
exit();
