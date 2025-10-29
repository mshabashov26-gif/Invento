<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit();
}

$teacher_id = $_SESSION['user_id'];
$booking_id = (int)($_POST['booking_id'] ?? 0);
$attendance = $_POST['attendance'] ?? '';
$comment    = trim($_POST['comment'] ?? '');

if (!$booking_id || !in_array($attendance, ['booked','attended','missed','canceled'])) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid input']);
    exit();
}

$stmt = $conn->prepare("
    UPDATE bookings
    SET attendance=?, teacher_comment=?, 
        attended_at = CASE WHEN ?='attended' THEN NOW() ELSE attended_at END,
        comment_updated_at = NOW()
    WHERE id=?");
$stmt->bind_param("sssi", $attendance, $comment, $attendance, $booking_id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    $lg = $conn->prepare("
        INSERT INTO logs (role,user_id,action,log_time)
        VALUES ('teacher', ?, CONCAT('Updated booking #', ?, ' → ', ?), NOW())");
    $lg->bind_param("iis", $teacher_id, $booking_id, $attendance);
    $lg->execute();
    $lg->close();
}

echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Saved' : 'Error saving']);
