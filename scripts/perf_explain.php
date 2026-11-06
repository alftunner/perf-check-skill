<?php

declare(strict_types=1);

/**
 * EXPLAIN и контрольный прогон запросов среза по ID из b_perf_sql: настоящий текст запроса остаётся в контейнере,
 * печатаются маска запроса, план и время. Выполняются только SELECT (сессия read only).
 *   docker exec -e PERF_DB_HOST=<PERF_DB_HOST> php-fpm php …/perf_explain.php <sql_id[,sql_id…]> [run=1]
 */

require __DIR__ . '/lib/mask.php';
require __DIR__ . '/lib/db.php';

$ids = array_filter(array_map('intval', explode(',', $argv[1] ?? '')));
if ($ids === []) {
    fwrite(STDERR, "Передайте ID из b_perf_sql через запятую\n");
    exit(2);
}
$run = (int) ($argv[2] ?? 1);
$db = perf_db(60000);
perf_slice_banner($db);
$res = $db->query('SELECT s.ID, s.HIT_ID, s.QUERY_TIME, s.SQL_TEXT, c.COMPONENT_NAME, h.SCRIPT_NAME
    FROM b_perf_sql s LEFT JOIN b_perf_component c ON c.ID = s.COMPONENT_ID JOIN b_perf_hit h ON h.ID = s.HIT_ID
    WHERE s.ID IN (' . implode(',', $ids) . ')');
foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
    $sql = (string) $r['SQL_TEXT'];
    printf(
        "\n##### sql_id %d · hit %d · %s · %s · на проде %.3f с\n%s\n",
        $r['ID'], $r['HIT_ID'], PerfMask::uri((string) $r['SCRIPT_NAME']), $r['COMPONENT_NAME'] ?? '(без компонента)', $r['QUERY_TIME'],
        mb_strimwidth(preg_replace('~\s+~u', ' ', PerfMask::sql($sql)) ?? '', 0, 600, '…')
    );
    if (!preg_match('~^\s*\(?\s*SELECT\b~i', $sql)) {
        echo "  не SELECT — план и прогон не выполняются\n";
        continue;
    }
    try {
        echo perf_md_table(array_map(
            static fn(array $p): array => PerfMask::row($p, false),
            $db->query('EXPLAIN ' . $sql)->fetch_all(MYSQLI_ASSOC)
        ), 60);
        if ($run) {
            $t = microtime(true);
            $q = $db->query($sql, MYSQLI_USE_RESULT);
            $n = 0;
            while ($q->fetch_row()) {
                ++$n;
            }
            $q->free();
            printf("  на БД среза: %.3f с, строк %d\n", microtime(true) - $t, $n);
        }
    } catch (Throwable $e) {
        echo '  ОШИБКА: ' . PerfMask::sql($e->getMessage()) . "\n";
    }
}
