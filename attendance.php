<?php
echo headerSection($logo, "Attendance Report");
?>

<h3>Student Information</h3>
<p>
  <b>Name:</b> <?= htmlspecialchars($name) ?><br>
  <b>Grade:</b> <?= htmlspecialchars($grade) ?><br>
  <b>Email:</b> <?= htmlspecialchars($email) ?>
</p>

<h4>Report Period: <?= $from ?> → <?= $to ?></h4>

<table border="1" cellspacing="0" cellpadding="8" width="100%">
  <tr style="background:#f0f0f0;">
    <th>Total Booked</th>
    <th>Visited</th>
    <th>Canceled</th>
    <th>Not Attended</th>
  </tr>
  <tr>
    <td><?= $stats['booked'] ?></td>
    <td><?= $stats['visited'] ?></td>
    <td><?= $stats['canceled'] ?></td>
    <td><?= $stats['not attended'] ?></td>
  </tr>
</table>

<h4 style="margin-top:20px;">Detailed Lessons</h4>
<table border="1" cellspacing="0" cellpadding="8" width="100%">
  <tr>
    <th>Date</th>
    <th>Subject</th>
    <th>Status</th>
  </tr>
  <?php foreach($bookings as $b): ?>
    <tr>
      <td><?= $b['booking_date'] ?></td>
      <td><?= htmlspecialchars($b['subject']) ?></td>
      <td><?= htmlspecialchars($b['status']) ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<?= footerSection(); ?>
