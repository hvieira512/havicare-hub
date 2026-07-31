<?php

$body = json_decode(file_get_contents('php://input'), true);
header('Content-Type: application/json');

file_put_contents('/tmp/beacondb-requests.log', json_encode($body, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

if (!is_array($body) || ($body['considerIp'] ?? null) !== false) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'invalid request']]);
    return;
}

echo json_encode([
    'location' => ['lat' => 41.706841, 'lng' => -8.793279],
    'accuracy' => 120,
]);
