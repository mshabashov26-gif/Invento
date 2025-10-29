<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/* -------------------------------
   🔒 Persistent Admin Login
--------------------------------*/
if (empty($_SESSION['admin_logged_in']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("SELECT email FROM admins WHERE remember_token=? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($email);
    if ($stmt->fetch() && $email === 'admin@invento.uz') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_email'] = $email;
    }
    $stmt->close();
}

/* -------------------------------
   🚫 Access Gate
--------------------------------*/
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
    header("Location: index.php");
    exit();
}

/* -------------------------------
   🧾 Log admin access
--------------------------------*/
$conn->query("CREATE TABLE IF NOT EXISTS logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_email VARCHAR(255),
  user_id INT,
  role VARCHAR(20),
  action TEXT,
  log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$log = $conn->prepare("INSERT INTO logs (admin_email, role, action) VALUES (?, 'admin', 'Viewed Admin Dashboard')");
$log->bind_param("s", $_SESSION['admin_email']);
$log->execute();
$log->close();

/* -------------------------------
   📊 Basic Users & Bookings Data
--------------------------------*/
$order = $_GET['order'] ?? 'grade';
$dir = ($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$valid = ['grade','name','created_at','id'];
if (!in_array($order, $valid)) $order = 'grade';

$users = $conn->query("SELECT id, name, email, grade, created_at FROM users ORDER BY $order $dir");

/* ✅ BOOKINGS TABLE DATA */
$bookings = $conn->query("
  SELECT b.id, u.name, u.grade, b.subject, b.booking_date 
  FROM bookings b 
  JOIN users u ON b.user_id = u.id
  ORDER BY b.booking_date DESC
");
/* ===== Booking status analytics ===== */
$statusCounts = [
  'booked'        => 0,
  'visited'       => 0,
  'canceled'      => 0,
  'not attended'  => 0,
];

$statRes = $conn->query("SELECT status, COUNT(*) AS total FROM bookings GROUP BY status");
if ($statRes) {
  while ($r = $statRes->fetch_assoc()) {
    $s = trim(strtolower($r['status'] ?? ''));
    if ($s === 'cancelled') $s = 'canceled';
    if ($s === 'not_attended' || $s === 'not-attended') $s = 'not attended';
    if (isset($statusCounts[$s])) {
      $statusCounts[$s] += (int)$r['total'];
    }
  }
}

$totalBookings = array_sum($statusCounts);
$percentages = [];
foreach ($statusCounts as $k => $v) {
  $percentages[$k] = $totalBookings > 0 ? round(($v / $totalBookings) * 100, 1) : 0;
}

$chartLabels   = json_encode(array_keys($statusCounts));
$chartCounts   = json_encode(array_values($statusCounts));
$chartPercents = json_encode(array_values($percentages));


/* ✅ PARENTS WITH STUDENT GRADES (one canonical build) */
$parentList = [];
$resP = $conn->query("
  SELECT 
    p.parent_email, 
    p.student_id, 
    u.name AS student_name, 
    u.grade AS student_grade
  FROM parents p
  JOIN users u ON u.id = p.student_id
  ORDER BY u.grade ASC, u.name ASC
");
while ($r = $resP->fetch_assoc()) {
    $parentList[] = $r;
}

/* recent logs list (kept) */
$logs = $conn->query("SELECT role, admin_email, user_id, action, log_time FROM logs ORDER BY id DESC LIMIT 250");

/* -------------------------------
   📧 Teacher Email Logs + Filters
--------------------------------*/
$conn->query("CREATE TABLE IF NOT EXISTS teacher_email_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  student_id INT NULL,
  student_name VARCHAR(255) NOT NULL,
  student_grade VARCHAR(20) NOT NULL,
  parent_email VARCHAR(255) NOT NULL,
  language ENUM('en','ru','uz') DEFAULT 'en',
  message TEXT,
  attachment_path VARCHAR(255),
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

/* Load Teachers */
$teacherList = [];
$resT = $conn->query("SELECT id, name, grades FROM teachers ORDER BY name ASC");
while ($r = $resT->fetch_assoc()) $teacherList[] = $r;

/* Load Students */
$studentList = [];
$resS = $conn->query("SELECT id, name, grade FROM users ORDER BY grade ASC, name ASC");
while ($r = $resS->fetch_assoc()) $studentList[] = $r;

/* Get Filter Values */
$elog_teacher_ids = $_GET['elog_teacher_ids'] ?? [];
$elog_student_ids = $_GET['elog_student_ids'] ?? [];
$elog_emails      = $_GET['elog_emails']      ?? [];
$elog_grades      = $_GET['elog_grades']      ?? [];
$elog_languages   = $_GET['elog_languages']   ?? [];
$elog_start       = trim($_GET['elog_start'] ?? '');
$elog_end         = trim($_GET['elog_end'] ?? '');

/* Prepare SQL for logs table (note: logs table column is student_grade) */
$elog_sql = "SELECT l.*, t.name AS teacher_name
             FROM teacher_email_logs l
             LEFT JOIN teachers t ON t.id = l.teacher_id
             WHERE 1";

if (!empty($elog_teacher_ids)) {
  $ids = implode(',', array_map('intval', $elog_teacher_ids));
  $elog_sql .= " AND l.teacher_id IN ($ids)";
}
if (!empty($elog_student_ids)) {
  $ids = implode(',', array_map('intval', $elog_student_ids));
  $elog_sql .= " AND l.student_id IN ($ids)";
}
if (!empty($elog_emails)) {
  $safe = array_map(fn($e)=>"'".$conn->real_escape_string($e)."'", $elog_emails);
  $elog_sql .= " AND l.parent_email IN (".implode(',', $safe).")";
}
if (!empty($elog_grades)) {
  $safe = array_map(fn($g)=>"'".$conn->real_escape_string($g)."'", $elog_grades);
  $elog_sql .= " AND l.student_grade IN (".implode(',', $safe).")";
}
if (!empty($elog_languages)) {
  $safe = array_map(fn($g)=>"'".$conn->real_escape_string($g)."'", $elog_languages);
  $elog_sql .= " AND l.language IN (".implode(',', $safe).")";
}
if ($elog_start && $elog_end) {
  $elog_sql .= " AND DATE(l.sent_at) BETWEEN '".$conn->real_escape_string($elog_start)."' AND '".$conn->real_escape_string($elog_end)."'";
}
$elog_sql .= " ORDER BY l.sent_at DESC";
$elog_res = $conn->query($elog_sql);

/* Keep filters for Export CSV */
$elog_query_str = http_build_query([
  'elog_teacher_ids' => $elog_teacher_ids,
  'elog_student_ids' => $elog_student_ids,
  'elog_emails'      => $elog_emails,
  'elog_grades'      => $elog_grades,
  'elog_languages'   => $elog_languages,
  'elog_start'       => $elog_start,
  'elog_end'         => $elog_end
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<style>
body { background: #f7f9fc; font-family: 'Segoe UI', sans-serif; }
.navbar { background: #3D90D7; }
.navbar-brand, .navbar a { color: white !important; }

/* Tables */
.table thead th { background: #eef3ff; cursor: pointer; }
.table-hover tbody tr:hover { background: #f5f9ff; }

/* Editable cells */
td.editable { cursor: pointer; transition: 0.2s; }
td.editable:hover { background: #eef4ff; }

/* Select2 Styling */
.select2-container .select2-selection--single { height: 36px; padding: 4px 8px; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 28px; }
.select2-container .select2-selection--multiple { min-height: 36px; }

/* Email Logs Styling */
#emailLogs form {
  background: white;
  padding: 16px;
  border-radius: 8px;
  box-shadow: 0px 2px 8px rgba(0,0,0,0.05);
}
#emailLogs th.sortable { cursor:pointer; }
#emailLogs th.sortable.asc::after { content:' ▲'; color:#007bff; }
#emailLogs th.sortable.desc::after { content:' ▼'; color:#007bff; }
</style>
</head>

<body>
<nav class="navbar navbar-dark px-4 d-flex justify-content-between">
  <span class="navbar-brand">Admin Panel</span>
  <div>
    <a class="btn btn-outline-light me-2" data-bs-toggle="modal" data-bs-target="#changePassModal">Change Password</a>
    <a class="btn btn-outline-light" href="admin_logout.php">Logout</a>
  </div>
</nav>

<div class="container mt-4">
  <ul class="nav nav-tabs" id="adminTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-target="#users" data-bs-toggle="tab">Users</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-target="#bookings" data-bs-toggle="tab">Bookings</button></li>
    <li class="nav-item">
    <li class="nav-item"><button class="nav-link" data-bs-target="#logs" data-bs-toggle="tab">Logs</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-target="#emailLogs" data-bs-toggle="tab">Email Logs</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-target="#analytics" data-bs-toggle="tab">Analytics</button></li>
  </ul>

  <div class="tab-content pt-3">

    <!-- ✅ USERS TAB -->
    <div class="tab-pane fade show active" id="users">
      <div class="d-flex justify-content-between mb-3">
        <h4>All Users</h4>
        <input id="userSearch" name="userSearch" class="form-control w-auto" placeholder="Search...">
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle" id="usersTable">
          <thead>
            <tr>
              <th><a href="?order=id&dir=<?= ($dir==='ASC'?'DESC':'ASC') ?>">ID</a></th>
              <th><a href="?order=name&dir=<?= ($dir==='ASC'?'DESC':'ASC') ?>">Name</a></th>
              <th>Email</th>
              <th><a href="?order=grade&dir=<?= ($dir==='ASC'?'DESC':'ASC') ?>">Grade</a></th>
              <th><a href="?order=created_at&dir=<?= ($dir==='ASC'?'DESC':'ASC') ?>">Joined</a></th>
              <th>Profile</th>
            </tr>
          </thead>
          <tbody>
            <?php while($u = $users->fetch_assoc()): ?>
            <tr data-id="<?= $u['id'] ?>">
              <td><?= $u['id'] ?></td>
              <td class="editable" data-field="name"><?= htmlspecialchars($u['name']) ?></td>
              <td class="editable" data-field="email"><?= htmlspecialchars($u['email']) ?></td>
              <td class="editable" data-field="grade"><?= htmlspecialchars($u['grade']) ?></td>
              <td><?= $u['created_at'] ?></td>
            <td>
    <a class="btn btn-sm btn-primary" 
       href="admin_edit_student_profile.php?id=<?= $u['id'] ?>">
       Edit
    </a>
</td>

            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>


    <div class="tab-pane fade" id="logs">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>Recent Logs</h4>
    <small class="text-muted">Newest first • last 250</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Role</th>
          <th>Admin Email</th>
          <th>User ID</th>
          <th>Action</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($logs && $logs->num_rows > 0): ?>
          <?php while($l = $logs->fetch_assoc()): ?>
            <tr>
              <td><?= (int)$l['id'] ?></td>
              <td><?= htmlspecialchars($l['role']) ?></td>
              <td><?= htmlspecialchars($l['admin_email']) ?></td>
              <td><?= (int)$l['user_id'] ?></td>
              <td><?= htmlspecialchars($l['action']) ?></td>
              <td><?= htmlspecialchars($l['log_time']) ?></td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No logs yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>


    <!-- ✅ BOOKINGS TAB -->
    <div class="tab-pane fade" id="bookings">
      <div class="d-flex justify-content-between mb-3">
        <h4>Bookings</h4>
        <select id="sortBookings" name="sortBookings" class="form-select w-auto">
          <option value="time">Sort by Time</option>
          <option value="name">Sort by Name</option>
          <option value="subject">Sort by Subject</option>
        </select>
      </div>
      <div class="table-responsive">
        <table class="table table-striped" id="bookingsTable">
          <thead class="table-light"><tr>
            <th>ID</th><th>Name</th><th>Grade</th><th>Subject</th><th>When</th><th>Edit</th>
          </tr></thead>
          <tbody>
            <?php while($b = $bookings->fetch_assoc()): ?>
            <tr>
              <td><?= $b['id'] ?></td>
              <td><?= htmlspecialchars($b['name']) ?></td>
              <td><?= htmlspecialchars($b['grade']) ?></td>
              <td><?= htmlspecialchars($b['subject']) ?></td>
              <td><?= $b['booking_date'] ?></td>
             <td>
  <button 
    onclick="window.location.href='admin_edit_booking.php?id=<?= $b['id'] ?>'" 
    class="btn btn-sm btn-warning">
    Edit
  </button>
</td>

            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ✅ EMAIL LOGS TAB -->
    <div class="tab-pane fade" id="emailLogs">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>📧 Sent Email Logs</h4>
        <div class="d-flex gap-2">
          <a class="btn btn-success btn-sm" href="email_export_csv.php?<?= $elog_query_str ?>">⬇ Export CSV</a>
          <button class="btn btn-outline-secondary btn-sm" type="button" id="resetElogForm">Reset</button>
        </div>
      </div>

      <!-- ✅ FILTER FORM -->
      <form method="GET" id="elogForm" class="row g-3 mb-4" autocomplete="on">
        <!-- Grade -->
        <div class="col-md-2">
          <label class="form-label" for="elogGrades">Grade</label>
          <select name="elog_grades[]" id="elogGrades" class="form-select select2" multiple>
            <?php for($i=6;$i<=12;$i++): ?>
              <option value="<?= $i ?>" <?= in_array("$i",$elog_grades)?'selected':'' ?>><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <!-- Teacher -->
        <div class="col-md-3">
          <label class="form-label" for="elogTeachers">Teacher</label>
          <select name="elog_teacher_ids[]" id="elogTeachers" class="form-select select2" multiple>
            <?php foreach($teacherList as $t): ?>
              <option value="<?= $t['id'] ?>"
                      data-grades="<?= htmlspecialchars($t['grades']) ?>"
                      <?= in_array($t['id'], $elog_teacher_ids) ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['name']) ?> (<?= htmlspecialchars($t['grades']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Student -->
        <div class="col-md-3">
          <label class="form-label" for="elogStudents">Student</label>
          <select name="elog_student_ids[]" id="elogStudents" class="form-select select2" multiple>
            <?php foreach($studentList as $s): ?>
              <option value="<?= $s['id'] ?>"
                      data-grade="<?= htmlspecialchars($s['grade']) ?>"
                      <?= in_array($s['id'], $elog_student_ids) ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['grade']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Parent -->
        <div class="col-md-3">
          <label class="form-label" for="elogEmails">Parent Email</label>
          <select name="elog_emails[]" id="elogEmails" class="form-select select2" multiple>
            <?php foreach($parentList as $p): ?>
              <option value="<?= htmlspecialchars($p['parent_email']) ?>"
                      data-student-id="<?= (int)$p['student_id'] ?>"
                      data-grade="<?= htmlspecialchars($p['student_grade']) ?>">
                <?= htmlspecialchars($p['parent_email']) ?> — <?= htmlspecialchars($p['student_name']) ?> (<?= htmlspecialchars($p['student_grade']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Language -->
        <div class="col-md-2">
          <label class="form-label" for="elogLanguages">Language</label>
          <select name="elog_languages[]" id="elogLanguages" class="form-select select2" multiple>
            <option value="en" <?= in_array('en',$elog_languages)?'selected':'' ?>>English</option>
            <option value="ru" <?= in_array('ru',$elog_languages)?'selected':'' ?>>Russian</option>
            <option value="uz" <?= in_array('uz',$elog_languages)?'selected':'' ?>>Uzbek</option>
          </select>
        </div>
        <!-- Date -->
        <div class="col-md-2">
          <label class="form-label" for="elogStart">From</label>
          <input type="date" id="elogStart" name="elog_start" class="form-control" value="<?= htmlspecialchars($elog_start) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label" for="elogEnd">To</label>
          <input type="date" id="elogEnd" name="elog_end" class="form-control" value="<?= htmlspecialchars($elog_end) ?>">
        </div>
        <div class="col-12">
          <button class="btn btn-primary btn-sm" id="applyElogFilters">Apply Filters</button>
        </div>
      </form>
      <!-- ✅ EMAIL LOGS TABLE -->
      <div class="table-responsive">
        <table id="emailLogsTable" class="table table-bordered align-middle">
          <thead class="table-light">
            <tr>
              <th class="sortable" data-col="0" data-type="date">Date</th>
              <th class="sortable" data-col="1" data-type="text">Teacher</th>
              <th class="sortable" data-col="2" data-type="text">Student</th>
              <th class="sortable" data-col="3" data-type="number">Grade</th>
              <th class="sortable" data-col="4" data-type="text">Parent</th>
              <th>Message</th>
              <th>Attachment</th>
            </tr>
          </thead>
          <tbody>
            <?php while($log = $elog_res->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($log['sent_at']) ?></td>
              <td><?= htmlspecialchars($log['teacher_name']) ?></td>
              <td><?= htmlspecialchars($log['student_name']) ?></td>
              <td><?= htmlspecialchars($log['student_grade']) ?></td>
              <td><?= htmlspecialchars($log['parent_email']) ?></td>
              <td>
                <button class="btn btn-sm btn-outline-primary viewMessageBtn"
                  data-teacher="<?= htmlspecialchars($log['teacher_name']) ?>"
                  data-student="<?= htmlspecialchars($log['student_name']) ?>"
                  data-message="<?= htmlspecialchars($log['message']) ?>">
                  View
                </button>
              </td>
              <td>
                <?php if (!empty($log['attachment_path'])): ?>
                  <a href="<?= htmlspecialchars($log['attachment_path']) ?>" target="_blank">📎 Open</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>



    <!-- ✅ MODAL FOR VIEW EMAIL -->
    <div class="modal fade" id="elogMsgModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">📨 Email Content</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p><strong>Teacher:</strong> <span id="elogTeacher"></span></p>
            <p><strong>Student:</strong> <span id="elogStudent"></span></p>
            <div class="border p-3 rounded" id="elogMessage" style="white-space:pre-wrap;"></div>
          </div>
        </div>
      </div>
    </div>

 <div class="tab-pane fade" id="analytics">
  <div class="card p-4">
    <div class="d-flex justify-content-between align-items-center">
      <h4 class="mb-0">📊 Booking Status Overview</h4>
      <span class="badge bg-secondary">Total: <?= (int)$totalBookings ?></span>
    </div>

    <div class="table-responsive mt-3">
      <table class="table table-bordered">
        <thead class="table-light">
          <tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Percent</th></tr>
        </thead>
        <tbody>
          <tr><td>Booked</td><td class="text-end"><?= $statusCounts['booked'] ?></td><td class="text-end"><?= $percentages['booked'] ?>%</td></tr>
          <tr><td>Visited</td><td class="text-end"><?= $statusCounts['visited'] ?></td><td class="text-end"><?= $percentages['visited'] ?>%</td></tr>
          <tr><td>Not attended</td><td class="text-end"><?= $statusCounts['not attended'] ?></td><td class="text-end"><?= $percentages['not attended'] ?>%</td></tr>
          <tr><td>Canceled</td><td class="text-end"><?= $statusCounts['canceled'] ?></td><td class="text-end"><?= $percentages['canceled'] ?>%</td></tr>
        </tbody>
      </table>
    </div>

    <div class="row mt-4">
      <div class="col-md-6"><canvas id="statusPie"></canvas></div>
      <div class="col-md-6"><canvas id="statusBar"></canvas></div>
    </div>
  </div>
</div>


<!-- ✅ JS IMPORTS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
/* ===== Select2 Initialization ===== */
$(function() {
  $('.select2').select2({ width: '100%', allowClear: true });
});

/* ===== Reset Elog Form button (no CSP issues) ===== */
$('#resetElogForm').on('click', function () {
  const form = document.getElementById('elogForm');
  if (form) {
    form.reset();
    // Clear Select2 visual selection too
    $('#elogGrades, #elogTeachers, #elogStudents, #elogEmails, #elogLanguages').val(null).trigger('change');
  }
});



/* ✅ Dynamic Filtering of Teachers, Students, Parents by Grade */
$('#elogGrades').on('change', function () {
  const selectedGrades = $(this).val() || []; // e.g. ["7","8"]

  // ---- Filter Teachers by their grades string (supports "6-9,11")
  $('#elogTeachers option').each(function () {
    const teacherGrades = String($(this).data('grades') || '');
    const matches = selectedGrades.length === 0
      ? true
      : selectedGrades.some(g => {
          // match either exact number or ranges like 6-9
          // expand ranges quickly:
          const parts = teacherGrades.split(',').map(s => s.trim());
          for (const p of parts) {
            if (/^\d+$/.test(p)) {
              if (p === g) return true;
            } else if (/^\d+\-\d+$/.test(p)) {
              const [a,b] = p.split('-').map(Number);
              const gv = Number(g);
              if (gv >= a && gv <= b) return true;
            }
          }
          return false;
        });
    $(this).prop('disabled', !matches).toggle(matches);
  });
  $('#elogTeachers').select2();

  // ---- Filter Students by data-grade
  $('#elogStudents option').each(function () {
    const studentGrade = String($(this).data('grade') ?? '');
    const show = (selectedGrades.length === 0) || selectedGrades.includes(studentGrade);
    $(this).prop('disabled', !show).toggle(show);
  });
  $('#elogStudents').select2();

  // ---- Filter Parent Emails by data-grade (from users.grade via join)
  $('#elogEmails option').each(function () {
    const g = String($(this).data('grade') ?? '');
    const show = (selectedGrades.length === 0) || selectedGrades.includes(g);
    $(this).prop('disabled', !show).toggle(show);
  });
  $('#elogEmails').select2();
});

/* ===== Users table search ===== */
$('#userSearch').on('input', function () {
  const q = this.value.toLowerCase();
  $('#usersTable tbody tr').each(function () {
    const show = $(this).text().toLowerCase().includes(q);
    this.style.display = show ? '' : 'none';
  });
});

/* ===== Sort Bookings by Dropdown ===== */
document.getElementById('sortBookings').addEventListener('change', function() {
  const idx = this.value === 'subject' ? 3 : (this.value === 'name' ? 1 : 4);
  const rows = Array.from(document.querySelectorAll('#bookingsTable tbody tr'));
  rows.sort((a,b) => a.children[idx].innerText.localeCompare(b.children[idx].innerText));
  const tbody = document.querySelector('#bookingsTable tbody');
  rows.forEach(r => tbody.appendChild(r));
});

/* ===== Modal: View Email Message ===== */
$(document).on('click', '.viewMessageBtn', function () {
  $('#elogTeacher').text($(this).data('teacher') || '');
  $('#elogStudent').text($(this).data('student') || '');
  $('#elogMessage').text($(this).data('message') || '');
  const modal = new bootstrap.Modal(document.getElementById('elogMsgModal'));
  modal.show();
});

/* ===== Robust sorting for Email Logs (works inside tabs, no CSP issues) ===== */
(function () {
  const toNumber = (s) => {
    const n = parseInt(String(s).trim(), 10);
    return Number.isFinite(n) ? n : -Infinity; // empty → lowest
  };
  const toDateMs = (s) => {
    // Support "YYYY-MM-DD HH:MM:SS" by turning space into 'T'
    const cleaned = String(s).trim().replace(' ', 'T');
    const ms = Date.parse(cleaned);
    return Number.isFinite(ms) ? ms : -Infinity;
  };

  // Event delegation so binding works even if DOM changes / tab loads later
  $(document).on('click', '#emailLogsTable th.sortable', function () {
    const $th = $(this);
    const $table = $th.closest('table');
    const $tbody = $table.find('tbody');
    const rows = $tbody.find('tr').get();

    const colIndex = Number($th.data('col'));
    const type = $th.data('type') || 'text';

    // Toggle direction on this header only
    const ascending = !$th.hasClass('asc');
    $table.find('th.sortable').removeClass('asc desc');
    $th.addClass(ascending ? 'asc' : 'desc');

    const cmp = (a, b) => {
      let A = $(a).children().eq(colIndex).text().trim();
      let B = $(b).children().eq(colIndex).text().trim();

      if (type === 'number') {
        A = toNumber(A);
        B = toNumber(B);
        return ascending ? A - B : B - A;
      }
      if (type === 'date') {
        A = toDateMs(A);
        B = toDateMs(B);
        return ascending ? A - B : B - A;
      }
      // text
      return ascending ? A.localeCompare(B) : B.localeCompare(A);
    };

    rows.sort(cmp);
    $.each(rows, (_, row) => $tbody.append(row));
  });
})();

(function(){
  const labels   = <?= $chartLabels ?>;
  const counts   = <?= $chartCounts ?>;
  const percents = <?= $chartPercents ?>;

  new Chart(document.getElementById('statusPie'), {
    type: 'pie',
    data: { labels, datasets: [{ data: percents }] },
    options: { plugins: { legend: { position: 'bottom' } } }
  });

  new Chart(document.getElementById('statusBar'), {
    type: 'bar',
    data: { labels, datasets: [{ data: counts }] },
    options: { scales: { y: { beginAtZero: true } } }
  });
})();
</script>
</body>
</html>
