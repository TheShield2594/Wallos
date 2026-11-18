<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- api_key: the API key of the user.
  Send it as "Authorization: Bearer <key>" (or an X-API-Key header); the api_key
  parameter still works but is deprecated. Required scope: read.

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- fixer: an object containing the Fixer settings.
- notes: warning messages or additional information (array).

Example response:
{
  "success": true,
  "title": "fixer",
  "fixer": {
    "api_key": "********",
    "provider": 0,
    "provider_name": "Fixer.io",
    "providers_with_key": [0, 1]
  },
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once '../../includes/fixer_keys.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "GET") {
    // Get user from API key
    $user = wallos_api_require($db, 'read');

    $userId = $user['id'];
    $providers = [
        0 => "Fixer.io",
        1 => "APILayer.com",
        2 => "Frankfurter"
    ];

    $query = "SELECT * FROM fixer WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':userId', $userId);
    $result = $stmt->execute();
    $fixer = $result->fetchArray(SQLITE3_ASSOC);

    $notes = [];

    if ($fixer) {
        unset($fixer['user_id']);
        $fixer['provider_name'] = $providers[$fixer['provider']];
        if ($fixer['api_key']) {
            $fixer['api_key'] = "********";
        }
        // The providers a key is saved for (issue #44); never the keys.
        $fixer['providers_with_key'] = array_keys(wallos_fixer_saved_keys($db, $userId));
    } else {
        $fixer = [];
        $notes[] = "No fixer settings found";
    }

    $response = [
        "success" => true,
        "title" => "fixer",
        "fixer" => $fixer,
        "notes" => $notes
    ];

    echo json_encode($response);

    $db->close();

} else {
    $response = [
        "success" => false,
        "title" => "Invalid request method"
    ];
    echo json_encode($response);
    exit;
}

?>