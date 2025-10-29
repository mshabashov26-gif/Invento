<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/* -----------------------------------
   🔒 Persistent Login
----------------------------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $conn->prepare("SELECT id, name, email, grades, course, avatar FROM teachers WHERE remember_token=? LIMIT 1");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            $t = $res->fetch_assoc();
            $_SESSION['user_id'] = $t['id'];
            $_SESSION['role'] = 'teacher';
            $_SESSION['name'] = $t['name'];
            $_SESSION['email'] = $t['email'];
        } else { header("Location: index.php"); exit(); }
        $stmt->close();
    } else { header("Location: index.php"); exit(); }
}

$teacher_id = $_SESSION['user_id'];

/* -----------------------------------
   🗓️ Timetable Maps
----------------------------------- */
$gradeMap = [
    '6A'=>'page_01.png','7'=>'page_02.png','8A'=>'page_03.png','9'=>'page_04.png',
    '10'=>'page_05.png','8B'=>'page_21.png','6B'=>'page_23.png'
];
$dpMapRaw = [
    'Afruza Yusufalieva'=>'page_06.png','Matvey Shabashov'=>'page_07.png',
    'Alisherkhoja Kattahadjaev'=>'page_08.png','Nigora Kudratillayeva'=>'page_09.png',
    'Nozimakhon Bakhtiyorova'=>'page_10.png','Ibrahim Khalimov'=>'page_11.png',
    'Laylokhon Valijonova'=>'page_12.png','Maftuna Erkinova'=>'page_13.png',
    'Jasmin Berdiyeva'=>'page_14.png','Muhammad Basel Hassan'=>'page_15.png',
    'Javokhir Rakhmadjonov'=>'page_16.png','Mokhinur Eshmukhamedova'=>'page_17.png',
    'Khonzodakhon Mamurova'=>'page_18.png','Said Al-Barr Akbarov'=>'page_19.png',
    'Odijon Mukhtorov'=>'page_20.png','Sayidbek Komiljonov'=>'page_22.png'
];
$dpMap = [];
foreach ($dpMapRaw as $k => $v) $dpMap[strtolower(trim($k))] = $v;

/* -----------------------------------
   👤 Teacher Data
----------------------------------- */
$stmt = $conn->prepare("SELECT name, email, grades, course, avatar FROM teachers WHERE id=?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$stmt->bind_result($teacherName, $teacherEmail, $teacherGrades, $teacherCourses, $teacherAvatar);
$stmt->fetch();
$stmt->close();
$teacherAvatar = !empty($teacherAvatar) && file_exists($teacherAvatar) ? $teacherAvatar : 'uploads/basic.jpg';

/* -----------------------------------
   🧮 Grade Filtering
----------------------------------- */
$allowedGrades = [];
if (!empty($teacherGrades)) {
    foreach (preg_split('/[,\s]+/', $teacherGrades) as $part) {
        if (str_contains($part, '-')) {
            [$s,$e] = explode('-', $part);
            for ($i=$s; $i<=$e; $i++) $allowedGrades[]=(string)$i;
        } else $allowedGrades[] = trim($part);
    }
}
$allowedGrades = array_unique(array_filter($allowedGrades));

/* -----------------------------------
   📚 Subjects & Bookings
----------------------------------- */
$teacherSubjects = preg_split('/[\n,]+/', (string)$teacherCourses);
$teacherSubjects = array_values(array_filter(array_map('trim', $teacherSubjects)));
$lowerSubjects   = array_map('strtolower', $teacherSubjects);

$bookings = [];
if (!empty($lowerSubjects)) {
    $marks = implode(',', array_fill(0, count($lowerSubjects), '?'));
    $sql = "SELECT id, student_name, student_grade, subject, booking_date, attendance, teacher_comment 
            FROM bookings WHERE LOWER(subject) IN ($marks) ORDER BY booking_date ASC";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($lowerSubjects));
    $stmt->bind_param($types, ...$lowerSubjects);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* -----------------------------------
   👨‍🎓 Students
----------------------------------- */
if (!empty($allowedGrades)) {
    $p = implode(',', array_fill(0, count($allowedGrades), '?'));
    $sql = "SELECT id, name, grade, email FROM users WHERE grade IN ($p) ORDER BY grade ASC, name ASC";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($allowedGrades));
    $stmt->bind_param($types, ...$allowedGrades);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else $students = [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Teacher Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#eef5f9;font-family:'Segoe UI',sans-serif;}
.navbar{background:#3d73dd;}
.navbar-brand{color:#fff!important;font-weight:600;}
.profile-card{background:#fff;border-radius:15px;padding:25px;box-shadow:0 6px 18px rgba(0,0,0,0.1);}
.avatar{width:130px;height:130px;border-radius:50%;object-fit:cover;margin-bottom:10px;border:3px solid #3d73dd;}
.booking-card{background:#f8fbff;border:1px solid #dde6f1;border-radius:10px;padding:15px;margin-bottom:10px;}
.note-box{width:100%;font-size:14px;padding:4px;border-radius:6px;border:1px solid #ccc;resize:vertical;}
.save-msg{font-size:12px;color:green;display:none;}
.attendance-select{border:1px solid #ccc;border-radius:5px;padding:3px 5px;}
</style>
</head>

<body>
<nav class="navbar navbar-dark px-4 mb-4 d-flex justify-content-between">
  <span class="navbar-brand">Teacher Dashboard</span>
  <div class="d-flex align-items-center">
    <div class="dropdown">
      <button class="btn btn-light border-0 dropdown-toggle" data-bs-toggle="dropdown">
        <img src="<?= htmlspecialchars($teacherAvatar) ?>" class="rounded-circle" width="40" height="40">
      </button>
      <ul class="dropdown-menu dropdown-menu-end shadow">
        <li><a class="dropdown-item" href="teacher_warnings_log.php">⚠️ Sent Warnings</a></li>
        <li><a class="dropdown-item" href="teacher_email_log.php">📧 Email Log</a></li>
        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#emailModal">📨 Send Reports</button></li>
        <li><a class="dropdown-item text-danger" href="logout.php">🚪 Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container">
  <div class="profile-card text-center mb-4">
    <img src="<?= htmlspecialchars($teacherAvatar) ?>" class="avatar">
    <h3><?= htmlspecialchars($teacherName) ?></h3>
    <p><strong>Email:</strong> <?= htmlspecialchars($teacherEmail) ?></p>
    <p><strong>Courses:</strong> <?= htmlspecialchars(implode(", ", $teacherSubjects)) ?></p>
    <p><strong>Grades:</strong> <?= htmlspecialchars($teacherGrades) ?></p>
  </div>

  <!-- 📘 BOOKINGS SECTION -->
  <div class="card p-3 mb-4 shadow-sm">
    <h4>📅 Bookings with Notes</h4>
    <?php if (!empty($bookings)): foreach ($bookings as $b): ?>
      <div class="booking-card">
        <strong><?= htmlspecialchars($b['student_name']) ?></strong> (Grade <?= htmlspecialchars($b['student_grade']) ?>)<br>
        Subject: <?= htmlspecialchars($b['subject']) ?><br>Time: <?= htmlspecialchars($b['booking_date']) ?><br>
        Status: 
        <select class="attendance-select" data-id="<?= $b['id'] ?>">
          <option value="booked" <?= $b['attendance']=='booked'?'selected':'' ?>>Booked</option>
          <option value="visited" <?= $b['attendance']=='visited'?'selected':'' ?>>Visited</option>
          <option value="not attended" <?= $b['attendance']=='not attended'?'selected':'' ?>>Not Attended</option>
          <option value="canceled" <?= $b['attendance']=='canceled'?'selected':'' ?>>Canceled</option>
        </select>

        <div class="mt-2">
          <label class="form-label mb-1">📝 Comment</label>
       <textarea class="note-box" data-id="<?= $b['id'] ?>"><?= htmlspecialchars($b['teacher_comment'] ?? '') ?></textarea>

          <div class="save-msg" id="saveMsg<?= $b['id'] ?>">Saved ✓</div>
        </div>
      </div>
    <?php endforeach; else: ?><p>No bookings available.</p><?php endif; ?>
  </div>

  <!-- 👩‍🎓 STUDENTS -->
  <div class="card p-3 shadow-sm">
    <h4>👩‍🎓 Students</h4>
    <?php foreach($students as $s): ?>
      <div class="booking-card d-flex justify-content-between align-items-center">
        <div><strong><?= htmlspecialchars($s['name']) ?></strong> (Grade <?= htmlspecialchars($s['grade']) ?>)<br><?= htmlspecialchars($s['email']) ?></div>
        <div class="d-flex gap-2">
          <button class="btn btn-warning btn-sm send-warning" data-id="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['name']) ?>">⚠️ Warning</button>
          <a href="teacher_report_pdf.php?student_id=<?= $s['id'] ?>" target="_blank" class="btn btn-success btn-sm">📄 Report</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ⚠️ Warning Modal -->
<div class="modal fade" id="warningModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title">⚠️ Send Warning</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="warningForm">
          <input type="hidden" name="student_id" id="warnStudentId">
          <div class="mb-3">
            <label class="form-label">Student</label>
            <input type="text" class="form-control" id="warnStudentName" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Warning Message</label>
            <textarea name="warning_text" class="form-control" rows="3" placeholder="Write the reason..."></textarea>
          </div>
          <button type="submit" class="btn btn-warning w-100">Send Warning</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 🟢 Attendance change autosave
document.querySelectorAll('.attendance-select').forEach(sel=>{
  sel.addEventListener('change', async ()=>{
    const fd=new FormData();
    fd.append('id',sel.dataset.id);
    fd.append('status',sel.value);
    await fetch('teacher_update_status.php',{method:'POST',body:fd});
  });
});

// 🟢 Comment autosave
document.querySelectorAll('.note-box').forEach(el=>{
  el.addEventListener('change', async ()=>{
    const id=el.dataset.id,val=el.value;
    const fd=new FormData();fd.append('id',id);fd.append('comment',val);
    const r=await fetch('teacher_update_note.php',{method:'POST',body:fd});
    const d=await r.json();
    const msg=document.getElementById('saveMsg'+id);
    if(d.ok){msg.style.display='block';setTimeout(()=>msg.style.display='none',1500);}
  });
});

// ⚠️ Warning Modal
const warnModal=new bootstrap.Modal(document.getElementById('warningModal'));
document.querySelectorAll('.send-warning').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.getElementById('warnStudentId').value=btn.dataset.id;
    document.getElementById('warnStudentName').value=btn.dataset.name;
    warnModal.show();
  });
});
document.getElementById('warningForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const fd=new FormData(e.target);
  const r=await fetch('teacher_send_warning.php',{method:'POST',body:fd});
  const d=await r.json();
  if(d.ok){alert('Warning sent successfully');warnModal.hide();}
  else alert('Error sending warning');
});
</script>
</body>
</html>
