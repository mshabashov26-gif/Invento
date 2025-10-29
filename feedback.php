<?php
echo headerSection($logo, "Teacher Feedback Report");
?>

<h3>Student: <?= htmlspecialchars($name) ?> (Grade <?= htmlspecialchars($grade) ?>)</h3>
<p>Report Period: <?= $from ?> → <?= $to ?></p>

<table border="1" cellspacing="0" cellpadding="8" width="100%">
  <tr style="background:#f0f0f0;">
    <th>Date</th>
    <th>Subject</th>
    <th>Teacher Comment</th>
  </tr>
  <?php foreach($bookings as $b): ?>
    <tr>
      <td><?= $b['booking_date'] ?></td>
      <td><?= htmlspecialchars($b['subject']) ?></td>
      <td><?= htmlspecialchars($b['teacher_comment'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<div style="text-align:right;margin-top:40px;">
  <img src="<?= $sign ?>" height="70"><br>
  <b>Academic Coordinator</b>
</div>

<?= footerSection(); ?>
