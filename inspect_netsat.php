<?php
$zip = new ZipArchive();
$filename = __DIR__ . '/Escalation Matrix Vendors - CNOC - 26032025.xlsx';

if ($zip->open($filename) === TRUE) {
    // 1. Read sharedStrings
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    $strings = [];
    if ($ssXml) {
        $xml = simplexml_load_string($ssXml);
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string)$si->t;
            } elseif (isset($si->r)) {
                $t = '';
                foreach ($si->r as $r) {
                    $t .= (string)$r->t;
                }
                $strings[] = $t;
            } else {
                $strings[] = '';
            }
        }
    }

    // 2. Read workbook relationships to map sheet name to file
    $wbXml = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $relsXml = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    
    $rels = [];
    foreach ($relsXml->Relationship as $r) {
        $rels[(string)$r['Id']] = (string)$r['Target'];
    }

    $sheetFiles = [];
    foreach ($wbXml->sheets->sheet as $s) {
        $name = (string)$s['name'];
        $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $rels[$rId];
        $sheetFiles[$name] = 'xl/' . ltrim($target, '/');
    }

    echo "Netsat target file: " . ($sheetFiles['Netsat'] ?? 'None') . "\n\n";

    // Let us parse Netsat sheet
    if (isset($sheetFiles['Netsat'])) {
        $netsatXml = simplexml_load_string($zip->getFromName($sheetFiles['Netsat']));
        $rows = [];
        foreach ($netsatXml->sheetData->row as $row) {
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
            if (!empty(array_filter($rowData))) {
                $rows[$rNum] = $rowData;
            }
        }

        echo "Netsat Rows Preview:\n";
        foreach (array_slice($rows, 0, 25, true) as $rIdx => $rData) {
            echo "Row $rIdx: " . json_encode($rData, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    $zip->close();
}
?>
