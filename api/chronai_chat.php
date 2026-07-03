<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'apiMain.php';

date_default_timezone_set('Asia/Kolkata');

set_time_limit(120);
ini_set('max_execution_time', '120');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only POST is allowed.',
    ]);
    exit();
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload.',
    ]);
    exit();
}

$message = trim((string)($payload['message'] ?? ''));
$userid = trim((string)($payload['userid'] ?? 'ALL'));
$accessRole = strtoupper(trim((string)($payload['access_role'] ?? 'EMPLOYEE')));
$model = trim((string)($payload['model'] ?? envOrDefault('OLLAMA_MODEL', 'tinyllama:latest')));
$allowLlm = filter_var($payload['allow_llm'] ?? envOrDefault('CHRONAI_ALLOW_LLM', 'false'), FILTER_VALIDATE_BOOLEAN);
$dashboardContext = is_array($payload['dashboard_context'] ?? null) ? $payload['dashboard_context'] : null;
$liveStartDate = date('Y-m-d');
$liveEndDate = date('Y-m-d');

if ($message === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Message is required.',
    ]);
    exit();
}

function normalizeAccessRole($role) {
    $value = strtoupper(trim((string)$role));
    if (in_array($value, ['ADMIN', 'LEADERSHIP', 'EXECUTIVE', 'SUPER_ADMIN'], true)) {
        return $value;
    }
    if ($value === 'CEO') return 'ADMIN';
    if ($value === 'MANAGER' || $value === 'TEAM_LEADER') return 'LEADERSHIP';
    if ($value === 'EMPLOYEE') return 'EXECUTIVE';
    return 'EXECUTIVE';
}

function validateChronAIUser($conn, $userid, $accessRole) {
    $role = normalizeAccessRole($accessRole);
    $user = trim((string)$userid);

    if ($role === 'SUPER_ADMIN') {
        return [
            'valid' => true,
            'userid' => $user !== '' ? $user : 'SUPER_ADMIN',
            'role' => 'SUPER_ADMIN',
        ];
    }

    if ($user === '' || strtoupper($user) === 'ALL') {
        if ($role === 'ADMIN') {
            return [
                'valid' => true,
                'userid' => 'ALL',
                'role' => 'ADMIN',
            ];
        }

        return [
            'valid' => false,
            'message' => 'A valid employee ID is required for ChronAI.',
        ];
    }

    $stmt = $conn->prepare("SELECT EMPID, ROLE, ACCESS_ROLE, ACTIVE_YN FROM EMP_DB WHERE EMPID = ? LIMIT 1");
    if (!$stmt) {
        return [
            'valid' => false,
            'message' => 'Unable to validate ChronAI user.',
        ];
    }

    $stmt->bind_param('s', $user);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return [
            'valid' => false,
            'message' => 'Invalid employee ID for ChronAI.',
        ];
    }

    if (strtoupper(trim($row['ACTIVE_YN'] ?? 'Y')) !== 'Y') {
        return [
            'valid' => false,
            'message' => 'Inactive employee cannot use ChronAI.',
        ];
    }

    $dbRole = normalizeAccessRole($row['ACCESS_ROLE'] ?? ($row['ROLE'] ?? 'EXECUTIVE'));
    // if ($role !== $dbRole && $role !== 'ADMIN') {
    //     return [
    //         'valid' => false,
    //         'message' => 'ChronAI role does not match the employee record.',
    //     ];
    // }

    return [
        'valid' => true,
        'userid' => $row['EMPID'],
        'role' => $dbRole,
    ];
}

function isChronAIKnowledgeQuestion($message) {
    $text = strtolower(trim((string)$message));
    $starters = [
        'what is ',
        'what are ',
        'what does ',
        'how does ',
        'how do ',
        'why ',
        'explain ',
        'define ',
        'meaning of ',
        'what pages',
        'which pages',
        'what modules',
        'which modules',
    ];

    $liveTerms = ['today', 'yesterday', 'this week', 'this month', 'current', 'now'];

    foreach ($liveTerms as $term) {
        if (strpos($text, $term) !== false) {
            return false;
        }
    }

    foreach ($starters as $starter) {
        if (strpos($text, $starter) === 0) {
            return true;
        }
    }

    return false;
}

function isChronAILiveToolQuestion($message) {
    $text = strtolower(trim((string)$message));
    $needles = [
        'list users',
        'list employees',
        'users belong',
        'employees belong',
        'my users',
        'my team',
        'reportees',
        'belongs to me',
        'app usage',
        'application usage',
        'application timeline',
        'app timeline',
        'website usage',
        'website timeline',
        'url usage',
        'work stream',
        'workstream',
    ];

    foreach ($needles as $needle) {
        if (strpos($text, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function envOrDefault($key, $default) {
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

function buildApiBaseUrl() {
    $configured = envOrDefault('PMS_API_BASE_URL', '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    return $scheme . '://' . $host . '/api';
}

function httpJsonRequest($url, $method = 'GET', $body = null, $headers = [], $timeout = 90, $connectTimeout = 10) {
    $ch = curl_init($url);
    $curlHeaders = array_merge(['Content-Type: application/json'], $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        throw new Exception('HTTP request failed: ' . $curlError);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new Exception('Invalid JSON response from ' . $url);
    }

    if ($httpCode >= 400) {
        $err = $decoded['message'] ?? ($decoded['error'] ?? ('HTTP ' . $httpCode));
        throw new Exception($err);
    }

    return $decoded;
}

function callRagService($message, $userid, $accessRole, $model, $allowLlm, $dashboardContext) {
    $ragUrl = rtrim(envOrDefault('CHRONAI_RAG_SERVICE_URL', 'http://127.0.0.1:8005'), '/') . '/chat';

    // Pass the real userid - don't substitute with role
    $effectiveUserid = (!empty($userid) && strtoupper($userid) !== 'ALL') ? $userid : 'ALL';

    return httpJsonRequest($ragUrl, 'POST', [
        'message' => $message,
        'userid' => $effectiveUserid,
        'access_role' => $accessRole,
        'model' => $model,
        'allow_llm' => $allowLlm,
        'dashboard_context' => $dashboardContext,
    ], [], 120, 30);
}

function extractDashboardContext($insights) {
    $summary = $insights['summary'] ?? [];
    $ops = $insights['ops'] ?? [];
    $hr = $insights['hr'] ?? [];
    $gap = $insights['gap'] ?? [];
    $queries = $insights['queries'] ?? [];

    return [
        'mode' => 'live',
        'period' => $insights['period'] ?? null,
        'summary' => [
            'activePercent' => $summary['activePercent'] ?? null,
            'idlePercent' => $summary['idlePercent'] ?? null,
            'activeUsers' => $summary['activeUsers'] ?? null,
            'idleUsers' => $summary['idleUsers'] ?? null,
            'totalUsers' => $summary['totalUsers'] ?? null,
            'productiveSeconds' => $summary['productiveSeconds'] ?? null,
            'idleSeconds' => $summary['idleSeconds'] ?? null,
            'peakWindow' => $summary['peakWindow'] ?? null,
            'idleWindow' => $summary['idleWindow'] ?? null,
        ],
        'ops' => $ops,
        'hr' => [
            'engagementTrend' => $hr['engagementTrend'] ?? [],
            'departmentComparison' => $hr['departmentComparison'] ?? null,
            'leaveCorrelation' => array_slice($hr['leaveCorrelation'] ?? [], 0, 5),
        ],
        'gap' => [
            'hrmsUsers' => $gap['hrmsUsers'] ?? null,
            'activeSystemUsers' => $gap['activeSystemUsers'] ?? null,
            'totalEmpScope' => $gap['totalEmpScope'] ?? null,
            'onLeave' => $gap['onLeave'] ?? null,
            'roleExempt' => $gap['roleExempt'] ?? null,
            'noAccess' => $gap['noAccess'] ?? null,
            'vendorShadow' => $gap['vendorShadow'] ?? null,
            'hrmsInactive' => $gap['hrmsInactive'] ?? null,
        ],
        'queries' => array_slice($queries, 0, 3),
    ];
}

function buildCompactDashboardContext($context) {
    if (!is_array($context)) {
        return [];
    }

    $summary = is_array($context['summary'] ?? null) ? $context['summary'] : [];
    $comparisonSummary = is_array($context['comparisonSummary'] ?? null) ? $context['comparisonSummary'] : [];
    $ops = is_array($context['ops'] ?? null) ? $context['ops'] : [];
    $gap = is_array($context['gap'] ?? null) ? $context['gap'] : [];
    $hr = is_array($context['hr'] ?? null) ? $context['hr'] : [];
    $queries = is_array($context['queries'] ?? null) ? $context['queries'] : [];
    $period = is_array($context['period'] ?? null) ? $context['period'] : [];
    $comparisonPeriod = is_array($context['comparisonPeriod'] ?? null) ? $context['comparisonPeriod'] : [];

    return [
        'mode' => $context['mode'] ?? null,
        'period' => $period,
        'comparisonPeriod' => $comparisonPeriod,
        'summary' => [
            'activePercent' => $summary['activePercent'] ?? null,
            'idlePercent' => $summary['idlePercent'] ?? null,
            'activeUsers' => $summary['activeUsers'] ?? null,
            'idleUsers' => $summary['idleUsers'] ?? null,
            'totalUsers' => $summary['totalUsers'] ?? null,
            'productiveSeconds' => $summary['productiveSeconds'] ?? null,
            'idleSeconds' => $summary['idleSeconds'] ?? null,
            'peakWindow' => $summary['peakWindow'] ?? null,
            'idleWindow' => $summary['idleWindow'] ?? null,
        ],
        'comparisonSummary' => [
            'activePercent' => $comparisonSummary['activePercent'] ?? null,
            'idlePercent' => $comparisonSummary['idlePercent'] ?? null,
            'productiveSeconds' => $comparisonSummary['productiveSeconds'] ?? null,
            'idleSeconds' => $comparisonSummary['idleSeconds'] ?? null,
        ],
        'ops' => [
            'topTwoShare' => $ops['workload']['topTwoShare'] ?? ($ops['topTwoShare'] ?? null),
            'underutilized' => $ops['underutilized'] ?? null,
            'slaRisk' => $ops['slaRisk'] ?? null,
        ],
        'gap' => [
            'hrmsUsers' => $gap['hrmsUsers'] ?? null,
            'activeSystemUsers' => $gap['activeSystemUsers'] ?? null,
            'totalEmpScope' => $gap['totalEmpScope'] ?? null,
            'onLeave' => $gap['onLeave'] ?? null,
            'roleExempt' => $gap['roleExempt'] ?? null,
            'noAccess' => $gap['noAccess'] ?? null,
            'vendorShadow' => $gap['vendorShadow'] ?? null,
            'hrmsInactive' => $gap['hrmsInactive'] ?? null,
        ],
        'hr' => [
            'engagementTrend' => array_slice($hr['engagementTrend'] ?? [], -3),
            'departmentComparison' => [
                'companyAvg' => $hr['departmentComparison']['companyAvg'] ?? null,
                'departments' => array_slice($hr['departmentComparison']['departments'] ?? [], 0, 3),
            ],
            'leaveCorrelation' => array_slice($hr['leaveCorrelation'] ?? [], 0, 3),
        ],
        'queries' => array_slice($queries, 0, 2),
    ];
}

function buildInsightsUrl($apiBaseUrl, $userid, $startDate, $endDate) {
    return $apiBaseUrl . '/ai_insights.php?' . http_build_query([
        'userid' => $userid,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'reportType' => 'MONTHLY_EXPORT',
    ]);
}

function hasUsefulDashboardContext($context) {
    if (!is_array($context)) {
        return false;
    }

    foreach (['summary', 'comparisonSummary', 'ops', 'hr', 'gap', 'queries', 'period'] as $key) {
        if (!array_key_exists($key, $context)) {
            continue;
        }

        $value = $context[$key];
        if (is_array($value) && !empty($value)) {
            return true;
        }

        if ($value !== null && $value !== '') {
            return true;
        }
    }

    return false;
}

function formatPctValue($value) {
    return ($value === null || $value === '') ? '�' : rtrim(rtrim(number_format((float)$value, 1), '0'), '.') . '%';
}

function formatCountValue($value) {
    return ($value === null || $value === '') ? '�' : (string)$value;
}

function buildChronAIFacts($context) {
    $summary = $context['summary'] ?? [];
    $ops = $context['ops'] ?? [];
    $gap = $context['gap'] ?? [];
    $hr = $context['hr'] ?? [];

    $underutilized = is_array($ops['underutilized'] ?? null) ? $ops['underutilized'] : [];
    $peakWindow = is_array($summary['peakWindow'] ?? null) ? $summary['peakWindow'] : [];
    $idleWindow = is_array($summary['idleWindow'] ?? null) ? $summary['idleWindow'] : [];
    $departmentComparison = is_array($hr['departmentComparison'] ?? null) ? $hr['departmentComparison'] : [];
    $departments = is_array($departmentComparison['departments'] ?? null) ? $departmentComparison['departments'] : [];
    $leaveCorrelation = is_array($hr['leaveCorrelation'] ?? null) ? $hr['leaveCorrelation'] : [];

    $hrmsUsers = (float)($gap['hrmsUsers'] ?? 0);
    $activeSystemUsers = (float)($gap['activeSystemUsers'] ?? 0);
    $visibility = $gap['visibilityRate'] ?? ($hrmsUsers > 0 ? round(($activeSystemUsers / $hrmsUsers) * 100, 1) : null);
    $hrmsGap = $gap['usageGapCount'] ?? ($gap['hrmsInactive'] ?? null);
    $slaRisk = $ops['slaRisk']['changePercent'] ?? null;
    $topTwoShare = $ops['topTwoShare'] ?? ($ops['workload']['topTwoShare'] ?? null);

    $facts = [];
    $facts[] = 'Productive activity: ' . formatPctValue($summary['activePercent'] ?? null);
    $facts[] = 'Idle activity: ' . formatPctValue($summary['idlePercent'] ?? null);
    $facts[] = 'Visibility: ' . formatPctValue($visibility);
    $facts[] = 'HRMS gap: ' . formatCountValue($hrmsGap) . ' users active yesterday but not logged in today';
    $facts[] = 'Productivity change: ' . formatPctValue($slaRisk);
    $facts[] = 'Top-2 workload concentration: ' . formatPctValue($topTwoShare);

    if (!empty($underutilized)) {
        $facts[] = 'Highest idle / low activity team: ' . ($underutilized['team'] ?? '�')
            . ', idle ' . formatPctValue($underutilized['idlePercent'] ?? null)
            . ', utilization ' . formatPctValue($underutilized['utilization'] ?? null);
    }

    if (!empty($peakWindow)) {
        $facts[] = 'Peak window: ' . (($peakWindow['start'] ?? '�') . ' to ' . ($peakWindow['end'] ?? '�'));
    }

    if (!empty($idleWindow)) {
        $facts[] = 'Idle window: ' . (($idleWindow['start'] ?? '�') . ' to ' . ($idleWindow['end'] ?? '�'));
    }

    if (!empty($departments)) {
        $topDept = $departments[0];
        $facts[] = 'Top department: ' . ($topDept['dept'] ?? '�') . ' at ' . ($topDept['value'] ?? '�') . ' hrs/day';
    }

    if (!empty($leaveCorrelation)) {
        $leave = $leaveCorrelation[0];
        $facts[] = 'Highest leave impact: ' . ($leave['dept'] ?? '�')
            . ', leave ' . ($leave['leave'] ?? '�')
            . ', idle ' . formatPctValue($leave['idle'] ?? null);
    }

    return implode("\n", array_map(function ($fact) {
        return '- ' . $fact;
    }, $facts));
}

function humanizeKey($key) {
    $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', (string)$key);
    $key = str_replace(['_', '-'], ' ', $key);
    $key = trim(preg_replace('/\s+/', ' ', $key));

    $aliases = [
        'hrmsUsers' => 'HRMS users',
        'activeSystemUsers' => 'PMS active users today',
        'hrmsInactive' => 'HRMS gap',
        'usageGapCount' => 'HRMS gap',
        'activePercent' => 'productive activity',
        'idlePercent' => 'idle activity',
        'topTwoShare' => 'top two workload concentration',
        'changePercent' => 'productivity change',
    ];

    return $aliases[$key] ?? ucwords($key);
}

function formatFactValue($key, $value) {
    if ($value === null || $value === '') {
        return '�';
    }

    $percentKeys = [
        'activePercent',
        'idlePercent',
        'visibilityRate',
        'idlePercent',
        'utilization',
        'topTwoShare',
        'changePercent',
    ];

    if (in_array($key, $percentKeys, true) && is_numeric($value)) {
        return formatPctValue($value);
    }

    if (is_bool($value)) {
        return $value ? 'yes' : 'no';
    }

    return (string)$value;
}

function flattenFacts($value, $path = [], $facts = []) {
    if (!is_array($value)) {
        return $facts;
    }

    foreach ($value as $key => $child) {
        $nextPath = array_merge($path, [(string)$key]);

        if (is_array($child)) {
            $facts = flattenFacts($child, $nextPath, $facts);
            continue;
        }

        $leafKey = (string)$key;
        $labelParts = array_map('humanizeKey', $nextPath);
        $label = implode(' ', $labelParts);
        $facts[] = [
            'key' => $leafKey,
            'path' => implode('.', $nextPath),
            'label' => $label,
            'value' => formatFactValue($leafKey, $child),
            'raw' => $child,
        ];
    }

    return $facts;
}

function tokenizeText($text) {
    $text = strtolower((string)$text);
    preg_match_all('/[a-z0-9]+/', $text, $matches);
    $stopWords = array_flip([
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'to', 'for', 'of', 'in', 'on',
        'today', 'now', 'current', 'show', 'give', 'me', 'what', 'which', 'who',
        'how', 'many', 'count', 'total', 'please', 'tell', 'about',
    ]);

    return array_values(array_filter($matches[0] ?? [], function ($token) use ($stopWords) {
        return strlen($token) > 1 && !isset($stopWords[$token]);
    }));
}

function scoreFact($questionTokens, $fact) {
    $haystack = strtolower($fact['label'] . ' ' . $fact['path']);
    $score = 0;

    foreach ($questionTokens as $token) {
        if (strpos($haystack, $token) !== false) {
            $score += strlen($token) >= 5 ? 3 : 1;
        }
    }

    return $score;
}

function buildGroundedReply($message, $context) {
    $facts = flattenFacts($context);
    $tokens = tokenizeText($message);

    if (empty($facts)) {
        return "I don't have dashboard data loaded for that yet.";
    }

    $scored = array_map(function ($fact) use ($tokens) {
        $fact['score'] = scoreFact($tokens, $fact);
        return $fact;
    }, $facts);

    usort($scored, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            return strlen($a['label']) <=> strlen($b['label']);
        }
        return $b['score'] <=> $a['score'];
    });

    $matches = array_values(array_filter($scored, function ($fact) {
        return $fact['score'] > 0 && $fact['value'] !== '�';
    }));

    if (empty($matches)) {
        $summary = buildChronAIFacts($context);
        return "**I couldn't find an exact matching metric.** Here are the available dashboard facts:\n" . $summary;
    }

    $top = array_slice($matches, 0, 4);
    $lines = [];
    foreach ($top as $fact) {
        $lines[] = '**' . $fact['label'] . ':** ' . $fact['value'];
    }

    return implode("\n", $lines);
}

try {
    $contextSource = 'frontend';
    $useRagService = filter_var(envOrDefault('CHRONAI_USE_RAG_SERVICE', 'true'), FILTER_VALIDATE_BOOLEAN);

    if ($useRagService && isChronAIKnowledgeQuestion($message) && !isChronAILiveToolQuestion($message)) {
        try {
            $knowledgeRole = normalizeAccessRole($accessRole);
            $ragResponse = callRagService($message, 'KNOWLEDGE', $knowledgeRole, $model, $allowLlm, null);
            $ragReply = trim((string)($ragResponse['reply'] ?? ''));

            if ($ragReply !== '') {
                echo json_encode([
                    'success' => true,
                    'reply' => $ragReply,
                    'model' => $ragResponse['model'] ?? 'rag-service',
                    'source' => $ragResponse['source'] ?? 'rag_service',
                    'route' => $ragResponse['route'] ?? null,
                    'sources' => $ragResponse['sources'] ?? [],
                    'context_generated_at' => null,
                    'context_source' => 'rag_knowledge_first',
                ]);
                $conn->close();
                exit();
            }
        } catch (Exception $ragException) {
            // Continue to dashboard fallback flow if the RAG service is unavailable.
        }
    }

    $validation = validateChronAIUser($conn, $userid, $accessRole);
    if (!$validation['valid']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $validation['message'],
        ]);
        $conn->close();
        exit();
    }

    $userid = $validation['userid'];
    $accessRole = $validation['role'];

if ($useRagService) {
        try {
            $ragResponse = callRagService($message, $userid, $accessRole, $model, $allowLlm, null);
            $ragReply = trim((string)($ragResponse['reply'] ?? ''));
            $ragSource = $ragResponse['source'] ?? '';
            
            $isErrorReply = false;
$isNotFound = false;


error_log("RAG REPLY CHECK: [" . $ragReply . "] isError=" . ($isErrorReply?'true':'false') . " isNotFound=" . ($isNotFound?'true':'false'));



if ($ragReply !== '') {

                echo json_encode([
                    'success' => true,
                    'reply' => $ragReply,
                    'model' => $ragResponse['model'] ?? 'rag-service',
                    'source' => $ragResponse['source'] ?? 'rag_service',
                    'route' => $ragResponse['route'] ?? null,
                    'sources' => $ragResponse['sources'] ?? [],
                    'context_generated_at' => null,
                    'context_source' => 'rag_registry',
                ]);
                $conn->close();
                exit();
            }

          // RAG returned empty reply - continue to dashboard fallback


// echo json_encode([
//     'success' => true,
//     'reply' => 'I could not find an answer for that.',
//     'model' => 'rag-service',
//     'source' => 'rag_empty',
//     'context_source' => 'rag_registry',
// ]);
// $conn->close();
// exit();


        } catch (Exception $ragException) {
            echo json_encode([
                'success' => false,
                'reply' => 'RAG error: ' . $ragException->getMessage(),
                'source' => 'rag_error',
            ]);
            $conn->close();
            exit();
        }
    }

    if (!hasUsefulDashboardContext($dashboardContext)) {
        $contextSource = 'backend';
        $apiBaseUrl = buildApiBaseUrl();
        $yesterdayDate = date('Y-m-d', strtotime($liveStartDate . ' -1 day'));

$todayInsights = httpJsonRequest(
    buildInsightsUrl($apiBaseUrl, $userid, $liveStartDate, $liveEndDate),
    'GET', null, [], 10, 5
);
$yesterdayInsights = httpJsonRequest(
    buildInsightsUrl($apiBaseUrl, $userid, $yesterdayDate, $yesterdayDate),
    'GET', null, [], 10, 5
);

        $dashboardContext = extractDashboardContext($todayInsights);
        $dashboardContext['comparisonPeriod'] = $yesterdayInsights['period'] ?? null;
        $dashboardContext['comparisonSummary'] = $yesterdayInsights['summary'] ?? null;
        $dashboardContext['comparisonOps'] = $yesterdayInsights['ops'] ?? null;
        $insights = $todayInsights;
    } else {
        $insights = [
            'generatedAt' => date('c'),
        ];
    }

    $compactContext = buildCompactDashboardContext($dashboardContext);

    if ($useRagService) {
        try {
            $ragResponse = callRagService($message, $userid, $accessRole, $model, $allowLlm, $dashboardContext);
            $ragReply = trim((string)($ragResponse['reply'] ?? ''));
            error_log("REACHED AFTER RAG EMPTY");

            error_log("RAG Reply: " . $ragReply);
error_log("RAG Source: " . ($ragResponse['source'] ?? 'unknown'));

            if ($ragReply !== '') {
                echo json_encode([
                    'success' => true,
                    'reply' => $ragReply,
                    'model' => $ragResponse['model'] ?? 'rag-service',
                    'source' => $ragResponse['source'] ?? 'rag_service',
                    'route' => $ragResponse['route'] ?? null,
                    'sources' => $ragResponse['sources'] ?? [],
                    'context_generated_at' => $insights['generatedAt'] ?? null,
                    'context_source' => $contextSource,
                ]);
                $conn->close();
                exit();
            }
      } catch (Exception $ragException) {
    error_log("RAG Exception: " . $ragException->getMessage());
    // Phase 1 fallback: keep the existing ChronAI behavior if FastAPI is offline.
}
    }

    if (!$allowLlm) {
        echo json_encode([
            'success' => true,
            'reply' => buildGroundedReply($message, $compactContext),
            'model' => 'dashboard-grounded',
            'source' => 'dashboard_grounded',
            'context_generated_at' => $insights['generatedAt'] ?? null,
            'context_source' => $contextSource,
        ]);
        $conn->close();
        exit();
    }

    $systemPrompt = implode("\n", [
        'You are ChronAI.',
        'Answer only the user question using the facts.',
        'Do not repeat the prompt, role, question, or facts.',
        'Do not output JSON.',
        'Do not invent names or numbers.',
        'Use 1 to 3 short bullets.',
    ]);

    $userPrompt = "Facts:\n"
        . buildChronAIFacts($compactContext)
        . "\n\nQuestion: {$message}\n\nAnswer only:";

    $ollamaUrl = rtrim(envOrDefault('OLLAMA_API_URL', 'http://127.0.0.1:11434'), '/') . '/api/chat';
    $ollamaPayload = [
        'model' => $model,
        'stream' => false,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'options' => [
            'temperature' => 0.2,
            'num_predict' => 120,
            'repeat_penalty' => 1.25,
        ],
    ];

    $ollamaResponse = httpJsonRequest($ollamaUrl, 'POST', $ollamaPayload);
    $reply = trim((string)($ollamaResponse['message']['content'] ?? ''));

    if ($reply === '') {
        throw new Exception('Ollama returned an empty response.');
    }

    echo json_encode([
        'success' => true,
        'reply' => $reply,
        'model' => $model,
        'source' => 'ollama',
        'context_generated_at' => $insights['generatedAt'] ?? null,
        'context_source' => $contextSource,
    ]);
} catch (Exception $e) {
    $fallbackContext = isset($compactContext) && is_array($compactContext)
        ? $compactContext
        : buildCompactDashboardContext($dashboardContext);
    $fallbackReply = buildGroundedReply($message, $fallbackContext);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $e->getMessage(),
        'reply' => $fallbackReply,
        'source' => 'dashboard_grounded_fallback',
        'ollama_error' => $e->getMessage(),
    ]);
}

$conn->close();
?>