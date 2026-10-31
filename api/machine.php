<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/machine.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET'); http_response_code(405); echo json_encode(['error' => ['code' => 'method_not_allowed']]); exit;
}
echo json_encode(medivendMachineStatus(), JSON_THROW_ON_ERROR);
