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
- version: a string containing the version matching the github package version.
- version_number: a string containing the version number.
- notes: warning messages or additional information (array).

Example response:
{
  "success": true,
  "title": "version",
  "version": "v2.42.1",
  "version_number": "2.42.1",
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once '../../includes/version.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "GET") {
    // Get user from API key
    $user = wallos_api_require($db, 'read');

    $version_number = substr($version, 1);

    $response = [
        "success" => true,
        "title" => "version",
        "version" => $version,
        "version_number" => $version_number,
        "notes" => []
    ];

    echo json_encode($response);

} else {
    $response = [
        "success" => false,
        "title" => "Invalid request method"
    ];
    echo json_encode($response);
    exit;
}

?>