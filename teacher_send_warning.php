<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include('db_connect.php');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$teacher_id = $_SESSION['user_id'];
$student_id = $_POST['student_id'] ?? null;
$text = trim($_POST['text'] ?? '');

if (!$student_id || !$text) {
    echo json_encode(['ok' => false, 'error' => 'Missing data']);
    exit;
}

// Insert warning
$stmt = $conn->prepare("INSERT INTO student_warnings (student_id, teacher_id, warning_text, date_sent, status) VALUES (?, ?, ?, NOW(), 'sent')");
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => $conn->error]);
    exit;
}
$stmt->bind_param("iis", $student_id, $teacher_id, $text);

if ($stmt->execute()) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => $stmt->error]);
}
$stmt->close();
$conn->close();
?>
