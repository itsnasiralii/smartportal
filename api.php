<?php
require_once __DIR__ . '/auth.php';
set_exception_handler(function (Throwable $error) {
    error_log((string)$error);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'The operation could not be saved. Check the server log and retry.']);
});

require_once 'db.php';
require_once 'noc_helpers.php';

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

$action_permissions = [
    'add_complaints' => ['complaints'],
    'list_complaints' => ['complaints'],
    'remove_complaint' => ['complaints'],
    'get_complaint' => ['complaints'],
    'generate_opening' => ['opening'],
    'generate_customer' => ['customer'],
    'generate_escalation' => ['escalation'],
    'generate_closure' => ['closure'],
    'generate_stats' => ['stats'],
    'generate_progress' => ['progress'],
    'get_dashboard' => ['dashboard', 'complaints'],
    'analyze_outage' => ['outage'],
    'outage_history' => ['outage'],
    'reset_outage_history' => ['outage'],
    'download_report' => ['outage'],
    'download_eml' => ['opening', 'customer', 'escalation', 'closure', 'stats', 'progress'],
    'get_vendor_matrix' => ['matrix', 'escalation'],
    'calculate_roster' => ['roster'],
    'get_router_meta' => ['router'],
    'get_router_commands' => ['router'],
    'get_router_inventory_meta' => ['router'],
    'get_router_inventory' => ['router'],
    'get_vpbx_data' => ['vpbx'],
    'add_vpbx_outgoing' => ['vpbx'],
    'update_vpbx_outgoing' => ['vpbx'],
    'delete_vpbx_outgoing' => ['vpbx'],
    'add_vpbx_incoming' => ['vpbx'],
    'update_vpbx_incoming' => ['vpbx'],
    'delete_vpbx_incoming' => ['vpbx'],
    'clear_vpbx_data' => ['vpbx'],
    'get_vpbx_ivrs' => ['vpbx'],
    'add_vpbx_ivr' => ['vpbx'],
    'delete_vpbx_ivr' => ['vpbx'],
    'search_nms_clients' => ['nms'],
    'get_nms_client' => ['nms'],
    'export_nms_clients' => ['nms'],
];

if (isset($action_permissions[$action])) {
    $allowed = false;
    foreach ($action_permissions[$action] as $req_feat) {
        if (has_feature_access($req_feat)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: You do not have permission to access this feature.']);
        exit;
    }
}

require_once 'noc_extensions.php';

// 1. ADD COMPLAINTS (BULK)
if ($action === 'add_complaints') {
    $raw = trim($_POST['raw_labels'] ?? '');
    $default_issue = trim($_POST['default_issue'] ?? 'Link is down.');
    $ticket = trim($_POST['ticket'] ?? '');

    if (empty($raw)) {
        echo json_encode(['success' => false, 'message' => 'Please provide at least one service label.']);
        exit;
    }

    $lines = explode("\n", str_replace("\r", "", $raw));
    $added = 0;
    $skipped = 0;
    $restarted = 0;

    $stmt_check = $pdo->prepare("SELECT id, status FROM complaints WHERE service_label = ? ORDER BY id DESC LIMIT 1");
    $stmt_insert = $pdo->prepare("
        INSERT INTO complaints (service_label, service_id, service_type, issue_type, ticket, status, last_stage, added_time)
        VALUES (?, ?, ?, ?, ?, 'QUEUED', 'Complaint added - awaiting opening', ?)
    ");

    foreach ($lines as $line) {
        $lbl = trim($line, " \t-•");
        if (empty($lbl)) continue;

        $stmt_check->execute([$lbl]);
        $existing = $stmt_check->fetch();

        if ($existing && in_array($existing['status'], ['QUEUED', 'OPEN'])) {
            $skipped++;
            continue;
        }

        if ($existing && $existing['status'] === 'CLOSED') {
            $restarted++;
        }

        $srv_type = detect_service_type($lbl);
        $srv_id = extract_service_id($lbl);
        $stmt_insert->execute([$lbl, $srv_id, $srv_type, $default_issue, $ticket, date('Y-m-d H:i:s')]);
        $added++;
    }

    $msg = "Added {$added} complaint(s) to queue.";
    if ($skipped > 0) $msg .= " Skipped {$skipped} duplicate(s).";
    if ($restarted > 0) $msg .= " Restarted {$restarted} previously closed complaint(s).";

    echo json_encode(['success' => true, 'message' => $msg]);
    exit;
}

// 2. GET COMPLAINTS LIST / DASHBOARD
if ($action === 'get_dashboard') {
    $filter = $_GET['filter'] ?? 'Queued + Open';
    $sql = "SELECT * FROM complaints";

    if ($filter === 'Open Only') {
        $sql .= " WHERE status = 'OPEN'";
    } elseif ($filter === 'Queued Only') {
        $sql .= " WHERE status = 'QUEUED'";
    } elseif ($filter === 'Closed Only') {
        $sql .= " WHERE status = 'CLOSED'";
    } elseif ($filter === 'Queued + Open') {
        $sql .= " WHERE status IN ('QUEUED', 'OPEN')";
    }
    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $output = [];
    foreach ($rows as $r) {
        $duration = ($r['reported_time'] && $r['restoration_time'])
            ? (no_noc_impact($r['root_cause'] ?? '', $r['issue_found_at'] ?? '', $r['corrective_action'] ?? '') ? 'NA' : calculate_duration($r['reported_time'], $r['restoration_time']))
            : '';

        $output[] = [
            'id' => $r['id'],
            'label' => htmlspecialchars($r['service_label']),
            'service_id' => htmlspecialchars($r['service_id'] ?: extract_service_id($r['service_label'])),
            'service_type' => htmlspecialchars($r['service_type']),
            'issue_type' => htmlspecialchars($r['issue_type']),
            'added_time' => format_dt($r['added_time']),
            'reported_time' => $r['reported_time'] ? format_dt($r['reported_time']) : 'Not opened',
            'age_badge' => incident_age_badge($r['reported_time'], $r['added_time'], $r['status']),
            'restoration_time' => $r['restoration_time'] ? format_dt($r['restoration_time']) : '—',
            'duration' => $duration ?: '—',
            'ticket' => htmlspecialchars($r['ticket'] ?: '—'),
            'last_stage' => htmlspecialchars($r['last_stage']),
            'status' => $r['status']
        ];
    }

    echo json_encode(['success' => true, 'data' => $output]);
    exit;
}

// 3. GENERATE OPENING EMAIL
if ($action === 'generate_opening') {
    $id = intval($_POST['complaint_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $issue = chosen('issue', 'custom_issue') ?: 'Link is down.';
    $ticket = trim($_POST['ticket'] ?? '');
    $priority = trim($_POST['priority'] ?? 'Normal');

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM complaints WHERE id = ?");
        $stmt->execute([$id]);
        $comp = $stmt->fetch();
        if ($comp) {
            $label = $comp['service_label'];
            if (!$ticket) $ticket = $comp['ticket'];
        }
    }

    if (empty($label)) {
        echo json_encode(['success' => false, 'message' => 'Please select a complaint or enter service label.']);
        exit;
    }

    // Resolve typed labels to the active record; never silently reopen a closed incident.
    if ($id > 0 && (!$comp || $comp['status'] === 'CLOSED')) fail_request('Select a queued or open complaint.');
    if ($id <= 0) {
        $stmt = $pdo->prepare("SELECT * FROM complaints WHERE service_label = ? AND status IN ('QUEUED', 'OPEN') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$label]);
        $comp = $stmt->fetch();
        if ($comp) { $id = $comp['id']; if (!$ticket) $ticket = $comp['ticket']; }
    }
    $srv_type = detect_service_type($label);
    $now = request_time();
    if ($id <= 0) {
        $pdo->prepare("INSERT INTO complaints (service_label, service_id, service_type, added_time, status) VALUES (?, ?, ?, ?, 'QUEUED')")->execute([$label, extract_service_id($label), $srv_type, date('Y-m-d H:i:s')]);
        $id = $pdo->lastInsertId();
    }

    // Update in DB if existing
    if ($id > 0) {
        $stmt_up = $pdo->prepare("
            UPDATE complaints
            SET status = 'OPEN',
                reported_time = COALESCE(reported_time, ?),
                issue_type = ?,
                ticket = ?,
                priority = ?,
                last_stage = 'Initial response'
            WHERE id = ?
        ");
        $stmt_up->execute([$now, $issue, $ticket, $priority, $id]);

        $stmt = $pdo->prepare("SELECT reported_time FROM complaints WHERE id = ?");
        $stmt->execute([$id]);
        $reported_time = $stmt->fetchColumn() ?: $now;
    } else {
        $reported_time = $now;
    }

    $subject = make_subject($label, rtrim($issue, '.'), $priority, $ticket);
    $body = "Dear Customer,\n\nWe acknowledge receipt of your complaint regarding service degradation/impact on your {$srv_type} service. Our Corporate NOC has initiated an investigation.\n\nDetails:\nService Details: {$label}\nIssue Type: {$issue}\nIncident Start Time: " . format_dt($reported_time) . "\nCurrent Status: Under investigation\nReference Ticket: {$ticket}\n\nOur technical teams are actively working to identify the root cause and restore service at the earliest possible time. Regular updates will be shared until the issue is fully resolved.\n\nWe appreciate your patience and cooperation.";

    echo json_encode([
        'success' => true,
        'subject' => $subject,
        'body' => $body,
        'runtime' => "✅ Complaint OPENED at " . format_dt($reported_time) . " for " . extract_service_id($label)
    ]);
    exit;
}

// 4. GENERATE CLOSURE EMAIL
if ($action === 'generate_closure') {
    $id = intval($_POST['complaint_id'] ?? 0);
    $found_at = chosen('found_at', 'custom_found_at') ?: 'Customer';
    $root_cause = chosen('root_cause', 'custom_root_cause') ?: 'No Issue Observed';
    $corrective_action = chosen('corrective_action', 'custom_action') ?: 'Auto from Root Cause';
    $final_status = trim($_POST['final_status'] ?? 'Service is up and working normally.');
    $priority = trim($_POST['priority'] ?? 'Normal');

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please select an active complaint from the dropdown.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM complaints WHERE id = ?");
    $stmt->execute([$id]);
    $comp = $stmt->fetch();

    if (!$comp) {
        echo json_encode(['success' => false, 'message' => 'Complaint record not found.']);
        exit;
    }

    $label = $comp['service_label'];
    $srv_type = $comp['service_type'] ?: detect_service_type($label);
    $reported_time = $comp['reported_time'];
    $restoration_time = request_time();
    if ($comp['status'] !== 'OPEN' || !$reported_time) fail_request('Open this complaint before generating its closure.');
    if (strtotime($restoration_time) < strtotime($reported_time)) fail_request('Closing time cannot be earlier than the saved opening time.');

    if ($corrective_action === 'Auto from Root Cause') {
        $auto_actions = $noc_options['AUTO_ACTIONS'];
        $corrective_action = $auto_actions[$root_cause] ?? 'Required corrective action was completed and service was restored.';
    }

    $is_no_issue = no_noc_impact($root_cause, $found_at, $corrective_action);
    $duration = ($is_no_issue || !$reported_time) ? 'NA' : calculate_duration($reported_time, $restoration_time);

    // Update DB
    $stmt_up = $pdo->prepare("
        UPDATE complaints
        SET status = 'CLOSED',
            restoration_time = ?,
            root_cause = ?,
            issue_found_at = ?,
            corrective_action = ?,
            last_stage = 'Closed'
        WHERE id = ?
    ");
    $stmt_up->execute([$restoration_time, $root_cause, $found_at, $corrective_action, $id]);

    $subject = make_subject($label, "Service Restored / Closure", $priority, $comp['ticket']);

    if ($is_no_issue) {
        $body = "Dear Customer,\n\nWe have thoroughly checked the reported service and no abnormality is currently observed at our end.\n\nIncident Summary:\nService Details: {$label}\nIssue Found At: {$found_at}\nRoot Cause: {$root_cause}\nCorrective Action: {$corrective_action}\nReported Time: " . format_dt($reported_time) . "\nVerification Time: " . format_dt($restoration_time) . "\nService Impact Duration: NA\n\nNote: As no issue was identified within our network, the service impact duration is not applicable.\n\nThe service is currently operating normally. Kindly verify and confirm if performance is satisfactory at your end.\n\nWe regret any inconvenience caused and appreciate your cooperation.\n\n{$final_status}";
    } else {
        $body = "Dear Customer,\n\nWe are pleased to inform you that your {$srv_type} service is fully operational.\n\nIncident Summary:\nService Details: {$label}\nIssue Found At: {$found_at}\nRoot Cause: {$root_cause}\nCorrective Action: {$corrective_action}\nReported Time: " . format_dt($reported_time) . "\nRestoration Time: " . format_dt($restoration_time) . "\nService Impact Duration: {$duration}\n\nThe service is now operating normally. Kindly verify and confirm if performance is satisfactory at your end.\n\nWe regret any inconvenience caused and appreciate your cooperation throughout the restoration process.\n\n{$final_status}";
    }

    echo json_encode([
        'success' => true,
        'subject' => $subject,
        'body' => $body,
        'runtime' => "✅ Complaint CLOSED at " . format_dt($restoration_time) . ". Impact Duration: {$duration}"
    ]);
    exit;
}

// 5. GET VENDOR CONTACTS & MATRIX
if ($action === 'get_vendor_matrix') {
    $vendor_id = intval($_GET['vendor_id'] ?? 0);
    $stmt_v = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
    $stmt_v->execute([$vendor_id]);
    $vendor_name = $stmt_v->fetchColumn() ?: 'Vendor';

    $stmt = $pdo->prepare("SELECT * FROM vendor_contacts WHERE vendor_id = ? ORDER BY id ASC");
    $stmt->execute([$vendor_id]);
    $contacts = $stmt->fetchAll();

    $emails = [];
    $l1_l2_emails = [];

    foreach ($contacts as $c) {
        if (!empty($c['email'])) {
            $emails[] = $c['email'];
            if (preg_match('/\b(?:LEVEL\s*|L)[12]\b/i', $c['level'])) {
                $l1_l2_emails[] = $c['email'];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'vendor_name' => $vendor_name,
        'contacts' => $contacts,
        'all_emails' => implode('; ', $emails),
        'l1_l2_emails' => implode('; ', $l1_l2_emails)
    ]);
    exit;
}

// 6. CALCULATE ROSTER
if ($action === 'calculate_roster') {
    require_once 'roster_helpers.php';

    $anchor_str = trim($_POST['anchor'] ?? date('Y-m-d'));
    $start_str = trim($_POST['range_start'] ?? date('Y-m-d'));
    $end_str = trim($_POST['range_end'] ?? date('Y-12-31'));
    $check_date_str = trim($_POST['check_date'] ?? date('Y-m-d'));
    $shift_start_str = trim($_POST['shift_start'] ?? '07:00');
    $check_time_str = trim($_POST['check_time'] ?? date('H:i'));

    $shift_hours = max(1, min(24, intval($_POST['shift_hours'] ?? 12)));
    $days_on = max(1, intval($_POST['days_on'] ?? 4));
    $days_off = max(1, intval($_POST['days_off'] ?? 4));

    $weekends = isset($_POST['weekends']) ? json_decode($_POST['weekends'], true) : ['Saturday', 'Sunday'];
    if (!is_array($weekends)) $weekends = ['Saturday', 'Sunday'];

    $anchor_ts = strtotime($anchor_str);
    $range_start_ts = strtotime($start_str);
    $range_end_ts = strtotime($end_str);
    $check_date_ts = strtotime($check_date_str);

    if ($range_end_ts < $range_start_ts) {
        echo json_encode(['success' => false, 'message' => 'Roster ending date cannot be before starting date.']);
        exit;
    }

    $cur = $range_start_ts;
    $rows = [];
    $duty_days = 0;
    $off_days = 0;
    $weekend_duties = 0;
    $total_hours = 0;
    $remaining_duty = 0;
    $completed_duty = 0;

    while ($cur <= $range_end_ts) {
        $duty = roster_is_duty($cur, $anchor_ts, $days_on, $days_off);
        $wName = date('l', $cur);
        $is_weekend = in_array($wName, $weekends);

        $shift_start_disp = '-';
        $shift_end_disp = '-';
        $hours = 0;

        if ($duty) {
            $duty_days++;
            $hours = $shift_hours;
            $total_hours += $hours;
            if ($is_weekend) $weekend_duties++;
            if ($cur >= $check_date_ts) $remaining_duty++;
            else $completed_duty++;

            $start_dt = strtotime(date('Y-m-d', $cur) . ' ' . $shift_start_str);
            $end_dt = $start_dt + ($shift_hours * 3600);
            $shift_start_disp = date('H:i', $start_dt);
            $shift_end_disp = date('H:i', $end_dt) . (date('Y-m-d', $end_dt) !== date('Y-m-d', $start_dt) ? ' (+1 day)' : '');
        } else {
            $off_days++;
        }

        $rows[] = [
            'date_str' => date('d M Y', $cur),
            'raw_date' => date('Y-m-d', $cur),
            'day' => $wName,
            'is_weekend' => $is_weekend,
            'is_duty' => $duty,
            'shift_start' => $shift_start_disp,
            'shift_end' => $shift_end_disp,
            'hours' => $hours
        ];

        $cur = strtotime('+1 day', $cur);
    }

    $total_days = count($rows);
    $duty_pct = $total_days > 0 ? round(($duty_days / $total_days) * 100, 1) : 0;
    $off_pct = $total_days > 0 ? round(($off_days / $total_days) * 100, 1) : 0;
    $avg_weekly_hours = $total_days > 0 ? round(($total_hours / $total_days) * 7, 1) : 0;

    // Shift Monitor
    $moment = strtotime($check_date_str . ' ' . $check_time_str);
    $active_shift = null;
    $upcoming_shift = null;

    // Check yesterday and today for active shift window
    foreach ([-1, 0] as $offset) {
        $cand_date = strtotime("{$offset} day", $check_date_ts);
        if (roster_is_duty($cand_date, $anchor_ts, $days_on, $days_off)) {
            $s_time = strtotime(date('Y-m-d', $cand_date) . ' ' . $shift_start_str);
            $e_time = $s_time + ($shift_hours * 3600);
            if ($moment >= $s_time && $moment < $e_time) {
                $active_shift = ['start' => $s_time, 'end' => $e_time];
                break;
            }
        }
    }

    if (!$active_shift && roster_is_duty($check_date_ts, $anchor_ts, $days_on, $days_off)) {
        $s_time = strtotime(date('Y-m-d', $check_date_ts) . ' ' . $shift_start_str);
        $e_time = $s_time + ($shift_hours * 3600);
        if ($moment < $s_time) {
            $upcoming_shift = ['start' => $s_time, 'end' => $e_time];
        }
    }

    list($cycle_pos, $cycle_len, $b_type, $b_pos, $b_tot) = roster_get_cycle_info($check_date_ts, $anchor_ts, $days_on, $days_off);
    list($next_duty_ts, $next_off_ts) = roster_get_next_blocks($check_date_ts, $anchor_ts, $days_on, $days_off);

    echo json_encode([
        'success' => true,
        'kpis' => [
            'total_days' => $total_days,
            'duty_days' => $duty_days,
            'off_days' => $off_days,
            'duty_pct' => $duty_pct,
            'off_pct' => $off_pct,
            'weekend_duties' => $weekend_duties,
            'total_hours' => $total_hours,
            'avg_weekly_hours' => $avg_weekly_hours,
            'completed_duty' => $completed_duty,
            'remaining_duty' => $remaining_duty
        ],
        'monitor' => [
            'timestamp_str' => date('l, d F Y • H:i', $moment) . ' PKT',
            'is_active' => ($active_shift !== null),
            'is_upcoming' => ($upcoming_shift !== null),
            'active_text' => $active_shift ? date('H:i', $active_shift['start']) . ' → ' . date('H:i', $active_shift['end']) : '',
            'remaining_minutes' => $active_shift ? max(0, intval(($active_shift['end'] - $moment) / 60)) : 0,
            'upcoming_text' => $upcoming_shift ? date('H:i', $upcoming_shift['start']) . ' → ' . date('H:i', $upcoming_shift['end']) : '',
            'until_minutes' => $upcoming_shift ? max(0, intval(($upcoming_shift['start'] - $moment) / 60)) : 0,
            'block_type' => $b_type,
            'block_pos' => $b_pos,
            'block_tot' => $b_tot,
            'cycle_pos' => $cycle_pos,
            'cycle_len' => $cycle_len,
            'cycle_pct' => $cycle_len > 0 ? round(($cycle_pos / $cycle_len) * 100, 1) : 0,
            'next_duty_str' => $next_duty_ts ? date('d M Y', $next_duty_ts) : '-',
            'next_off_str' => $next_off_ts ? date('d M Y', $next_off_ts) : '-'
        ],
        'daily' => $rows
    ]);
    exit;
}

// ============================================================
// VPBX / REGULATORY TESTING PORTAL ACTIONS
// ============================================================

function vpbx_reindex_outgoing($pdo) {
    $rows = $pdo->query("SELECT id FROM vpbx_outgoing ORDER BY id ASC")->fetchAll();
    $stmt = $pdo->prepare("UPDATE vpbx_outgoing SET case_num = ? WHERE id = ?");
    foreach ($rows as $idx => $r) {
        $stmt->execute([$idx + 1, $r['id']]);
    }
}

function vpbx_reindex_incoming($pdo) {
    $rows = $pdo->query("SELECT id FROM vpbx_incoming ORDER BY id ASC")->fetchAll();
    $stmt = $pdo->prepare("UPDATE vpbx_incoming SET case_num = ? WHERE id = ?");
    foreach ($rows as $idx => $r) {
        $stmt->execute([$idx + 1, $r['id']]);
    }
}

function vpbx_get_data($pdo) {
    $outgoing = $pdo->query("SELECT id, case_num, client, timestamp, master_num, child_num, party_b, dial_mode, dialed_party_b, operator, ivr, status FROM vpbx_outgoing ORDER BY case_num ASC")->fetchAll();
    $incoming = $pdo->query("SELECT id, case_num, client, timestamp, party_a, party_b, operator, ivr, status FROM vpbx_incoming ORDER BY case_num ASC")->fetchAll();
    $ivrs = $pdo->query("SELECT id, message, is_default FROM vpbx_ivrs ORDER BY is_default DESC, id ASC")->fetchAll();
    return ['outgoing' => $outgoing, 'incoming' => $incoming, 'ivrs' => $ivrs];
}

function vpbx_digits_only($val) {
    return preg_replace('/\D/', '', (string)$val);
}

function vpbx_normalize_party_b($val) {
    $d = vpbx_digits_only($val);
    if (strlen($d) === 13 && strpos($d, '66') === 0) {
        return substr($d, 2);
    }
    return $d;
}

if ($action === 'get_vpbx_data') {
    echo json_encode(array_merge(['success' => true], vpbx_get_data($pdo)));
    exit;
}

if ($action === 'get_vpbx_ivrs') {
    $ivrs = $pdo->query("SELECT id, message, is_default FROM vpbx_ivrs ORDER BY is_default DESC, id ASC")->fetchAll();
    echo json_encode(['success' => true, 'ivrs' => $ivrs]);
    exit;
}

if ($action === 'add_vpbx_ivr') {
    if (($_SESSION['noc_role'] ?? '') !== 'admin' && empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin privileges required to manage IVR options.']);
        exit;
    }
    $message = trim($_POST['message'] ?? '');
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'IVR announcement text is required.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO vpbx_ivrs (message, is_default) VALUES (?, 0)");
        $stmt->execute([$message]);
        echo json_encode(array_merge(['success' => true, 'message' => "IVR option '{$message}' added successfully."], vpbx_get_data($pdo)));
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'This IVR option already exists in the system.']);
    }
    exit;
}

if ($action === 'delete_vpbx_ivr') {
    if (($_SESSION['noc_role'] ?? '') !== 'admin' && empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin privileges required to manage IVR options.']);
        exit;
    }
    $ivr_id = intval($_POST['ivr_id'] ?? 0);
    if ($ivr_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Select a valid IVR option to delete.']);
        exit;
    }
    $row = $pdo->query("SELECT * FROM vpbx_ivrs WHERE id = {$ivr_id}")->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'IVR option not found.']);
        exit;
    }
    if (!empty($row['is_default']) || $row['message'] === 'Standard IVR') {
        echo json_encode(['success' => false, 'message' => 'Standard IVR is a core system default and cannot be deleted.']);
        exit;
    }
    $pdo->prepare("DELETE FROM vpbx_ivrs WHERE id = ?")->execute([$ivr_id]);
    echo json_encode(array_merge(['success' => true, 'message' => "IVR option '{$row['message']}' deleted."], vpbx_get_data($pdo)));
    exit;
}

if ($action === 'add_vpbx_outgoing') {
    $client = trim($_POST['client'] ?? '');
    $master = vpbx_digits_only($_POST['master_num'] ?? '');
    $child = vpbx_digits_only($_POST['child_num'] ?? '');
    $party_b = vpbx_normalize_party_b($_POST['party_b'] ?? '');
    $dial_mode = in_array($_POST['dial_mode'] ?? '', ['With 66', 'Without 66']) ? $_POST['dial_mode'] : 'With 66';
    $operator = trim($_POST['operator'] ?? 'Jazz');
    $status = in_array($_POST['status'] ?? '', ['Connected', 'Failed', 'Busy / No Answer']) ? $_POST['status'] : 'Connected';
    $ivr = ($status === 'Connected') ? 'Standard IVR' : trim($_POST['ivr'] ?? 'Standard IVR');
    $timestamp = trim($_POST['timestamp'] ?? date('h:i A'));

    if (empty($client)) {
        echo json_encode(['success' => false, 'message' => 'Client Name is required.']);
        exit;
    }
    if (strlen($master) < 10 || strlen($master) > 11) {
        echo json_encode(['success' => false, 'message' => 'Master Number must contain 10 or 11 digits.']);
        exit;
    }
    if (strlen($child) !== 11 || strpos($child, '03') !== 0) {
        echo json_encode(['success' => false, 'message' => 'Child Number must be an 11-digit Pakistani mobile number starting with 03.']);
        exit;
    }
    if (strlen($party_b) !== 11 || strpos($party_b, '03') !== 0) {
        echo json_encode(['success' => false, 'message' => 'Party B must be an 11-digit Pakistani mobile number starting with 03.']);
        exit;
    }

    $dialed_party_b = ($dial_mode === 'With 66') ? "66{$party_b}" : $party_b;

    $stmt = $pdo->prepare("INSERT INTO vpbx_outgoing (case_num, client, timestamp, master_num, child_num, party_b, dial_mode, dialed_party_b, operator, ivr, status) VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$client, $timestamp, $master, $child, $party_b, $dial_mode, $dialed_party_b, $operator, $ivr, $status]);
    vpbx_reindex_outgoing($pdo);

    echo json_encode(array_merge(['success' => true, 'message' => 'Outgoing test case added.'], vpbx_get_data($pdo)));
    exit;
}

if ($action === 'update_vpbx_outgoing') {
    $case_num = intval($_POST['case_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    if ($case_num <= 0 || empty($new_status)) {
        echo json_encode(['success' => false, 'message' => 'Select a Case ID and a new status first.']);
        exit;
    }

    if ($new_status === 'Connected') {
        $stmt = $pdo->prepare("UPDATE vpbx_outgoing SET status = ?, ivr = 'Standard IVR' WHERE case_num = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE vpbx_outgoing SET status = ? WHERE case_num = ?");
    }
    $stmt->execute([$new_status, $case_num]);

    echo json_encode(array_merge(['success' => true, 'message' => "Case #{$case_num} updated."], vpbx_get_data($pdo)));
    exit;
}

if ($action === 'delete_vpbx_outgoing') {
    $case_num = intval($_POST['case_id'] ?? 0);
    if ($case_num <= 0) {
        echo json_encode(['success' => false, 'message' => 'Select a Case ID to delete.']);
        exit;
    }
    $pdo->prepare("DELETE FROM vpbx_outgoing WHERE case_num = ?")->execute([$case_num]);
    vpbx_reindex_outgoing($pdo);

    echo json_encode(array_merge(['success' => true, 'message' => 'Case deleted.'], vpbx_get_data($pdo)));
    exit;
}

if ($action === 'add_vpbx_incoming') {
    $client = trim($_POST['client'] ?? '');
    $party_a = vpbx_digits_only($_POST['party_a'] ?? '');
    $party_b = vpbx_digits_only($_POST['party_b'] ?? '');
    $operator = trim($_POST['operator'] ?? 'Jazz');
    $status = in_array($_POST['status'] ?? '', ['Received', 'Blocked']) ? $_POST['status'] : 'Received';
    $ivr = ($status === 'Received') ? 'Standard IVR' : trim($_POST['ivr'] ?? 'Standard IVR');
    $timestamp = trim($_POST['timestamp'] ?? date('h:i A'));

    if (empty($client)) {
        echo json_encode(['success' => false, 'message' => 'Client Name is required.']);
        exit;
    }
    if (strlen($party_a) !== 11 || strpos($party_a, '03') !== 0) {
        echo json_encode(['success' => false, 'message' => 'Party A must be an 11-digit mobile number starting with 03.']);
        exit;
    }
    if (strlen($party_b) < 10 || strlen($party_b) > 11) {
        echo json_encode(['success' => false, 'message' => 'Party B must contain 10 or 11 digits.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO vpbx_incoming (case_num, client, timestamp, party_a, party_b, operator, ivr, status) VALUES (0, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$client, $timestamp, $party_a, $party_b, $operator, $ivr, $status]);
    vpbx_reindex_incoming($pdo);

    echo json_encode(array_merge(['success' => true, 'message' => 'Incoming test case added.'], vpbx_get_data($pdo)));
    exit;
}

if ($action === 'update_vpbx_incoming') {
    $case_num = intval($_POST['case_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    if ($case_num <= 0 || empty($new_status)) {
        echo json_encode(['success' => false, 'message' => 'Select a Case ID and a new status first.']);
        exit;
    }

    if ($new_status === 'Received') {
        $stmt = $pdo->prepare("UPDATE vpbx_incoming SET status = ?, ivr = 'Standard IVR' WHERE case_num = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE vpbx_incoming SET status = ? WHERE case_num = ?");
    }
    $stmt->execute([$new_status, $case_num]);

    echo json_encode(array_merge(['success' => true, 'message' => "Case #{$case_num} updated."], vpbx_get_data($pdo)));
    exit;
}

if ($action === 'delete_vpbx_incoming') {
    $case_num = intval($_POST['case_id'] ?? 0);
    if ($case_num <= 0) {
        echo json_encode(['success' => false, 'message' => 'Select a Case ID to delete.']);
        exit;
    }
    $pdo->prepare("DELETE FROM vpbx_incoming WHERE case_num = ?")->execute([$case_num]);
    vpbx_reindex_incoming($pdo);

    echo json_encode(array_merge(['success' => true, 'message' => 'Case deleted.'], vpbx_get_data($pdo)));
    exit;
}

if ($action === 'clear_vpbx_data') {
    $pdo->exec("DELETE FROM vpbx_outgoing");
    $pdo->exec("DELETE FROM vpbx_incoming");
    echo json_encode(['success' => true, 'message' => 'All runtime session cases were cleared.', 'outgoing' => [], 'incoming' => []]);
    exit;
}

// -----------------------------------------------------------------------------
// ROUTER COMMAND LIBRARY ENDPOINTS
// -----------------------------------------------------------------------------
if ($action === 'get_router_meta') {
    $rows = $pdo->query("SELECT platform, category, COUNT(*) AS command_count
                         FROM router_commands
                         WHERE is_active = 1
                         GROUP BY platform, category
                         ORDER BY platform COLLATE NOCASE, category COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);

    $platforms = [];
    $categories = [];
    foreach ($rows as $row) {
        $platform = (string)$row['platform'];
        $category = (string)$row['category'];
        if (!in_array($platform, $platforms, true)) {
            $platforms[] = $platform;
        }
        if (!isset($categories[$platform])) {
            $categories[$platform] = [];
        }
        $categories[$platform][] = [
            'name' => $category,
            'count' => (int)$row['command_count']
        ];
    }

    echo json_encode([
        'success' => true,
        'platforms' => $platforms,
        'categories' => $categories
    ]);
    exit;
}

if ($action === 'get_router_commands') {
    $platform = trim($_GET['platform'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $search = trim($_GET['q'] ?? '');

    // Do not dump the entire command library. A platform + category must be selected.
    if ($platform === '' || $category === '') {
        echo json_encode([
            'success' => true,
            'commands' => [],
            'message' => 'Select a router platform and troubleshooting category.'
        ]);
        exit;
    }

    $sql = "SELECT id, title, platform, category, command_template AS template, description, sort_order
            FROM router_commands
            WHERE is_active = 1 AND platform = ? COLLATE NOCASE AND category = ? COLLATE NOCASE";
    $bind = [$platform, $category];

    if ($search !== '') {
        $sql .= " AND (title LIKE ? OR description LIKE ? OR command_template LIKE ?)";
        $like = '%' . $search . '%';
        $bind[] = $like;
        $bind[] = $like;
        $bind[] = $like;
    }

    $sql .= " ORDER BY sort_order ASC, title COLLATE NOCASE ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);

    echo json_encode([
        'success' => true,
        'commands' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// ROUTER INVENTORY ENDPOINTS
// -----------------------------------------------------------------------------
if ($action === 'get_router_inventory_meta') {
    $devices = $pdo->query("
        SELECT d.id, d.hostname, d.platform, d.source_name, d.imported_at,
               COUNT(DISTINCT i.id) AS interface_count,
               COUNT(DISTINCT v.id) AS vrf_count,
               COUNT(DISTINCT p.id) AS peer_count
        FROM router_devices d
        LEFT JOIN router_interfaces i ON i.device_id = d.id
        LEFT JOIN router_vrfs v ON v.device_id = d.id
        LEFT JOIN router_bgp_peers p ON p.device_id = d.id
        GROUP BY d.id
        ORDER BY d.hostname COLLATE NOCASE
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'devices' => $devices]);
    exit;
}

if ($action === 'get_router_inventory') {
    $deviceId = intval($_GET['device_id'] ?? 0);
    if ($deviceId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Select a stored router first.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, hostname, platform, source_name, imported_at FROM router_devices WHERE id = ?");
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        echo json_encode(['success' => false, 'message' => 'Stored router was not found.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT i.id, i.interface_name, i.description, i.client_name, i.vlan_id, i.vrf, i.bandwidth_kbps,
               ip.ip_address, ip.subnet_mask, ip.prefix_length, ip.is_secondary
        FROM router_interfaces i
        LEFT JOIN router_interface_ips ip ON ip.interface_id = i.id
        WHERE i.device_id = ?
        ORDER BY i.client_name COLLATE NOCASE, i.interface_name COLLATE NOCASE, ip.is_secondary ASC, ip.id ASC
    ");
    $stmt->execute([$deviceId]);
    $interfaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT id, vrf, peer_ip, remote_asn, import_policy, export_policy, import_prefix, export_prefix
        FROM router_bgp_peers
        WHERE device_id = ?
        ORDER BY vrf COLLATE NOCASE, peer_ip
    ");
    $stmt->execute([$deviceId]);
    $peers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT vrf_name FROM router_vrfs WHERE device_id = ? ORDER BY vrf_name COLLATE NOCASE");
    $stmt->execute([$deviceId]);
    $vrfs = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT name FROM router_prefix_lists WHERE device_id = ? ORDER BY name COLLATE NOCASE");
    $stmt->execute([$deviceId]);
    $prefixLists = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT name FROM router_route_policies WHERE device_id = ? ORDER BY name COLLATE NOCASE");
    $stmt->execute([$deviceId]);
    $routePolicies = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'device' => $device,
        'interfaces' => $interfaces,
        'peers' => $peers,
        'vrfs' => $vrfs,
        'prefix_lists' => $prefixLists,
        'route_policies' => $routePolicies
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// NMS CUSTOMER LABEL SEARCH ENDPOINTS
// -----------------------------------------------------------------------------
function nms_build_filter_query($params) {
    $where = [];
    $bind = [];

    // Status pill filter
    $status_pill = trim($params['status_filter'] ?? 'all');
    if ($status_pill === 'Approved') {
        $where[] = "approval_status LIKE '%Approved%'";
    } elseif ($status_pill === 'Rejected') {
        $where[] = "approval_status LIKE '%Rejected%'";
    } elseif ($status_pill === 'Active') {
        $where[] = "status LIKE '%Active%'";
    } elseif ($status_pill === 'Suspended') {
        $where[] = "status LIKE '%Suspended%'";
    } elseif ($status_pill === 'Terminated') {
        $where[] = "(status LIKE '%Terminated%' OR is_terminated LIKE '%Yes%')";
    } elseif ($status_pill === 'ZTE') {
        $where[] = "is_zte = 1";
    }

    // Top search fields
    $fields = [
        'unique_link_id' => 'unique_link_id',
        'client_name' => 'client_name',
        'service' => 'service',
        'department' => 'department',
        'nms_label' => 'nms_user_label',
        'site' => 'site',
        'isp' => 'isp',
        'vlan' => 'vlan',
        'tier_level' => 'tier_level',
        'deployed_region' => 'region',
        'last_action' => 'last_action_taken',
        'handling_region' => 'handling_region',
        'username' => 'dashboard_username'
    ];

    foreach ($fields as $param_key => $col_name) {
        $val = trim($params[$param_key] ?? '');
        if ($val !== '') {
            $where[] = "{$col_name} LIKE ?";
            $bind[] = "%{$val}%";
        }
    }

    // Date range filter
    $start_date = trim($params['start_date'] ?? '');
    $end_date = trim($params['end_date'] ?? '');
    if ($start_date !== '') {
        $where[] = "(go_ahead_date >= ? OR last_action_taken_on >= ?)";
        $bind[] = $start_date;
        $bind[] = $start_date;
    }
    if ($end_date !== '') {
        $where[] = "(go_ahead_date <= ? OR last_action_taken_on <= ?)";
        $bind[] = $end_date . ' 23:59:59';
        $bind[] = $end_date . ' 23:59:59';
    }

    // Column-specific quick search filters (from sub-header input row)
    $col_filters = [
        'col_unique_id' => 'unique_link_id',
        'col_client' => 'client_name',
        'col_nms_label' => 'nms_user_label',
        'col_service' => 'service',
        'col_department' => 'department',
        'col_site' => 'site',
        'col_region' => 'region',
        'col_last_action' => 'last_action_taken',
        'col_last_date' => 'last_action_taken_on',
        'col_tier' => 'tier_level',
        'col_status' => 'approval_status',
        'col_go_ahead' => 'go_ahead_date',
        'col_bw' => 'solution_design_bw',
        'col_username' => 'dashboard_username'
    ];

    foreach ($col_filters as $param_key => $col_name) {
        $val = trim($params[$param_key] ?? '');
        if ($val !== '') {
            $where[] = "{$col_name} LIKE ?";
            $bind[] = "%{$val}%";
        }
    }

    $sql_where = empty($where) ? "" : ("WHERE " . implode(" AND ", $where));
    return ['where' => $sql_where, 'bind' => $bind];
}

if ($action === 'search_nms_clients') {
    $q = nms_build_filter_query($_REQUEST);
    
    // Count total matching
    $count_sql = "SELECT COUNT(*) FROM nms_clients " . $q['where'];
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($q['bind']);
    $total = (int)$stmt_count->fetchColumn();

    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = min(100, max(5, intval($_REQUEST['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $pages = max(1, ceil($total / $limit));

    $select_cols = "id, row_num, unique_link_id, client_name, nms_user_label, service, department, site, region, last_action_taken, last_action_taken_on, tier_level, status, approval_status, go_ahead_date, solution_design_bw, dashboard_username, is_zte";
    $data_sql = "SELECT {$select_cols} FROM nms_clients " . $q['where'] . " ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}";
    $stmt_data = $pdo->prepare($data_sql);
    $stmt_data->execute($q['bind']);
    $records = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'records' => $records,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'limit' => $limit
    ]);
    exit;
}

if ($action === 'get_nms_client') {
    $id = intval($_REQUEST['id'] ?? 0);
    $unique_id = trim($_REQUEST['unique_id'] ?? '');

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM nms_clients WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($unique_id !== '') {
        $stmt = $pdo->prepare("SELECT * FROM nms_clients WHERE unique_link_id = ?");
        $stmt->execute([$unique_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing ID or Unique Link ID.']);
        exit;
    }

    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        echo json_encode(['success' => false, 'message' => 'Customer link not found.']);
        exit;
    }

    $all_data = json_decode($client['all_data_json'] ?? '{}', true);
    unset($client['all_data_json']);
    $client['full_attributes'] = $all_data;

    echo json_encode(['success' => true, 'client' => $client]);
    exit;
}

if ($action === 'export_nms_clients') {
    $q = nms_build_filter_query($_REQUEST);
    $select_cols = "row_num, unique_link_id, client_name, nms_user_label, service, department, site, region, last_action_taken, last_action_taken_on, tier_level, approval_status, status, go_ahead_date, solution_design_bw, dashboard_username, is_zte";
    $sql = "SELECT {$select_cols} FROM nms_clients " . $q['where'] . " ORDER BY id ASC LIMIT 10000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($q['bind']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nms_clients_report_' . date('Y-m-d') . '.csv"');
    
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        '#', 'Unique / FLL Link ID', 'Client Name', 'NMS Label', 'Service', 'Department',
        'Site', 'Deployed Region', 'Last Action Taken', 'Last Date', 'Tier Level',
        'Status', 'State', 'Go Ahead Date', 'Solution BW (Mbps)', 'Username', 'Platform / ZTE'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['row_num'],
            $r['unique_link_id'],
            $r['client_name'],
            $r['nms_user_label'],
            $r['service'],
            $r['department'],
            $r['site'],
            $r['region'],
            $r['last_action_taken'],
            $r['last_action_taken_on'],
            $r['tier_level'],
            $r['approval_status'],
            $r['status'],
            $r['go_ahead_date'],
            $r['solution_design_bw'],
            $r['dashboard_username'],
            !empty($r['is_zte']) ? 'ZTE' : 'NMS'
        ]);
    }
    fclose($out);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
?>
