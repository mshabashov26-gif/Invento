<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/* -----------------------------------
   🔒 Access Control
----------------------------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit();
}
$teacher_id = $_SESSION['user_id'];

/* -----------------------------------
   📋 Fetch Sent Warnings
----------------------------------- */
$sql = "SELECT sw.id, u.name AS student_name, u.grade, sw.warning_text, sw.date_sent, sw.status
        FROM student_warnings sw
        JOIN users u ON sw.student_id = u.id
        WHERE sw.teacher_id = ?
        ORDER BY sw.date_sent DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$warnings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>⚠️ Sent Warnings Log</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#eef5f9;font-family:'Segoe UI',sans-serif;}
.navbar{background:#3d73dd;}
.navbar-brand{color:#fff!important;font-weight:600;}
.card{background:#fff;border-radius:10px;padding:20px;box-shadow:0 5px 15px rgba(0,0,0,0.1);}
.badge-sent{background:#28a745;color:#fff;}
.badge-failed{background:#dc3545;color:#fff;}
.warning-box{background:#f8fbff;border-left:5px solid #ffc107;padding:10px;border-radius:6px;margin-bottom:10px;}
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4 mb-4 d-flex justify-content-between">
  <span class="navbar-brand">⚠️ Sent Warnings Log</span>
  <a href="teachers.php" class="btn btn-light btn-sm">← Back to Dashboard</a>
</nav>

<div class="container">
  <div class="card">
    <h4 class="mb-3">All Warnings Sent by You</h4>
    <?php if (!empty($warnings)): ?>
      <?php foreach ($warnings as $w): ?>
        <div class="warning-box">
          <strong><?= htmlspecialchars($w['student_name']) ?></strong> 
          (Grade <?= htmlspecialchars($w['grade']) ?>)
          <span class="badge <?= $w['status']=='sent'?'badge-sent':'badge-failed' ?>">
            <?= ucfirst($w['status']) ?>
          </span>
          <br>
          <small class="text-muted">📅 <?= htmlspecialchars($w['date_sent']) ?></small>
          <p class="mt-2"><?= nl2br(htmlspecialchars($w['warning_text'])) ?></p>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p>No warnings have been sent yet.</p>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
