<?php
/**
 * Волга Рядом — общая логика приёма заявок.
 * Совместимо с PHP 7.4 и 8.x, без composer.
 * Секреты здесь НЕ хранятся: они в config.php вне веб-корня (см. HANDOVER.md).
 */
if (!defined('VOLGA_API')) { http_response_code(404); exit; }

/* ---------- конфиг ---------- */
function volga_config_path()
{
    $env = getenv('VOLGA_CONFIG');
    if ($env && is_file($env)) return $env;
    $candidates = array(
        dirname(__DIR__, 3) . '/volga-private/config.php', // /var/www/uXXX/data/volga-private (рекомендуется)
        dirname(__DIR__, 2) . '/volga-private/config.php', // /var/www/uXXX/data/www/volga-private
    );
    foreach ($candidates as $p) { if (@is_file($p)) return $p; }
    return null;
}

function volga_config()
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = volga_config_path();
    if (!$path) return null;
    $user = require $path;
    if (!is_array($user)) return null;
    $defaults = array(
        'timezone' => 'Europe/Samara',
        'gas_url' => '',
        'gas_secret' => '',
        'calendar_csv_url' => 'https://docs.google.com/spreadsheets/d/1B3WqP-4xF3rgqtBeoYLwNJgzBQfuDcMqesXrgxcR_mI/gviz/tq?tqx=out:csv&gid=1360421097',
        'tg_token' => '',
        'tg_chat_ids' => array(),
        'tg_api_base' => 'https://api.telegram.org',
        'mail_to' => array('volga.ryadom@yandex.ru'),
        'mail_from' => 'noreply@volga-ryadom63.ru',
        'mail_transport' => 'mail', // 'mail' | 'file' (для тестов) | 'none'
        'data_dir' => dirname($path) . '/data',
        'setup_key' => '',
        'allowed_origins' => array('https://www.volga-ryadom63.ru', 'https://volga-ryadom63.ru', 'http://www.volga-ryadom63.ru', 'http://volga-ryadom63.ru'),
        'min_fill_seconds' => 4,
        'rate_limit' => array('per_10min' => 5, 'per_day' => 20),
        'max_nights' => 60,
        'max_guests' => 2,
        'houses' => array(
            'house_1' => 'Первый нижний домик',
            'house_2' => 'Второй нижний домик',
            'house_3' => 'Первый верхний домик',
            'house_4' => 'Второй верхний домик',
        ),
        'price_weekday' => 6000,
        'price_weekend' => 8000,
        'weekend_nights' => array(5, 6), // ночь с пятницы и с субботы (ISO: 1=пн … 7=вс)
        'price_extra_bed' => 1500,
        'prepayment_percent' => 50,
        'http_timeout' => 15,
    );
    $cfg = array_replace($defaults, $user);
    date_default_timezone_set($cfg['timezone']);
    if (!is_dir($cfg['data_dir'])) @mkdir($cfg['data_dir'], 0700, true);
    return $cfg;
}

/* ---------- ответы ---------- */
function volga_json($status, $data)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function volga_log($msg)
{
    $cfg = volga_config();
    if (!$cfg) return;
    $line = date('c') . ' ' . $msg . "\n";
    @file_put_contents($cfg['data_dir'] . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function volga_client_ip()
{
    // REG.RU отдаёт реальный IP в REMOTE_ADDR; заголовкам X-Forwarded-For не доверяем.
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/* ---------- антиспам: лимит по IP ---------- */
function volga_rate_limited($cfg)
{
    $file = $cfg['data_dir'] . '/ratelimit.json';
    $ipKey = hash('sha256', volga_client_ip() . '|' . $cfg['gas_secret']);
    $now = time();
    $fh = fopen($file, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $all = $raw ? json_decode($raw, true) : array();
    if (!is_array($all)) $all = array();
    foreach ($all as $k => $times) {
        $all[$k] = array_values(array_filter($times, function ($t) use ($now) { return $t > $now - 86400; }));
        if (!$all[$k]) unset($all[$k]);
    }
    $mine = isset($all[$ipKey]) ? $all[$ipKey] : array();
    $last10 = count(array_filter($mine, function ($t) use ($now) { return $t > $now - 600; }));
    $limited = ($last10 >= $cfg['rate_limit']['per_10min'] || count($mine) >= $cfg['rate_limit']['per_day']);
    if (!$limited) { $mine[] = $now; $all[$ipKey] = $mine; }
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($all));
    flock($fh, LOCK_UN); fclose($fh);
    return $limited;
}

/* ---------- даты и цена ---------- */
function volga_valid_ymd($s)
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    list($y, $m, $d) = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

function volga_norm_date($s)
{
    $s = trim((string)$s);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return '';
}

function volga_nights($in, $out)
{
    $a = new DateTime($in); $b = new DateTime($out);
    return (int)$a->diff($b)->format('%r%a');
}

function volga_price($cfg, $in, $out, $extraBed)
{
    $total = 0;
    $d = new DateTime($in); $end = new DateTime($out);
    while ($d < $end) {
        $isWeekend = in_array((int)$d->format('N'), $cfg['weekend_nights'], true);
        $total += $isWeekend ? $cfg['price_weekend'] : $cfg['price_weekday'];
        if ($extraBed) $total += $cfg['price_extra_bed'];
        $d->modify('+1 day');
    }
    $prepay = (int)round($total * $cfg['prepayment_percent'] / 100);
    return array($total, $prepay);
}

function volga_overlaps($aIn, $aOut, $bIn, $bOut)
{
    return $aIn < $bOut && $bIn < $aOut; // строки YYYY-MM-DD сравниваются корректно
}

function volga_status_blocks($status)
{
    $s = strtolower(trim((string)$status));
    return $s === '' || $s === 'pending_payment' || $s === 'confirmed';
}

/* ---------- HTTP ---------- */
function volga_http($method, $url, $body, $timeout, $headers = array())
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true, // Apps Script отвечает 302 → GET на googleusercontent
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        );
        if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $body; }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return array(0, '', $err);
        return array($code, $resp, '');
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => (string)$body,
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1,
    )));
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1]; }
    }
    return array($code, $resp === false ? '' : $resp, $resp === false ? 'stream error' : '');
}

/* ---------- Google: Apps Script веб-приложение владельца таблицы ---------- */
function volga_gas_call($cfg, $action, $payload)
{
    if (!$cfg['gas_url'] || !$cfg['gas_secret']) return array(false, null, 'gas not configured');
    $body = json_encode(array('secret' => $cfg['gas_secret'], 'action' => $action, 'data' => $payload), JSON_UNESCAPED_UNICODE);
    list($code, $resp, $err) = volga_http('POST', $cfg['gas_url'], $body, $cfg['http_timeout'], array('Content-Type: application/json'));
    if ($code !== 200) return array(false, null, "gas http $code $err");
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        return array(false, $data, 'gas error: ' . (is_array($data) && isset($data['error']) ? $data['error'] : substr((string)$resp, 0, 200)));
    }
    return array(true, $data, '');
}

/* ---------- публичный CSV календаря (резерв, если Apps Script недоступен) ---------- */
function volga_parse_csv($text)
{
    $rows = array();
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text); rewind($fh);
    while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) { if ($r !== array(null)) $rows[] = $r; }
    fclose($fh);
    return $rows;
}

function volga_calendar_ranges($cfg, $maxAge = 60)
{
    $cache = $cfg['data_dir'] . '/calendar_cache.csv';
    $text = null;
    if (is_file($cache) && filemtime($cache) > time() - $maxAge) $text = file_get_contents($cache);
    if ($text === null) {
        list($code, $resp) = volga_http('GET', $cfg['calendar_csv_url'] . '&_=' . time(), null, 10);
        if ($code === 200 && strpos($resp, 'object_id') !== false) {
            $text = $resp;
            @file_put_contents($cache, $resp, LOCK_EX);
        } elseif (is_file($cache)) {
            $text = file_get_contents($cache); // устаревший кеш лучше, чем ничего
        } else {
            return null;
        }
    }
    $rows = volga_parse_csv($text);
    $head = array_shift($rows);
    if (!$head) return null;
    $ix = array_flip($head);
    $out = array();
    foreach ($rows as $r) {
        $get = function ($k) use ($r, $ix) { return isset($ix[$k], $r[$ix[$k]]) ? $r[$ix[$k]] : ''; };
        $in = volga_norm_date($get('check_in')); $o = volga_norm_date($get('check_out'));
        if (!$in || !$o) continue;
        $out[] = array('object_id' => $get('object_id'), 'check_in' => $in, 'check_out' => $o, 'status' => $get('status'));
    }
    return $out;
}

/* ---------- локальный журнал и очередь ---------- */
function volga_journal_append($cfg, $booking, $state)
{
    $line = json_encode(array('ts' => date('c'), 'state' => $state, 'booking' => $booking), JSON_UNESCAPED_UNICODE) . "\n";
    return file_put_contents($cfg['data_dir'] . '/bookings.jsonl', $line, FILE_APPEND | LOCK_EX) !== false;
}

function volga_queue_load($cfg)
{
    $f = $cfg['data_dir'] . '/queue.json';
    if (!is_file($f)) return array();
    $q = json_decode((string)file_get_contents($f), true);
    return is_array($q) ? $q : array();
}

function volga_queue_save($cfg, $q)
{
    file_put_contents($cfg['data_dir'] . '/queue.json', json_encode(array_values($q), JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Пытается дописать в таблицу заявки, принятые, пока Google был недоступен. */
function volga_sync_queue($cfg, $limit = 10)
{
    $q = volga_queue_load($cfg);
    if (!$q) return array('synced' => 0, 'left' => 0, 'conflicts' => 0);
    $left = array(); $synced = 0; $conflicts = 0; $n = 0;
    foreach ($q as $b) {
        if ($n++ >= $limit) { $left[] = $b; continue; }
        list($ok, $data, $err) = volga_gas_call($cfg, 'book', $b);
        if (!$ok) { $left[] = $b; volga_log('sync fail ' . $b['booking_id'] . ' ' . $err); continue; }
        if (isset($data['available']) && $data['available'] === false) {
            // Даты успели занять, пока Google был недоступен: пишем строку со статусом conflict и предупреждаем.
            $b2 = $b; $b2['status'] = 'conflict'; $b2['available'] = 'FALSE';
            volga_gas_call($cfg, 'book', array_merge($b2, array('force' => true)));
            volga_notify($cfg, $b2, "⚠️ КОНФЛИКТ: заявка была принята, пока Google был недоступен, а даты уже заняты. Свяжитесь с гостем.");
            volga_journal_append($cfg, $b2, 'sync_conflict');
            $conflicts++;
            continue;
        }
        volga_journal_append($cfg, $b, 'synced_later');
        $synced++;
    }
    volga_queue_save($cfg, $left);
    return array('synced' => $synced, 'left' => count($left), 'conflicts' => $conflicts);
}

/* ---------- уведомления ---------- */
function volga_booking_text($cfg, $b, $prefix = '')
{
    $lines = array();
    if ($prefix) $lines[] = $prefix;
    $lines[] = '🏕 Новая заявка ' . $b['booking_id'];
    $lines[] = 'Домик: ' . $b['object_name'] . ' (' . $b['object_id'] . ')';
    $lines[] = 'Даты: ' . date('d.m.Y', strtotime($b['check_in'])) . ' → ' . date('d.m.Y', strtotime($b['check_out'])) . ' (' . $b['nights'] . ' ноч.)';
    $lines[] = 'Гость: ' . $b['guest_name'];
    $lines[] = 'Телефон: ' . $b['phone'];
    $lines[] = 'Гостей: ' . $b['guests'] . ($b['extra_bed'] ? ' + доп. место' : '');
    $lines[] = 'Сумма: ' . number_format($b['total_price'], 0, '', ' ') . ' ₽, предоплата ' . number_format($b['prepayment'], 0, '', ' ') . ' ₽';
    if ($b['comment'] !== '') $lines[] = 'Комментарий: ' . $b['comment'];
    $lines[] = 'Статус: ' . $b['status'];
    if (!empty($b['_note'])) $lines[] = $b['_note'];
    return implode("\n", $lines);
}

function volga_telegram($cfg, $text)
{
    if (!$cfg['tg_token'] || !$cfg['tg_chat_ids']) return false;
    $okAny = false;
    foreach ((array)$cfg['tg_chat_ids'] as $chat) {
        $url = rtrim($cfg['tg_api_base'], '/') . '/bot' . $cfg['tg_token'] . '/sendMessage';
        $body = json_encode(array('chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true), JSON_UNESCAPED_UNICODE);
        list($code, $resp, $err) = volga_http('POST', $url, $body, 10, array('Content-Type: application/json'));
        if ($code === 200) $okAny = true; else volga_log("telegram fail chat=$chat http=$code $err " . substr((string)$resp, 0, 200));
    }
    return $okAny;
}

function volga_mail($cfg, $subject, $text)
{
    $to = implode(', ', (array)$cfg['mail_to']);
    if (!$to || $cfg['mail_transport'] === 'none') return false;
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = 'From: =?UTF-8?B?' . base64_encode('Сайт Волга Рядом') . '?= <' . $cfg['mail_from'] . ">\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    $body = chunk_split(base64_encode($text));
    if ($cfg['mail_transport'] === 'file') {
        return file_put_contents($cfg['data_dir'] . '/outbox.txt', "To: $to\r\nSubject: $subj\r\n$headers\r\n\r\n$body\r\n----\r\n", FILE_APPEND | LOCK_EX) !== false;
    }
    $ok = @mail($to, $subj, $body, $headers, '-f' . $cfg['mail_from']);
    if (!$ok) volga_log('mail() failed');
    return $ok;
}

function volga_notify($cfg, $b, $prefix = '')
{
    $text = volga_booking_text($cfg, $b, $prefix);
    $tg = volga_telegram($cfg, $text);
    $mail = volga_mail($cfg, ($prefix ? '[!] ' : '') . 'Заявка ' . $b['booking_id'] . ': ' . $b['object_name'] . ', ' . $b['check_in'], $text);
    return array($tg, $mail);
}
