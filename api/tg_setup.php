<?php
/**
 * Помощник настройки Telegram (только с секретным ключом из config.php → setup_key).
 *  /api/tg_setup.php?key=КЛЮЧ          — показать chat_id всех, кто недавно написал боту (/start)
 *  /api/tg_setup.php?key=КЛЮЧ&test=1   — отправить тестовое сообщение во все tg_chat_ids из config.php
 * chat_id потом вписывается в config.php → tg_chat_ids.
 */
define('VOLGA_API', 1);
require __DIR__ . '/lib.php';

$cfg = volga_config();
if (!$cfg) volga_json(500, array('ok' => false, 'error' => 'config_missing'));
$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!$cfg['setup_key'] || !hash_equals($cfg['setup_key'], $key)) volga_json(404, array('ok' => false));

if (!empty($_GET['test'])) {
    $ok = volga_telegram($cfg, '✅ Тест: уведомления о бронях с сайта volga-ryadom63.ru настроены.');
    volga_json(200, array('ok' => $ok, 'chat_ids' => $cfg['tg_chat_ids']));
}
$url = rtrim($cfg['tg_api_base'], '/') . '/bot' . $cfg['tg_token'] . '/getUpdates';
list($code, $resp) = volga_http('GET', $url, null, 10);
$data = json_decode((string)$resp, true);
$chats = array();
if (is_array($data) && !empty($data['result'])) {
    foreach ($data['result'] as $u) {
        $m = isset($u['message']) ? $u['message'] : (isset($u['my_chat_member']) ? $u['my_chat_member'] : (isset($u['channel_post']) ? $u['channel_post'] : null));
        if (!$m || empty($m['chat'])) continue;
        $c = $m['chat'];
        $chats[$c['id']] = array(
            'chat_id' => $c['id'],
            'type' => isset($c['type']) ? $c['type'] : '',
            'name' => trim((isset($c['title']) ? $c['title'] : '') . ' ' . (isset($c['first_name']) ? $c['first_name'] : '') . ' ' . (isset($c['last_name']) ? $c['last_name'] : '')),
            'username' => isset($c['username']) ? '@' . $c['username'] : '',
        );
    }
}
volga_json(200, array('ok' => $code === 200, 'hint' => 'Если список пуст — напишите боту /start и обновите страницу.', 'chats' => array_values($chats), 'configured' => $cfg['tg_chat_ids']));
