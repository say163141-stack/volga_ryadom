# CLAUDE_BRIEF.md — контекст проекта «Волга Рядом»

Этот файл — выжимка из предыдущей сессии. Прочитай его целиком, прежде чем что-то менять.
Задача новой сессии: довести сайт и систему бронирования до боевого состояния и **сам коммитить/пушить изменения в git** (в веб-чате это было невозможно).

---

## 1. Что это за проект

Статичный сайт базы отдыха (глэмпинга) «Волга Рядом» на GitHub Pages.
- Репозиторий: https://github.com/say163141-stack/volga_ryadom
- Боевой домен: http://www.volga-ryadom63.ru/ (через CNAME → файл `CNAME` в корне = `www.volga-ryadom63.ru`)
- Стек: чистый HTML + CSS + JS, без сборки. 5 страниц.
- Бэкенд бронирования: n8n Cloud (вебхуки) + Google Sheets + Telegram-бот.

## 2. Структура сайта (актуальная, собрана в прошлой сессии)

```
/index.html          — главная: hero, карусель-галерея, домики, развлечения, форма бронирования с календарём, отзывы
/house.html          — домики: интерьеры, удобства, цены (БЕЗ оплаты)
/what_to_do.html     — развлечения: причал, SUP, бассейн, баня, окрестности
/booking.html        — бронирование: календарь занятых дат + форма заявки (БЕЗ оплаты)
/contacts.html       — контакты
/CNAME               — www.volga-ryadom63.ru
/assets/style.css    — весь стиль (палитра глэмпинга: зелёный/песочный/дерево)
/assets/app.js       — меню, карусель, календарь занятых дат, отправка формы
/assets/img/         — все изображения (реальные фото клиента, оптимизированы под веб)
```

### Изображения в /assets/img/
logo.png, favicon.png, overview.jpg, overview_day.jpg, overview_night.jpg,
house_night.jpg, house_night_2.jpg, interior_1.jpg, interior_2.jpg, interior_3.jpg,
pool.jpg, pier.jpg, activities.jpg

Все фото — реальные снимки клиента (heic сконвертирован, EXIF-поворот применён, ужаты до ~1600px/85%).
ВАЖНО: воду в бассейне НЕ перекрашивали, газон на фото развлечений НЕ добавляли, апскейл НЕ делали —
нет инструмента фоторедактирования. Если клиент снова попросит — это задача для графического редактора, не для кода.

## 3. Ключевые требования клиента (соблюдать строго)

- Весь сайт на русском.
- Логотип (вертикальный) — слева вверху на ВСЕХ страницах, десктоп + мобайл. Уже сделано.
- НЕ добавлять оплату на сайте (ни карты, ни СБП, ни Tinkoff). Бронирование = заявка в Telegram + запись в Sheets.
- НЕ предлагать другую архитектуру/сервисы/базы данных. НЕ использовать Яндекс Календарь / CalDAV.
- Карусель фото на главной — листается (стрелки + точки + свайп). Уже сделано.
- Календарь должен показывать занятые даты (тянутся из Google Sheets через n8n). Уже сделано.
- Адаптив десктоп + мобайл. Уже сделано и проверено скриншотами.

## 4. Бэкенд n8n (УЖЕ НАСТРОЕН в прошлой сессии)

Инстанс: https://say163141.app.n8n.cloud

### Workflow 1 — приём заявки на бронь
- ID: `FhChN7TxkhgFMjmj`, имя `volga_ryadom`
- Боевой вебхук (POST): `https://say163141.app.n8n.cloud/webhook/volga-booking-create`
- Цепочка: Webhook → Set → Code (готовит бронь) → Google Sheets read → Code: Check Availability → If
  → TRUE: Google Sheets Append → Telegram → Respond 200
  → FALSE: Respond 409 «Выбранные даты уже заняты»
- Принимает JSON: object_id, check_in (YYYY-MM-DD), check_out, guest_name, phone, guests, comment.
- Объект только один: house_1 = «Домик у Волги», 6000 ₽/ночь, предоплата 3000.
- Append использует mappingMode `autoMapInputData` (НЕ defineBelow — defineBelow ломался из-за schema).
- created_at генерится в узле Code (а не в Append).
- Telegram-узел: resource=message, operation=sendMessage, On Error = Continue (чтобы сбой Telegram не рушил заявку).
- CORS включён: Webhook options.allowedOrigins=*, оба Respond-узла отдают Access-Control-Allow-Origin: *.

### Workflow 2 — занятые даты для календаря
- ID: `uRbZsGSsGmtSGNsK`, имя `volga_busy_dates`
- Боевой вебхук (GET): `https://say163141.app.n8n.cloud/webhook/volga-busy-dates`
- Цепочка: Webhook GET → Google Sheets read → Code (собирает занятые ночи) → Respond JSON {busy_dates:[...]}
- Возвращает массив дат-ночей (заезд включительно, выезд НЕ включительно), статусы pending_payment/confirmed.
- CORS включён.
- Проверен: вернул ["2026-07-10","2026-07-11"].

### Google Sheets
- Документ: «Volga Ryadom - Bookings», ID `1fUc_hww3xpLrIAAXUptIiNN6IpztdpgMkO5tNuUE5kc`
- Лист: «Bookings», gid 1880647509
- Колонки: booking_id, created_at, object_id, object_name, guest_name, phone, check_in, check_out, guests, nights, total_price, prepayment, status, comment
- ПРИМЕЧАНИЕ: в таблице есть лишние пустые колонки check_in_iso/event_title/event_description от старой схемы — на работу не влияют, можно удалить.

## 5. Конфиг на фронте (assets/app.js, верх файла)

```js
const N8N_BASE = "https://say163141.app.n8n.cloud";
const BOOKING_WEBHOOK = N8N_BASE + "/webhook/volga-booking-create";
const BUSY_DATES_WEBHOOK = N8N_BASE + "/webhook/volga-busy-dates";
const TELEGRAM_LINK = "https://t.me/volga_ryadom_bot";
```
Если поменяются пути вебхуков — править здесь.

## 6. ЧТО ОСТАЛОСЬ СДЕЛАТЬ (todo для этой сессии)

1. [git] Закоммитить и запушить актуальные файлы сайта в репозиторий (главное, ради чего перешли в Code).
2. [клиент, вручную] Активировать оба workflow в n8n (тумблер Active). Без этого боевые вебхуки молчат.
3. [клиент, вручную] Написать `/start` Telegram-боту, иначе уведомления падают с «chat not found».
4. [клиент, вручную] Удалить тестовую строку BR-1782737387566 из Google Sheets (из-за неё 10–11 июля показаны занятыми).
5. [проверка] После активации и деплоя — отправить тестовую заявку с сайта, убедиться что:
   - приходит ответ 200 и сообщение «Заявка принята»,
   - строка появляется в Google Sheets,
   - приходит уведомление в Telegram,
   - повторная заявка на те же даты даёт 409,
   - календарь на сайте подсвечивает занятые даты.

## 7. Известные нюансы / грабли

- n8n Google Sheets Append v4.7: при mappingMode=defineBelow требует непустой columns.schema, иначе падает «columns.schema is required» либо «Column names were updated». Решение, которое работает: autoMapInputData + поля во входном JSON названы ровно как колонки таблицы.
- Поле type=date в форме рендерит дату в локали браузера (mm/dd/yyyy у некоторых), но значение уходит в формате YYYY-MM-DD из календаря — это ок.
- Поля check_in/check_out на сайте readonly, заполняются кликами по календарю (диапазон). Календарь блокирует выбор занятых дат и диапазонов, пересекающих занятые.
- При локальном рендере из песочницы внешние запросы к n8n могут не проходить — проверять занятые даты надо на боевом сайте.
```
