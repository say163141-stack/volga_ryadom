<?php
/**
 * Волга Рядом — настройки обработчика заявок.
 * Скопируйте этот файл на хостинг В ПАПКУ ВНЕ САЙТА:
 *   /var/www/uXXXXXXX/data/volga-private/config.php
 * (рядом с папкой www, НЕ внутри volga-ryadom63.ru). Права 600.
 * В git настоящий config.php не коммитить.
 */
return array(
    // Google Apps Script (веб-приложение владельца таблицы). URL вида https://script.google.com/macros/s/…/exec
    'gas_url' => '',
    // Секрет: тот же, что в Code.gs → SHARED_SECRET. Сгенерировать: openssl rand -hex 24
    'gas_secret' => '',

    // Telegram-бот @volga_ryadom_bot: токен из @BotFather → /mybots → бот → API Token
    'tg_token' => '',
    // Кому слать уведомления: chat_id (числа). Узнать: написать боту /start и открыть /api/tg_setup.php?key=SETUP_KEY
    'tg_chat_ids' => array(),

    // Копии заявок на почту (можно несколько адресов)
    'mail_to' => array('volga.ryadom@yandex.ru'),
    // Отправитель: ящик на домене сайта (создаётся в панели REG.RU → Почта) или любой адрес @volga-ryadom63.ru
    'mail_from' => 'noreply@volga-ryadom63.ru',

    // Ключ для служебных страниц tg_setup.php и sync.php. Сгенерировать: openssl rand -hex 16
    'setup_key' => '',

    // Цены (₽). Сейчас на сайте: будни от 6000, выходные от 8000, доп. место 1500/ночь, предоплата 50 %.
    'price_weekday' => 6000,
    'price_weekend' => 8000,
    'weekend_nights' => array(5, 6), // ночи с пятницы и с субботы (1=пн … 7=вс)
    'price_extra_bed' => 1500,
    'prepayment_percent' => 50,

    // Остальное можно не трогать (значения по умолчанию в api/lib.php):
    // 'data_dir' => __DIR__ . '/data',   // журнал заявок, очередь, лог
    // 'min_fill_seconds' => 4,
    // 'rate_limit' => array('per_10min' => 5, 'per_day' => 20),
    // 'houses' => array('house_1' => 'Первый нижний домик', ...),
);
