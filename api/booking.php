<?php
/**
 * POST /api/booking.php — приём заявки на бронь.
 * Ответы (как у прежнего n8n): 200 {success:true} — принято; 409 {success:false, available:false} — даты заняты.
 */
define('VOLGA_API', 1);
require __DIR__ . '/lib.php';

$cfg = volga_config();
if (!$cfg) { volga_json(500, array('success' => false, 'error' => 'config_missing')); }

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { volga_json(405, array('success' => false, 'error' => 'method')); }

// Запросы только со своего сайта (у same-origin fetch браузер присылает Origin для POST).
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin !== '' && !in_array($origin, $cfg['allowed_origins'], true)) {
    volga_json(403, array('success' => false, 'error' => 'origin'));
}

$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode((string)$raw, true);
if (!is_array($in)) $in = $_POST;

// Антиспам 1: скрытое поле-ловушка. Боту отвечаем «успехом», ничего не делая.
if (!empty($in['website'])) { volga_log('honeypot hit'); volga_json(200, array('success' => true)); }

// Антиспам 2: форма заполнена слишком быстро (или без отметки времени).
$ts = isset($in['form_ts']) ? (int)$in['form_ts'] : 0; // мс, ставит браузер при открытии страницы
$elapsed = time() - (int)floor($ts / 1000);
if ($ts <= 0 || $elapsed < $cfg['min_fill_seconds'] || $elapsed > 86400 * 2) {
    volga_log("too fast/invalid ts elapsed=$elapsed");
    volga_json(400, array('success' => false, 'error' => 'too_fast', 'message' => 'Похоже, форма отправлена слишком быстро. Подождите пару секунд и попробуйте снова.'));
}

// Антиспам 3: лимит заявок с одного IP.
if (volga_rate_limited($cfg)) {
    volga_json(429, array('success' => false, 'error' => 'rate_limited', 'message' => 'Слишком много заявок. Попробуйте позже или напишите нам в Telegram.'));
}

/* ---------- валидация ---------- */
$s = function ($k, $max) use ($in) {
    $v = isset($in[$k]) ? trim(strip_tags((string)$in[$k])) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    return mb_substr($v, 0, $max, 'UTF-8');
};
$objectId = $s('object_id', 20);
$checkIn = $s('check_in', 10);
$checkOut = $s('check_out', 10);
$name = $s('guest_name', 80);
$phone = preg_replace('/[^0-9+()\- ]/', '', $s('phone', 30));
$comment = $s('comment', 1000);
$guests = isset($in['guests']) ? (int)$in['guests'] : 2;
$extraBed = !empty($in['extra_bed']) ? 1 : 0;

$errors = array();
if (!isset($cfg['houses'][$objectId])) $errors[] = 'object_id';
if (!volga_valid_ymd($checkIn) || !volga_valid_ymd($checkOut)) $errors[] = 'dates';
$today = date('Y-m-d');
if (!$errors) {
    $nights = volga_nights($checkIn, $checkOut);
    if ($checkIn < $today || $nights < 1 || $nights > $cfg['max_nights']) $errors[] = 'dates';
}
if ($name === '') $errors[] = 'guest_name';
$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) < 10 || strlen($digits) > 15) $errors[] = 'phone';
if ($guests < 1 || $guests > $cfg['max_guests']) $errors[] = 'guests';
$consentRaw = isset($in['pd_consent']) ? $in['pd_consent'] : '';
$consent = ($consentRaw === true || $consentRaw === 1 || $consentRaw === '1' || $consentRaw === 'on' || $consentRaw === 'true');
if (!$consent) {
    volga_json(400, array('success' => false, 'error' => 'consent', 'fields' => array('pd_consent'), 'message' => 'Чтобы отправить заявку, подтвердите согласие на обработку персональных данных.'));
}
if ($errors) {
    volga_json(400, array('success' => false, 'error' => 'validation', 'fields' => $errors, 'message' => 'Проверьте даты, имя и телефон.'));
}

/* ---------- бронь ---------- */
list($total, $prepay) = volga_price($cfg, $checkIn, $checkOut, $extraBed);
$ms = (int)round(microtime(true) * 1000);
$booking = array(
    'booking_id' => 'BR-' . $ms,
    'created_at' => gmdate('Y-m-d\TH:i:s', (int)($ms / 1000)) . '.' . sprintf('%03d', $ms % 1000) . 'Z',
    'object_id' => $objectId,
    'object_name' => $cfg['houses'][$objectId],
    'guest_name' => $name,
    'phone' => $phone,
    'check_in' => $checkIn,
    'check_out' => $checkOut,
    'guests' => $guests,
    'extra_bed' => $extraBed,
    'nights' => $nights,
    'total_price' => $total,
    'prepayment' => $prepay,
    'status' => 'pending_payment',
    'comment' => $comment,
    'available' => 'TRUE',
    'conflicts_count' => 0,
);

// Одна заявка за раз: исключаем гонку двух одновременных броней.
$lock = fopen($cfg['data_dir'] . '/booking.lock', 'c');
flock($lock, LOCK_EX);

// Сначала досылаем то, что накопилось, пока Google был недоступен.
volga_sync_queue($cfg, 5);

// Конфликты с заявками, которые ещё не попали в таблицу.
foreach (volga_queue_load($cfg) as $q) {
    if ($q['object_id'] === $objectId && volga_overlaps($checkIn, $checkOut, $q['check_in'], $q['check_out'])) {
        flock($lock, LOCK_UN);
        volga_json(409, array('success' => false, 'available' => false, 'message' => 'Выбранные даты уже заняты'));
    }
}

list($ok, $data, $err) = volga_gas_call($cfg, 'book', $booking);
if ($ok) {
    if (isset($data['available']) && $data['available'] === false) {
        flock($lock, LOCK_UN);
        volga_journal_append($cfg, array('object_id' => $objectId, 'check_in' => $checkIn, 'check_out' => $checkOut), 'rejected_busy');
        volga_json(409, array('success' => false, 'available' => false, 'message' => 'Выбранные даты уже заняты'));
    }
    $state = 'saved_to_sheet';
} else {
    // Google недоступен: проверяем занятость по публичному календарю (или его кешу) и ставим заявку в очередь.
    volga_log('gas fail: ' . $err);
    $ranges = volga_calendar_ranges($cfg, 300);
    if ($ranges !== null) {
        foreach ($ranges as $r) {
            if ($r['object_id'] === $objectId && volga_status_blocks($r['status']) && volga_overlaps($checkIn, $checkOut, $r['check_in'], $r['check_out'])) {
                flock($lock, LOCK_UN);
                volga_json(409, array('success' => false, 'available' => false, 'message' => 'Выбранные даты уже заняты'));
            }
        }
        $booking['_note'] = '⚠️ Google-таблица временно недоступна: заявка сохранена на сервере и будет дописана в таблицу автоматически.';
    } else {
        $booking['_note'] = '⚠️ Google недоступен, занятость НЕ проверена — сверьте даты вручную. Заявка сохранена на сервере.';
    }
    $q = volga_queue_load($cfg);
    $q[] = array_diff_key($booking, array('_note' => 1));
    volga_queue_save($cfg, $q);
    $state = 'queued';
}

$saved = volga_journal_append($cfg, $booking, $state);
flock($lock, LOCK_UN);
fclose($lock);

list($tg, $mail) = volga_notify($cfg, $booking);
if (!$tg) volga_log('telegram not delivered for ' . $booking['booking_id']);

volga_json(200, array(
    'success' => true,
    'booking_id' => $booking['booking_id'],
    'nights' => $nights,
    'total_price' => $total,
    'prepayment' => $prepay,
));
