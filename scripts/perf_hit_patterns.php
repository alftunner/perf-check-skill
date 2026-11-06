<?php

declare(strict_types=1);

/**
 * Шаблоны SQL внутри хита (или списка хитов): сколько раз повторяется запрос и в каком компоненте — поиск N+1.
 *   docker exec -e PERF_DB_HOST=<PERF_DB_HOST> php-fpm php …/perf_hit_patterns.php <hit_id[,hit_id…]> [limit=25]
 */

require __DIR__ . '/lib/mask.php';
require __DIR__ . '/lib/db.php';

$ids = implode(',', array_map('intval', explode(',', $argv[1] ?? '0')));
$limit = (int) ($argv[2] ?? 25);
$db = perf_db();
$res = $db->query("SELECT s.QUERY_TIME, LEFT(s.SQL_TEXT, 3000) SQL_TEXT, c.COMPONENT_NAME, s.SELECTED_ROWS
    FROM b_perf_sql s LEFT JOIN b_perf_component c ON c.ID = s.COMPONENT_ID WHERE s.HIT_ID IN ($ids) ORDER BY s.NN");
$agg = [];
$n = 0;
$total = 0.0;
while ($row = $res->fetch_assoc()) {
    ++$n;
    $total += (float) $row['QUERY_TIME'];
    $t = PerfMask::template((string) $row['SQL_TEXT']);
    $k = md5($t);
    $agg[$k] ??= ['cnt' => 0, 'sum' => 0.0, 'rows' => 0, 'text' => $t, 'comps' => []];
    ++$agg[$k]['cnt'];
    $agg[$k]['sum'] += (float) $row['QUERY_TIME'];
    $agg[$k]['rows'] += (int) $row['SELECTED_ROWS'];
    $cn = (string) ($row['COMPONENT_NAME'] ?? '(без компонента)');
    $agg[$k]['comps'][$cn] = ($agg[$k]['comps'][$cn] ?? 0) + 1;
}
uasort($agg, static fn(array $a, array $b): int => $b['cnt'] <=> $a['cnt']);
printf("Хиты %s: %d запросов, %.3f с SQL, %d уникальных шаблонов\n\n", $ids, $n, $total, count($agg));
$i = 0;
foreach ($agg as $a) {
    if (++$i > $limit) {
        break;
    }
    arsort($a['comps']);
    printf(
        "×%-5d Σ %.3f с | строк %d | %s\n      %s\n\n",
        $a['cnt'], $a['sum'], $a['rows'],
        implode(', ', array_map(static fn($k, $v) => "{$k}×{$v}", array_keys($a['comps']), $a['comps'])),
        mb_strimwidth($a['text'], 0, 420, '…')
    );
}
