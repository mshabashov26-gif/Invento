<?php
session_start();
include('db_connect.php');


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST["email"];
    $password = $_POST["password"];

    $stmt = $conn->prepare("SELECT id, password FROM admins WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($id, $hashed);
    $stmt->fetch();

    if ($stmt->num_rows > 0 && password_verify($password, $hashed)) {
        $_SESSION["admin_id"] = $id;
        header("Location: admin_dashboard.php");
        exit();
    } else {
        echo "Invalid admin login.";
    }
}
?>

<h2>Admin Login</h2>
<form method="POST">
    <input type="email" name="email" required placeholder="Admin Email"><br>
    <input type="password" name="password" required placeholder="Password"><br>
    <button type="submit">Login</button>
</form>
