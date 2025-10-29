<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('db_connect.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/PHPMailer/src/Exception.php';

// --- AUTH ---
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
if (empty($student_avatar) || !file_exists($student_avatar)) $student_avatar = $defaultAvatar;

// --- SUBJECT CONFIGURATION ---
$first = strtolower(explode(" ", $student_name)[0]);
if ($student_grade == 12) {
    if (in_array($first, ['nigora', 'afruza'])) {
        $allowedSubjects = ["Business Management", "Math", "TOK", "Russian A", "English B", "Biology", "Chemistry"];
    } else {
        $allowedSubjects = ["Business Management", "Math", "Art", "Computer Science", "TOK", "Russian A", "English B"];
    }
} elseif ($student_grade == 11) {
    $allowedSubjects = ["Business Management", "Math", "Russian A", "English A", "Biology", "Chemistry", "Computer Science"];
} else {
    $allowedSubjects = ["Math", "Science", "English", "Russian A", "I&S"];
}

// --- GOOGLE CALENDAR EVENTS ---
$ics_url = "https://calendar.google.com/calendar/ical/c_fa8beeecc764d2836e99bf057540e15f037c8a762be33c3a0a660a1f45862f90%40group.calendar.google.com/public/basic.ics";
$ics_content = @file_get_contents($ics_url);
$tz = new DateTimeZone('Asia/Tashkent');
$allEvents = [];
if ($ics_content) {
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics_content, $matches);
    foreach ($matches[1] as $event) {
        preg_match('/DTSTART(?:;TZID=.*)?:([\dT]+Z?)/', $event, $start);
        preg_match('/SUMMARY:(.+)/', $event, $summary);
        if (!empty($start[1]) && !empty($summary[1])) {
            $dateStr = $start[1];
            $date = str_ends_with($dateStr, 'Z') ? new DateTime($dateStr, new DateTimeZone('UTC')) : DateTime::createFromFormat('Ymd\THis', $dateStr, $tz);
            if ($date && str_ends_with($dateStr, 'Z')) $date->setTimezone($tz);
            $allEvents[] = ["date" => $date->format("Y-m-d H:i"), "summary" => trim($summary[1])];
        }
    }
}

// --- BOOKING SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['slot'], $_POST['subject'])) {
    $slot = $_POST['slot'];
    $subject = $_POST['subject'];

    // --- Prevent same-time or overlapping bookings (±1 hour) ---
    $slotTime = new DateTime($slot);
    $startTime = clone $slotTime;
    $endTime = clone $slotTime;
    $startTime->modify('-1 hour');
    $endTime->modify('+1 hour');

    $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM bookings WHERE user_id=? AND booking_date BETWEEN ? AND ?");
    $start = $startTime->format('Y-m-d H:i:s');
    $end = $endTime->format('Y-m-d H:i:s');
    $stmtCheck->bind_param("iss", $student_id, $start, $end);
    $stmtCheck->execute();
    $stmtCheck->bind_result($conflictCount);
    $stmtCheck->fetch();
    $stmtCheck->close();

    if ($conflictCount > 0) {
        $error = "❌ You already have a booking within 1 hour of this time.";
    } else {
        // Save booking to DB (teacher will see it automatically)
        $stmt = $conn->prepare("INSERT INTO bookings(user_id, subject, booking_date, created_at, student_name, student_grade, student_email)
                                VALUES (?, ?, ?, NOW(), ?, ?, ?)");
        $stmt->bind_param("isssss", $student_id, $subject, $slot, $student_name, $student_grade, $student_email);

        if ($stmt->execute()) {
            // Fetch matching teacher
            $teacher_email = null;
            $teacher_name = "Teacher";
            $stmtT = $conn->prepare("SELECT name, email FROM teachers WHERE LOWER(course) LIKE CONCAT('%', LOWER(?), '%') LIMIT 1");
            $stmtT->bind_param("s", $subject);
            $stmtT->execute();
            $stmtT->bind_result($teacher_name, $teacher_email);
            $stmtT->fetch();
            $stmtT->close();

            // --- SEND EMAIL FUNCTION ---
            function sendEmail($to,$name,$subjectLine,$title,$content,$avatar){
                $mail=new PHPMailer(true);
                try{
                    $mail->isSMTP();
                    $mail->Host='smtp.gmail.com';
                    $mail->SMTPAuth=true;
                    $mail->Username='gougoweg@gmail.com';
                    $mail->Password='fqsf fnww jlgm wqnb';
                    $mail->SMTPSecure=PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port=465;
                    $mail->setFrom('gougoweg@gmail.com','Correctional Lessons | Invento');
                    $mail->addAddress($to,$name);
                    $logo=__DIR__.'/With logo (1) (1).png';
                    $sig=__DIR__.'/signature.png';
                    if(file_exists($logo))$mail->AddEmbeddedImage($logo,'logoimg');
                    if(file_exists($sig))$mail->AddEmbeddedImage($sig,'signimg');
                    if(file_exists($avatar)){
                        $mail->AddEmbeddedImage($avatar,'avatarimg');
                        $avatarHTML="<img src='cid:avatarimg' width='80' height='80' style='border-radius:50%;margin-right:20px;'>";
                    }else{$avatarHTML="";}
                    $mail->isHTML(true);
                    $mail->Subject=$subjectLine;
                    $mail->Body="<div style='font-family:Segoe UI,sans-serif;background:#f8fbff;border-radius:10px;padding:20px'>
                        <div style='text-align:center'><img src='cid:logoimg' width='150'><h2 style='color:#2d6bb3'>$title</h2></div>
                        <div style='background:white;padding:20px;border-radius:10px;display:flex;align-items:center;'>$avatarHTML<div>$content</div></div>
                        <div style='text-align:center;margin-top:20px'><img src='cid:signimg' width='160'><p style='font-size:12px;color:#777'>Invento – The Uzbek International School</p></div>
                    </div>";
                    $mail->send();
                }catch(Exception $e){}
            }

            // Email content
            $studentBody = "
                <p>Dear <b>$student_name</b>,</p>
                <p>Your correctional lesson has been successfully booked:</p>
                <ul>
                    <li><b>Subject:</b> $subject</li>
                    <li><b>Date & Time:</b> $slot</li>
                    <li><b>Grade:</b> $student_grade</li>
                </ul>
                <p>Please arrive on time.</p>";
            $teacherBody = "
                <p>Dear <b>$teacher_name</b>,</p>
                <p><b>$student_name</b> (Grade $student_grade) booked a lesson:</p>
                <ul>
                    <li><b>Subject:</b> $subject</li>
                    <li><b>Date:</b> $slot</li>
                    <li><b>Student Email:</b> $student_email</li>
                </ul>";

            sendEmail($student_email, $student_name, "Booking Confirmed – $subject", "Booking Confirmation", $studentBody, $student_avatar);
            if ($teacher_email) sendEmail($teacher_email, $teacher_name, "New Lesson Booking – $subject", "New Lesson Booking", $teacherBody, $student_avatar);

            $success = "✅ Booking completed successfully for $subject at $slot. Confirmation email sent.";
        } else {
            $error = "❌ Booking failed.";
        }
        $stmt->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Book a Lesson</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{font-family:'Montserrat',sans-serif;background:#e6f4fa;}
.navbar{background:#8cbce6;border-radius:12px;margin-bottom:20px;}
.card{background:#d0e8f9;border-radius:15px;box-shadow:0 8px 25px rgba(0,0,0,0.1);}
.profile-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:none;background:none;}
.dropdown-menu{border-radius:12px;box-shadow:0 4px 10px rgba(0,0,0,0.1);}
.fade-out{opacity:0;transition:opacity 1s ease-out;}
</style>
</head>
<body>
<nav class="navbar navbar-dark px-4 d-flex justify-content-between align-items-center">
    <span class="navbar-brand">🗓️ Book a Lesson</span>
    <div class="dropdown">
        <button class="btn btn-light border-0 dropdown-toggle" data-bs-toggle="dropdown">
            <img src="<?= htmlspecialchars($student_avatar) ?>" onerror="this.src='uploads/basic.jpg';" class="profile-avatar">
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

<div class="container mt-4">
    <?php if(!empty($success)): ?>
        <div id="alertBox" class="alert alert-success text-center fw-bold"><?= $success ?></div>
    <?php elseif(!empty($error)): ?>
        <div id="alertBox" class="alert alert-danger text-center fw-bold"><?= $error ?></div>
    <?php endif; ?>

    <div class="card p-4">
        <h3>📅 Available Slots</h3>
        <form method="POST">
            <div class="mb-3">
                <label>Choose Subject</label>
                <select id="subjectSelect" name="subject" class="form-select" required>
                    <option value="">-- Select Subject --</option>
                    <?php foreach($allowedSubjects as $sub): ?>
                        <option value="<?= htmlspecialchars($sub) ?>"><?= htmlspecialchars($sub) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label>Select Slot</label>
                <select id="slotSelect" name="slot" class="form-select" required>
                    <option value="">-- Select subject first --</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-100">Confirm Booking</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const alertBox=document.getElementById('alertBox');
if(alertBox){setTimeout(()=>{alertBox.classList.add('fade-out');setTimeout(()=>alertBox.remove(),1000);},5000);}
document.getElementById('openSupport').onclick=()=>window.location.href='support.php';
const events=<?= json_encode($allEvents) ?>;
function normalize(s){return s.toLowerCase().replace(/[^a-z0-9 ]/g,'').trim();}
document.getElementById('subjectSelect').addEventListener('change',function(){
  const subj=normalize(this.value);
  const slots=document.getElementById('slotSelect');
  slots.innerHTML='';
  const filtered=events.filter(e=>normalize(e.summary).includes(subj));
  if(filtered.length===0){slots.innerHTML="<option>No available slots</option>";return;}
  filtered.forEach(e=>{
    const opt=document.createElement('option');
    opt.value=e.date;opt.textContent=e.date;
    slots.appendChild(opt);
  });
});
</script>
</body>
</html>
