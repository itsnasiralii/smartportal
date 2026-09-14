<?php
$zip = new ZipArchive();
$filename = __DIR__ . '/Escalation Matrix Vendors - CNOC - 26032025.xlsx';

if ($zip->open($filename) === TRUE) {
    // Read workbook.xml for sheet names
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $xml = simplexml_load_string($wbXml);
    $sheets = [];
    foreach ($xml->sheets->sheet as $s) {
        $sheets[] = (string)$s['name'];
    }
    echo "Total Sheets: " . count($sheets) . "\n";
    echo "Sheet Names:\n" . implode(", ", $sheets) . "\n";
    $zip->close();
} else {
    echo "Failed to open zip\n";
}
?>
