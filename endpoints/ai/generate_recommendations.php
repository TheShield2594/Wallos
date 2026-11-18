<?php
set_time_limit(300);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/ai_client.php';
require_once '../../includes/ai_recommendations.php';



// Load AI settings
$aiSettings = ai_load_settings($db, $userId);

if (!$aiSettings) {
    echo json_encode(["success" => false, "message" => translate('error', $i18n)]);
    exit;
}

// Categories
$stmt = $db->prepare("SELECT * FROM categories WHERE user_id = :user_id");
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$categories = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categories[$row['id']] = $row;
}

// Currencies
$stmt = $db->prepare("SELECT * FROM currencies WHERE user_id = :user_id");
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$currencies = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $currencies[$row['id']] = $row;
}

// Household members
$stmt = $db->prepare("SELECT * FROM household WHERE user_id = :user_id");
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$members = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $members[$row['id']] = $row;
}

// User language
$stmt = $db->prepare("SELECT language FROM user WHERE id = :user_id");
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$userLanguage = $result->fetchArray(SQLITE3_ASSOC)['language'] ?? 'en';

// Language name
require_once '../../includes/i18n/languages.php';
$userLanguageName = $languages[$userLanguage]['name'] ?? 'English';

// Subscriptions
$stmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id = :user_id AND inactive = 0");
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

$subscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $subscriptions[] = $row;
}

if (empty($subscriptions)) {
    echo json_encode(["success" => false, "message" => translate('error', $i18n)]);
    exit;
}

// The shared prompt (includes/ai_recommendations.php). Descriptions and
// website domains go only when the account allows it (on by default).
$includeDescriptions = (int) ($aiSettings['include_descriptions'] ?? 1) === 1;
$subscriptionsForAI = wallos_ai_build_payload($subscriptions, $currencies, $categories, $members, $includeDescriptions);
$prompt = wallos_ai_recommendations_prompt($subscriptionsForAI, $userLanguageName, $includeDescriptions);

// Run the completion against the configured provider
$aiResult = ai_complete($aiSettings, $prompt, $db, $i18n, $userId);

if (!$aiResult['success']) {
    echo json_encode($aiResult);
    exit;
}

$recommendations = ai_extract_json($aiResult['content']);

if (isset($recommendations['recommendations']) && is_array($recommendations['recommendations'])) {
    $recommendations = $recommendations['recommendations'];
}

if (is_array($recommendations)) {
    $normalizedRecommendations = [];

    foreach ($recommendations as $rec) {
        if (!is_array($rec)) {
            continue;
        }

        $title = $rec['title'] ?? null;
        $description = $rec['description'] ?? null;
        $savings = $rec['savings'] ?? '';

        if (!is_scalar($title) || !is_scalar($description)
            || ($savings !== null && !is_scalar($savings))) {
            continue;
        }

        $title = trim((string) $title);
        $description = trim((string) $description);
        $savings = $savings === null ? '' : trim((string) $savings);

        if ($title === '' || $description === '') {
            continue;
        }

        $normalizedRecommendations[] = [
            'title' => $title,
            'description' => $description,
            'savings' => $savings,
        ];
    }

    $recommendations = $normalizedRecommendations;
}

if (!empty($recommendations)) {
    // Clear old recommendations
    $stmt = $db->prepare("DELETE FROM ai_recommendations WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    // Insert new recommendations
    $insert = $db->prepare("
        INSERT INTO ai_recommendations (user_id, type, title, description, savings)
        VALUES (:user_id, :type, :title, :description, :savings)
    ");

    foreach ($recommendations as $rec) {
        $insert->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $insert->bindValue(':type', 'subscription', SQLITE3_TEXT);
        $insert->bindValue(':title', $rec['title'] ?? '', SQLITE3_TEXT);
        $insert->bindValue(':description', $rec['description'] ?? '', SQLITE3_TEXT);
        $insert->bindValue(':savings', $rec['savings'] ?? '', SQLITE3_TEXT);
        $insert->execute();
    }

    echo json_encode([
        "success"         => true,
        "message"         => translate('success', $i18n),
        "recommendations" => $recommendations,
    ]);
    exit;
}

$jsonError = json_last_error_msg();
ai_log_failure($aiSettings['type'] ?? '', $userId, 'invalid_completion_json', [
    'response_length' => strlen($aiResult['content']),
    'json_error' => $jsonError,
]);
echo json_encode([
    "success"    => false,
    "message"    => translate('ai_invalid_json_response', $i18n),
    "json_error" => $jsonError,
]);
exit;
