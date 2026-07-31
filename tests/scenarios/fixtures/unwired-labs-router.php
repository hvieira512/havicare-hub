<?php

$body = json_decode(file_get_contents('php://input'), true);
header('Content-Type: application/json');

$logged = is_array($body) ? $body : [];
if (isset($logged['token'])) {
    $logged['token'] = '***';
}
file_put_contents('/tmp/unwired-labs-requests.log', json_encode($logged, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

if (!is_array($body)
    || ($body['token'] ?? null) !== 'scenario-unwired-token'
    || ($body['address'] ?? null) !== 0
    || ($body['bt'] ?? null) !== 0
    || count($body['wifi'] ?? []) < 2
) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    return;
}

echo json_encode([
    'status' => 'ok',
    'lat' => 41.706841,
    'lon' => -8.793279,
    'accuracy' => 120,
]);
