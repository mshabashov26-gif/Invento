<?php
echo headerSection($logo, "Subject Performance Report");
?>

<h3>Student: <?= htmlspecialchars($name) ?> (Grade <?= htmlspecialchars($grade) ?>)</h3>
<p>Report Period: <?= $from ?> → <?= $to ?></p>

<table border="1" cellspacing="0" cellpadding="8" width="100%">
  <tr style="background:#f0f0f0;">
    <th>Subject</th>
    <th>Date</th>
    <th>Status</th>
    <th>Teacher Comment</th>
  </tr>
  <?php foreach($bookings as $b): ?>
    <tr>
      <td><?= htmlspecialchars($b['subject']) ?></td>
      <td><?= $b['booking_date'] ?></td>
      <td><?= htmlspecialchars($b['status']) ?></td>
      <td><?= htmlspecialchars($b['teacher_comment'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<?= footerSection(); ?>
