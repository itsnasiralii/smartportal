<?php
require_once 'db.php';
$stmt = $pdo->prepare("UPDATE vendor_contacts SET email = 'cdms@cyber.net.pk; helpdesk@cyber.net.pk' WHERE email LIKE '%cdms@cyber.net.pk%' AND vendor_id = (SELECT id FROM vendors WHERE name = 'Cybernet')");
$stmt->execute();
echo "Fixed Cybernet Row: " . $stmt->rowCount() . " affected.\n";
?>
