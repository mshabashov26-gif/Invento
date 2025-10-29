<?php
require 'vendor/autoload.php';

use chillerlan\QRCode\Data\QRData;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\QRCode;

/* ✅ Configure QR for PNG output using GD */
$options = new QROptions([
    'version'      => 5,
    'outputType'   => QRCode::OUTPUT_IMAGE_PNG, // <-- Force PNG
    'eccLevel'     => QRCode::ECC_L,
    'scale'        => 5,
    'imageBase64'  => true,
]);

$data = "Student Test QR"; // test text

/* ✅ Generate QR image */
$qrcode = new QRCode($options);
$qrImage = $qrcode->render($data);

/* ✅ Use Base64 image for browser / PDF */
?>
<!DOCTYPE html>
<html>
<body>
<h2>✅ Final QR Code Test</h2>
<img src="<?= $qrImage ?>" alt="QR">
</body>
</html>
