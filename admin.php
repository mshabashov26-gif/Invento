<?php
include('db_connect.php');

session_start();

// Simple admin check (later improve with separate login)
$admin_email = "admin@invento.uz";
if ($_SESSION['user_email'] !== $admin_email) {
    echo "Access denied.";
    exit;
}

$users = $conn->query("SELECT * FROM users");
$bookings = $conn->query("SELECT b.id, u.name, u.grade, b.subject, b.booking_date 
                          FROM bookings b JOIN users u ON b.user_id=u.id");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <h2>Admin Panel</h2>
    <h3>All Users</h3>
    <ul>
        <?php while($u = $users->fetch_assoc()): ?>
            <li><?php echo $u['name']." (".$u['email'].") Grade ".$u['grade']; ?></li>
        <?php endwhile; ?>
    </ul>

    <h3>All Bookings</h3>
    <ul>
        <?php while($b = $bookings->fetch_assoc()): ?>
            <li><?php echo $b['name']." (Grade ".$b['grade'].") booked ".$b['subject']." at ".$b['booking_date']; ?></li>
        <?php endwhile; ?>
    </ul>
</div>
</body>
</html>
