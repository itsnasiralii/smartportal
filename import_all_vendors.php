<?php
require_once 'db.php';

$filename = __DIR__ . '/Escalation Matrix Vendors - CNOC - 26032025.xlsx';
$zip = new ZipArchive();

if ($zip->open($filename) !== TRUE) {
    die("Could not open Excel file.\n");
}

// 1. Shared Strings
$ssXml = $zip->getFromName('xl/sharedStrings.xml');
$strings = [];
if ($ssXml) {
    $xml = simplexml_load_string($ssXml);
    foreach ($xml->si as $si) {
        if (isset($si->t)) {
            $strings[] = (string)$si->t;
        } elseif (isset($si->r)) {
            $t = '';
            foreach ($si->r as $r) { $t .= (string)$r->t; }
            $strings[] = $t;
        } else {
            $strings[] = '';
        }
    }
}

// 2. Sheet relationships
$wbXml = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
$relsXml = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
$rels = [];
foreach ($relsXml->Relationship as $r) {
    $rels[(string)$r['Id']] = (string)$r['Target'];
}

$sheetFiles = [];
foreach ($wbXml->sheets->sheet as $s) {
    $name = trim((string)$s['name']);
    $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    $target = $rels[$rId];
    $sheetFiles[$name] = 'xl/' . ltrim($target, '/');
}

// Clear old vendors and contacts to perform fresh clean import from the Excel
$pdo->exec("DELETE FROM vendor_contacts");
$pdo->exec("DELETE FROM vendors");

$stmt_v = $pdo->prepare("INSERT INTO vendors (name) VALUES (?)");
$stmt_c = $pdo->prepare("INSERT INTO vendor_contacts (vendor_id, level, name, designation, escalation_time, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)");

$totalVendors = 0;
$totalContacts = 0;

$ignoreSheets = ['Cover', 'CNOC', 'Central Region Access', 'Site Alarms Info', 'Access South', 'Access North 3'];

foreach ($sheetFiles as $vendorName => $filePath) {
    if (in_array($vendorName, $ignoreSheets)) continue;

    $xmlContent = $zip->getFromName($filePath);
    if (!$xmlContent) continue;
    $sheetXml = simplexml_load_string($xmlContent);
    if (!$sheetXml || !isset($sheetXml->sheetData->row)) continue;

    // Build raw rows
    $rows = [];
    foreach ($sheetXml->sheetData->row as $row) {
        $rNum = (int)$row['r'];
        $rowData = [];
        foreach ($row->c as $c) {
            $val = (string)$c->v;
            $type = (string)$c['t'];
            if ($type === 's' && isset($strings[(int)$val])) {
                $val = $strings[(int)$val];
            }
            $col = preg_replace('/[0-9]/', '', (string)$c['r']);
            $rowData[$col] = trim($val);
        }
        $rows[$rNum] = $rowData;
    }

    // Detect header row
    $headerRowIdx = null;
    $colMap = ['level' => null, 'name' => null, 'desig' => null, 'time' => null, 'phone' => null, 'email' => null];

    foreach ($rows as $rNum => $rCols) {
        $str = strtolower(implode(' ', $rCols));
        if (strpos($str, 'name') !== false && (strpos($str, 'escalation') !== false || strpos($str, 'email') !== false || strpos($str, 'contact') !== false)) {
            $headerRowIdx = $rNum;
            foreach ($rCols as $cLetter => $cText) {
                $t = strtolower($cText);
                if (strpos($t, 'level') !== false || strpos($t, 'escalation levels') !== false) $colMap['level'] = $cLetter;
                elseif (strpos($t, 'name') !== false && strpos($t, 'vendor') === false) $colMap['name'] = $cLetter;
                elseif (strpos($t, 'designation') !== false || strpos($t, 'title') !== false) $colMap['desig'] = $cLetter;
                elseif (strpos($t, 'time') !== false) $colMap['time'] = $cLetter;
                elseif (strpos($t, 'contact') !== false || strpos($t, 'phone') !== false || strpos($t, 'mobile') !== false || strpos($t, 'number') !== false) $colMap['phone'] = $cLetter;
                elseif (strpos($t, 'email') !== false || strpos($t, 'mail') !== false) $colMap['email'] = $cLetter;
            }
            break;
        }
    }

    // Default fallback columns if not strictly found by header text
    if (!$headerRowIdx) {
        // Let's check if Row 2 or 3 has standard columns B, C, D, E, F, G
        $headerRowIdx = 3;
        $colMap = ['level' => 'B', 'name' => 'C', 'desig' => 'D', 'time' => 'E', 'phone' => 'F', 'email' => 'G'];
    }

    // Insert Vendor
    $stmt_v->execute([$vendorName]);
    $vendorId = $pdo->lastInsertId();
    $totalVendors++;

    $currentLevel = 'Level 1';
    $currentTime = '';
    $vendorContactsCount = 0;

    foreach ($rows as $rNum => $rCols) {
        if ($rNum <= $headerRowIdx) continue;

        $name = isset($colMap['name']) ? ($rCols[$colMap['name']] ?? '') : '';
        $email = isset($colMap['email']) ? ($rCols[$colMap['email']] ?? '') : '';
        $phone = isset($colMap['phone']) ? ($rCols[$colMap['phone']] ?? '') : '';
        $desig = isset($colMap['desig']) ? ($rCols[$colMap['desig']] ?? '') : '';
        $level = isset($colMap['level']) ? ($rCols[$colMap['level']] ?? '') : '';
        $time = isset($colMap['time']) ? ($rCols[$colMap['time']] ?? '') : '';

        // If level specified, update currentLevel
        if (!empty($level) && preg_match('/(?:level|tier|l[1-5]|hod|manager|support|director)/i', $level)) {
            $currentLevel = $level;
        }
        if (!empty($time)) {
            $currentTime = $time;
        }

        // Must have at least a Name or an Email to be a contact
        if (empty($name) && empty($email)) continue;
        if (stripos($name, 'escalation') !== false || stripos($name, 'matrix') !== false) continue;

        $stmt_c->execute([
            $vendorId,
            $level ?: $currentLevel,
            $name ?: 'Support Desk',
            $desig,
            $time ?: $currentTime,
            $phone,
            $email
        ]);
        $totalContacts++;
        $vendorContactsCount++;
    }

    echo "Imported {$vendorName}: {$vendorContactsCount} contacts.\n";
}

$zip->close();
echo "\n============================================\n";
echo "SUCCESS: Imported {$totalVendors} Vendors and {$totalContacts} Contacts!\n";
echo "============================================\n";
?>
