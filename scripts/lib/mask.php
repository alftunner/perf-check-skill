<?php

declare(strict_types=1);

/**
 * Маскирование данных клиентов в выводе скриптов регулярной проверки Bitrix.
 * Правило и его границы — SKILL.md, раздел «Данные клиентов»; проверка — scripts/test/mask_test.php.
 */
final class PerfMask
{
    public const HIDDEN = '‹скрыто›';

    /** Таблицы без данных людей: строки из них показываются после детекторов, остальные таблицы — режим «данные». */
    private const TECH_TABLE = '~^(?:b_perf_\w+|information_schema\.\w+|b_option(?:_site)?|b_module\w*|b_agent|b_hlblock_entity\w*'
        . '|b_iblock|b_iblock_property\w*|b_iblock_type\w*|b_lang|b_site\w*|b_culture|b_user_field\w*|b_samson_oil_history)$~i';

    /** Публичные разделы: числа в пути — коды разделов, товаров и статей. */
    private const PUBLIC_PATH = '~^/(?:catalog|info|promo|home|work|news|brands|search|actions|about|help)(?:/|$)~';

    /** Параметры URL, значения которых не относятся к посетителю. */
    private const SAFE_PARAM = '~^(?:PAGEN_\d+|SHOWALL_\d+|SIZEN_\d+|SECTION|SECTION_ID|SECTION_CODE|BRAND|sort|order|by|view|display'
        . '|set_filter|del_filter|login|logout|register|forgot_password|clear_cache|show_all|mode|tab|lang|type|project)$~i';

    private const SECRET_COLUMN = '~(?:^|_)(?:PASSWORD|PASS|TOKEN|SECRET|CHECKWORD|HASH|MD5|COOKIE|SESSION_DATA|AUTH|API_KEY|SIGN|SIGNATURE)(?:_|$)~i';
    private const SQL_COLUMN = '~(?:^|_)(?:SQL_TEXT|SQL|QUERY_TEXT|INFO)$~i';
    private const URI_COLUMN = '~(?:URI|URL|REFERE?R|HREF|LINK)~i';
    private const UA_COLUMN = '~(?:^|_)(?:USER_AGENT|UA)$~i';
    private const ID_COLUMN = '~(?:^|_)ID$~i';
    private const PERSON_COLUMN = '~(?:^|_)(?:EMAIL|E_MAIL|MAIL|PHONE|TEL|MOBILE|FAX|LOGIN|NAME|FIO|SURNAME|PATRONYMIC|IP|IP_ADDR|IP_FIRST|IP_LAST'
        . '|REMOTE_ADDR|ADDRESS|ADDR|STREET|HOUSE|FLAT|ZIP|INN|KPP|OGRN|OKPO|BIK|ACCOUNT|BANK|CARD|PASSPORT|SNILS|BIRTHDAY|BIRTHDATE|BIRTH'
        . '|COMMENTS?|MESSAGE|TEXT|NOTES?|DESCRIPTION|TITLE|COMPANY|ORGANIZATION|CONTACT|PERSON|CLIENT|BUYER|CUSTOMER|RECIPIENT|SENDER'
        . '|QUERY|PHRASE|SEARCH)(?:_|$)~i';

    /** Порядок важен: подстановка ‹вид› не должна совпадать со следующими детекторами. */
    private const DETECTORS = [
        'token' => '~\beyJ[\w\-]{8,}\.[\w\-]{8,}\.[\w\-]{8,}|\b(?:perm|Bearer)[:\s]+[\w.\-=:]{16,}~u',
        'email' => '~[\p{L}\p{N}._%+\-]+(?:@|%40)[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)*\.\p{L}{2,}~u',
        'uuid' => '~\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b~u',
        'hash' => '~\b[0-9a-fA-F]{16,}\b~u',
        'ip' => '~(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?!\w|\.\d)|(?<![\w:.])(?=[0-9A-Fa-f:.]*::|(?:[0-9A-Fa-f]{1,4}:){2})[0-9A-Fa-f:.]{6,45}(?![\w:])~u',
        'phone' => '~(?<![\w+])(?:\+7|8)[\s\-(]{0,2}\d{3}[\s\-)]{0,2}\d{3}[\s\-]?\d{2}[\s\-]?\d{2}(?!\d)|(?<![\w+])\+?7\d{10}(?!\d)~u',
        'fio' => '~\b\p{Lu}\p{Ll}+\s+\p{Lu}\.\s?\p{Lu}\.|\b\p{Lu}\.\s?\p{Lu}\.\s?\p{Lu}\p{Ll}+'
            . '|\b\p{Lu}\p{Ll}+\s+\p{Lu}\p{Ll}+\s+\p{Lu}\p{Ll}+(?:вич|вна|ична|чна|оглы|кызы)\b'
            . '|\b\p{Lu}\p{Ll}+\s+\p{Lu}\p{Ll}+(?:вич|вна|ична|чна)\s+\p{Lu}\p{Ll}+~u',
        'company' => '~\b(?:ООО|ОАО|ЗАО|ПАО|АО|НКО|ИП|ГУП|МУП|ФГУП|ГБУ|МБУ|ГКУ|МКУ|ФГБУ|АНО|ТОО)\s*[«"„“][^»"“”\n]{1,80}[»"“”]|\bИП\s+\p{Lu}\p{Ll}+~u',
        'address' => '~(?<!\p{L})(?:ул\.|улица|пр-т|просп\.|проспект|пер\.|переулок|ш\.|шоссе|наб\.|набережная|б-р|бульвар|пл\.|площадь)'
            . '\s*\p{Lu}[^\n,;]{0,40},?\s*(?:д\.|дом)\s*\d+~u',
        'number' => '~(?<![\w\-]|\d[.,]|[A-Za-z_\-]/)\d{6,}(?!\w|[.,]\d)~u',
    ];

    /** URL внутри текста: абсолютный, путь от корня (с query string или без) или голая query string. */
    private const EMBEDDED_URL = '~https?://[^\s`\'"<>|)\]»]+|(?<![\w/])/(?=[\w\-.%\~]*[A-Za-z])[\w\-./%\~]+(?:\?[^\s`\'"<>|)\]»]+)?|(?<![\w/?&])\?[A-Za-z_][\w\[\]]*=[^\s`\'"<>|)\]»]+~u';

    private static ?string $key = null;

    public static function scrub(string $s): string
    {
        return mb_scrub($s, 'UTF-8');
    }

    /** SQL для показа: литералы → 'X' (кроме дат и коротких кодов), числа от 5 цифр → N. */
    public static function sql(string $sql): string
    {
        return self::text(self::rewriteSql(
            self::scrub($sql),
            static fn(string $inner, bool $closed): string => $closed ? (self::isCode($inner) ? "'" . $inner . "'" : "'X'") : "'X…",
            static fn(string $code): string => preg_replace(['~0x[0-9A-Fa-f]{8,}~', '~\b\d{5,}(?:\.\d+)?\b~'], 'N', $code) ?? $code
        ));
    }

    /** Шаблон запроса для группировки: все литералы → 'X', все числа → N, списки IN свёрнуты. */
    public static function template(string $sql): string
    {
        $s = preg_replace('~\s+~', ' ', self::scrub($sql)) ?? '';
        $s = self::rewriteSql(
            $s,
            static fn(string $inner, bool $closed): string => $closed ? "'X'" : "'X…",
            static fn(string $code): string => preg_replace(['~\b0x[0-9A-Fa-f]+\b~', '~\b\d+(?:\.\d+)?\b~'], 'N', $code) ?? $code
        );
        $s = preg_replace(['~\(\s*N(?:\s*,\s*N)+\s*\)~', "~\\(\\s*'X'(?:\\s*,\\s*'X')+\\s*\\)~"], ['(N…)', "('X'…)"], $s) ?? $s;

        return self::text($s);
    }

    public static function uri(string $uri): string
    {
        $u = self::scrub($uri);
        $prefix = '';
        if (preg_match('~^(https?://[^/?#]+)(.*)$~is', $u, $m)) {
            $prefix = self::detect($m[1]);
            $u = $m[2];
        }
        $u = preg_replace('~#.*$~s', '', $u) ?? $u;
        [$path, $query] = array_pad(explode('?', $u, 2), 2, null);
        if (!preg_match(self::PUBLIC_PATH, $path)) {
            $path = preg_replace('~\d{4,}~', 'N', $path) ?? $path;
        }
        $path = self::detect($path);
        if ($query === null || $query === '') {
            return $prefix . $path;
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
            $k = urldecode($k);
            if (!preg_match('~^[A-Za-z0-9_\[\].\-]{1,40}$~', $k)) {
                $pairs[] = '…';
                continue;
            }
            if ($v === null) {
                $pairs[] = $k;
                continue;
            }
            $dv = urldecode($v);
            $safe = preg_match(self::SAFE_PARAM, $k) && preg_match('~^[A-Za-z0-9_\-]{1,40}$~', $dv) && self::detect($dv) === $dv;
            $pairs[] = $k . '=' . ($safe ? $dv : '…');
        }

        return $prefix . $path . ($pairs === [] ? '' : '?' . implode('&', $pairs));
    }

    /** Свободный текст: URL маскируются по правилам uri(), затем детекторы → ‹вид›. */
    public static function text(string $s): string
    {
        $s = preg_replace_callback(self::EMBEDDED_URL, static fn(array $m): string => self::uri($m[0]), self::scrub($s)) ?? $s;

        return self::detect($s);
    }

    public static function userAgent(string $ua): string
    {
        foreach (['YandexBot', 'YandexImages', 'YandexMobileBot', 'YandexMetrika', 'YandexAccessibilityBot', 'YandexRenderResourcesBot',
            'Googlebot', 'AdsBot-Google', 'Google-InspectionTool', 'bingbot', 'Applebot', 'DuckDuckBot', 'Baiduspider', 'PetalBot', 'AhrefsBot',
            'SemrushBot', 'MJ12bot', 'DotBot', 'Bytespider', 'GPTBot', 'ClaudeBot', 'facebookexternalhit', 'Mail.RU_Bot', 'TelegramBot', 'vkShare'] as $bot) {
            if (stripos($ua, $bot) !== false) {
                return 'bot:' . $bot;
            }
        }
        if (preg_match('~bot|crawl|spider|slurp|curl|wget|python|java/|go-http|okhttp|headless|scrapy|httpclient~i', $ua)) {
            return 'bot:other';
        }
        $family = match (true) {
            str_contains($ua, 'YaBrowser') => 'YaBrowser',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'other',
        };

        return 'browser:' . $family . '/' . (preg_match('~Mobile|Android|iPhone|iPad~i', $ua) ? 'mobile' : 'desktop');
    }

    /** Псевдоним без цифр (ключ живёт только в процессе): одинаковые значения видны как одинаковые, восстановить нельзя. */
    public static function pseudo(string $kind, string $value): string
    {
        self::$key ??= random_bytes(32);
        $h = hash_hmac('sha256', $value, self::$key, true);
        $abc = 'abcdefghjkmnpqrstuvwxyz';
        $out = '';
        for ($i = 0; $i < 6; ++$i) {
            $out .= $abc[ord($h[$i]) % strlen($abc)];
        }

        return '‹' . $kind . ':' . $out . '›';
    }

    /** true — в запросе только perfmon и служебные таблицы (или EXPLAIN/SHOW); иначе ячейки маскируются в режиме «данные». */
    public static function isTechnicalQuery(string $sql): bool
    {
        if (preg_match('~^\s*(?:EXPLAIN|DESCRIBE|DESC|SHOW)\b~i', $sql)) {
            return true;
        }
        $code = self::rewriteSql(self::scrub($sql), static fn(): string => "''", static fn(string $c): string => $c);
        preg_match_all('~\b(?:FROM|JOIN|UPDATE|INTO)\s+((?:`?\w+`?\s*\.\s*)?`?\w+`?)~i', $code, $m1);
        preg_match_all('~\b(?:information_schema\.\w+|(?:b|hl|kor|samson|api|bx)_\w+)~i', $code, $m2);
        foreach (array_merge($m1[1], $m2[0]) as $t) {
            $t = preg_replace('~[`\s]~', '', $t) ?? $t;
            if (!preg_match(self::TECH_TABLE, $t)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, ?string> $row
     *
     * @return array<array-key, ?string>
     */
    public static function row(array $row, bool $dataMode): array
    {
        $out = [];
        foreach ($row as $col => $v) {
            $out[$col] = self::cell((string) $col, $v, $dataMode);
        }

        return $out;
    }

    public static function cell(string $col, ?string $v, bool $dataMode): ?string
    {
        if ($v === null || $v === '') {
            return $v;
        }
        $v = self::scrub($v);
        if (preg_match(self::SECRET_COLUMN, $col)) {
            return self::HIDDEN;
        }
        if (preg_match(self::SQL_COLUMN, $col) || preg_match('~^\s*\(?\s*(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|SHOW|WITH|CALL|SET|ALTER|CREATE|DROP|TRUNCATE|EXPLAIN)\b~i', $v)) {
            return self::sql($v);
        }
        if (preg_match(self::URI_COLUMN, $col) || preg_match('~^(?:https?://|/)\S*$~', $v)) {
            return self::uri($v);
        }
        if (preg_match(self::UA_COLUMN, $col)) {
            return self::userAgent($v);
        }
        if ($dataMode && preg_match(self::PERSON_COLUMN, $col)) {
            return self::pseudo(self::kindOf($col), $v);
        }
        if (preg_match('~^-?\d+(?:\.\d+)?$~', $v)) {
            return $dataMode && preg_match(self::ID_COLUMN, $col) ? self::pseudo('id', $v) : $v;
        }
        if (!$dataMode) {
            return self::text($v);
        }

        return self::isCode($v) ? $v : self::pseudo('v', $v);
    }

    /**
     * Находки для проверки исходящего текста (pii_check.php).
     *
     * @return list<array{kind: string, offset: int, length: int}>
     */
    public static function find(string $s): array
    {
        $s = self::scrub($s);
        $found = [];
        foreach (self::DETECTORS as $kind => $re) {
            preg_match_all($re, $s, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$match, $offset]) {
                if ($kind !== 'ip' || self::isIp($match)) {
                    $found[] = ['kind' => $kind, 'offset' => $offset, 'length' => strlen($match)];
                }
            }
        }
        preg_match_all(self::EMBEDDED_URL, $s, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$url, $offset]) {
            $url = rtrim($url, '.,;:!');
            if (self::uri($url) !== $url) {
                $found[] = ['kind' => 'url', 'offset' => $offset, 'length' => strlen($url)];
            }
        }
        usort($found, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $found;
    }

    /**
     * Контекст находки для печати: строка маскируется целиком по частям до и после находки, обрезка — уже по маске.
     *
     * @param array{kind: string, offset: int, length: int} $f
     */
    public static function context(string $s, array $f, int $width = 60): string
    {
        $start = strrpos(substr($s, 0, $f['offset']), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($s, "\n", $f['offset'] + $f['length']);
        $end = $end === false ? strlen($s) : $end;
        $before = self::text(substr($s, $start, $f['offset'] - $start));
        $after = self::text(substr($s, $f['offset'] + $f['length'], $end - $f['offset'] - $f['length']));

        return trim((mb_strlen($before) > $width ? '…' . mb_substr($before, -$width) : $before) . '‹' . $f['kind'] . '›' . mb_strimwidth($after, 0, $width, '…'));
    }

    private static function detect(string $s): string
    {
        foreach (self::DETECTORS as $kind => $re) {
            $s = preg_replace_callback(
                $re,
                static fn(array $m): string => $kind !== 'ip' || self::isIp($m[0]) ? '‹' . $kind . '›' : $m[0],
                $s
            ) ?? $s;
        }

        return $s;
    }

    private static function isIp(string $s): bool
    {
        if (str_contains($s, ':')) {
            return substr_count($s, ':') >= 2 && filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /** Литерал, который не может нести данные человека: дата, короткое число, флаг, код вида 'Y', 'mg', 'PRICE_TYPE'. */
    private static function isCode(string $v): bool
    {
        return $v === ''
            || preg_match('~^-?\d{1,4}(?:[.,]\d+)?$~', $v)
            || preg_match('~^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?$~', $v)
            || preg_match('~^\d{2}\.\d{2}\.\d{4}(?: \d{2}:\d{2}(?::\d{2})?)?$~', $v)
            || preg_match('~^[A-Za-z0-9]{1,3}$~', $v)
            || preg_match('~^%?[A-Z][A-Z0-9_]{0,39}%?$~', $v);
    }

    private static function kindOf(string $col): string
    {
        return match (true) {
            (bool) preg_match('~(?:^|_)(?:IP|IP_ADDR|IP_FIRST|IP_LAST|REMOTE_ADDR)(?:_|$)~i', $col) => 'ip',
            (bool) preg_match('~MAIL~i', $col) => 'email',
            (bool) preg_match('~PHONE|TEL|MOBILE|FAX~i', $col) => 'phone',
            default => 'v',
        };
    }

    /**
     * Разбор SQL на код и литералы ('…', "…"; `…` — идентификатор). Незакрытый литерал (SQL_TEXT обрезан) тянется до конца строки.
     *
     * @param callable(string, bool): string $literal
     * @param callable(string): string       $code
     */
    private static function rewriteSql(string $s, callable $literal, callable $code): string
    {
        $out = '';
        $buf = '';
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $ch = $s[$i];
            if ($ch === '`') {
                $j = strpos($s, '`', $i + 1);
                $j = $j === false ? $n : $j + 1;
                $buf .= substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }
            if ($ch !== "'" && $ch !== '"') {
                $buf .= $ch;
                ++$i;
                continue;
            }
            $out .= $code($buf);
            $buf = '';
            $j = $i + 1;
            $closed = false;
            while ($j < $n) {
                if ($s[$j] === '\\') {
                    $j += 2;
                    continue;
                }
                if ($s[$j] === $ch) {
                    if ($j + 1 < $n && $s[$j + 1] === $ch) {
                        $j += 2;
                        continue;
                    }
                    $closed = true;
                    break;
                }
                ++$j;
            }
            $out .= $literal(substr($s, $i + 1, min($j, $n) - $i - 1), $closed);
            $i = $closed ? $j + 1 : $n;
        }

        return $out . $code($buf);
    }
}
