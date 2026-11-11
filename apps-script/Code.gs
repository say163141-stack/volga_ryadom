/**
 * Волга Рядом — приём заявок с сайта в Google-таблицу.
 * Устанавливается ВЛАДЕЛЬЦЕМ таблицы «Volga Ryadom - Bookings»: Расширения → Apps Script.
 * Сайт (PHP на REG.RU) вызывает этот скрипт со своего сервера с секретом; посетителям адрес не виден.
 *
 * Что делает: под блокировкой проверяет занятость домика по листу Bookings и публичному листу
 * Busy Calendar, при свободных датах дописывает строку в оба листа (как раньше делал n8n).
 */

// 1) Вставьте сюда тот же секрет, что в config.php → gas_secret (длинная случайная строка).
const SHARED_SECRET = 'ВСТАВЬТЕ_СЕКРЕТ_ИЗ_CONFIG_PHP';

// Таблица заявок (эта же таблица) и лист внутри неё — по gid из адреса листа.
const BOOKINGS_SHEET_GID = 1880647509;
// Публичная таблица календаря (только домик/даты/статус, без ФИО) — её читает сайт.
const CALENDAR_SPREADSHEET_ID = '1B3WqP-4xF3rgqtBeoYLwNJgzBQfuDcMqesXrgxcR_mI';
const CALENDAR_SHEET_GID = 1360421097;

const BLOCKING_STATUSES = ['', 'pending_payment', 'confirmed'];
const TEXT_COLUMNS = ['booking_id', 'created_at', 'check_in', 'check_out', 'phone']; // храним как текст, чтобы таблица не превращала их в даты/числа

function doPost(e) {
  try {
    const req = JSON.parse(e.postData.contents);
    if (!req || req.secret !== SHARED_SECRET || SHARED_SECRET.indexOf('ВСТАВЬТЕ') === 0) return out_({ ok: false, error: 'forbidden' });
    if (req.action === 'ping') return out_({ ok: true, pong: true });
    if (req.action === 'book') return out_(book_(req.data || {}));
    return out_({ ok: false, error: 'unknown_action' });
  } catch (err) {
    return out_({ ok: false, error: String(err) });
  }
}

function doGet() {
  return out_({ ok: true, service: 'volga-booking' });
}

function book_(b) {
  const lock = LockService.getScriptLock();
  lock.waitLock(25000);
  try {
    const bookings = sheetByGid_(SpreadsheetApp.getActive(), BOOKINGS_SHEET_GID);
    const calendar = sheetByGid_(SpreadsheetApp.openById(CALENDAR_SPREADSHEET_ID), CALENDAR_SHEET_GID);

    // Повторная отправка той же заявки (например, из очереди) — не дублируем.
    const bRows = rowsAsObjects_(bookings);
    if (bRows.some(function (r) { return r.booking_id === b.booking_id; })) return { ok: true, available: true, duplicate: true };

    if (!b.force) {
      const conflicts = bRows.concat(rowsAsObjects_(calendar)).filter(function (r) {
        return r.object_id === b.object_id &&
          BLOCKING_STATUSES.indexOf(String(r.status || '').toLowerCase().trim()) !== -1 &&
          normDate_(r.check_in) && normDate_(r.check_out) &&
          normDate_(r.check_in) < b.check_out && b.check_in < normDate_(r.check_out);
      });
      if (conflicts.length) return { ok: true, available: false, conflicts_count: conflicts.length };
    }

    appendByHeader_(bookings, b);
    appendByHeader_(calendar, { object_id: b.object_id, check_in: b.check_in, check_out: b.check_out, status: b.status });
    SpreadsheetApp.flush();
    return { ok: true, available: true };
  } finally {
    lock.releaseLock();
  }
}

function sheetByGid_(ss, gid) {
  const sh = ss.getSheets().filter(function (s) { return s.getSheetId() === gid; })[0];
  if (!sh) throw new Error('sheet gid ' + gid + ' not found in ' + ss.getName());
  return sh;
}

function rowsAsObjects_(sh) {
  const values = sh.getDataRange().getDisplayValues();
  const head = values.shift() || [];
  return values.map(function (row) {
    const o = {};
    head.forEach(function (h, i) { o[String(h).trim()] = row[i]; });
    return o;
  });
}

function appendByHeader_(sh, obj) {
  const lastCol = sh.getLastColumn();
  const head = sh.getRange(1, 1, 1, lastCol).getDisplayValues()[0].map(function (h) { return String(h).trim(); });
  const row = head.map(function (h) {
    if (!(h in obj) || obj[h] === null || obj[h] === undefined) return '';
    let v = obj[h];
    // защита от формул: в текстовых колонках хватает формата «@», в остальных — апостроф
    if (typeof v === 'string' && TEXT_COLUMNS.indexOf(h) === -1 && /^[=+\-@]/.test(v)) v = "'" + v;
    return v;
  });
  const r = sh.getLastRow() + 1;
  head.forEach(function (h, i) { if (TEXT_COLUMNS.indexOf(h) !== -1) sh.getRange(r, i + 1).setNumberFormat('@'); });
  sh.getRange(r, 1, 1, head.length).setValues([row]);
}

function normDate_(s) {
  s = String(s || '').trim();
  let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return m[1] + '-' + m[2] + '-' + m[3];
  m = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
  if (m) return m[3] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2);
  return '';
}

function out_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

/** Запустите вручную один раз из редактора, чтобы выдать скрипту доступ к таблицам. Ничего не записывает. */
function authorizeOnce() {
  const a = sheetByGid_(SpreadsheetApp.getActive(), BOOKINGS_SHEET_GID).getName();
  const c = sheetByGid_(SpreadsheetApp.openById(CALENDAR_SPREADSHEET_ID), CALENDAR_SHEET_GID).getName();
  Logger.log('OK: ' + a + ' / ' + c);
}
