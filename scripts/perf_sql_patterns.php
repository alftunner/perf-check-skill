<?php

declare(strict_types=1);

/**
 * Группировка b_perf_sql по шаблону запроса (PerfMask::template: литералы → 'X', числа → N) — топ по суммарному времени.
 *   docker exec -e PERF_DB_HOST=<PERF_DB_HOST> php-fpm php …/perf_sql_patterns.php [min_query_time=0] [limit=40] [script_name_like]
 * Полный проход по ~1 млн строк — минуты: запускать отвязанно (docker exec -d … > raw/<файл>.txt 2>&1).
 */

require __DIR__ . '/lib/mask.php';
require __DIR__ . '/lib/db.php';

$minTime = (float) ($argv[1] ?? 0.0);
$limit = (int) ($argv[2] ?? 40);
$scriptLike = $argv[3] ?? null;
$db = perf_db(3600000);
perf_slice_banner($db);

$where = 'WHERE s.QUERY_TIME >= ' . $minTime;
$join = '';
if ($scriptLike !== null) {
    $join = 'JOIN b_perf_hit h ON h.ID = s.HIT_ID';
    $where .= " AND h.SCRIPT_NAME LIKE '" . $db->real_escape_string($scriptLike) . "'";
}
$res = $db->query(
    "SELECT s.QUERY_TIME, LEFT(s.SQL_TEXT, 4000) SQL_TEXT, s.HIT_ID, c.COMPONENT_NAME, s.SELECTED_ROWS
     FROM b_perf_sql s LEFT JOIN b_perf_component c ON c.ID = s.COMPONENT_ID $join $where",
    MYSQLI_USE_RESULT
);
$agg = [];
$n = 0;
while ($row = $res->fetch_assoc()) {
    ++$n;
    $t = PerfMask::template((string) $row['SQL_TEXT']);
    $key = md5($t);
    $agg[$key] ??= ['cnt' => 0, 'sum' => 0.0, 'max' => 0.0, 'hits' => [], 'comps' => [], 'rows' => 0, 'text' => $t, 'sample_hit' => (int) $row['HIT_ID']];
    $a = &$agg[$key];
    ++$a['cnt'];
    $a['sum'] += (float) $row['QUERY_TIME'];
    $a['max'] = max($a['max'], (float) $row['QUERY_TIME']);
    $a['rows'] += (int) $row['SELECTED_ROWS'];
    $a['hits'][(int) $row['HIT_ID']] = true;
    $cn = (string) ($row['COMPONENT_NAME'] ?? '');
    $a['comps'][$cn] = ($a['comps'][$cn] ?? 0) + 1;
    unset($a);
}
$res->free();

uasort($agg, static fn(array $a, array $b): int => $b['sum'] <=> $a['sum']);
printf("Строк b_perf_sql обработано: %d; уникальных шаблонов: %d\n\n", $n, count($agg));
$i = 0;
foreach ($agg as $a) {
    if (++$i > $limit) {
        break;
    }
    arsort($a['comps']);
    $top = array_slice($a['comps'], 0, 3, true);
    $comps = implode(', ', array_map(static fn($k, $v) => ($k === '' ? '(без компонента)' : $k) . "×$v", array_keys($top), $top));
    printf(
        "#%d  Σ %.1f с | %d вып. | сред. %.1f мс | макс %.2f с | хитов %d | строк/вып. %.0f | sample_hit %d\n    комп.: %s\n    %s\n\n",
        $i, $a['sum'], $a['cnt'], $a['sum'] / $a['cnt'] * 1000, $a['max'], count($a['hits']), $a['rows'] / $a['cnt'], $a['sample_hit'],
        $comps, mb_strimwidth($a['text'], 0, 700, '…')
    );
}
