<?php
// Run each permission case in a separate PHP process; no database is needed.
function has_feature_access($feature) {
    return $feature === 'router' && ($GLOBALS['argv'][1] ?? '') === 'allowed';
}
ob_start();
include __DIR__ . '/../acl_panel.php';
$html = ob_get_clean();
$expected = ($argv[1] ?? '') === 'allowed';
if (str_contains($html, 'id="tab-acl"') !== $expected) {
    fwrite(STDERR, "ACL panel did not respect router permission.\n");
    exit(1);
}
echo 'PASS: ACL panel ' . ($expected ? 'visible with' : 'hidden without') . " router permission.\n";
