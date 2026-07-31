<?php

$body = json_decode(file_get_contents('php://input'), true);
header('Content-Type: application/json');

if (!is_array($body) || ($body['considerIp'] ?? null) !== false) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'invalid request']]);
    return;
}

echo json_encode([
    'location' => ['lat' => 41.706841, 'lng' => -8.793279],
    'accuracy' => 120,
]);
