<?php
/**
 * Досылка в Google-таблицу заявок, принятых при недоступном Google.
 * Cron (рекомендуется раз в 10 минут): php /var/www/uXXXXXXX/data/www/volga-ryadom63.ru/api/sync.php
 * Или вручную в браузере: /api/sync.php?key=SETUP_KEY
 */
define('VOLGA_API', 1);
require __DIR__ . '/lib.php';

$cfg = volga_config();
if (!$cfg) { if (PHP_SAPI === 'cli') { fwrite(STDERR, "config missing\n"); exit(1); } volga_json(500, array('ok' => false)); }
if (PHP_SAPI !== 'cli') {
    $key = isset($_GET['key']) ? (string)$_GET['key'] : '';
    if (!$cfg['setup_key'] || !hash_equals($cfg['setup_key'], $key)) volga_json(404, array('ok' => false));
}
$lock = fopen($cfg['data_dir'] . '/booking.lock', 'c');
flock($lock, LOCK_EX);
$res = volga_sync_queue($cfg, 50);
flock($lock, LOCK_UN);
if (PHP_SAPI === 'cli') { echo json_encode($res) . "\n"; exit(0); }
volga_json(200, array('ok' => true) + $res);
