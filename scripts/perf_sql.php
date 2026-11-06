<?php

declare(strict_types=1);

/**
 * Запросник к БД среза: только чтение, вывод всегда маскирован (PerfMask) — markdown-таблица, VERTICAL=1 — построчно без обрезки.
 *   docker exec -e PERF_DB_HOST=<PERF_DB_HOST> php-fpm php /var/www/project/.claude/skills/perf-check/scripts/perf_sql.php "SELECT …;
 *   SELECT …"                                  # запросы разделяются «;» + перевод строки
 *   … perf_sql.php -f /var/www/project/<путь>.sql
 * Запрос не только к perfmon и служебным таблицам — режим «данные»: строки → псевдонимы, ID → псевдонимы, числа и коды как есть.
 */

require __DIR__ . '/lib/mask.php';
require __DIR__ . '/lib/db.php';

$args = array_slice($argv, 1);
$sql = ($args[0] ?? '') === '-f' ? (string) file_get_contents($args[1] ?? '') : implode(' ', $args);
if (trim($sql) === '') {
    fwrite(STDERR, "Передайте SQL строкой или через -f файл\n");
    exit(2);
}
if (getenv('RAW')) {
    fwrite(STDERR, "RAW не поддерживается: вывод всегда маскирован; полный текст ячеек — VERTICAL=1\n");
}
$db = perf_db();
perf_slice_banner($db);

$t = microtime(true);
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: [])) as $one) {
    if (!preg_match('~^\(?\s*(?:SELECT|SHOW|EXPLAIN|DESCRIBE|DESC)\b~i', $one) || preg_match('~\bINTO\s+(?:OUTFILE|DUMPFILE)\b|\bFOR\s+UPDATE\b~i', $one)) {
        fwrite(STDERR, 'Пропущено (только чтение): ' . mb_strimwidth(PerfMask::sql($one), 0, 100, '…') . "\n");
        continue;
    }
    $dataMode = !PerfMask::isTechnicalQuery($one);
    $res = $db->query($one);
    if (!$res instanceof mysqli_result) {
        echo "OK\n";
        continue;
    }
    $rows = array_map(static fn(array $r): array => PerfMask::row($r, $dataMode), $res->fetch_all(MYSQLI_ASSOC));
    $res->free();
    if (getenv('VERTICAL')) {
        foreach ($rows as $i => $r) {
            echo "--- #$i\n";
            foreach ($r as $c => $v) {
                echo "$c: " . ($v ?? 'NULL') . "\n";
            }
        }
    } else {
        echo perf_md_table($rows);
    }
    echo count($rows) . ' строк' . ($dataMode ? ' · режим «данные»: строки и ID показаны псевдонимами' : '') . "\n";
}
fprintf(STDERR, "[%.2f c]\n", microtime(true) - $t);
