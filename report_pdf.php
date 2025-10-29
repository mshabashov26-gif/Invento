<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('memory_limit', '512M');
set_time_limit(60);
ob_start();

require_once __DIR__ . '/vendor/autoload.php';
include('db_connect.php');

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') die('Access denied.');

$student_id = $_SESSION['user_id'];
$type = $_GET['type'] ?? 'attendance';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');

/* ---------- Student data ---------- */
$stmt = $conn->prepare("SELECT name, grade, email FROM users WHERE id=?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($name, $grade, $email);
$stmt->fetch();
$stmt->close();

/* ---------- Bookings ---------- */
$sql = "SELECT subject, teacher_comment, booking_date, status
        FROM bookings
        WHERE user_id=? AND DATE(booking_date) BETWEEN ? AND ?
        ORDER BY booking_date ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $student_id, $from, $to);
$stmt->execute();
$bookings = $stmt->get_result();
$stmt->close();

/* ---------- Stats ---------- */
$stats = ['booked'=>0,'visited'=>0,'canceled'=>0,'not attended'=>0];
$rows  = [];
while ($b = $bookings->fetch_assoc()){
  $rows[] = $b;
  if (isset($stats[$b['status']])) $stats[$b['status']]++;
}
$totalLessons = array_sum($stats);

/* re-iterable */
$bookingsArr = $rows;

/* ---------- Assets: logo + QR (base64) ---------- */
$logoFile = __DIR__ . '/invento.png';
$signatureFile = __DIR__ . '/signature.png';

if (file_exists($logoFile)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile));
} else {
    // Fallback if logo not found
    $svgLogo = '<svg width="400" height="100" xmlns="http://www.w3.org/2000/svg">
        <rect width="400" height="100" fill="#1a5f7a"/>
        <text x="200" y="60" font-family="Arial" font-size="36" fill="white" text-anchor="middle" font-weight="bold">INVENTO</text>
    </svg>';
    $logoBase64 = 'data:image/svg+xml;base64,' . base64_encode($svgLogo);
}

$signatureBase64 = is_file($signatureFile) ? ('data:image/png;base64,' . base64_encode(@file_get_contents($signatureFile))) : '';

/* ---------- QR Code Generation - FIXED ---------- */
$qrText = "Invento Report | Student: $name | Grade: $grade | Type: $type | Period: $from to $to";
$qro = new QROptions([
    'outputType' => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel' => QRCode::ECC_L,
    'scale' => 10,
    'imageBase64' => false
]);

$qrCode = new QRCode($qro);
$qrRender = $qrCode->render($qrText);

// Check if it's already a data URI or raw binary
if (strpos($qrRender, 'data:image') === 0) {
    $qrBase64 = $qrRender;
} else {
    // If it's raw binary, encode it
    $qrBase64 = 'data:image/png;base64,' . base64_encode($qrRender);
}

/* ---------- Small GD helpers: return base64 PNG ---------- */
function chart_pie_attendance(array $stats, int $w=320, int $h=320): string {
  $total = max(1, array_sum($stats));
  $im = imagecreatetruecolor($w,$h);
  imagesavealpha($im, true);
  $clear = imagecolorallocatealpha($im,255,255,255,127); 
  imagefill($im,0,0,$clear);
  
  $cols = [
    'visited'      => imagecolorallocate($im,  40,167, 69),
    'booked'       => imagecolorallocate($im,  61,144,215),
    'canceled'     => imagecolorallocate($im, 220, 53, 69),
    'not attended' => imagecolorallocate($im, 255,152,  0),
  ];
  
  $start = 0;
  foreach($stats as $k=>$v){
    if ($v <= 0) continue;
    $angle = round(($v/$total)*360);
    imagefilledarc($im,$w/2,$h/2,$w-40,$h-40,$start,$start+$angle,$cols[$k],IMG_ARC_PIE);
    $start += $angle;
  }
  
  ob_start(); 
  imagepng($im); 
  $b=base64_encode(ob_get_clean()); 
  imagedestroy($im);
  return 'data:image/png;base64,'.$b;
}

function chart_bar_subjects(array $rows, int $w=520, int $h=260): array {
  // returns [imgBase64, summaryBySubject]
  $summary = [];
  foreach ($rows as $r) {
    $s = $r['subject'];
    if (!isset($summary[$s])) {
      $summary[$s] = [
        'visited' => 0,
        'booked' => 0,
        'canceled' => 0,
        'not attended' => 0
      ];
    }
    if (isset($summary[$s][$r['status']])) {
      $summary[$s][$r['status']]++;
    }
  }

  $im = imagecreatetruecolor($w,$h);
  $white = imagecolorallocate($im,255,255,255);
  imagefill($im,0,0,$white);
  $green = imagecolorallocate($im,40,167,69);
  $blue  = imagecolorallocate($im,61,144,215);
  $black = imagecolorallocate($im,20,20,20);
  $x = 60; $barW = 30; $maxH = 150;

  foreach($summary as $sub=>$s){
    $total = array_sum($s);
    $rate = $total ? $s['visited']/$total : 0;
    $barH = $rate*$maxH;
    imagefilledrectangle($im,$x,$h-40-$barH,$x+$barW,$h-40,$green);
    imagestringup($im,2,$x+6,$h-42,substr($sub,0,8),$black);
    $x += $barW+25;
  }
  imageline($im,40,$h-40,$w-10,$h-40,$black);
  
  ob_start(); 
  imagepng($im);
  $b64 = base64_encode(ob_get_clean());
  imagedestroy($im);
  return ['data:image/png;base64,'.$b64,$summary];
}

/* ---------- Styles ---------- */
echo "
<style>
body{font-family:'DejaVu Sans',sans-serif;font-size:12px;color:#222;}
.header{background:#fff;color:#1a5f7a;padding:15px 20px;border-bottom:3px solid #1a5f7a;margin-bottom:20px;}
.header img{height:60px;margin-right:15px;vertical-align:middle;}
.header h2{display:inline-block;margin:0;vertical-align:middle;color:#1a5f7a;}
.footer{position:fixed;bottom:0;left:0;right:0;background:#1a5f7a;color:white;font-size:11px;text-align:center;padding:8px 0;}
.section-title{color:#1a5f7a;border-bottom:2px solid #1a5f7a;margin-top:25px;margin-bottom:15px;padding-bottom:5px;font-size:16px;font-weight:bold;}
table{width:100%;border-collapse:collapse;margin-top:15px;}
th,td{border:1px solid #ccc;padding:8px;text-align:center;font-size:11px;}
th{background:#e8f4f8;font-weight:bold;color:#1a5f7a;}
.cover{text-align:center;margin-top:80px;padding:20px;}
.cover h1{color:#1a5f7a;font-size:24px;margin:20px 0;}
.cover h2{color:#57313b;font-size:20px;margin:15px 0;}
.cover p{font-size:13px;line-height:1.8;margin:10px 0;color:#333;}
.page-break{page-break-after:always;}
</style>";

/* ---------- Cover ---------- */
echo "
<div class='cover'>
  <img src='$logoBase64' style='width:400px;height:auto;margin-bottom:30px;'><br>
  <h2 style='color:#57313b;'>".ucfirst($type)." Report</h2>
  <p><b>Student Name:</b> $name<br><b>Grade:</b> $grade<br><b>Email:</b> $email<br><b>Report Period:</b> $from to $to</p>
  <br>
  <img src='$qrBase64' style='width:150px;height:150px;margin-top:20px;'><br>
  <small style='color:#555;'>QR Verification Code</small><br>
  <small style='color:#888;font-size:10px;'>Scan to verify report authenticity</small>
</div>
<div class='page-break'></div>
";

/* ---------- Reports ---------- */
if($type==='attendance'){
  echo "<div class='header'><img src='$logoBase64'><h2>Attendance Report</h2></div>
  <h3 class='section-title'>📊 Attendance Summary</h3>
  <p>This report shows your attendance status for the selected period.</p>
  <table>
    <tr><th>Status</th><th>Count</th><th>Percentage</th></tr>";
  foreach($stats as $k=>$v) {
    $percentage = $totalLessons > 0 ? round(($v / $totalLessons) * 100, 1) : 0;
    echo "<tr><td>".ucfirst($k)."</td><td>$v</td><td>{$percentage}%</td></tr>";
  }
  echo "<tr style='background:#f0f0f0;'><th>Total Lessons</th><th>$totalLessons</th><th>100%</th></tr>";
  echo "</table>
  <div style='text-align:center;margin-top:30px;'>
    <img src='".chart_pie_attendance($stats)."' width='280'><br>
    <small style='color:#666;'>Attendance Distribution Chart</small>
  </div>
  <div class='footer'>Invento – The Uzbek International School © ".date('Y')."</div>";
}
elseif($type==='subject'){
  [$barChart,$sum] = chart_bar_subjects($bookingsArr);
  echo "<div class='header'><img src='$logoBase64'><h2>Subject Performance Report</h2></div>
  <h3 class='section-title'>📘 Performance by Subject</h3>
  <p>This report shows your attendance performance across different subjects.</p>
  <table>
    <tr><th>Subject</th><th>Visited</th><th>Booked</th><th>Canceled</th><th>Not Attended</th><th>Total</th></tr>";
  foreach($sum as $s=>$v) {
    $total = array_sum($v);
    echo "<tr><td>$s</td><td>{$v['visited']}</td><td>{$v['booked']}</td><td>{$v['canceled']}</td><td>{$v['not attended']}</td><td>$total</td></tr>";
  }
  echo "</table>
  <div style='text-align:center;margin-top:30px;'>
    <img src='$barChart' width='480'><br>
    <small style='color:#666;'>Subject Attendance Rate Comparison</small>
  </div>
  <div class='footer'>Invento – The Uzbek International School © ".date('Y')."</div>";
}
elseif($type==='monthly'){
  echo "<div class='header'><img src='$logoBase64'><h2>Monthly Progress Report</h2></div>
  <h3 class='section-title'>📅 Monthly Summary</h3>
  <p>Overall progress summary for the selected month.</p>
  <table>
    <tr><th>Metric</th><th>Value</th></tr>
    <tr><td>Total Lessons Booked</td><td>$totalLessons</td></tr>
    <tr><td>Lessons Attended</td><td>{$stats['visited']}</td></tr>
    <tr><td>Lessons Canceled</td><td>{$stats['canceled']}</td></tr>
    <tr><td>Lessons Not Attended</td><td>{$stats['not attended']}</td></tr>
    <tr><td>Lessons Still Booked</td><td>{$stats['booked']}</td></tr>";
  
  $attendance_rate = $totalLessons > 0 ? round(($stats['visited'] / $totalLessons) * 100, 1) : 0;
  echo "<tr style='background:#e8f4f8;'><th>Attendance Rate</th><th>{$attendance_rate}%</th></tr>";
  echo "</table>
  <div style='text-align:center;margin-top:30px;'>
    <img src='".chart_pie_attendance($stats)."' width='280'><br>
    <small style='color:#666;'>Monthly Attendance Overview</small>
  </div>
  <div class='footer'>Invento – The Uzbek International School © ".date('Y')."</div>";
}
elseif($type==='feedback'){
  echo "<div class='header'><img src='$logoBase64'><h2>Teacher Feedback Report</h2></div>
  <h3 class='section-title'>💬 Teacher Comments & Feedback</h3>
  <p>Review of teacher feedback for your lessons during the selected period.</p>";
  
  if (empty($bookingsArr)) {
    echo "<p style='text-align:center;color:#666;margin-top:30px;'>No bookings found for the selected period.</p>";
  } else {
    echo "<table>
      <tr><th>Date</th><th>Subject</th><th>Status</th><th>Teacher Feedback</th></tr>";
    foreach($bookingsArr as $b) {
      $date = date('M d, Y', strtotime($b['booking_date']));
      $comment = !empty($b['teacher_comment']) ? htmlspecialchars($b['teacher_comment']) : '<em style="color:#999;">No feedback provided</em>';
      $status_color = '';
      if ($b['status'] === 'visited') $status_color = 'color:#28a745;';
      elseif ($b['status'] === 'canceled') $status_color = 'color:#dc3545;';
      elseif ($b['status'] === 'not attended') $status_color = 'color:#ff9800;';
      
      echo "<tr>
        <td>$date</td>
        <td>{$b['subject']}</td>
        <td style='$status_color'>".ucfirst($b['status'])."</td>
        <td style='text-align:left;'>$comment</td>
      </tr>";
    }
    echo "</table>";
  }
  
  if (!empty($signatureBase64)) {
    echo "<div style='text-align:right;margin-top:50px;margin-right:30px;'>
      <img src='$signatureBase64' height='60'><br>
      <b>Academic Coordinator</b><br>
      <small style='color:#666;'>".date('F d, Y')."</small>
    </div>";
  }
  
  echo "<div class='footer'>Invento – The Uzbek International School © ".date('Y')."</div>";
}

/* ---------- Watermark ---------- */
$html = ob_get_clean();
$map = [
  'attendance'=>'Attendance Report – Invento Confidential',
  'subject'=>'Subject Performance – Invento Confidential',
  'monthly'=>'Monthly Summary – Invento Confidential',
  'feedback'=>'Teacher Feedback – Invento Confidential'
];
$watermarkText = $map[$type] ?? 'Invento Confidential';
$html .= "<style>.watermark{position:fixed;top:45%;left:10%;transform:rotate(-40deg);opacity:0.04;font-size:72px;color:#1a5f7a;width:80%;text-align:center;z-index:-1;font-weight:bold;}</style>
<div class='watermark'>$watermarkText</div>";

/* ---------- PDF render ---------- */
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4','portrait');
$dompdf->render();

// Clean filename
$clean_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
$filename = "Invento_Report_{$type}_{$clean_name}_".date('Y-m-d').".pdf";

$dompdf->stream($filename, ["Attachment" => false]);
exit;
?>