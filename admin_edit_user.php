<?php
session_start();
include('db_connect.php');

// Only admin
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
  exit("<div class='alert alert-danger'>Access denied.</div>");
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
  exit("<div class='alert alert-danger'>Invalid user ID.</div>");
}

// Fetch user data
$stmt = $conn->prepare("SELECT id, name, email, grade FROM users WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) exit("<div class='alert alert-warning'>User not found.</div>");

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name']);
  $email = trim($_POST['email']);
  $grade = trim($_POST['grade']);

  $upd = $conn->prepare("UPDATE users SET name=?, email=?, grade=? WHERE id=?");
  $upd->bind_param("sssi", $name, $email, $grade, $id);
  $upd->execute();
  $upd->close();

  // Log
  $log = $conn->prepare("INSERT INTO logs (admin_email, role, user_id, action, log_time) VALUES (?, 'admin', ?, ?, NOW())");
  $action = "Edited user #$id ($name)";
  $log->bind_param("sis", $_SESSION['admin_email'], $id, $action);
  $log->execute();
  $log->close();

  echo "<div class='alert alert-success'>✅ User updated successfully.</div>";
  exit;
}
?>

<form method="POST" id="userEditForm">
  <div class="mb-3">
    <label>Name</label>
    <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($user['name']) ?>">
  </div>
  <div class="mb-3">
    <label>Email</label>
    <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($user['email']) ?>">
  </div>
  <div class="mb-3">
    <label>Grade</label>
    <input type="text" name="grade" class="form-control" required value="<?= htmlspecialchars($user['grade']) ?>">
  </div>
  <button class="btn btn-primary w-100" type="submit">Save Changes</button>
</form>

<script>
document.getElementById('userEditForm').addEventListener('submit', async e=>{
  e.preventDefault();
  const form = e.target;
  const data = new FormData(form);
  const res = await fetch(window.location.href, {method:'POST', body:data});
  document.getElementById('editUserBody').innerHTML = await res.text();
});
</script>
