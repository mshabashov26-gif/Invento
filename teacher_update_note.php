<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
session_start();
include('db_connect.php');
header('Content-Type: application/json');

if(!isset($_SESSION['user_id']) || $_SESSION['role']!=='teacher'){
  echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}

$id=intval($_POST['id']??0);
$comment=trim($_POST['comment']??'');

if($id<=0){ echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }

$stmt=$conn->prepare("UPDATE bookings SET teacher_comment=? WHERE id=?");
$stmt->bind_param("si",$comment,$id);
$ok=$stmt->execute();
$stmt->close(); $conn->close();

echo json_encode(['ok'=>$ok]);
