<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/* ---------------------------------
   🔐 Access Gate
--------------------------------- */
if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_email'] ?? '') !== 'admin@invento.uz') {
  http_response_code(403);
  exit("<div class='alert alert-danger m-3'>Access denied.</div>");
}

/* ---------------------------------
   🔧 Helpers
--------------------------------- */
function table_has_column(mysqli $conn, string $table, string $column): bool {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
    $result = $conn->query($sql);

    return ($result && $result->num_rows > 0);
}


function phpmailer_send_wrapper($to, $subject, $html, $alt='') {
  // Try to use project’s existing mail helper if available
  if (!function_exists('sendMail') && !function_exists('send_mail') && !function_exists('mailer_send')) {
    @include_once __DIR__.'/mailer.php';
    @include_once __DIR__.'/mail/mailer.php';
    @include_once __DIR__.'/includes/mailer.php';
    @include_once __DIR__.'/sendMail.php';
    @include_once __DIR__.'/mail/sendMail.php';
  }
  if (function_exists('sendMail'))    return sendMail($to, $subject, $html, $alt);
  if (function_exists('send_mail'))   return send_mail($to, $subject, $html, $alt);
  if (function_exists('mailer_send')) return mailer_send($to, $subject, $html, $alt);

  // Fallback (simple PHP mail)
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type: text/html; charset=UTF-8\r\n";
  $headers .= "From: Invento <no-reply@invento.uz>\r\n";
  return @mail($to, $subject, $html, $headers);
}

function log_booking_change(mysqli $conn, int $bookingId, string $adminEmail, string $action, $oldVal=null, $newVal=null) {
  $conn->query("CREATE TABLE IF NOT EXISTS booking_history (
      id INT AUTO_INCREMENT PRIMARY KEY,
      booking_id INT NOT NULL,
      admin_email VARCHAR(255),
      action VARCHAR(255),
      old_value TEXT,
      new_value TEXT,
      timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $stmt = $conn->prepare("INSERT INTO booking_history (booking_id, admin_email, action, old_value, new_value) VALUES (?,?,?,?,?)");
  $old = is_scalar($oldVal) ? (string)$oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE);
  $new = is_scalar($newVal) ? (string)$newVal : json_encode($newVal, JSON_UNESCAPED_UNICODE);
  $stmt->bind_param("issss", $bookingId, $adminEmail, $action, $old, $new);
  $stmt->execute();
  $stmt->close();
}

function add_log(mysqli $conn, $adminEmail, $userId, $action) {
  $conn->query("CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_email VARCHAR(255),
    user_id INT,
    role VARCHAR(20),
    action TEXT,
    log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )");
  $stmt = $conn->prepare("INSERT INTO logs (admin_email, role, user_id, action) VALUES (?, 'admin', ?, ?)");
  $stmt->bind_param("sis", $adminEmail, $userId, $action);
  $stmt->execute();
  $stmt->close();
}

/* ---------------------------------
   📥 Input
--------------------------------- */
$adminEmail = $_SESSION['admin_email'];
$bookingId  = intval($_GET['id'] ?? 0);
if ($bookingId <= 0) {
  exit("<div class='alert alert-danger m-3'>Invalid booking ID.</div>");
}

/* ---------------------------------
   🧭 Schema detection
--------------------------------- */
$hasTeacherId = table_has_column($conn, 'bookings', 'teacher_id');
$hasStatus    = table_has_column($conn, 'bookings', 'status'); // you confirmed this exists

// Allowed statuses per your enum
$ALLOWED_STATUS = ['booked','visited','canceled','not attended'];

/* ---------------------------------
   ⚠️ Warnings table (ensure)
--------------------------------- */
$conn->query("CREATE TABLE IF NOT EXISTS student_warnings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  reason TEXT,
  issued_by VARCHAR(255),
  issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ---------------------------------
   🧾 Fetch booking
--------------------------------- */
$sql = "
  SELECT b.id, b.subject, b.booking_date, u.id AS user_id, u.name, u.grade, u.email AS student_email
  ".($hasTeacherId ? ", b.teacher_id" : "")."
  ".($hasStatus    ? ", b.status"     : "")."
  FROM bookings b
  JOIN users u ON b.user_id = u.id
  WHERE b.id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $bookingId);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) exit("<div class='alert alert-warning m-3'>Booking not found.</div>");

/* ---------------------------------
   👪 Parent emails
--------------------------------- */
$parentEmails = [];
$pq = $conn->prepare("SELECT parent_email FROM parents WHERE student_id=? AND parent_email IS NOT NULL AND parent_email<>''");
$pq->bind_param("i", $booking['user_id']);
$pq->execute();
$pr = $pq->get_result();
while ($row = $pr->fetch_assoc()) {
  if (filter_var($row['parent_email'], FILTER_VALIDATE_EMAIL)) $parentEmails[] = $row['parent_email'];
}
$pq->close();

/* ---------------------------------
   🧑‍🏫 Teachers list (if teacher_id exists)
--------------------------------- */
$teachers = [];
if ($hasTeacherId) {
  $tr = $conn->query("SELECT id, name, email, grades FROM teachers ORDER BY name ASC");
  while ($t = $tr->fetch_assoc()) $teachers[] = $t;
}

/* ---------------------------------
   📨 POST actions
--------------------------------- */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  /* Update booking */
  if ($action === 'update') {
    $newSubject = trim($_POST['subject'] ?? $booking['subject']);
    $newDate    = trim($_POST['booking_date'] ?? date('Y-m-d H:i:s', strtotime($booking['booking_date'])));
    $newTeacher = $hasTeacherId ? intval($_POST['teacher_id'] ?? ($booking['teacher_id'] ?? 0)) : null;
    $newStatus  = $hasStatus ? trim($_POST['status'] ?? $booking['status'] ?? 'booked') : null;
    if ($hasStatus && !in_array($newStatus, $ALLOWED_STATUS, true)) {
      $newStatus = $booking['status'] ?? 'booked';
    }

    $oldSnapshot = $booking;

    if ($hasTeacherId && $hasStatus) {
      $upd = $conn->prepare("UPDATE bookings SET subject=?, booking_date=?, teacher_id=?, status=? WHERE id=?");
      $upd->bind_param("ssisi", $newSubject, $newDate, $newTeacher, $newStatus, $bookingId);
    } elseif ($hasTeacherId) {
      $upd = $conn->prepare("UPDATE bookings SET subject=?, booking_date=?, teacher_id=? WHERE id=?");
      $upd->bind_param("ssii", $newSubject, $newDate, $newTeacher, $bookingId);
    } elseif ($hasStatus) {
      $upd = $conn->prepare("UPDATE bookings SET subject=?, booking_date=?, status=? WHERE id=?");
      $upd->bind_param("sssi", $newSubject, $newDate, $newStatus, $bookingId);
    } else {
      $upd = $conn->prepare("UPDATE bookings SET subject=?, booking_date=? WHERE id=?");
      $upd->bind_param("ssi", $newSubject, $newDate, $bookingId);
    }
    $ok = $upd->execute();
    $upd->close();

    if ($ok) {
      log_booking_change($conn, $bookingId, $adminEmail, 'update',
        $oldSnapshot,
        [
          'subject'      => $newSubject,
          'booking_date' => $newDate,
          'teacher_id'   => $newTeacher,
          'status'       => $newStatus
        ]
      );
      add_log($conn, $adminEmail, $booking['user_id'], "Edited booking #{$bookingId} ({$newSubject})");
      header("Location: ".$_SERVER['REQUEST_URI']);
      exit;
    } else {
      $flash = ['type'=>'danger','msg'=>'Failed to update booking.'];
    }
  }

  /* Cancel booking (soft): status='canceled' */
  if ($action === 'cancel') {
    $reason = trim($_POST['reason'] ?? '');
    $old = $booking;

    if ($hasStatus) {
      $upd = $conn->prepare("UPDATE bookings SET status='canceled' WHERE id=?");
      $upd->bind_param("i", $bookingId);
      $ok = $upd->execute();
      $upd->close();
    } else {
      // No status column: keep the hard-delete fallback
      $del = $conn->prepare("DELETE FROM bookings WHERE id=?");
      $del->bind_param("i", $bookingId);
      $ok = $del->execute();
      $del->close();
    }

    if ($ok) {
      log_booking_change($conn, $bookingId, $adminEmail, 'cancel', $old, ['reason'=>$reason]);
      add_log($conn, $adminEmail, $booking['user_id'], "Cancelled booking #{$bookingId}");

      // email student + parents
      $subject = "Booking Canceled: ".$booking['subject'];
      $html = "<div style='font-family:Segoe UI,Arial,sans-serif'>
        <p>Hello,</p>
        <p>The booking for <strong>".htmlspecialchars($booking['subject'])."</strong> scheduled on <strong>".htmlspecialchars($booking['booking_date'])."</strong> has been <strong>canceled</strong>.</p>"
        .($reason !== '' ? "<p><em>Reason:</em> ".nl2br(htmlspecialchars($reason))."</p>" : "")
        ."<hr><small>— Invento</small></div>";
      $alt = "Booking canceled: {$booking['subject']} on {$booking['booking_date']}".($reason ? "\nReason: $reason" : "");

      $sentOk = 0; $targets = [];
      if (filter_var($booking['student_email'], FILTER_VALIDATE_EMAIL)) $targets[] = $booking['student_email'];
      $targets = array_merge($targets, $parentEmails);
      $targets = array_values(array_unique($targets));
      foreach ($targets as $to) if (phpmailer_send_wrapper($to, $subject, $html, $alt)) $sentOk++;

      if ($hasStatus) {
        header("Location: ".$_SERVER['REQUEST_URI']);
        exit;
      } else {
        header("Location: admin_dashboard.php#bookings");
        exit;
      }
    } else {
      $flash = ['type'=>'danger','msg'=>'Failed to cancel booking.'];
    }
  }

  /* Send reminder email */
  if ($action === 'send_reminder') {
    $subjectLine = "Reminder: ".$booking['subject']." on ".date('M j, Y H:i', strtotime($booking['booking_date']));
    $html = "<div style='font-family:Segoe UI,Arial,sans-serif'>
      <p>Hello ".htmlspecialchars($booking['name']).",</p>
      <p>This is a reminder for your booking:</p>
      <ul>
        <li><strong>Subject:</strong> ".htmlspecialchars($booking['subject'])."</li>
        <li><strong>Date & Time:</strong> ".htmlspecialchars($booking['booking_date'])."</li>
      </ul>
      <hr><small>— Invento</small></div>";
    $alt = "Reminder for booking\nSubject: {$booking['subject']}\nWhen: {$booking['booking_date']}";

    $targets = [];
    if (filter_var($booking['student_email'], FILTER_VALIDATE_EMAIL)) $targets[] = $booking['student_email'];
    $targets = array_merge($targets, $parentEmails);
    $targets = array_values(array_unique($targets));

    $sentOk = 0;
    foreach ($targets as $to) if (phpmailer_send_wrapper($to, $subjectLine, $html, $alt)) $sentOk++;

    log_booking_change($conn, $bookingId, $adminEmail, 'send_reminder', null, ['sent_to'=>$targets]);
    add_log($conn, $adminEmail, $booking['user_id'], "Sent reminder for booking #{$bookingId}");

    $flash = ['type'=>'success','msg'=>"Reminder sent to {$sentOk} recipient(s)."];
  }

  /* Add warning */
  if ($action === 'add_warning') {
    $reason = trim($_POST['warning_reason'] ?? '');
    if ($reason !== '') {
      $stmt = $conn->prepare("INSERT INTO student_warnings (student_id, reason, issued_by) VALUES (?,?,?)");
      $stmt->bind_param("iss", $booking['user_id'], $reason, $adminEmail);
      $stmt->execute();
      $stmt->close();
      log_booking_change($conn, $bookingId, $adminEmail, 'add_warning', null, ['student_id'=>$booking['user_id'], 'reason'=>$reason]);
      header("Location: ".$_SERVER['REQUEST_URI']);
      exit;
    } else {
      $flash = ['type'=>'danger','msg'=>'Warning reason required.'];
    }
  }
}

/* ---------------------------------
   📜 Load warnings & history (with filters)
--------------------------------- */
$period   = $_GET['period'] ?? 'week'; // week|month|custom
$startStr = $_GET['start']  ?? '';
$endStr   = $_GET['end']    ?? '';

if ($period === 'week') {
  $start = (new DateTime('monday this week'))->format('Y-m-d 00:00:00');
  $end   = (new DateTime('sunday this week'))->format('Y-m-d 23:59:59');
} elseif ($period === 'month') {
  $start = (new DateTime('first day of this month'))->format('Y-m-d 00:00:00');
  $end   = (new DateTime('last day of this month'))->format('Y-m-d 23:59:59');
} else {
  $start = $startStr ? date('Y-m-d 00:00:00', strtotime($startStr)) : '1970-01-01 00:00:00';
  $end   = $endStr   ? date('Y-m-d 23:59:59', strtotime($endStr))   : '2999-12-31 23:59:59';
}

$warnings = [];
$wr = $conn->prepare("SELECT id, reason, issued_by, issued_at FROM student_warnings WHERE student_id=? ORDER BY issued_at DESC");
$wr->bind_param("i", $booking['user_id']);
$wr->execute();
$wres = $wr->get_result();
while ($w = $wres->fetch_assoc()) $warnings[] = $w;
$wr->close();

// ensure booking_history exists
$conn->query("CREATE TABLE IF NOT EXISTS booking_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  admin_email VARCHAR(255),
  action VARCHAR(255),
  old_value TEXT,
  new_value TEXT,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$history = [];
$hr = $conn->prepare("SELECT admin_email, action, old_value, new_value, timestamp FROM booking_history WHERE booking_id=? AND timestamp BETWEEN ? AND ? ORDER BY id DESC");
$hr->bind_param("iss", $bookingId, $start, $end);
$hr->execute();
$hres = $hr->get_result();
while ($h = $hres->fetch_assoc()) $history[] = $h;
$hr->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Booking #<?= htmlspecialchars($bookingId) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f7f9fc;font-family:Segoe UI,system-ui,-apple-system,sans-serif}
  .card{box-shadow:0 2px 10px rgba(0,0,0,.06);border:none;border-radius:14px}
  .form-label{font-weight:600}
  code{white-space:pre-wrap}
</style>
</head>
<body class="p-3 p-md-4">

<div class="container">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Edit Booking #<?= htmlspecialchars($bookingId) ?></h3>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-secondary" href="admin_dashboard.php#bookings">← Back to Bookings</a>
      <a class="btn btn-outline-primary" href="admin_edit_student_profile.php?id=<?= intval($booking['user_id']) ?>">Student Profile</a>
      <a class="btn btn-outline-dark" href="admin_bookings_list.php">All Current Bookings</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Left: Edit form -->
    <div class="col-lg-7">
      <div class="card p-3 p-md-4">
        <h5 class="mb-3">Booking Details</h5>
        <form method="POST">
          <input type="hidden" name="action" value="update">
          <div class="mb-3">
            <label class="form-label">Student</label>
            <input type="text" class="form-control" disabled value="<?= htmlspecialchars($booking['name'].' (Grade '.$booking['grade'].')') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Subject</label>
            <input type="text" name="subject" class="form-control" required value="<?= htmlspecialchars($booking['subject']) ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Date & Time</label>
            <input type="datetime-local" name="booking_date" class="form-control" required value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($booking['booking_date']))) ?>">
          </div>

          <?php if ($hasTeacherId): ?>
          <div class="mb-3">
            <label class="form-label">Teacher</label>
            <select name="teacher_id" class="form-select">
              <option value="">— Not assigned —</option>
              <?php foreach ($teachers as $t): ?>
                <option value="<?= intval($t['id']) ?>" <?= (!empty($booking['teacher_id']) && intval($booking['teacher_id']) === intval($t['id']))?'selected':'' ?>>
                  <?= htmlspecialchars($t['name']) ?> <?= $t['grades'] ? ' ('.$t['grades'].')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if ($hasStatus): ?>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
              <?php
                $current = $booking['status'] ?? 'booked';
                foreach ($ALLOWED_STATUS as $st):
              ?>
                <option value="<?= $st ?>" <?= $st===$current?'selected':'' ?>><?= ucfirst($st) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary">💾 Save Changes</button>
            <button type="submit" name="action" value="send_reminder" class="btn btn-outline-primary">📨 Send Reminder</button>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">🗑 Cancel Booking</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Right: Warnings + History -->
    <div class="col-lg-5">
      <div class="card p-3 p-md-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Student Warnings</h5>
          <a class="btn btn-sm btn-outline-secondary" href="admin_student_warnings.php?id=<?= intval($booking['user_id']) ?>">Warnings Page</a>
        </div>

        <?php if (count($warnings) === 0): ?>
          <div class="alert alert-success">No warnings for this student.</div>
        <?php else: ?>
          <ul class="list-group mb-3">
            <?php foreach ($warnings as $w): ?>
              <li class="list-group-item">
                <div class="small text-muted float-end"><?= htmlspecialchars($w['issued_at']) ?></div>
                <div><strong>By:</strong> <?= htmlspecialchars($w['issued_by'] ?: '—') ?></div>
                <div><strong>Reason:</strong> <?= nl2br(htmlspecialchars($w['reason'])) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="action" value="add_warning">
          <label class="form-label">Add Warning</label>
          <textarea name="warning_reason" class="form-control mb-2" rows="2" placeholder="Reason..."></textarea>
          <button class="btn btn-warning">➕ Add Warning</button>
        </form>
      </div>

      <div class="card p-3 p-md-4">
        <div class="d-flex align-items-center justify-content-between">
          <h5 class="mb-0">History</h5>
          <form class="d-flex gap-2 align-items-center" method="GET">
            <input type="hidden" name="id" value="<?= intval($bookingId) ?>">
            <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="week"  <?= $period==='week'?'selected':''  ?>>This week</option>
              <option value="month" <?= $period==='month'?'selected':'' ?>>This month</option>
              <option value="custom"<?= $period==='custom'?'selected':''?>>Custom</option>
            </select>
            <?php if ($period==='custom'): ?>
              <input type="date" name="start" class="form-control form-control-sm" value="<?= htmlspecialchars($startStr) ?>">
              <input type="date" name="end"   class="form-control form-control-sm" value="<?= htmlspecialchars($endStr) ?>">
              <button class="btn btn-sm btn-outline-secondary">Apply</button>
            <?php endif; ?>
          </form>
        </div>

        <?php if (count($history) === 0): ?>
          <div class="alert alert-light mt-3">No history logs for the selected period.</div>
        <?php else: ?>
          <div class="table-responsive mt-3">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr><th>When</th><th>Admin</th><th>Action</th><th>Details</th></tr>
              </thead>
              <tbody>
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td class="text-nowrap"><?= htmlspecialchars($h['timestamp']) ?></td>
                    <td><?= htmlspecialchars($h['admin_email']) ?></td>
                    <td><?= htmlspecialchars($h['action']) ?></td>
                    <td class="small">
                      <?php
                        $old = $h['old_value']; $new = $h['new_value'];
                        $oj = json_decode($old, true);
                        $nj = json_decode($new, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($nj)) {
                          echo "<div><strong>New:</strong> <code>".htmlspecialchars(json_encode($nj, JSON_UNESCAPED_UNICODE))."</code></div>";
                        } else if ($new) {
                          echo "<div><strong>New:</strong> ".nl2br(htmlspecialchars($new))."</div>";
                        }
                        if (json_last_error() === JSON_ERROR_NONE && is_array($oj)) {
                          echo "<div><strong>Old:</strong> <code>".htmlspecialchars(json_encode($oj, JSON_UNESCAPED_UNICODE))."</code></div>";
                        } else if ($old) {
                          echo "<div><strong>Old:</strong> ".nl2br(htmlspecialchars($old))."</div>";
                        }
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Cancel modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="cancel">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Cancel Booking #<?= intval($bookingId) ?></h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to cancel this booking?</p>
        <label class="form-label">Reason (optional)</label>
        <textarea class="form-control" name="reason" rows="3" placeholder="Reason for cancellation..."></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Close</button>
        <button class="btn btn-danger" type="submit">Cancel Booking</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
