<?php
/**
 * noc_helpers.php - Core telecom processing logic, string extractors, and formatting
 * Converted directly from the Python NOC Console
 */

date_default_timezone_set('Asia/Karachi');

function clean_text($val) {
    return $val === null ? '' : trim((string)$val);
}

function get_current_time_str() {
    return date('Y-m-d H:i:s');
}

function format_dt($val) {
    if (!$val) return 'NA';
    $ts = is_numeric($val) ? $val : strtotime($val);
    if (!$ts) return 'NA';
    return date('d-m-Y h:i A', $ts);
}

function detect_service_type($text) {
    $val = strtoupper(clean_text($text));
    $checks = [
        ['BGP', 'BGP DIA'],
        ['MPLS', 'MPLS'],
        ['DPLC', 'DPLC'],
        ['TURBONET', 'Turbonet'],
        ['TURBO', 'Turbonet'],
        ['SIP PRI', 'SIP PRI'],
        ['SIP_PRI', 'SIP PRI'],
        ['IPLC', 'IPLC'],
        ['DARKCORE', 'Darkcore Fiber'],
        ['DARK CORE', 'Darkcore Fiber'],
        ['M2M', 'M2M'],
        ['PRI', 'PRI'],
        ['SIP', 'SIP'],
        ['DIA', 'DIA'],
    ];

    foreach ($checks as $item) {
        if (strpos($val, $item[0]) !== false) {
            return $item[1];
        }
    }
    return 'Corporate';
}

function extract_service_id($label) {
    $val = clean_text($label);
    if (!$val) return 'Service';

    $patterns = [
        '/\bDPLC\d+SL\d+\b/i',
        '/\bDIA\d+SL\d+\b/i',
        '/\bTurbo\d+SL\d+\b/i',
        '/\bLNK[A-Z0-9]+\b/i',
        '/\bMPLS[A-Z0-9-]*\b/i',
        '/\bPRI[A-Z0-9-]*\b/i',
        '/\bSIP[A-Z0-9-]*\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $val, $matches)) {
            return $matches[0];
        }
    }

    if (strpos($val, '_') !== false) {
        $parts = explode('_', $val);
        $candidate = trim(end($parts));
        if ($candidate) return substr($candidate, 0, 70);
    }

    return substr($val, 0, 70);
}

function priority_prefix($priority) {
    $map = [
        'Normal' => '',
        'Follow-up' => 'FOLLOW-UP | ',
        'Urgent' => 'URGENT | ',
        'Critical' => 'CRITICAL | ',
    ];
    return $map[$priority] ?? '';
}

function make_subject($label, $topic, $priority = 'Normal', $ticket = '') {
    $service_id = extract_service_id($label);
    $ticket = clean_text($ticket);
    $ticket_part = $ticket ? " | {$ticket}" : '';
    return priority_prefix($priority) . "{$topic} | {$service_id}{$ticket_part}";
}

function salutation($audience) {
    return ($audience === 'Customer') ? 'Dear Customer,' : 'Dear Team,';
}

function calculate_duration($start_time, $end_time) {
    if (!$start_time || !$end_time) return 'NA';
    $t1 = is_numeric($start_time) ? $start_time : strtotime($start_time);
    $t2 = is_numeric($end_time) ? $end_time : strtotime($end_time);
    if (!$t1 || !$t2) return 'NA';

    $total_minutes = max(0, intval(($t2 - $t1) / 60));
    $days = intdiv($total_minutes, 1440);
    $rem = $total_minutes % 1440;
    $hours = intdiv($rem, 60);
    $minutes = $rem % 60;

    if ($days > 0) return "{$days} Day(s) {$hours} Hour(s) {$minutes} Minute(s)";
    if ($hours > 0) return "{$hours} Hour(s) {$minutes} Minute(s)";
    return "{$minutes} Minute(s)";
}

function elapsed_text($start_time, $end_time = null) {
    if (!$start_time) return 'Not started';
    $t1 = is_numeric($start_time) ? $start_time : strtotime($start_time);
    $t2 = $end_time ? (is_numeric($end_time) ? $end_time : strtotime($end_time)) : time();
    $minutes = max(0, intval(($t2 - $t1) / 60));

    if ($minutes >= 1440) {
        $days = intdiv($minutes, 1440);
        $rem = $minutes % 1440;
        $hours = intdiv($rem, 60);
        $mins = $rem % 60;
        return "{$days}d {$hours}h {$mins}m";
    }
    if ($minutes >= 60) {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        return "{$hours}h {$mins}m";
    }
    return "{$minutes}m";
}

function incident_age_badge($reported_time, $added_time = null, $status = 'OPEN') {
    $base = $reported_time ?: $added_time;
    if (!$base) return '—';
    if ($status === 'CLOSED') return '<span class="status-pill status-closed">Closed</span>';

    $t1 = is_numeric($base) ? $base : strtotime($base);
    $minutes = max(0, intval((time() - $t1) / 60));

    if ($minutes < 5) {
        $marker = '🟢';
        $class = 'status-green';
    } elseif ($minutes < 15) {
        $marker = '🟡';
        $class = 'status-yellow';
    } elseif ($minutes < 30) {
        $marker = '🟠';
        $class = 'status-orange';
    } else {
        $marker = '🔴';
        $class = 'status-red';
    }

    $prefix = (!$reported_time) ? 'Queued ' : '';
    $elapsed = elapsed_text($base);
    return "<span class=\"status-pill {$class}\">{$marker} {$prefix}{$elapsed}</span>";
}

function format_ettr($ettr_choice, $custom_ettr = '') {
    if ($ettr_choice === 'Awaited') return "ETTR will be shared once received from the concerned team.";
    if ($ettr_choice === 'Not Available') return "Currently, no confirmed ETTR is available. Further updates will be shared accordingly.";
    if ($ettr_choice === 'Not Applicable') return "";
    if ($ettr_choice === 'Custom') {
        $val = clean_text($custom_ettr);
        return $val ? "The tentative ETTR shared by the concerned team is {$val}." : "ETTR will be shared once received from the concerned team.";
    }
    return "The tentative ETTR shared by the concerned team is {$ettr_choice}.";
}
?>
