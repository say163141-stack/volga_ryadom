#!/usr/bin/env bash
# Локальные тесты обработчика заявок: без реальной таблицы, реального Telegram и реальной почты.
set -uo pipefail
PHP=${PHP:-php}
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$ROOT/tests/tmp"; rm -rf "$T"; mkdir -p "$T/private/data"
cat > "$T/private/config.php" <<CFG
<?php return array(
 'gas_url'=>'http://127.0.0.1:8099/gas','gas_secret'=>'test-secret',
 'calendar_csv_url'=>'http://127.0.0.1:8099/calendar.csv?x=1',
 'tg_token'=>'TEST:TOKEN','tg_chat_ids'=>array(123456789),'tg_api_base'=>'http://127.0.0.1:8099',
 'mail_transport'=>'file','setup_key'=>'k123','min_fill_seconds'=>2,
 'rate_limit'=>array('per_10min'=>100,'per_day'=>200),
 'allowed_origins'=>array('http://127.0.0.1:8088'),
);
CFG
MOCK_DIR="$T" $PHP -S 127.0.0.1:8099 "$ROOT/tests/mock_server.php" >"$T/mock.log" 2>&1 & M=$!
VOLGA_CONFIG="$T/private/config.php" $PHP -S 127.0.0.1:8088 -t "$ROOT" >"$T/site.log" 2>&1 & S=$!
trap 'kill $M $S 2>/dev/null' EXIT
sleep 1
pass=0; fail=0
TS=$(( ($(date +%s) - 10) * 1000 ))
D1=$(date -d '+30 day' +%F); D2=$(date -d '+32 day' +%F); D3=$(date -d '+31 day' +%F); D4=$(date -d '+34 day' +%F); D5=$(date -d '+40 day' +%F); D6=$(date -d '+41 day' +%F)
post(){ curl -s -o "$T/resp.json" -w "%{http_code}" -H 'Content-Type: application/json' -H 'Origin: http://127.0.0.1:8088' -d "$1" http://127.0.0.1:8088/api/booking.php; }
body(){ echo "{\"object_id\":\"$1\",\"check_in\":\"$2\",\"check_out\":\"$3\",\"guest_name\":\"Тест Тестов\",\"phone\":\"+7 900 000-00-00\",\"guests\":2,\"extra_bed\":${5:-0},\"comment\":\"=HYPERLINK(1)\",\"website\":\"${6:-}\",\"form_ts\":${4:-$TS},\"pd_consent\":1}"; }
check(){ local name="$1" want="$2" got="$3"; if [ "$want" = "$got" ]; then echo "PASS  $name ($got)"; pass=$((pass+1)); else echo "FAIL  $name: want $want got $got — $(cat "$T/resp.json")"; fail=$((fail+1)); fi; }

c=$(post "$(body house_1 $D1 $D2)"); check "1. новая заявка принята" 200 "$c"
grep -q '"success":true' "$T/resp.json" && echo "      ответ: $(cat "$T/resp.json")"
c=$(post "$(body house_1 $D3 $D4)"); check "2. пересечение дат → 409" 409 "$c"
grep -q '"available":false' "$T/resp.json" && echo "      ответ: $(cat "$T/resp.json")"
c=$(post "$(body house_2 $D1 $D2)"); check "3. другой домик на те же даты → 200" 200 "$c"
c=$(post "$(body house_1 $D2 $D4)"); check "4. заезд в день выезда → 200" 200 "$c"
c=$(post "$(body house_3 $D5 $D6 0 0 spam)"); check "5. honeypot: тихий 200" 200 "$c"
c=$(post "$(body house_3 $D5 $D6 $(( $(date +%s) * 1000 )))"); check "6. слишком быстро → 400" 400 "$c"
c=$(post "$(body house_3 2020-01-01 2020-01-02)"); check "7. даты в прошлом → 400" 400 "$c"
c=$(post '{"object_id":"house_9"}'); check "8. мусор → 400" 400 "$c"
# Согласие 152-ФЗ: валидная заявка без галочки не должна приниматься.
NOCONSENT=$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); d.pop('pd_consent',None); print(json.dumps(d,ensure_ascii=False))" "$(body house_3 $D5 $D6)")
c=$(post "$NOCONSENT"); check "23. без согласия на обработку ПДн → 400" 400 "$c"
grep -q '"error":"consent"' "$T/resp.json" && echo "      ответ: $(cat "$T/resp.json")"
c=$(curl -s -o "$T/resp.json" -w "%{http_code}" -H 'Origin: https://evil.example' -H 'Content-Type: application/json' -d "$(body house_3 $D5 $D6)" http://127.0.0.1:8088/api/booking.php); check "9. чужой Origin → 403" 403 "$c"
c=$(curl -s -o "$T/resp.json" -w "%{http_code}" http://127.0.0.1:8088/api/lib.php); check "10. lib.php напрямую не исполняется (php -S игнорирует .htaccess; на Apache — 403)" 404 "$c"
# Google недоступен → очередь, проверка по CSV, потом досылка
touch "$T/gas_down"
c=$(post "$(body house_1 $D3 $D4)"); check "11. Google лежит, даты заняты по CSV → 409" 409 "$c"
c=$(post "$(body house_4 $D5 $D6 "" 1)"); check "12. Google лежит, свободно → 200 + очередь" 200 "$c"
q=$(python3 -c "import json;print(len(json.load(open('$T/private/data/queue.json'))))"); check "13. в очереди 1 заявка" 1 "$q"
c=$(post "$(body house_4 $D5 $D6)"); check "14. повтор тех же дат, пока Google лежит → 409 (по очереди)" 409 "$c"
c=$(curl -s -o "$T/resp.json" -w "%{http_code}" "http://127.0.0.1:8088/api/busy.php"); check "15. busy.php отвечает" 200 "$c"
grep -q "\"house_4\",\"check_in\":\"$D5\"" "$T/resp.json" && check "16. busy.php включает заявку из очереди" 1 1 || check "16. busy.php включает заявку из очереди" 1 0
grep -qi "phone\|guest_name" "$T/resp.json" && check "17. busy.php без ПДн" clean leak || check "17. busy.php без ПДн" clean clean
rm "$T/gas_down"
c=$(curl -s -o "$T/resp.json" -w "%{http_code}" "http://127.0.0.1:8088/api/sync.php?key=k123"); check "18. sync.php досылает очередь" 200 "$c"; echo "      $(cat "$T/resp.json")"
n=$(python3 -c "import json;print(len(json.load(open('$T/sheet.json'))['bookings']))"); check "19. в «таблице» 4 строки" 4 "$n"
c=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:8088/api/sync.php?key=wrong"); check "20. sync.php без ключа закрыт" 404 "$c"
c=$(curl -s -o "$T/resp.json" -w "%{http_code}" "http://127.0.0.1:8088/api/tg_setup.php?key=k123"); check "21. tg_setup показывает chat_id" 200 "$c"; echo "      $(cat "$T/resp.json")"
tg=$(grep -c sendMessage "$T/telegram.log" 2>/dev/null || wc -l < "$T/telegram.log"); echo "      Telegram-сообщений в моке: $(wc -l < "$T/telegram.log")"
m=$(grep -c '^To: ' "$T/private/data/outbox.txt"); echo "      писем в outbox: $m"
j=$(wc -l < "$T/private/data/bookings.jsonl"); echo "      строк в журнале bookings.jsonl: $j"
python3 - "$T" <<'PY'
import json,sys
t=sys.argv[1]; s=json.load(open(t+'/sheet.json'))['bookings']
b=s[0]; print('      пример строки в таблицу:', {k:b[k] for k in ['booking_id','created_at','object_id','object_name','nights','total_price','prepayment','status','extra_bed','comment']})
PY
# лимит по IP
sed -i "s/'per_10min'=>100/'per_10min'=>2/" "$T/private/config.php"; rm -f "$T/private/data/ratelimit.json"
post "$(body house_2 $D5 $D6)" >/dev/null; post "$(body house_2 $D5 $D6)" >/dev/null
c=$(post "$(body house_2 $D5 $D6)"); check "22. лимит по IP → 429" 429 "$c"
echo "ИТОГО: $pass passed, $fail failed"
[ "$fail" = 0 ]
