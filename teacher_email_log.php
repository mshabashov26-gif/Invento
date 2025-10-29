<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

/* DB */
include('db_connect.php');

/* Require teacher login (same pattern you use elsewhere) */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    // support persistent cookie if you use it
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $conn->prepare("SELECT id, name, email FROM teachers WHERE remember_token=? LIMIT 1");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            $t = $res->fetch_assoc();
            $_SESSION['user_id'] = $t['id'];
            $_SESSION['role']    = 'teacher';
            $_SESSION['name']    = $t['name'];
            $_SESSION['email']   = $t['email'];
        } else {
            header("Location: index.php"); exit;
        }
        $stmt->close();
    } else {
        header("Location: index.php"); exit;
    }
}

$teacher_id   = (int)$_SESSION['user_id'];
$teacher_name = $_SESSION['name'] ?? '';

/* Fetch email logs for this teacher */
$logs = [];
$stmt = $conn->prepare("
    SELECT id, sent_at, student_name, grade, parent_email, message, attachment
    FROM email_logs
    WHERE teacher_id = ?
    ORDER BY sent_at DESC
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result) $logs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>📧 Sent Email Log</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f4f7fc;font-family:'Segoe UI',sans-serif;}
  .navbar{background:#3d73dd;}
  .navbar-brand{color:#fff!important;font-weight:600;}
  .card{background:white;border-radius:12px;box-shadow:0 5px 20px rgba(0,0,0,0.1);}
  .table thead th{background:#eef3ff;}
  .truncate{max-width:380px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .badge-grade{background:#e9f2ff;color:#1b4ad1;}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-4 d-flex justify-content-between">
  <span class="navbar-brand">📧 Sent Email Log</span>
  <div>
    <a href="teachers.php" class="btn btn-light me-2">← Back</a>
    <a href="logout.php" class="btn btn-outline-light">Logout</a>
  </div>
</nav>

<div class="container mt-4 mb-5">
  <div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="m-0">Emails sent by <strong><?= htmlspecialchars($teacher_name) ?></strong></h5>
      <span class="text-muted small"><?= count($logs) ?> record(s)</span>
    </div>

    <?php if(empty($logs)): ?>
      <div class="alert alert-info m-2">
        No emails logged yet. Use “📨 Send Reports to Parents” from your dashboard to send and log emails.
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th style="width:150px;">Date</th>
              <th>Student</th>
              <th style="width:90px;">Grade</th>
              <th>Parent Email</th>
              <th>Message</th>
              <th style="width:120px;">Attachment</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($logs as $row): 
              $sentAt    = $row['sent_at'] ?? '';
              $student   = $row['student_name'] ?? '';
              $grade     = $row['grade'] ?? '';
              $pemail    = $row['parent_email'] ?? '';
              $msg       = $row['message'] ?? '';
              $attach    = $row['attachment'] ?? '';
              $attachUrl = $attach ? 'uploads/reports/'.rawurlencode($attach) : '';
              $hasFile   = $attach && file_exists(__DIR__ . '/uploads/reports/' . $attach);
            ?>
            <tr>
              <td class="text-nowrap"><?= htmlspecialchars($sentAt) ?></td>
              <td><?= htmlspecialchars($student) ?></td>
              <td><span class="badge badge-grade"><?= htmlspecialchars($grade) ?></span></td>
              <td><a href="mailto:<?= htmlspecialchars($pemail) ?>"><?= htmlspecialchars($pemail) ?></a></td>
              <td class="truncate" title="<?= htmlspecialchars($msg) ?>"><?= htmlspecialchars($msg) ?></td>
              <td>
                <?php if($hasFile): ?>
                  <a class="btn btn-outline-primary btn-sm" href="<?= $attachUrl ?>" download>Download</a>
                <?php elseif($attach): ?>
                  <span class="text-danger small">Missing file</span>
                <?php else: ?>
                  <span class="text-muted small">None</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
