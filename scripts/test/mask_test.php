<?php

declare(strict_types=1);

/**
 * Проверка маскирования на синтетических данных (реальных данных клиентов здесь нет и быть не должно).
 *   php .claude/skills/perf-check/scripts/test/mask_test.php
 */

require __DIR__ . '/../lib/mask.php';

$fail = 0;
$check = static function (string $name, bool $ok, string $got = '') use (&$fail): void {
    if (!$ok) {
        ++$fail;
        echo "FAIL $name\n     $got\n";
    }
};
$absent = static function (string $name, string $out, array $raw) use ($check): void {
    foreach ($raw as $r) {
        $check("$name: нет «" . mb_substr($r, 0, 12) . '…»', !str_contains($out, $r), $out);
    }
};

$s = PerfMask::sql("SELECT U.ID FROM b_user U WHERE U.LOGIN = 'ivan.petrov@example.com' AND U.ID = 722274 AND U.ACTIVE = 'Y' AND U.IBLOCK_ID = 41 LIMIT 20");
$absent('sql login', $s, ['ivan.petrov', '722274']);
$check('sql сохраняет коды и короткие числа', str_contains($s, "ACTIVE = 'Y'") && str_contains($s, 'IBLOCK_ID = 41') && str_contains($s, 'LIMIT 20'), $s);

$s = PerfMask::sql("INSERT INTO b_stat_hit (URL, USER_AGENT) VALUES ('https://www.example.com/personal/?email=ivan@example.com', 'Mozilla/5.0 (Windows NT 10.0");
$absent('sql обрезанный литерал', $s, ['ivan@', 'Mozilla', 'Windows']);
$check('sql обрезанный литерал → X…', str_ends_with($s, "'X…"), $s);

$s = PerfMask::sql("SELECT 1 FROM t WHERE DATE_HIT > '2026-11-02 13:03:45' AND NAME = 'O''Brien' AND X = 'a\\'Smith' AND N2 = \"Иванов Иван\" AND IP = '203.0.113.7' AND H = 'a2e82b67f00b089666a8da2e5a76a0b9'");
$absent('sql кавычки', $s, ['Brien', 'Smith', 'Иванов', '203.0.113', 'a2e82b67']);
$check('sql сохраняет дату', str_contains($s, "'2026-11-02 13:03:45'"), $s);

$t = PerfMask::template("SELECT * FROM b_sale_order WHERE USER_ID IN (1, 2, 3) AND STATUS_ID IN ('N', 'P') AND EMAIL = 'a@example.com' AND ID = 10409767");
$check('template', $t === "SELECT * FROM b_sale_order WHERE USER_ID IN (N…) AND STATUS_ID IN ('X'…) AND EMAIL = 'X' AND ID = N", $t);
$t = PerfMask::template("UPDATE b_stat_session SET URL_LAST = 'https://www.example.com/info/guide/index.php?login=yes&id=684");
$check('template обрезанный', str_ends_with($t, "'X…") && !str_contains($t, 'guide'), $t);

foreach ([
    '/search/?q=%D0%BE%D1%81%D0%B2%D0%B5%D0%B6%D0%B8%D1%82%D0%B5%D0%BB%D1%8C&PAGEN_1=2' => '/search/?q=…&PAGEN_1=2',
    '/desk/registration/users/detail.php?ID=1192324' => '/desk/registration/users/detail.php?ID=…',
    '/personal/order/detail/10409767/' => '/personal/order/detail/N/',
    '/catalog/goods/123456/' => '/catalog/goods/123456/',
    '/catalog/brands/list.php?BRAND=BRAUBERG&PAGEN_1=120' => '/catalog/brands/list.php?BRAND=BRAUBERG&PAGEN_1=120',
    '/auth/?login=yes&USER_LOGIN=ivan%40example.com' => '/auth/?login=yes&USER_LOGIN=…',
    '/info/guide/index.php?ID=21245258&rb_clickid=237533971-1793613894-1259289370-0' => '/info/guide/index.php?ID=…&rb_clickid=…',
    '/work/info/guide/index.php?SECTION=2574&login=yes&id=684&PAGEN_1=9' => '/work/info/guide/index.php?SECTION=2574&login=yes&id=…&PAGEN_1=9',
    'https://203.0.113.7/x/?y=1' => 'https://‹ip›/x/?y=…',
    'https://local.example.com' => 'https://local.example.com',
    '/desk/clients/commercial_offers/order.php?COMMERCIAL_OFFER_ID=…' => '/desk/clients/commercial_offers/order.php?COMMERCIAL_OFFER_ID=…',
] as $in => $want) {
    $got = PerfMask::uri($in);
    $check("uri $in", $got === $want, $got);
}

$raw = ['ivan.petrov@example.com', '916) 123', '7701234567', 'Ромашка', 'Петров П.', 'Ленина', 'a2e82b67f00b', '123e4567', '2001:db8', 'eyJhbGci', 'perm:cm9vdC5yb290'];
$x = PerfMask::text('Пишите на ivan.petrov@example.com или +7 (916) 123-45-67, ИНН 7701234567, ООО «Ромашка», Петров П. П., ул. Ленина, д. 5. '
    . 'Сессия a2e82b67f00b089666a8da2e5a76a0b9, uuid 123e4567-e89b-12d3-a456-426614174000, ip 2001:db8::1, '
    . 'токен eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U и perm:cm9vdC5yb290.UjEyMw==.abcdefghij');
$absent('text', $x, $raw);

$clean = 'Запрос 147 мс × 7 089 = 1 040 с (40 % SQL), mg-180953, bxkor-281, `City::getStores()`, `App\\Env\\Delivery\\BaseAbstract::getPickupPointCollection()`, '
    . '13:03–13:13, 02.11.2026, MySQL 5.7.27, 19,6 МБ, `/catalog/1050/?from=…`, `/catalog/brands/list.php?BRAND=BRAUBERG&PAGEN_1=120`, '
    . '`UF_ACTIVE=\'1\'`, hl_apiship_terminal (UF_STATUS, UF_IDP, UF_DATE_CREATE), `local/php_interface/vendor/Kor/Store/User.php:416`, Валерий Моренков';
$check('text не трогает чистый текст', PerfMask::text($clean) === $clean, PerfMask::text($clean));
$check('find на чистом тексте пусто', PerfMask::find($clean) === [], json_encode(PerfMask::find($clean), JSON_UNESCAPED_UNICODE));
$check('text маскирует номер в пути ЛК', PerfMask::text('хит /personal/order/detail/10409767/, карточка /catalog/goods/531352/') === 'хит /personal/order/detail/N/, карточка /catalog/goods/531352/', PerfMask::text('хит /personal/order/detail/10409767/, карточка /catalog/goods/531352/'));
$check('find ловит номер в пути без query', array_column(PerfMask::find('Пример: /personal/order/detail/10409767/.'), 'kind') === ['url'], json_encode(PerfMask::find('Пример: /personal/order/detail/10409767/.')));
$check('find ловит голый номер в пути', array_column(PerfMask::find('заказ /10409767/'), 'kind') === ['number'], json_encode(PerfMask::find('заказ /10409767/')));
$check('деление в SQL — не путь', !in_array('url', array_column(PerfMask::find('ROUND(SUM(x)/1048576)'), 'kind'), true));
$kinds = array_column(PerfMask::find('См. `/search/?q=освежитель+для` и etalon 10.0.0.41, клиент 1192324'), 'kind');
$check('find url/ip/number', $kinds === ['url', 'ip', 'number'], implode(',', $kinds));

$r = PerfMask::row(['ID' => '959861', 'REQUEST_URI' => '/desk/clients/okt/?CLIENT_ID=123456', 'PAGE_TIME' => '1.234', 'QUERIES' => '1171045', 'COMPONENT_NAME' => 'public:basket'], false);
$check('row тех.: ID и метрики как есть', $r['ID'] === '959861' && $r['PAGE_TIME'] === '1.234' && $r['QUERIES'] === '1171045' && $r['COMPONENT_NAME'] === 'public:basket', json_encode($r, JSON_UNESCAPED_UNICODE));
$check('row тех.: URI', $r['REQUEST_URI'] === '/desk/clients/okt/?CLIENT_ID=…', (string) $r['REQUEST_URI']);

$r = PerfMask::row(['ID' => '722274', 'LOGIN' => 'ivan', 'UF_STATUS' => 'W', 'cnt' => '15', 'UF_TITLE' => 'Ромашка', 'IP' => '203.0.113.7',
    'USER_AGENT' => 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', 'PASSWORD' => 'x', 'UF_CODE' => 'dmd100001', 'x' => 'ООО Ромашка'], true);
$absent('row данные', json_encode($r, JSON_UNESCAPED_UNICODE), ['722274', 'ivan', 'Ромашка', '203.0.113', 'dmd100001']);
$check('row данные: коды и счётчики как есть', $r['UF_STATUS'] === 'W' && $r['cnt'] === '15', json_encode($r, JSON_UNESCAPED_UNICODE));
$check('row данные: UA → класс, пароль скрыт', $r['USER_AGENT'] === 'bot:YandexBot' && $r['PASSWORD'] === PerfMask::HIDDEN, json_encode($r, JSON_UNESCAPED_UNICODE));
$check('row данные: псевдоним IP', str_starts_with((string) $r['IP'], '‹ip:'), (string) $r['IP']);

$p1 = PerfMask::pseudo('ip', '203.0.113.7');
$check('pseudo стабилен в процессе и без цифр', $p1 === PerfMask::pseudo('ip', '203.0.113.7') && $p1 !== PerfMask::pseudo('ip', '203.0.113.8') && !preg_match('~\d~', $p1), $p1);

foreach ([
    ['SELECT * FROM b_perf_hit h JOIN b_perf_sql s ON s.HIT_ID = h.ID', true],
    ['SELECT COUNT(*) FROM b_user', false],
    ["SELECT * FROM b_perf_sql WHERE SQL_TEXT LIKE '%hl_clients%'", true],
    ['EXPLAIN SELECT * FROM hl_clients', true],
    ['SELECT TABLE_NAME FROM information_schema.TABLES', true],
    ['SELECT * FROM b_perf_hit, b_user', false],
    ['SELECT UF_STATUS, COUNT(*) FROM `hl_commercial_offers` GROUP BY UF_STATUS', false],
] as [$q, $want]) {
    $check("isTechnicalQuery $q", PerfMask::isTechnicalQuery($q) === $want);
}

echo $fail === 0 ? "OK: все проверки маскирования пройдены\n" : "Провалено: $fail\n";
exit($fail === 0 ? 0 : 1);
