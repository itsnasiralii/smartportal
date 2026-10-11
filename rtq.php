<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/rtq_model.php';
$admin = ($_SESSION['noc_role'] ?? '') === 'admin' || !empty($_SESSION['admin_logged_in']);
if (!$admin && !has_feature_access('rtq')) { http_response_code(403); exit('RTQ access is disabled for this account.'); }
$pdo->exec('CREATE TABLE IF NOT EXISTS rtq_records (id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT NOT NULL, fingerprint TEXT NOT NULL UNIQUE, version INTEGER NOT NULL DEFAULT 1, updated_by TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS rtq_settings (id INTEGER PRIMARY KEY, data TEXT NOT NULL)');
$config=json_decode($pdo->query('SELECT data FROM rtq_settings WHERE id=1')->fetchColumn() ?: file_get_contents(__DIR__.'/rtq_defaults.json'),true);
if (isset($_GET['data']) || $_SERVER['REQUEST_METHOD']==='POST') {
 header('Content-Type: application/json');
 try {
  if ($_SERVER['REQUEST_METHOD']==='POST') {
   $body=json_decode(file_get_contents('php://input'),true);
   if (!is_array($body)) throw new InvalidArgumentException('Invalid request.');
   if (($body['action'] ?? '')==='settings') {
    if (!$admin) { http_response_code(403); exit(json_encode(['error'=>'Administrator access required.'])); }
    $config=rtq_validate_config($body['config'] ?? null);
    $pdo->prepare('INSERT OR REPLACE INTO rtq_settings(id,data) VALUES(1,?)')->execute([json_encode($config)]);
   } elseif (($body['action'] ?? '')==='save') {
    $rows=$body['records'] ?? [];
    if (!is_array($rows) || !count($rows) || count($rows)>1000) throw new InvalidArgumentException('Provide 1 to 1000 records.');
    $pdo->beginTransaction();
    foreach ($rows as $row) {
     $data=rtq_validate($row['data'] ?? [],$config);
     $fingerprint=hash('sha256',json_encode([$data['CSP TT'],strtoupper($data['UniqueID'] ?: $data['NMS User Label']),$data['Complaint Date/Time']]));
     $args=[json_encode($data),$fingerprint,$_SESSION['noc_user'] ?? '',date('c')];
     if (!empty($row['id'])) {
      $stmt=$pdo->prepare('UPDATE rtq_records SET data=?, fingerprint=?, updated_by=?, updated_at=?, version=version+1 WHERE id=? AND version=?');
      $stmt->execute(array_merge($args,[(int)$row['id'],(int)($row['version'] ?? 0)]));
      if (!$stmt->rowCount()) throw new InvalidArgumentException('Record changed by another user. Reload before editing.');
     } else $pdo->prepare('INSERT INTO rtq_records(data,fingerprint,updated_by,updated_at) VALUES(?,?,?,?)')->execute($args);
    }
    $pdo->commit();
   } else throw new InvalidArgumentException('Unknown action.');
  }
  $rows=$pdo->query('SELECT * FROM rtq_records ORDER BY id DESC')->fetchAll();
  foreach ($rows as &$row) { $row['data']=json_decode($row['data'],true); unset($row['fingerprint']); } unset($row);
  echo json_encode(['records'=>$rows,'config'=>$config]);
 } catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error'=>$e instanceof InvalidArgumentException ? $e->getMessage() : 'Save failed. A duplicate ticket/link/complaint time may already exist. No records from this batch were saved.']);
 }
 exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RTQ · Smartportal</title><link rel="stylesheet" href="style.css"><style>
body{padding:24px}.rtq-wrap{max-width:1450px;margin:auto}.rtq-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.rtq-grid input,.rtq-grid select,textarea{width:100%;box-sizing:border-box}textarea{min-height:100px}label{display:block;margin-bottom:6px}button,a.btn-secondary{margin:5px}#rtq-message{white-space:pre-wrap;padding:12px}td{max-width:320px;overflow-wrap:anywhere}summary{cursor:pointer;padding:14px}#rtq-settings{min-height:420px;font-family:monospace}</style></head><body><main class="rtq-wrap">
<a href="index.php" class="btn-secondary">← Smartportal</a><h1>RTQ Management</h1><p>Review link details, apply a case and save complaint records. Times use Asia/Karachi.</p>
<div id="rtq-message" role="status" aria-live="polite"></div>
<section class="panel-card"><div class="panel-body"><label for="rtq-preset">Case preset</label><select id="rtq-preset"><option value="">Select a case</option></select><button type="button" id="rtq-apply" class="btn-secondary">Apply case</button><p>Each preset fills the case fields. You can change any selection before saving.</p>
<form id="rtq-form"><div class="rtq-grid" id="rtq-fields"></div><details><summary>Bulk NMS labels (one per line)</summary><textarea id="rtq-bulk" aria-label="Bulk NMS labels" placeholder="Paste labels for the same ticket, dates and case. Leave empty for a single record."></textarea><p>Unique IDs are extracted only when a supported ID is present. For different tickets or dates, add separate batches.</p></details><button class="btn-primary" id="rtq-save">Save record / batch</button><button class="btn-secondary" type="button" id="rtq-clear">New record</button></form></div></section>
<section class="panel-card"><div class="panel-body"><h2>Saved records <span id="rtq-count"></span></h2><input id="rtq-search" placeholder="Search ticket, label, ID or case" aria-label="Search records"><button class="btn-secondary" id="rtq-export">Export filtered CSV</button><button class="btn-secondary" id="rtq-backup">Download full JSON backup</button><p>Records use the portal database. Download a backup before a redeploy if your hosting has no persistent disk.</p><div class="table-responsive"><table class="data-table"><thead><tr><th>Database TT</th><th>CSP TT / Link</th><th>Issue Found At</th><th>SLA Sheet Working</th><th>RCA / Team</th><th>Status</th><th>Action</th></tr></thead><tbody id="rtq-records"></tbody></table></div></div></section>
<?php if($admin): ?><details class="panel-card" id="settings"><summary>Admin: RTQ dropdowns and case presets</summary><div class="panel-body"><p>Edit option lists and presets below. Existing records remain unchanged.</p><textarea id="rtq-settings" aria-label="RTQ settings JSON"></textarea><button id="rtq-settings-save" class="btn-primary">Save RTQ settings</button></div></details><?php endif; ?>
</main><script>window.rtqBoot=<?= json_encode(['fields'=>rtq_fields(),'config'=>$config,'csrf'=>$_SESSION['csrf']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="rtq.js?v=<?= filemtime(__DIR__.'/rtq.js') ?>"></script></body></html>
