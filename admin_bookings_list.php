<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

// ✅ Admin access check
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_email'] !== 'admin@invento.uz') {
    exit("<div class='alert alert-danger m-3'>Access denied.</div>");
}

// ✅ Delete booking (Soft delete if status exists, otherwise remove)
if (isset($_POST['delete_booking'])) {
    $bookingId = intval($_POST['delete_booking']);
    $hasStatus = $conn->query("SHOW COLUMNS FROM bookings LIKE 'status'")->num_rows > 0;

    if ($hasStatus) {
        $stmt = $conn->prepare("UPDATE bookings SET status='cancelled' WHERE id=?");
        $stmt->bind_param("i", $bookingId);
    } else {
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id=?");
        $stmt->bind_param("i", $bookingId);
    }
    $stmt->execute();
    $stmt->close();
}

// ✅ Filters
$filterGrade = $_GET['grade'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterTeacher = $_GET['teacher'] ?? '';
$filterTime = $_GET['time'] ?? 'all';
$searchTerm = trim($_GET['search'] ?? '');

// ✅ Dynamic conditions
$conditions = [];
if ($filterGrade !== '') $conditions[] = "u.grade = '".$conn->real_escape_string($filterGrade)."'";
if ($filterStatus !== '') $conditions[] = "b.status = '".$conn->real_escape_string($filterStatus)."'";
if ($filterTeacher !== '') $conditions[] = "b.teacher_id = '".intval($filterTeacher)."'";

if ($filterTime === 'upcoming') $conditions[] = "b.booking_date >= NOW()";
elseif ($filterTime === 'past') $conditions[] = "b.booking_date < NOW()";

if ($searchTerm !== '') {
    $safe = $conn->real_escape_string($searchTerm);
    $conditions[] = "(u.name LIKE '%$safe%' OR b.subject LIKE '%$safe%')";
}

$whereSQL = count($conditions) ? ("WHERE ".implode(" AND ", $conditions)) : "";

// ✅ Teacher column exists?
$hasTeacherId = $conn->query("SHOW COLUMNS FROM bookings LIKE 'teacher_id'")->num_rows > 0;
$teacherJoin = $hasTeacherId ? "LEFT JOIN teachers t ON t.id=b.teacher_id" : "";

// ✅ Fetch bookings
$sql = "
 SELECT b.id, b.subject, b.booking_date, b.status,
        u.name AS student_name, u.grade,
        ".($hasTeacherId ? "t.name AS teacher_name," : "'-' AS teacher_name,")."
        u.id AS student_id
 FROM bookings b
 JOIN users u ON b.user_id=u.id
 $teacherJoin
 $whereSQL
 ORDER BY b.booking_date DESC
";
$bookings = $conn->query($sql);

// ✅ Fetch teacher list (for filters)
$teachers = [];
if ($hasTeacherId) {
    $tr = $conn->query("SELECT id, name FROM teachers ORDER BY name ASC");
    while ($t = $tr->fetch_assoc()) $teachers[] = $t;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bookings List</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">

<div class="container">
    <h3 class="mb-3">📚 Bookings Management</h3>
    <a href="admin_dashboard.php#bookings" class="btn btn-secondary mb-3">← Back to Dashboard</a>

    <!-- ✅ Filters -->
    <form method="GET" class="row g-2 mb-4">
        <div class="col-md-2">
            <select name="grade" class="form-select">
                <option value="">Grade (All)</option>
                <?php for ($i=6; $i<=12; $i++): ?>
                    <option value="<?= $i ?>" <?= $filterGrade==$i?'selected':'' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">Status (All)</option>
                <option value="booked" <?= $filterStatus=='booked'?'selected':'' ?>>Booked</option>
                <option value="visited" <?= $filterStatus=='visited'?'selected':'' ?>>Visited</option>
                <option value="not attended" <?= $filterStatus=='not attended'?'selected':'' ?>>Not Attended</option>
                <option value="cancelled" <?= $filterStatus=='cancelled'?'selected':'' ?>>Cancelled</option>
            </select>
        </div>

        <?php if ($hasTeacherId): ?>
        <div class="col-md-2">
            <select name="teacher" class="form-select">
                <option value="">Teacher (All)</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $filterTeacher==$t['id']?'selected':'' ?>>
                        <?= htmlspecialchars($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="col-md-2">
            <select name="time" class="form-select">
                <option value="all" <?= $filterTime=='all'?'selected':'' ?>>All Time</option>
                <option value="upcoming" <?= $filterTime=='upcoming'?'selected':'' ?>>Upcoming</option>
                <option value="past" <?= $filterTime=='past'?'selected':'' ?>>Past</option>
            </select>
        </div>

        <div class="col-md-3">
            <input type="text" name="search" class="form-control" placeholder="Search student or subject..." value="<?= htmlspecialchars($searchTerm) ?>">
        </div>

        <div class="col-md-1">
            <button class="btn btn-primary w-100">Filter</button>
        </div>
    </form>

    <!-- ✅ Bookings Table -->
    <div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Student</th>
                <th>Grade</th>
                <?php if ($hasTeacherId): ?><th>Teacher</th><?php endif; ?>
                <th>Subject</th>
                <th>Date & Time</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($bookings->num_rows == 0): ?>
            <tr><td colspan="8" class="text-center">No bookings found.</td></tr>
        <?php else: ?>
            <?php while($b = $bookings->fetch_assoc()): ?>
            <tr>
                <td><?= $b['id'] ?></td>
                <td><?= htmlspecialchars($b['student_name']) ?></td>
                <td><?= htmlspecialchars($b['grade']) ?></td>
                <?php if ($hasTeacherId): ?>
                <td><?= htmlspecialchars($b['teacher_name'] ?? '-') ?></td>
                <?php endif; ?>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><?= htmlspecialchars($b['booking_date']) ?></td>
                <td>
                    <?php
                    $badge = match($b['status']) {
                        'visited' => 'success',
                        'not attended' => 'danger',
                        'cancelled' => 'secondary',
                        default => 'primary',
                    };
                    ?>
                    <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($b['status'] ?? 'booked') ?></span>
                </td>
                <td>
                    <a href="admin_edit_booking.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <form method="POST" style="display:inline;">
                        <button name="delete_booking" value="<?= $b['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this booking?')">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</body>
</html>
