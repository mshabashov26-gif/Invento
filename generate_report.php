<?php
session_start();
include('db_connect.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, grade, email FROM users WHERE id=?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($name, $grade, $email);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Generate Reports</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f5f9ff;font-family:'Segoe UI',sans-serif;}
.container{max-width:700px;margin-top:60px;}
.card{background:white;border-radius:15px;box-shadow:0 8px 25px rgba(0,0,0,0.1);}
.btn-primary{background:#5aa0e0;border:none;}
</style>
</head>
<body>
<div class="container">
  <div class="card p-4">
    <h3 class="text-center mb-3">📑 Generate Report</h3>
    <p><b>Student:</b> <?= htmlspecialchars($name) ?> (Grade <?= htmlspecialchars($grade) ?>)<br>
    <b>Email:</b> <?= htmlspecialchars($email) ?></p>

    <form method="GET" action="report_pdf.php" target="_blank">
      <div class="mb-3">
        <label class="form-label">Select Report Type</label>
        <select name="type" class="form-select" required>
          <option value="attendance">Attendance Report</option>
          <option value="subject">Subject Performance Report</option>
          <option value="monthly">Monthly Summary Report</option>
          <option value="feedback">Teacher Feedback Report</option>
        </select>
      </div>

      <div class="mb-3 row">
        <div class="col">
          <label>From</label>
          <input type="date" name="from" class="form-control" required>
        </div>
        <div class="col">
          <label>To</label>
          <input type="date" name="to" class="form-control" required>
        </div>
      </div>

      <button class="btn btn-primary w-100">Generate PDF Report</button>
    </form>
  </div>
</div>
</body>
</html>
