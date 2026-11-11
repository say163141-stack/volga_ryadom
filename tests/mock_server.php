<?php
// Мок для локальных тестов: Apps Script (+302 как у Google), Telegram Bot API, публичный CSV календаря.
$dir = getenv('MOCK_DIR') ?: __DIR__ . '/tmp';
$stateFile = "$dir/sheet.json";
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : array('bookings' => array());
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/gas' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_file("$dir/gas_down")) { http_response_code(503); echo 'down'; exit; }
    $req = json_decode(file_get_contents('php://input'), true);
    if (($req['secret'] ?? '') !== 'test-secret') { $res = array('ok' => false, 'error' => 'forbidden'); }
    else {
        $b = $req['data'];
        $res = array('ok' => true, 'available' => true);
        foreach ($state['bookings'] as $r) {
            if ($r['booking_id'] === $b['booking_id']) { $res['duplicate'] = true; goto done; }
        }
        if (empty($b['force'])) foreach ($state['bookings'] as $r) {
            if ($r['object_id'] === $b['object_id'] && in_array($r['status'], array('', 'pending_payment', 'confirmed'), true)
                && $r['check_in'] < $b['check_out'] && $b['check_in'] < $r['check_out']) { $res = array('ok' => true, 'available' => false); goto done; }
        }
        $state['bookings'][] = $b;
        file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE));
    }
    done:
    $id = uniqid();
    file_put_contents("$dir/gas_$id.json", json_encode($res));
    http_response_code(302); header("Location: /gas_result?id=$id"); exit; // как script.google.com
}
if ($path === '/gas_result') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
    echo file_get_contents("$dir/gas_" . basename($_GET['id']) . '.json'); exit;
}
if ($path === '/calendar.csv') {
    if (is_file("$dir/csv_down")) { http_response_code(503); exit; }
    header('Content-Type: text/csv');
    echo "\"object_id\",\"check_in\",\"check_out\",\"status\"\n";
    foreach ($state['bookings'] as $r) echo "\"{$r['object_id']}\",\"{$r['check_in']}\",\"{$r['check_out']}\",\"{$r['status']}\"\n";
    exit;
}
if (preg_match('#^/bot[^/]+/(sendMessage|getUpdates)$#', $path, $m)) {
    if ($m[1] === 'sendMessage') {
        file_put_contents("$dir/telegram.log", file_get_contents('php://input') . "\n", FILE_APPEND);
        echo json_encode(array('ok' => true, 'result' => array('message_id' => 1)));
    } else {
        echo json_encode(array('ok' => true, 'result' => array(array('update_id' => 1, 'message' => array('chat' => array('id' => 123456789, 'type' => 'private', 'first_name' => 'Тест', 'username' => 'owner'), 'text' => '/start')))));
    }
    exit;
}
http_response_code(404); echo '{}';
