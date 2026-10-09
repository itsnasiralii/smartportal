<?php
/**
 * roster_helpers.php - Core Duty Roster calculation engine
 * Converted directly from Python Duty Roster Console
 */

date_default_timezone_set('Asia/Karachi');

// Anchor is the first duty date of the selected starting shift. Flip once
// per duty/off block, including dates before the anchor (floor, not truncation).
function roster_shift_start($day_ts, $anchor_ts, $days_on, $days_off, $start_time, $rotation = 'fixed') {
    if ($rotation !== 'alternating') return $start_time;
    $diff_days = intval(round(($day_ts - $anchor_ts) / 86400));
    $block = (int) floor($diff_days / ($days_on + $days_off));
    $night = $start_time === '19:00';
    if ($block % 2 !== 0) $night = !$night;
    return $night ? '19:00' : '07:00';
}

function roster_is_duty($day_timestamp, $anchor_timestamp, $days_on, $days_off) {
    $cycle_length = $days_on + $days_off;
    if ($cycle_length <= 0) return false;
    $diff_days = intval(round(($day_timestamp - $anchor_timestamp) / 86400));
    $cycle_pos = $diff_days % $cycle_length;
    if ($cycle_pos < 0) $cycle_pos += $cycle_length;
    return $cycle_pos < $days_on;
}

function roster_get_cycle_info($day_ts, $anchor_ts, $days_on, $days_off) {
    $cycle_length = $days_on + $days_off;
    if ($cycle_length <= 0) return [1, 1, 'Duty', 1, 1];
    $diff_days = intval(round(($day_ts - $anchor_ts) / 86400));
    $cycle_pos = ($diff_days % $cycle_length);
    if ($cycle_pos < 0) $cycle_pos += $cycle_length;
    $pos_1based = $cycle_pos + 1;

    if ($pos_1based <= $days_on) {
        return [$pos_1based, $cycle_length, 'Duty', $pos_1based, $days_on];
    } else {
        return [$pos_1based, $cycle_length, 'Off', $pos_1based - $days_on, $days_off];
    }
}

function roster_get_next_blocks($check_ts, $anchor_ts, $days_on, $days_off) {
    $currently_duty = roster_is_duty($check_ts, $anchor_ts, $days_on, $days_off);

    $next_transition = null;
    for ($i = 1; $i <= 366; $i++) {
        $cand = strtotime("+{$i} day", $check_ts);
        if (roster_is_duty($cand, $anchor_ts, $days_on, $days_off) !== $currently_duty) {
            $next_transition = $cand;
            break;
        }
    }

    $subsequent_transition = null;
    if ($next_transition) {
        for ($i = 1; $i <= 366; $i++) {
            $cand = strtotime("+{$i} day", $next_transition);
            if (roster_is_duty($cand, $anchor_ts, $days_on, $days_off) === $currently_duty) {
                $subsequent_transition = $cand;
                break;
            }
        }
    }

    if ($currently_duty) {
        return [$subsequent_transition, $next_transition];
    } else {
        return [$next_transition, $subsequent_transition];
    }
}
?>

