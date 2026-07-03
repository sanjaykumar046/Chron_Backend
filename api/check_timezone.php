<?php
// Save this as check_timezone.php and run it

include 'apiMain.php';

header('Content-Type: application/json');

// Set timezone like your email script does
date_default_timezone_set('Asia/Kolkata');
$conn->query("SET time_zone = '+05:30'");

// Get current times
$php_time = date('Y-m-d H:i:s');
$php_timezone = date_default_timezone_get();

// Get MySQL time
$mysql_result = $conn->query("SELECT NOW() as mysql_time, @@session.time_zone as mysql_tz, @@global.time_zone as global_tz");
$mysql_data = $mysql_result->fetch_assoc();

// Get system time
$system_time = shell_exec('date "+%Y-%m-%d %H:%M:%S"');

// Check your schedule record
$schedule_check = $conn->query("SELECT id, next_send, last_sent, updated_at, status FROM EMAIL_SCHEDULES WHERE id = 64");
$schedule = $schedule_check->fetch_assoc();

// Calculate time differences
$now_utc = gmdate('Y-m-d H:i:s');
$now_ist = date('Y-m-d H:i:s');

$result = [
    'php' => [
        'timezone' => $php_timezone,
        'current_time_ist' => $php_time,
        'current_time_utc' => $now_utc,
        'difference_hours' => (strtotime($now_ist) - strtotime($now_utc)) / 3600
    ],
    'mysql' => [
        'session_timezone' => $mysql_data['mysql_tz'],
        'global_timezone' => $mysql_data['global_tz'],
        'current_time' => $mysql_data['mysql_time']
    ],
    'system' => [
        'current_time' => trim($system_time)
    ],
    'schedule_64' => [
        'id' => $schedule['id'],
        'status' => $schedule['status'],
        'next_send' => $schedule['next_send'],
        'last_sent' => $schedule['last_sent'],
        'updated_at' => $schedule['updated_at'],
        'time_since_last_sent_minutes' => $schedule['last_sent'] ? 
            round((strtotime($now_ist) - strtotime($schedule['last_sent'])) / 60, 2) : null,
        'time_since_updated_minutes' => $schedule['updated_at'] ? 
            round((strtotime($now_ist) - strtotime($schedule['updated_at'])) / 60, 2) : null
    ],
    'analysis' => []
];

// Analysis
if ($result['php']['difference_hours'] != 5.5) {
    $result['analysis'][] = "?? WARNING: IST-UTC difference is not 5.5 hours!";
}

if ($mysql_data['mysql_tz'] != '+05:30' && $mysql_data['mysql_tz'] != 'Asia/Kolkata') {
    $result['analysis'][] = "?? WARNING: MySQL timezone is not set to IST!";
}

if ($mysql_data['global_tz'] == 'SYSTEM') {
    $result['analysis'][] = "?? INFO: MySQL using SYSTEM timezone. Check server timezone.";
}

if ($schedule['status'] == 'sending') {
    $stuck_time = round((strtotime($now_ist) - strtotime($schedule['updated_at'])) / 60, 2);
    $result['analysis'][] = "? CRITICAL: Schedule 64 stuck in 'sending' for $stuck_time minutes!";
}

// Check if times match
$mysql_php_diff = abs(strtotime($mysql_data['mysql_time']) - strtotime($php_time));
if ($mysql_php_diff > 60) { // More than 1 minute difference
    $result['analysis'][] = "?? WARNING: PHP and MySQL times differ by " . round($mysql_php_diff / 60, 2) . " minutes!";
}

echo json_encode($result, JSON_PRETTY_PRINT);

$conn->close();
?>