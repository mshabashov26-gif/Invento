<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

/**
 * -------------------------------------------
 *  AUTO LOGIN VIA COOKIE (your original logic)
 * -------------------------------------------
 */
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];

    // Try student
    $stmt = $conn->prepare("SELECT id FROM users WHERE remember_token=? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($uid);
    if ($stmt->fetch()) {
        $_SESSION['user_id'] = $uid;
        $_SESSION['role'] = 'student';
        $stmt->close();
        header("Location: book.php");
        exit();
    }
    $stmt->close();

    // Try teacher
    $stmt = $conn->prepare("SELECT id FROM teachers WHERE remember_token=? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($uid);
    if ($stmt->fetch()) {
        $_SESSION['user_id'] = $uid;
        $_SESSION['role'] = 'teacher';
        $stmt->close();
        header("Location: teachers.php");
        exit();
    }
    $stmt->close();
}

/**
 * -------------------------------------------
 *  FETCH UPCOMING LESSONS (ICS)
 * -------------------------------------------
 */
$ics_url = "https://calendar.google.com/calendar/ical/c_fa8beeecc764d2836e99bf057540e15f037c8a762be33c3a0a660a1f45862f90%40group.calendar.google.com/public/basic.ics";
$ch = curl_init($ics_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
$ics_content = curl_exec($ch);
curl_close($ch);

$upcomingLessons = [];
if ($ics_content) {
    $tz = new DateTimeZone('Asia/Tashkent');
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics_content, $matches);
    foreach ($matches[1] as $event) {
        preg_match('/DTSTART(?:;TZID=.*)?:([\dT]+Z?)/', $event, $start);
        preg_match('/SUMMARY:(.+)/', $event, $summary);
        if (!empty($start[1]) && !empty($summary[1])) {
            $dateStr = $start[1];
            $date = str_ends_with($dateStr, 'Z')
                ? new DateTime($dateStr, new DateTimeZone('UTC'))
                : DateTime::createFromFormat('Ymd\THis', $dateStr, $tz);
            if ($date && str_ends_with($dateStr, 'Z')) $date->setTimezone($tz);
            if ($date && $date > new DateTime("now", $tz)) {
                $upcomingLessons[] = ["date" => $date, "summary" => trim($summary[1])];
            }
        }
    }
    usort($upcomingLessons, fn($a, $b) => $a['date'] <=> $b['date']);
    $upcomingLessons = array_slice($upcomingLessons, 0, 5);
}

$error = "";

/**
 * -------------------------------------------
 *  LOGIN HANDLING (Admin → Teacher → Student)
 * -------------------------------------------
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);
    $remember = !empty($_POST["remember"]);

    /**
     * -------------------------
     * ADMIN LOGIN (updated)
     * -------------------------
     */
    if ($email === 'admin@invento.uz') {
        $stmtA = $conn->prepare("SELECT id, name, email, password, role FROM admins WHERE email=? LIMIT 1");
        $stmtA->bind_param("s", $email);
        $stmtA->execute();
        $resA = $stmtA->get_result();
        if ($resA->num_rows === 1) {
            $admin = $resA->fetch_assoc();
            if (password_verify($password, $admin['password']) || $password === $admin['password']) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_role'] = $admin['role'];

                // Log admin login
                $log = $conn->prepare("INSERT INTO logs (admin_email, action) VALUES (?, 'Admin login')");
                $log->bind_param("s", $admin['email']);
                $log->execute();
                $log->close();

                header("Location: admin_dashboard.php");
                exit();
            } else {
                $error = "❌ Invalid password.";
            }
        } else {
            $error = "❌ Admin account not found.";
        }
        $stmtA->close();

    } else {
        /**
         * -------------------------
         * TEACHER LOGIN
         * -------------------------
         */
        $stmt = $conn->prepare("SELECT id, name, email, password FROM teachers WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $teacherRes = $stmt->get_result();

        if ($teacherRes->num_rows === 1) {
            $teacher = $teacherRes->fetch_assoc();
            if (password_verify($password, $teacher['password']) || $password === $teacher['password']) {
                $_SESSION['user_id'] = $teacher['id'];
                $_SESSION['email'] = $teacher['email'];
                $_SESSION['name'] = $teacher['name'];
                $_SESSION['role'] = 'teacher';

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + (86400 * 30), "/", "", false, true);
                    $upd = $conn->prepare("UPDATE teachers SET remember_token=? WHERE id=?");
                    $upd->bind_param("si", $token, $teacher['id']);
                    $upd->execute();
                    $upd->close();
                }

                header("Location: teachers.php");
                exit();
            } else {
                $error = "❌ Invalid password.";
            }
        } else {
            /**
             * -------------------------
             * STUDENT LOGIN
             * -------------------------
             */
            $stmt2 = $conn->prepare("SELECT id, name, email, grade, profile_pic, password FROM users WHERE email=?");
            $stmt2->bind_param("s", $email);
            $stmt2->execute();
            $userRes = $stmt2->get_result();

            if ($userRes->num_rows === 1) {
                $user = $userRes->fetch_assoc();
                if ($user['password'] === md5($password) || password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['student_name'] = $user['name'];
                    $_SESSION['grade'] = $user['grade'];
                    $_SESSION['profile_pic'] = $user['profile_pic'];
                    $_SESSION['role'] = 'student';

                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_token', $token, time() + (86400 * 30), "/", "", false, true);
                        $upd = $conn->prepare("UPDATE users SET remember_token=? WHERE id=?");
                        $upd->bind_param("si", $token, $user['id']);
                        $upd->execute();
                        $upd->close();
                    }

                    header("Location: book.php");
                    exit();
                } else {
                    $error = "❌ Invalid password.";
                }
            } else {
                $error = "❌ Account not found.";
            }
            $stmt2->close();
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Correctional Lessons Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
<style>
body { font-family:'Segoe UI',sans-serif; background:#f7f9fc; color:#333; margin:0; }
.navbar { background:#3d73dd; }
.navbar-brand { color:#fff !important; font-weight:600; }
.navbar .btn-login { color:#fff; border:2px solid #fff; border-radius:8px; font-weight:500; }
.navbar .btn-login:hover { background:#fff; color:#3d73dd; }
.hero { text-align:center; padding:100px 20px; background:linear-gradient(120deg,#d0e1fd,#f2f6ff); }
.hero h1 { font-size:3rem; font-weight:700; margin-bottom:20px; color:#2c3e50; }
.hero p { font-size:1.25rem; max-width:800px; margin:0 auto 25px; }
.section { padding:60px 20px; }
.section h2 { text-align:center; font-weight:700; margin-bottom:40px; color:#2c3e50; }
.lesson-card { background:#fff; border-radius:10px; padding:15px; margin-bottom:10px; box-shadow:0 4px 12px rgba(0,0,0,0.1); }
footer { background:#3d73dd; color:#fff; text-align:center; padding:15px; margin-top:40px; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark px-4">
  <span class="navbar-brand">Correctional Lessons Portal</span>
  <div class="ms-auto">
    <button class="btn btn-login" data-bs-toggle="modal" data-bs-target="#loginModal">Login</button>
  </div>
</nav>

<section class="hero">
  <div class="container">
    <h1>Welcome to the Correctional Lessons Portal</h1>
    <p>Correctional lessons are after-school sessions that provide students with focused academic support and opportunities to strengthen their understanding of key topics.</p>
    <p><strong>Sessions run from 3:45 PM to 7:00 PM</strong> — helping students grow with personalized attention.</p>
  </div>
</section>

<section class="section" style="background:#f0f5fd;">
  <div class="container">
    <h2>Why Are Correctional Lessons Important?</h2>
    <div class="row justify-content-center text-center">
      <div class="col-md-4 mb-4">
        <h5>🎯 Individual Focus</h5>
        <p>Students receive personalized support tailored to their learning needs.</p>
      </div>
      <div class="col-md-4 mb-4">
        <h5>📘 Academic Reinforcement</h5>
        <p>Lessons strengthen knowledge and close performance gaps.</p>
      </div>
      <div class="col-md-4 mb-4">
        <h5>💪 Confidence and Growth</h5>
        <p>Regular small-group learning boosts confidence and motivation.</p>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>Nearest Correctional Lessons</h2>
    <?php if(!empty($upcomingLessons)): ?>
      <?php foreach($upcomingLessons as $lesson): ?>
        <div class="lesson-card">
          <strong><?= htmlspecialchars($lesson['summary']) ?></strong><br>
          <?= $lesson['date']->format('D, d M Y H:i') ?>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="text-center">No upcoming lessons scheduled.</p>
    <?php endif; ?>
    <div class="text-center mt-3">
      <a href="<?= htmlspecialchars($ics_url) ?>" target="_blank" class="btn btn-primary">View Full Calendar</a>
    </div>
  </div>
</section>

<!-- LOGIN MODAL -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content p-3">
      <div class="modal-header">
        <h5 class="modal-title">Login to Your Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if(!empty($error)): ?>
          <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
          <div class="mb-3">
            <label>Email</label>
            <input type="email" name="email" class="form-control" required />
          </div>
          <div class="mb-3">
            <label>Password</label>
            <input type="password" name="password" class="form-control" required />
          </div>
          <div class="form-check mb-3">
            <input type="checkbox" name="remember" class="form-check-input" id="remember" />
            <label for="remember" class="form-check-label">Remember me</label>
          </div>
          <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
      </div>
    </div>
  </div>
</div>

<footer>
  <p>© <?= date("Y") ?> Correctional Lessons Portal — Empowering Students Through Guidance</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($error)): ?>
<script>
document.addEventListener("DOMContentLoaded", () => {
  new bootstrap.Modal(document.getElementById('loginModal')).show();
});
</script>
<?php endif; ?>
</body>
</html>
