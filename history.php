<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

// --- AUTH GUARD ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

$student_id = $_SESSION['user_id'];

// --- FETCH STUDENT DATA ---
$stmt = $conn->prepare("SELECT name, email, profile_pic FROM users WHERE id=?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($student_name, $student_email, $student_avatar);
$stmt->fetch();
$stmt->close();

$defaultAvatar = 'uploads/basic.jpg';
if (empty($student_avatar) || !file_exists($student_avatar)) {
    $student_avatar = $defaultAvatar;
}

// --- FETCH BOOKINGS ---
$stmt = $conn->prepare("SELECT id, subject, booking_date FROM bookings WHERE user_id=? ORDER BY booking_date DESC");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Your Bookings</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
  font-family:'Montserrat',sans-serif;
  background:#f3f6fb;
}
.navbar {
  background:#8cbce6;
  border-radius:12px;
  margin-bottom:20px;
}
.table {
  background:white;
  border-radius:10px;
  overflow:hidden;
}
.profile-avatar {
  width:40px;
  height:40px;
  border-radius:50%;
  object-fit:cover;
  border:none;
  background:none;
}
.dropdown-menu {
  border-radius:12px;
  box-shadow:0 4px 10px rgba(0,0,0,0.1);
}
.btn-cancel {
  background:#dc3545;
  color:white;
  border:none;
  border-radius:8px;
  padding:5px 12px;
  transition:0.2s;
}
.btn-cancel:hover {
  background:#a71d2a;
}
.fade-out {
  opacity:0;
  transition:opacity 1s ease-out;
}
.card {
  background:#d0e8f9;
  border-radius:15px;
  box-shadow:0 8px 25px rgba(0,0,0,0.1);
  padding:30px;
}
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4 d-flex justify-content-between align-items-center">
  <span class="navbar-brand">📘 Your Bookings</span>
  <div class="dropdown">
    <button class="btn btn-light border-0 dropdown-toggle" data-bs-toggle="dropdown">
      <img src="<?= htmlspecialchars($student_avatar) ?>" onerror="this.src='uploads/basic.jpg';" class="profile-avatar" alt="Profile">
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow">
      <li><a class="dropdown-item" href="profile.php">👤 Profile</a></li>
      <li><a class="dropdown-item" href="book.php">🗓️ Book a Lesson</a></li>
      <li><a class="dropdown-item" href="history.php">📘 Your Bookings</a></li> 
      <li><a class="dropdown-item" href="reports.php">📊 Reports</a></li>
      <li><a class="dropdown-item" href="student_announcements.php">📢 Announcements</a></li>
      <li><a class="dropdown-item" href="support.php">💬 Support</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="logout.php">🚪 Logout</a></li>
    </ul>
  </div>
</nav>

<div class="container mt-4">
  <?php if (isset($_GET['canceled'])): ?>
    <div id="alertBox" class="alert alert-success text-center fw-bold">✅ Booking canceled successfully.</div>
  <?php elseif (isset($_GET['booked'])): ?>
    <div id="alertBox" class="alert alert-success text-center fw-bold">✅ Booking completed successfully!</div>
  <?php endif; ?>

  <div class="card mt-3">
    <h3 class="fw-bold text-center mb-3">🗂️ My Lessons</h3>
    <?php if (count($bookings) > 0): ?>
      <table class="table table-hover align-middle text-center">
        <thead class="table-primary">
          <tr>
            <th>Subject</th>
            <th>Date & Time</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($bookings as $b): ?>
          <tr>
            <td><?= htmlspecialchars($b['subject']) ?></td>
            <td><?= htmlspecialchars($b['booking_date']) ?></td>
            <td>
              <form method="POST" action="cancel_booking.php" style="display:inline;">
                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                <button type="submit" class="btn-cancel">Cancel</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="text-muted text-center mt-3">You have no bookings yet.</p>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const alertBox = document.getElementById('alertBox');
if (alertBox) {
  setTimeout(() => {
    alertBox.classList.add('fade-out');
    setTimeout(() => alertBox.remove(), 1000);
  }, 4000);
}
</script>
</body>
</html>
