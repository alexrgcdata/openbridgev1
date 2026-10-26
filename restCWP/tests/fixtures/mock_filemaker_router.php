<?php

declare(strict_types=1);

$logPath = getenv('OPENBRIDGE_MOCK_LOG') ?: __DIR__ . DIRECTORY_SEPARATOR . 'mock_filemaker_requests.jsonl';
$headers = function_exists('getallheaders') ? getallheaders() : [];
$body = (string) file_get_contents('php://input');
$request = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'path' => parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
    'query' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY),
    'headers' => $headers,
    'body' => $body,
];
file_put_contents($logPath, json_encode($request, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

header('X-Mock-FileMaker: yes');

$path = (string) $request['path'];
$method = (string) $request['method'];

if ($path === '/fmi/odata/v4/Demo/$metadata') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><edmx:Edmx><edmx:DataServices><Schema><EntityContainer><EntitySet Name="Contacts"/><EntitySet Name="Invoices"/></EntityContainer></Schema></edmx:DataServices></edmx:Edmx>';
    return;
}

if ($path === '/fmi/odata/v4/Demo/Missing') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => '404', 'message' => ['value' => 'Entity set Missing was not found.']]]);
    return;
}

if ($path === '/fmi/odata/v4/Demo/Contacts' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        '@odata.count' => 1,
        '@odata.nextLink' => 'Contacts?$skip=27',
        'value' => [[
            'First Name' => 'Alex',
            'Invoice.Total' => '0012.50',
            'ROWID' => 42,
        ]],
    ], JSON_UNESCAPED_SLASHES);
    return;
}

if (preg_match("~^/fmi/odata/v4/Demo/Contacts\\(.+\\)$~", $path) === 1 && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'First Name' => 'Alex',
        'Primary Key' => rawurldecode($path),
    ], JSON_UNESCAPED_SLASHES);
    return;
}

if ($path === '/fmi/odata/v4/Demo/Script.Touch' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
    return;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => ['code' => 'not_found', 'message' => ['value' => 'Mock route not found.']]]);
