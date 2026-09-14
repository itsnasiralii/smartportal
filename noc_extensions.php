<?php
// Shared request validation and restored console workflows.
$noc_options = json_decode(file_get_contents(__DIR__ . '/noc_options.json'), true);
function reply($data) { echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function fail_request($message) { http_response_code(422); reply(['success' => false, 'message' => $message]); }
function posted($name, $default = '') { return trim((string)($_POST[$name] ?? $default)); }
function chosen($name, $manual) {
    $value = posted($name);
    if (in_array($value, ['Other', 'Other / Manual', 'Custom'])) {
        $value = posted($manual);
        if ($value === '') fail_request('Please complete the custom ' . str_replace('_', ' ', $name) . '.');
    }
    return $value;
}
function request_time() {
    if (posted('time_mode') !== 'Manual') return date('Y-m-d H:i:s');
    $raw = posted('manual_time');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, new DateTimeZone('Asia/Karachi'));
    if (!$date || $date->format('Y-m-d\TH:i') !== $raw) fail_request('Enter a valid date and time in Pakistan time.');
    return $date->format('Y-m-d H:i:s');
}
function no_noc_impact($cause, $found, $action) {
    return in_array($cause, ['No Issue Observed', 'No Issue Found after testing', 'Customer related issue', 'Customer own last mile', 'Customer end power issue'])
        || $found === 'Customer' || preg_match('/no issue|no fault|no abnormality|no problem/i', $cause)
        || preg_match('/no activity performed at our end|no activity was performed|already found operational|already operating normally/i', $action);
}
function recipients($raw) {
    if (preg_match('/[\r\n]/', $raw)) fail_request('Email addresses must be on one line.');
    $emails = [];
    foreach (preg_split('/[;,]+/', $raw) as $email) {
        $email = trim($email);
        if ($email === '') continue;
        if (preg_match('/<([^<>]+)>$/', $email, $match)) $email = $match[1];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail_request('Invalid email address: ' . $email);
        $emails[strtolower($email)] = $email;
    }
    return implode(', ', $emails);
}
function matrix_html($contacts) {
    if (!$contacts) return '<p>No escalation contacts configured for this target.</p>';
    $html = '<table border="1" cellpadding="8" style="border-collapse:collapse"><tr><th>Level</th><th>Name</th><th>Designation</th><th>Escalation time</th><th>Phone</th><th>Email</th></tr>';
    foreach ($contacts as $contact) {
        $html .= '<tr>';
        foreach (['level', 'name', 'designation', 'escalation_time', 'phone', 'email'] as $key) $html .= '<td>' . htmlspecialchars($contact[$key] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '</tr>';
    }
    return $html . '</table>';
}
function update_stage($label, $stage) {
    global $pdo;
    $pdo->prepare("UPDATE complaints SET last_stage = ? WHERE service_label = ? AND status IN ('QUEUED', 'OPEN')")->execute([$stage, $label]);
}
function handover($rows) {
    if (!$rows) return 'No outages processed in the current shift history.';
    $lines = ['SHIFT HANDOVER SUMMARY / DAILY OUTAGE REPORT', 'Total Outages: ' . count($rows), 'Total Down Links Processed: ' . array_sum(array_column($rows, 'total_links')), 'High Priority Links (>250M): ' . array_sum(array_column($rows, 'priority_count')), ''];
    foreach (array_reverse($rows) as $r) $lines[] = "Outage #{$r['id']} ({$r['processed_time']}): {$r['total_links']} links down ({$r['summary']}) | Occurred: {$r['occurred_time']}";
    return implode("\n", $lines);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !in_array($action, ['get_dashboard', 'get_vendor_matrix', 'list_complaints', 'outage_history', 'download_report', 'get_vpbx_data', 'get_vpbx_ivrs', 'search_nms_clients', 'get_nms_client', 'export_nms_clients'])) {
    http_response_code(405); reply(['success' => false, 'message' => 'Use POST for this action.']);
}
// Serialize complaint changes across team sessions, as in the Python shared-state lock.
if (in_array($action, ['add_complaints', 'generate_opening', 'generate_closure', 'remove_complaint', 'generate_progress', 'generate_escalation'])) {
    $pdo->exec('BEGIN IMMEDIATE');
    register_shutdown_function(function () use ($pdo) {
        $error = error_get_last();
        $failed = http_response_code() >= 400 || ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]));
        $pdo->exec($failed ? 'ROLLBACK' : 'COMMIT');
    });
}
if ($action === 'list_complaints') reply(['success' => true, 'data' => $pdo->query('SELECT * FROM complaints ORDER BY id DESC')->fetchAll()]);
if ($action === 'remove_complaint') {
    $pdo->prepare('DELETE FROM complaints WHERE id = ?')->execute([(int)posted('complaint_id')]);
    reply(['success' => true, 'message' => 'Complaint removed.']);
}
if ($action === 'generate_customer' || $action === 'generate_stats' || $action === 'generate_progress' || $action === 'generate_escalation') {
    $label = posted('label');
    if ($label === '') fail_request('Please enter a service label or load a complaint.');
    $priority = posted('priority', 'Normal');
    $policy = '';
    $ticket = posted('ticket');
    if ($action === 'generate_customer') {
        $findings = json_decode(posted('findings', '[]'), true);
        if (!is_array($findings)) fail_request('Invalid findings.');
        $bullets = array_map(fn($f) => '• ' . rtrim((string)$f, '.') . '.', $findings);
        $subject = make_subject($label, posted('issue_summary') ?: 'Customer-End Verification', $priority, $ticket);
        $body = "Dear Customer,\n\nWe have thoroughly checked the reported service.\n\nFindings are shared below:\n" . ($bullets ? implode("\n", $bullets) : '• No abnormality is currently observed at our end.') . "\n\n" . ($noc_options['customer_action_sentence'][posted('requested_action')] ?? posted('requested_action')) . "\n\nPlease share your feedback so we can proceed accordingly.\n\nYour cooperation in this matter will be highly appreciated.";
        if (detect_service_type($label) === 'Turbonet') $policy = 'Internal Turbo policy: Do not share the Turbonet graph with the customer. Route graph requests through KAM/GCSSQ.';
    } elseif ($action === 'generate_stats') {
        $scenario = posted('scenario');
        if (!isset($noc_options['stats_items'][$scenario])) fail_request('Select a valid troubleshooting scenario.');
        $subject = make_subject($label, 'Required Stats - ' . $scenario);
        $body = salutation(posted('audience', 'Customer')) . "\n\nTo proceed with detailed investigation of the reported issue, kindly share the following information/statistics:\n\n• " . implode("\n• ", $noc_options['stats_items'][$scenario]);
        if (posted('context')) $body .= "\n\n" . posted('context');
        if ($scenario === 'Banking Application Issue') $body .= "\n\nPCAPdroid capture method:\nSelect PCAP file → select the target application → START capture → reproduce the banking-app issue/login error → STOP capture → share the generated PCAP file.";
        $body .= "\n\nOnce the required information is received, we will proceed with further investigation accordingly.\n\nYour cooperation is highly appreciated.";
        if ($scenario === 'Turbo Slow Speed') $policy = 'Internal Turbo policy: Never share the Turbonet graph directly with the customer. Route graph requests to KAM; graph-policy escalation should be handled with GCSSQ.';
    } elseif ($action === 'generate_progress') {
        $status = posted('status');
        if (!isset($noc_options['PROGRESS_TEXT'][$status])) fail_request('Select a valid progress status.');
        $subject = make_subject($label, 'Progress Update - ' . $status, $priority);
        $body = salutation(posted('audience', 'Customer')) . "\n\nPlease find the latest update regarding the reported service:\n\n" . $noc_options['PROGRESS_TEXT'][$status];
        if (posted('note')) $body .= "\n\n" . posted('note');
        $ettr = format_ettr(posted('ettr', 'Awaited'), posted('custom_ettr'));
        if ($ettr) $body .= "\n\n" . $ettr;
        $body .= "\n\nFurther updates will be shared accordingly.\n\nYour patience and cooperation are highly appreciated.";
        update_stage($label, $status);
    } else {
        $target = posted('target');
        if (!$target) fail_request('Select a vendor or enter an internal team.');
        $to = recipients(posted('to'));
        $cc = recipients(posted('cc'));
        $format = posted('format', 'A');
        $subject = $format === 'C' ? '[' . ($ticket ?: 'No-Ticket') . '] : [' . (posted('custom_subject') ?: $label) . "] : [{$target}]" : ($format === 'B' ? "{$label} || {$target}" : make_subject($label, posted('level') . ' - ' . $target, $priority, $ticket));
        $intro = $format === 'B' ? 'Please check the link mentioned in subject' : 'Please check the below-mentioned service';
        $body = "Dear {$target} Team,\n\n{$intro}, as the customer is currently facing a connectivity issue.\n\nService Details: {$label}\n\nKindly investigate the issue, restore the service on priority, and share the ETTR at the earliest.";
        $level = posted('level');
        $levels = ['Follow-up' => 'Please share the latest progress on our previous request.', 'Urgent' => 'Kindly prioritize the reported issue on urgent basis.', 'Critical' => 'Immediate engagement and restoration on top priority are required.', 'Multiple follow-ups / no response' => 'Despite repeated follow-ups, we have not received a progressive update. Kindly share a clear action plan.', 'Request exact delay reason / RFO' => 'Kindly share the exact reason for the delay, clear RFO, defined action plan, and expected restoration timeline.'];
        if (isset($levels[$level])) $body .= "\n\n" . $levels[$level];
        if (posted('findings')) $body .= "\n\nCurrent Findings:\n" . posted('findings');
        if (posted('requested_action')) $body .= "\n\nRequired Action: " . posted('requested_action');
        $body .= "\n\nYour prompt support and cooperation will be highly appreciated.";
        $stmt = $pdo->prepare('SELECT c.* FROM vendor_contacts c JOIN vendors v ON v.id = c.vendor_id WHERE v.name = ? ORDER BY c.id');
        $stmt->execute([$target]);
        $matrix = matrix_html($stmt->fetchAll());
        update_stage($label, 'Escalation - ' . $level . ' - ' . $target);
        reply(compact('subject', 'body', 'to', 'cc', 'matrix', 'target') + ['success' => true]);
    }
    reply(compact('subject', 'body', 'policy') + ['success' => true]);
}
if ($action === 'download_eml') {
    $to = recipients(posted('to')); $cc = recipients(posted('cc'));
    $subject = posted('subject'); $body = posted('body');
    if (!$subject || !$body) fail_request('Generate an escalation before downloading a draft.');
    $boundary = 'noc-' . bin2hex(random_bytes(16));
    $headers = ['X-Unsent: 1', 'MIME-Version: 1.0', 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?='];
    if ($to) $headers[] = 'To: ' . $to;
    if ($cc) $headers[] = 'Cc: ' . $cc;
    $from = getenv('NOC_FROM_EMAIL') ?: '';
    if ($from && filter_var($from, FILTER_VALIDATE_EMAIL)) $headers[] = 'From: ' . $from;
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $stmt = $pdo->prepare('SELECT c.* FROM vendor_contacts c JOIN vendors v ON v.id = c.vendor_id WHERE v.name = ? ORDER BY c.id');
    $stmt->execute([posted('target')]);
    $html = '<html><body><div style="white-space:pre-wrap;font-family:Calibri,Arial">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</div><hr>' . matrix_html($stmt->fetchAll()) . '</body></html>';
    $message = implode("\r\n", $headers) . "\r\n\r\n";
    foreach (['plain' => $body, 'html' => $html] as $type => $text) {
        $message .= "--{$boundary}\r\nContent-Type: text/{$type}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n");
    }
    $message .= "--{$boundary}--\r\n";
    header('Content-Type: message/rfc822');
    header('Content-Disposition: attachment; filename="Escalation.eml"');
    echo $message; exit;
}
if ($action === 'outage_history') {
    $rows = $pdo->query('SELECT * FROM outage_history ORDER BY id DESC')->fetchAll();
    reply(['success' => true, 'data' => $rows, 'handover' => handover($rows)]);
}
if ($action === 'reset_outage_history') {
    $pdo->exec('DELETE FROM outage_history');
    reply(['success' => true, 'message' => 'Shift history cleared.']);
}
if ($action === 'analyze_outage') {
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) fail_request('Upload a CSV, XLS or XLSX alarm dump within the server upload limit.');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xls', 'xlsx']) || $file['size'] > 20 * 1024 * 1024) fail_request('Use a CSV, XLS or XLSX file smaller than 20 MB.');
    $token = bin2hex(random_bytes(16));
    $dir = $noc_data_dir . '/exports/' . $token;
    if (!mkdir($dir, 0700, true)) fail_request('Could not create the export directory.');
    $input = $dir . '/input.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $input)) fail_request('Could not store the upload.');
    $python = getenv('NOC_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
    $proc = proc_open([$python, __DIR__ . '/outage_worker.py', $input, $dir], [1 => ['pipe', 'w'], 2 => ['file', $dir . '/error.log', 'w']], $pipes);
    if (!is_resource($proc)) fail_request('Outage analyzer requires Python with pandas, openpyxl, xlrd and tzdata. Configure NOC_PYTHON.');
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $code = proc_close($proc); unlink($input);
    $result = json_decode($output, true);
    if ($code !== 0 || !$result) fail_request('Outage analyzer could not run. Verify NOC_PYTHON and install the packages in requirements-outage.txt.');
    if (!$result['success']) reply($result);
    $h = $result['history'];
    $pdo->prepare('INSERT INTO outage_history (processed_time, file_name, total_links, priority_count, summary, occurred_time) VALUES (?, ?, ?, ?, ?, ?)')->execute([date('Y-m-d H:i:s'), basename($file['name']), $h['total_links'], $h['priority_count'], $h['summary'], $h['occurred_time']]);
    reply(['success' => true, 'output' => $result['output'], 'token' => $token]);
}
if ($action === 'download_report') {
    $token = $_GET['token'] ?? '';
    $kind = $_GET['kind'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !in_array($kind, ['full', 'simple'])) fail_request('Invalid report.');
    $path = $noc_data_dir . '/exports/' . $token . '/' . $kind . '.xlsx';
    if (!is_file($path)) fail_request('Report no longer exists. Analyze the file again.');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . ($kind === 'full' ? 'Outage_Links' : 'Sample_Links') . '.xlsx"');
    readfile($path); exit;
}
