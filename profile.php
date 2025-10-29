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
$stmt = $conn->prepare("SELECT name, grade, email, profile_pic FROM users WHERE id=?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($student_name, $student_grade, $student_email, $student_avatar);
$stmt->fetch();
$stmt->close();

$defaultAvatar = 'uploads/basic.jpg';
if (empty($student_avatar) || !file_exists($student_avatar)) {
    $student_avatar = $defaultAvatar;
}

// --- TIMETABLE MAPPING ---
$gradeMap = [
    '6A'=>'page_01.png','7'=>'page_02.png','8A'=>'page_03.png','9'=>'page_04.png',
    '10'=>'page_05.png','8B'=>'page_21.png','6B'=>'page_23.png'
];
$dpMap = [
    'afruza'=>'page_06.png','matvey'=>'page_07.png','alisher'=>'page_08.png','nigora'=>'page_09.png',
    'nozima'=>'page_10.png','ibrohim'=>'page_11.png','laylo'=>'page_12.png',
    'maftuna'=>'page_13.png','jasmin'=>'page_14.png','bassal'=>'page_15.png','javohir'=>'page_16.png',
    'mokhinur'=>'page_17.png','khonzoda'=>'page_18.png','said'=>'page_19.png','odilzhon'=>'page_20.png','sayidbek'=>'page_22.png'
];
$basePath = "timetable/";
$studentFirst = strtolower(explode(" ", $student_name)[0]);
$timetableFile = ($student_grade >= 11) ? ($dpMap[$studentFirst] ?? null) : ($gradeMap[$student_grade] ?? null);
$timetablePath = $timetableFile ? $basePath . $timetableFile : null;

// --- HANDLE AVATAR UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $fileName = basename($_FILES["avatar"]["name"]);
    $safeName = preg_replace("/[^a-zA-Z0-9_.-]/", "_", $fileName);
    $targetFile = $targetDir . time() . "_" . $safeName;
    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif'];
    if (in_array($fileType, $allowed) && $_FILES["avatar"]["size"] <= 5 * 1024 * 1024) {
        if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $targetFile)) {
            $stmt = $conn->prepare("UPDATE users SET profile_pic=? WHERE id=?");
            $stmt->bind_param("si", $targetFile, $student_id);
            $stmt->execute();
            $stmt->close();
            header("Location: profile.php?updated=1");
            exit();
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Student Profile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{font-family:'Montserrat',sans-serif;background:#e6f4fa;margin:0;}
.navbar{background:#8cbce6 !important;border-radius:12px;margin-bottom:20px;}
.profile-card{background:#d0e8f9;border-radius:15px;box-shadow:0 8px 25px rgba(0,0,0,0.1);padding:30px;text-align:center;}
.avatar-lg{width:140px;height:140px;border-radius:50%;border:4px solid #5aa0e0;object-fit:cover;margin-bottom:10px;}
.btn-primary{background:#5aa0e0;border:none;color:#fff;border-radius:10px;transition:0.3s;}
.btn-primary:hover{background:#337fc1;transform:scale(1.05);}
.profile-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:none;background:none;}
.dropdown-menu{border-radius:12px;box-shadow:0 4px 10px rgba(0,0,0,0.1);}
.modal-body img{max-width:100%;height:auto;border-radius:12px;transition:transform 0.3s;}
.zoom-controls{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);display:flex;gap:10px;}
.zoom-btn{background:#5aa0e0;color:white;border:none;border-radius:50%;width:45px;height:45px;font-size:22px;}
.zoom-btn:hover{background:#337fc1;}
.download-btn{background:#5aa0e0;color:white;border:none;border-radius:8px;padding:6px 12px;transition:0.3s;}
.download-btn:hover{background:#337fc1;}
.date-picker{border-radius:8px;border:1px solid #ccc;padding:5px 10px;}
</style>
</head>
<body>

<nav class="navbar navbar-dark px-4 d-flex justify-content-between align-items-center">
  <span class="navbar-brand">👤 My Profile</span>
  <div class="dropdown">
    <button class="btn btn-light border-0 dropdown-toggle" data-bs-toggle="dropdown">
      <img src="<?= htmlspecialchars($student_avatar) ?>" onerror="this.src='uploads/basic.jpg';" class="profile-avatar" alt="Profile Picture">
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow">
      <li><a class="dropdown-item" href="profile.php">👤 Profile</a></li>
      <li><a class="dropdown-item" href="book.php">🗓️ Book a Lesson</a></li>
      <li><a class="dropdown-item" href="history.php">📘 Your Bookings</a></li>
      <li><a class="dropdown-item" href="reports.php">📊 Reports</a></li>
      <li><button class="dropdown-item" id="openSupport" type="button">💬 Support</button></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="logout.php">🚪 Logout</a></li>
    </ul>
  </div>
</nav>

<div class="container">
  <div class="profile-card mx-auto">
    <img src="<?= htmlspecialchars($student_avatar) ?>" onerror="this.src='uploads/basic.jpg';" class="avatar-lg" alt="Profile Picture">
    <h3 class="fw-bold mb-1"><?= htmlspecialchars($student_name) ?></h3>
    <p class="text-muted mb-2">Grade <?= htmlspecialchars($student_grade) ?></p>
    <p><strong>Email:</strong> <?= htmlspecialchars($student_email) ?></p>

    <form method="POST" enctype="multipart/form-data" class="mt-3">
      <input type="file" name="avatar" accept="image/*" required>
      <button class="btn btn-primary btn-sm mt-2">Change Avatar</button>
    </form>
    <?php if (isset($_GET['updated'])): ?>
      <div class="text-success mt-2">✅ Avatar updated successfully.</div>
    <?php endif; ?>

    <hr class="my-4">

    <?php if ($timetablePath && file_exists($timetablePath)): ?>
      <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#timetableModal">📅 View Timetable</button>
    <?php else: ?>
      <p class="text-muted mt-2">No timetable available.</p>
    <?php endif; ?>
  </div>
</div>

<!-- TIMETABLE MODAL -->
<div class="modal fade" id="timetableModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content bg-dark text-center position-relative">
      <div class="modal-header border-0">
        <h5 class="modal-title text-white">Your Timetable</h5>
        <div>
          <?php if ($timetablePath): ?>
            <a href="<?= htmlspecialchars($timetablePath) ?>" download class="download-btn me-3">⬇️ Download</a>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">✖ Close</button>
        </div>
      </div>
      <div class="modal-body d-flex justify-content-center align-items-center">
        <?php if ($timetablePath): ?>
          <img id="zoomImage" src="<?= htmlspecialchars($timetablePath) ?>?t=<?= time() ?>" alt="Timetable Image">
        <?php else: ?>
          <p class="text-white">Timetable not found.</p>
        <?php endif; ?>
      </div>
      <div class="zoom-controls">
        <button class="zoom-btn" id="zoomIn">＋</button>
        <button class="zoom-btn" id="zoomOut">－</button>
      </div>
    </div>
  </div>
</div>

<footer class="text-center mt-4 text-muted mb-3">
  © <?= date('Y') ?> Correctional Lessons Portal — Empowering Students Through Guidance
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ✅ Support redirect
document.getElementById('openSupport').onclick = () => {
  window.location.href = 'support.php';
};

// Zoom controls
let zoom = 1; 
const img = document.getElementById('zoomImage');
if (img) {
  document.getElementById('zoomIn').onclick = () => { zoom += 0.2; img.style.transform = `scale(${zoom})`; };
  document.getElementById('zoomOut').onclick = () => { if (zoom > 0.4) { zoom -= 0.2; img.style.transform = `scale(${zoom})`; } };
}
</script>
</body>
</html>
