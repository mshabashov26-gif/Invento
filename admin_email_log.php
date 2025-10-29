<?php
include('db_connect.php');
$sql = "SELECT l.*, t.name AS teacher_name 
        FROM teacher_email_logs l 
        LEFT JOIN teachers t ON l.teacher_id = t.id
        ORDER BY l.sent_at DESC";
$result = $conn->query($sql);
?>

<?php
include('db_connect.php');

$sql = "SELECT l.*, t.name AS teacher_name 
        FROM teacher_email_logs l 
        LEFT JOIN teachers t ON l.teacher_id = t.id 
        WHERE 1";

// 🔍 Apply filters
if (!empty($_GET['teacher'])) $sql .= " AND t.name LIKE '%" . $conn->real_escape_string($_GET['teacher']) . "%'";
if (!empty($_GET['student'])) $sql .= " AND l.student_name LIKE '%" . $conn->real_escape_string($_GET['student']) . "%'";
if (!empty($_GET['email'])) $sql .= " AND l.parent_email LIKE '%" . $conn->real_escape_string($_GET['email']) . "%'";
if (!empty($_GET['grade'])) $sql .= " AND l.student_grade = '" . $conn->real_escape_string($_GET['grade']) . "'";
if (!empty($_GET['language'])) $sql .= " AND l.language = '" . $conn->real_escape_string($_GET['language']) . "'";
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $sql .= " AND DATE(l.sent_at) BETWEEN '" . $_GET['start_date'] . "' AND '" . $_GET['end_date'] . "'";
}

$sql .= " ORDER BY l.sent_at DESC";
$result = $conn->query($sql);
?>

<h3>📧 Email Logs</h3>

<!-- 🔎 Search & Filter Form -->
<form method="GET" class="row g-2 mb-3">
  <div class="col-md-2"><input type="text" name="teacher" class="form-control" placeholder="Teacher" value="<?= $_GET['teacher'] ?? '' ?>"></div>
  <div class="col-md-2"><input type="text" name="student" class="form-control" placeholder="Student" value="<?= $_GET['student'] ?? '' ?>"></div>
  <div class="col-md-2"><input type="text" name="email" class="form-control" placeholder="Parent Email" value="<?= $_GET['email'] ?? '' ?>"></div>
  <div class="col-md-1"><input type="text" name="grade" class="form-control" placeholder="Grade" value="<?= $_GET['grade'] ?? '' ?>"></div>
  <div class="col-md-2">
    <select name="language" class="form-select">
      <option value="">Language</option>
      <option value="en">English</option>
      <option value="ru">Russian</option>
      <option value="uz">Uzbek</option>
    </select>
  </div>
  <div class="col-md-2"><input type="date" name="start_date" class="form-control"></div>
  <div class="col-md-2"><input type="date" name="end_date" class="form-control"></div>
  <div class="col-md-12 d-flex gap-2 mt-2">
    <button class="btn btn-primary">🔎 Search</button>
    <a href="admin_email_log.php" class="btn btn-secondary">❌ Reset</a>
    <a href="email_export_csv.php" class="btn btn-success">📁 Export to Excel (CSV)</a>
  </div>
</form>

<!-- 📊 Results Table -->
<table class="table table-bordered table-striped">
  <thead>
    <tr>
      <th>Date</th>
      <th>Teacher</th>
      <th>Student</th>
      <th>Grade</th>
      <th>Parent Email</th>
      <th>Language</th>
      <th>Attachment</th>
      <th>Message</th>
      <th>Delete</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($result->num_rows > 0): ?>
      <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
          <td><?= $row['sent_at'] ?></td>
          <td><?= htmlspecialchars($row['teacher_name']) ?></td>
          <td><?= htmlspecialchars($row['student_name']) ?></td>
          <td><?= htmlspecialchars($row['student_grade']) ?></td>
          <td><?= htmlspecialchars($row['parent_email']) ?></td>
          <td><?= strtoupper($row['language']) ?></td>
          <td><?php if ($row['attachment_path']) echo "<a href='{$row['attachment_path']}' target='_blank'>📎 File</a>"; ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary viewMessageBtn"
                    data-message="<?= htmlspecialchars($row['message'], ENT_QUOTES) ?>"
                    data-student="<?= htmlspecialchars($row['student_name']) ?>"
                    data-teacher="<?= htmlspecialchars($row['teacher_name']) ?>">
              View
            </button>
          </td>
          <td><a href="email_delete.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this log?')">🗑</a></td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr><td colspan="9" class="text-center">No records found</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<!-- 📩 Message Preview Modal -->
<div class="modal fade" id="messageModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">📨 Sent Message</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p><strong>Teacher:</strong> <span id="modalTeacher"></span></p>
        <p><strong>Student:</strong> <span id="modalStudent"></span></p>
        <hr>
        <div id="modalMessageContent" style="white-space:pre-wrap;"></div>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.viewMessageBtn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.getElementById('modalTeacher').innerText = btn.dataset.teacher;
    document.getElementById('modalStudent').innerText = btn.dataset.student;
    document.getElementById('modalMessageContent').innerHTML = btn.dataset.message;
    new bootstrap.Modal(document.getElementById('messageModal')).show();
  });
});
</script>
