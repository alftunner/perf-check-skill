<?php

declare(strict_types=1);

/**
 * Подключение к БД среза perfmon только на чтение. Хост — обязательный -e PERF_DB_HOST (без него скрипт молча ушёл бы
 * на дефолтную тестовую базу со старым срезом), логин и пароль — из окружения контейнера, в выводе их нет.
 * Ошибки MySQL цитируют кусок запроса — печатаются только через PerfMask::sql().
 */

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'Ошибка: ' . PerfMask::sql($e->getMessage()) . "\n");
    exit(1);
});

function perf_db(int $timeoutMs = 600000): mysqli
{
    $host = (string) getenv('PERF_DB_HOST');
    if ($host === '' || (string) getenv('PROJECT_DB_USERNAME') === '') {
        fwrite(STDERR, "Запуск: docker exec -e PERF_DB_HOST=<хост БД среза> php-fpm php …\n");
        exit(2);
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    [$h, $p] = array_pad(explode(':', $host, 2), 2, null);
    $db = new mysqli($h, (string) getenv('PROJECT_DB_USERNAME'), (string) getenv('PROJECT_DB_PASSWORD'), (string) getenv('PROJECT_DB_NAME'), $p !== null ? (int) $p : 3306);
    $db->set_charset('utf8mb4');
    $db->query('SET SESSION TRANSACTION READ ONLY');
    $db->query('SET SESSION max_execution_time = ' . $timeoutMs);

    return $db;
}

/** Окно среза в stderr: по нему видно, что скрипт смотрит в нужную базу. */
function perf_slice_banner(mysqli $db): void
{
    $r = $db->query('SELECT MIN(DATE_HIT) a, MAX(DATE_HIT) b, COUNT(*) c FROM b_perf_hit')->fetch_assoc();
    fprintf(STDERR, "[срез %s – %s, %s хитов]\n", $r['a'] ?? '—', $r['b'] ?? '—', number_format((int) $r['c'], 0, ',', ' '));
}

/** @param list<array<string, ?string>> $rows */
function perf_md_table(array $rows, int $width = 160): string
{
    if ($rows === []) {
        return "(пусто)\n";
    }
    $cols = array_keys($rows[0]);
    $cell = static fn(?string $v): string => $v === null ? 'NULL' : (preg_replace('~\s+~u', ' ', $v) ?? $v);
    $w = [];
    foreach ($cols as $c) {
        $w[$c] = mb_strlen((string) $c);
        foreach ($rows as $r) {
            $w[$c] = max($w[$c], min($width, mb_strlen($cell($r[$c]))));
        }
    }
    $line = static function (array $r) use ($cols, $w, $cell, $width): string {
        $out = [];
        foreach ($cols as $c) {
            $v = mb_strimwidth($cell($r[$c] ?? null), 0, $width, '…');
            $out[] = $v . str_repeat(' ', max(0, $w[$c] - mb_strlen($v)));
        }

        return '| ' . implode(' | ', $out) . " |\n";
    };
    $s = $line(array_combine($cols, array_map('strval', $cols)));
    $s .= '|' . implode('|', array_map(static fn($c) => str_repeat('-', $w[$c] + 2), $cols)) . "|\n";
    foreach ($rows as $r) {
        $s .= $line($r);
    }

    return $s;
}
