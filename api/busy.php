<?php
/**
 * GET /api/busy.php — занятые периоды для календаря (без персональных данных).
 * Источник — публичный лист «Busy Calendar» + заявки из локальной очереди (если Google был недоступен).
 */
define('VOLGA_API', 1);
require __DIR__ . '/lib.php';

$cfg = volga_config();
if (!$cfg) volga_json(500, array('ok' => false, 'error' => 'config_missing'));

$ranges = volga_calendar_ranges($cfg, 60);
if ($ranges === null) volga_json(502, array('ok' => false, 'error' => 'calendar_unavailable'));
foreach (volga_queue_load($cfg) as $q) {
    $ranges[] = array('object_id' => $q['object_id'], 'check_in' => $q['check_in'], 'check_out' => $q['check_out'], 'status' => 'pending_payment');
}
volga_json(200, array('ok' => true, 'bookings' => $ranges));
