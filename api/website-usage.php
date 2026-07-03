<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// $servername = "pmsglobal.cel8gekqgbno.us-east-1.rds.amazonaws.com";
// $username = "admin";
// $password = "wfxicVdxG71bjvdVhFN3";
// $dbname = "PMS_PRO";

$servername = "localhost";
$username = "root";
$password = "Sanjaykumar@7";
$dbname = "prod_ent1_tenant_0_demo";

try {
    // Create mysqli connection
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }

    // Get parameters from request - 8 parameters matching your procedure
    $startDate = $_GET['startDate'] ?? 'ALL';
    $endDate = $_GET['endDate'] ?? 'ALL';
    $empId = $_GET['empId'] ?? 'ALL';
    $empNames = $_GET['empNames'] ?? 'ALL';
    $department = $_GET['department'] ?? 'ALL';
    $project = $_GET['project'] ?? 'ALL';
    $team = $_GET['team'] ?? 'ALL';
    $userId = $_GET['userId'] ?? 'admin';

    // Call the stored procedure with 8 parameters
    $stmt = $conn->prepare("CALL PR_GET_TBL_WEBSITE_USAGE_SUMMARY(?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssssss', $startDate, $endDate, $empId, $empNames, $department, $project, $team, $userId);
    $stmt->execute();

    // Fetch all results
    $result = $stmt->get_result();
    $results = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Initialize data arrays
    $utilizationData = [];
    $topDomainsData = [];
    $topWebsitesData = [];
    $userActivityData = [];
    $insights = [];
    $observations = [];
    $alerts = [];

    // Process each result row
    foreach ($results as $row) {
        // Map productivity categories based on PRODUCTIVE_YN
        $productivityStatus = '';
        $isProductive = false;

        if (isset($row['PRODUCTIVE_YN'])) {
            switch (strtoupper($row['PRODUCTIVE_YN'])) {
                case 'Y':
                    $productivityStatus = 'Productive';
                    $isProductive = true;
                    break;
                case 'U':
                    $productivityStatus = 'Non-Productive';
                    $isProductive = false;
                    break;
                case 'N':
                    $productivityStatus = 'Restricted';
                    $isProductive = false;
                    break;
                default:
                    $productivityStatus = 'Unknown';
                    $isProductive = false;
            }
        }

        // Build user activity data
        $userActivityData[] = [
            'date' => $row['DATE'] ?? $startDate,
            'user' => $row['EMPNAME'] ?? 'Unknown',
            'emp_id' => $row['EMPID'] ?? '',
            'team' => $row['Team'] ?? 'Unknown',
            'department' => $row['Department'] ?? 'Unknown',
            'project' => $row['Project'] ?? 'Unknown',
            'website' => $row['WEBSITE'] ?? '',
            'website_url' => $row['WEBSITE_URL'] ?? '',
            'duration' => convertTimeToMinutes($row['DURATION'] ?? '00:00:00'),
            'category' => $productivityStatus,
            'is_productive' => $isProductive,
            'notes' => generateNotesFromDuration($row['DURATION'] ?? 0, $isProductive)
        ];

        // Build top domains data
        if (isset($row['WEBSITE']) && !empty($row['WEBSITE'])) {
            $domain = extractDomain($row['WEBSITE_URL'] ?? $row['WEBSITE']);
            $duration = intval($row['DURATION'] ?? 0);

            if ($domain && $duration > 0) {
                $topDomainsData[] = [
                    'domain' => $domain,
                    'time_spent' => $duration,
                    'category' => $productivityStatus
                ];
            }
        }

        // Build top websites data
        if (isset($row['WEBSITE']) && !empty($row['WEBSITE'])) {
            $website = $row['WEBSITE'];
            $timeSpent = intval($row['DURATION'] ?? 0);

            if ($website && $timeSpent > 0) {
                $topWebsitesData[] = [
                    'website' => $website,
                    'time_spent' => $timeSpent,
                    'category' => $productivityStatus
                ];
            }
        }
    }

    // Calculate overall utilization
    $utilizationData = calculateOverallUtilization($userActivityData);

    // Aggregate and sort top domains
    $topDomainsData = aggregateTopDomains($topDomainsData);
    usort($topDomainsData, function($a, $b) {
        return $b['time_spent'] - $a['time_spent'];
    });
    $topDomainsData = array_slice($topDomainsData, 0, 10);

    // Aggregate and sort top websites
    $topWebsitesData = aggregateTopWebsites($topWebsitesData);
    usort($topWebsitesData, function($a, $b) {
        return $b['time_spent'] - $a['time_spent'];
    });
    $topWebsitesData = array_slice($topWebsitesData, 0, 10);

    // Generate insights
    $insights = generateInsights($utilizationData, $userActivityData);
    $observations = generateObservations($userActivityData);
    $alerts = generateAlerts($userActivityData);

    // Return response
    $response = [
        'success' => true,
        'message' => 'Data retrieved successfully',
        'data' => [
            'utilizationData' => $utilizationData,
            'topDomainsData' => $topDomainsData,
            'topWebsitesData' => $topWebsitesData,
            'userActivityData' => $userActivityData,
            'insights' => $insights,
            'observations' => $observations,
            'alerts' => $alerts
        ]
    ];

    echo json_encode($response);
    $conn->close();

} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'data' => []
    ];
    echo json_encode($response);
}

// Helper functions
function extractDomain($url) {
    if (empty($url)) return '';
    $url = preg_replace('/^https?:\/\//', '', $url);
    $url = preg_replace('/^www\./', '', $url);
    $parts = explode('/', $url);
    return $parts[0];
}

function generateNotesFromDuration($duration, $isProductive, $productivityStatus = '') {
    $notes = [];
    $durationMinutes = intval($duration);

    if ($durationMinutes > 60) {
        $notes[] = 'Extended usage (' . round($durationMinutes / 60, 1) . ' hours)';
    } elseif ($durationMinutes > 30) {
        $notes[] = 'Moderate usage (' . $durationMinutes . ' minutes)';
    } else {
        $notes[] = 'Brief usage (' . $durationMinutes . ' minutes)';
    }

    if ($isProductive) {
        $notes[] = 'Productive activity';
    } elseif ($productivityStatus === 'Restricted') {
        $notes[] = 'Restricted activity';
    } else {
        $notes[] = 'Non-productive activity';
    }

    return implode(', ', $notes);
}

function calculateOverallUtilization($userActivityData) {
    if (empty($userActivityData)) {
        return [
            'productive_percentage' => 0,
            'non_productive_percentage' => 0,
            'restricted_percentage' => 0,
            'total_duration' => 0
        ];
    }

    $totalDuration = 0;
    $productiveDuration = 0;
    $nonProductiveDuration = 0;
    $restrictedDuration = 0;

    foreach ($userActivityData as $data) {
        $duration = intval($data['duration']);
        $totalDuration += $duration;

        switch ($data['category'] ?? '') {
            case 'Productive':
                $productiveDuration += $duration;
                break;
            case 'Non-Productive':
                $nonProductiveDuration += $duration;
                break;
            case 'Restricted':
                $restrictedDuration += $duration;
                break;
        }
    }

    if ($totalDuration == 0) {
        return [
            'productive_percentage' => 0,
            'non_productive_percentage' => 0,
            'restricted_percentage' => 0,
            'total_duration' => 0
        ];
    }

    return [
        'productive_percentage' => round(($productiveDuration / $totalDuration) * 100, 1),
        'non_productive_percentage' => round(($nonProductiveDuration / $totalDuration) * 100, 1),
        'restricted_percentage' => round(($restrictedDuration / $totalDuration) * 100, 1),
        'total_duration' => $totalDuration
    ];
}

function aggregateTopDomains($topDomainsData) {
    $aggregated = [];
    foreach ($topDomainsData as $data) {
        $domain = $data['domain'];
        if (!isset($aggregated[$domain])) {
            $aggregated[$domain] = ['domain' => $domain, 'time_spent' => 0, 'category' => $data['category']];
        }
        $aggregated[$domain]['time_spent'] += $data['time_spent'];
    }
    return array_values($aggregated);
}

function aggregateTopWebsites($topWebsitesData) {
    $aggregated = [];
    foreach ($topWebsitesData as $data) {
        $website = $data['website'];
        if (!isset($aggregated[$website])) {
            $aggregated[$website] = ['website' => $website, 'time_spent' => 0, 'category' => $data['category']];
        }
        $aggregated[$website]['time_spent'] += $data['time_spent'];
    }
    return array_values($aggregated);
}

function generateInsights($utilizationData, $userActivityData) {
    $productivePercentage = $utilizationData['productive_percentage'] ?? 0;
    $nonProductivePercentage = $utilizationData['non_productive_percentage'] ?? 0;
    $restrictedPercentage = $utilizationData['restricted_percentage'] ?? 0;
    $totalDuration = $utilizationData['total_duration'] ?? 0;

    if ($productivePercentage >= 70) {
        $message = "Excellent productivity! {$productivePercentage}% of time was productive.";
    } elseif ($productivePercentage >= 50) {
        $message = "Good productivity at {$productivePercentage}%. Consider reducing non-productive and restricted activities.";
    } elseif ($productivePercentage >= 30) {
        $message = "Moderate productivity at {$productivePercentage}%. Significant time spent on non-productive/restricted sites.";
    } else {
        $message = "Low productivity: only {$productivePercentage}% productive usage. Review user behavior and enforce policies.";
    }

    return [
        'message' => $message,
        'productive_percentage' => $productivePercentage,
        'non_productive_percentage' => $nonProductivePercentage,
        'restricted_percentage' => $restrictedPercentage,
        'total_duration_minutes' => $totalDuration
    ];
}

function generateObservations($userActivityData) {
    $totalUsers = count(array_unique(array_column($userActivityData, 'emp_id')));
    $totalSessions = count($userActivityData);
    return [
        'message' => "Analysis covers {$totalUsers} users with {$totalSessions} website sessions. Monitor patterns for optimization opportunities.",
        'total_users' => $totalUsers,
        'total_sessions' => $totalSessions
    ];
}

function generateAlerts($userActivityData) {
    $highNonProductiveUsers = [];
    $highRestrictedUsers = [];
    $userDurations = [];

    foreach ($userActivityData as $data) {
        $empId = $data['emp_id'];
        if (!isset($userDurations[$empId])) {
            $userDurations[$empId] = [
                'total_duration' => 0,
                'non_productive_duration' => 0,
                'restricted_duration' => 0,
                'name' => $data['user']
            ];
        }

        $duration = intval($data['duration']);
        $userDurations[$empId]['total_duration'] += $duration;

        if ($data['category'] === 'Non-Productive') {
            $userDurations[$empId]['non_productive_duration'] += $duration;
        } elseif ($data['category'] === 'Restricted') {
            $userDurations[$empId]['restricted_duration'] += $duration;
        }
    }

    foreach ($userDurations as $empId => $userData) {
        $total = $userData['total_duration'];
        if ($total > 0) {
            $npPercent = ($userData['non_productive_duration'] / $total) * 100;
            $rPercent = ($userData['restricted_duration'] / $total) * 100;
            if ($npPercent > 60) $highNonProductiveUsers[] = $userData['name'];
            if ($rPercent > 30) $highRestrictedUsers[] = $userData['name'];
        }
    }

    $messageParts = [];
    if (count($highNonProductiveUsers) > 0) {
        $messageParts[] = "High non-productive usage (>60%) detected for " . count($highNonProductiveUsers) . " user(s)";
    }
    if (count($highRestrictedUsers) > 0) {
        $messageParts[] = "Restricted websites usage (>30%) detected for " . count($highRestrictedUsers) . " user(s)";
    }

    $message = empty($messageParts)
        ? 'No immediate alerts. All users within acceptable productivity and restriction ranges.'
        : implode('. ', $messageParts) . '. Review recommended.';

    return [
        'message' => $message,
        'non_productive_users' => $highNonProductiveUsers,
        'restricted_users' => $highRestrictedUsers
    ];
}

function convertTimeToMinutes($timeStr) {
    if (empty($timeStr)) return 0;
    $parts = explode(':', $timeStr);
    $hours = isset($parts[0]) ? intval($parts[0]) : 0;
    $minutes = isset($parts[1]) ? intval($parts[1]) : 0;
    $seconds = isset($parts[2]) ? intval($parts[2]) : 0;
    $totalMinutes = $hours * 60 + $minutes + ($seconds / 60);
    return round($totalMinutes, 2);
}
?>