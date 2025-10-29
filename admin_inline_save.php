<?php
session_start();
include('db_connect.php');

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
  http_response_code(403);
  exit("Access denied");
}

$id = intval($_POST['id'] ?? 0);
$field = $_POST['field'] ?? '';
$value = trim($_POST['value'] ?? '');

$allowed = ['name','email','grade'];
if ($id <= 0 || !in_array($field,$allowed)) {
  http_response_code(400);
  exit("Invalid input");
}

$stmt = $conn->prepare("UPDATE users SET $field=? WHERE id=?");
$stmt->bind_param("si",$value,$id);
$stmt->execute();
$stmt->close();

$log = $conn->prepare("INSERT INTO logs (admin_email,role,user_id,action,log_time) VALUES (?,'admin',?, ?,NOW())");
$action = "Inline edit: changed $field of user #$id to '$value'";
$log->bind_param("sis",$_SESSION['admin_email'],$id,$action);
$log->execute();
$log->close();

echo "Saved";
?>
