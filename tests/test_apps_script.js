// Проверка логики Code.gs на заглушках SpreadsheetApp (node tests/test_apps_script.js). Реальную таблицу не трогает.
const fs = require('fs'), vm = require('vm');
function makeSheet(gid, name, head, rows) {
  const data = [head.slice(), ...rows.map(r => r.slice())]; const formats = {};
  return {
    data, formats, getSheetId: () => gid, getName: () => name,
    getDataRange: () => ({ getDisplayValues: () => data.map(r => r.map(v => String(v))) }),
    getLastColumn: () => data[0].length, getLastRow: () => data.length,
    getRange: (r, c, nr, nc) => ({
      getDisplayValues: () => [data[r - 1].slice(c - 1, c - 1 + nc).map(String)],
      setNumberFormat: (f) => { formats[r + ':' + c] = f; return this; },
      setValues: (v) => { data[r - 1] = v[0].slice(); },
    }),
  };
}
const H = ['object_id','guest_name','phone','check_in','check_out','guests','comment','booking_id','object_name','nights','total_price','prepayment','status','check_in_iso','check_out_iso','event_title','available','event_description','conflicts_count','created_at','extra_bed'];
const bookings = makeSheet(1880647509, 'Bookings', H, [['house_1','x','+7','2026-12-10','2026-12-12',2,'','BR-1','Первый нижний домик',2,0,0,'pending_payment','','','','TRUE','',0,'2026-11-01T00:00:00.000Z',0]]);
const calendar = makeSheet(1360421097, 'Busy', ['object_id','check_in','check_out','status'], [['house_2','20.12.2026','22.12.2026','confirmed'],['house_3','2026-12-01','2026-12-05','cancelled']]);
const ctx = {
  SpreadsheetApp: { getActive: () => ({ getSheets: () => [bookings], getName: () => 'B' }), openById: () => ({ getSheets: () => [calendar], getName: () => 'C' }), flush() {} },
  LockService: { getScriptLock: () => ({ waitLock() {}, releaseLock() {} }) },
  ContentService: { MimeType: { JSON: 'json' }, createTextOutput: (s) => ({ s, setMimeType() { return this; } }) },
  Logger: { log() {} },
};
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(__dirname + '/../apps-script/Code.gs', 'utf8').replace("'ВСТАВЬТЕ_СЕКРЕТ_ИЗ_CONFIG_PHP'", "'s3'"), ctx);
const call = (o) => JSON.parse(ctx.doPost({ postData: { contents: JSON.stringify(o) } }).s);
const b = (id, h, i, o, extra) => Object.assign({ booking_id: id, object_id: h, check_in: i, check_out: o, status: 'pending_payment', guest_name: 'Иван', phone: '+7 900 1', comment: '=1+1', created_at: '2026-11-11T00:00:00.000Z' }, extra || {});
let ok = 0, bad = 0; const t = (n, c) => { c ? ok++ : bad++; console.log((c ? 'PASS ' : 'FAIL ') + n); };
t('неверный секрет → forbidden', call({ secret: 'x', action: 'book', data: b('BR-2','house_1','2026-12-20','2026-12-21') }).error === 'forbidden');
t('пересечение с Bookings → available:false', call({ secret: 's3', action: 'book', data: b('BR-3','house_1','2026-12-11','2026-12-13') }).available === false);
t('пересечение с календарём (дата 20.12.2026) → available:false', call({ secret: 's3', action: 'book', data: b('BR-4','house_2','2026-12-21','2026-12-23') }).available === false);
t('отменённая бронь не блокирует', call({ secret: 's3', action: 'book', data: b('BR-5','house_3','2026-12-02','2026-12-03') }).available === true);
t('свободно → запись в оба листа', call({ secret: 's3', action: 'book', data: b('BR-6','house_1','2026-12-12','2026-12-14') }).available === true && bookings.data.length === 4 && calendar.data.length === 5);
t('повтор той же заявки не дублируется', call({ secret: 's3', action: 'book', data: b('BR-6','house_1','2026-12-12','2026-12-14') }).duplicate === true && bookings.data.length === 4);
const last = bookings.data[3];
t('колонки по заголовкам: booking_id/object_id/check_in на своих местах', last[H.indexOf('booking_id')] === 'BR-6' && last[H.indexOf('object_id')] === 'house_1' && last[H.indexOf('check_in')] === '2026-12-12');
t('формула в комментарии экранирована', last[H.indexOf('comment')] === "'=1+1");
t('телефон пишется как текст без искажений', last[H.indexOf('phone')] === '+7 900 1');
t('даты/телефон в текстовом формате', Object.values(bookings.formats).filter(f => f === '@').length >= 5);
t('календарь получил только домик/даты/статус', JSON.stringify(calendar.data[4]) === JSON.stringify(['house_1','2026-12-12','2026-12-14','pending_payment']));
console.log(`ИТОГО: ${ok} passed, ${bad} failed`); process.exit(bad ? 1 : 0);
