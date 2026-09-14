<?php
require_once 'db.php';
$stmt = $pdo->prepare("SELECT * FROM vendor_contacts WHERE vendor_id = (SELECT id FROM vendors WHERE name = 'Netsat')");
$stmt->execute();
$contacts = $stmt->fetchAll();
foreach ($contacts as $c) {
    echo "{$c['level']} | {$c['name']} | {$c['designation']} | {$c['phone']} | {$c['email']}\n";
}
?>
