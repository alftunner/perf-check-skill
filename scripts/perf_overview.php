<?php

declare(strict_types=1);

/**
 * Стандартные таблицы регламента по срезу (markdown в stdout) и ключевые метрики в JSON для сравнения со следующим срезом.
 *   docker exec -e PERF_DB_HOST=<PERF_DB_HOST> php-fpm php …/perf_overview.php [json_out] > <срез>/raw/overview.md
 * json_out — путь внутри контейнера (/var/www/project/.claude/tasks/perf-…/raw/overview.json).
 */

require __DIR__ . '/lib/mask.php';
require __DIR__ . '/lib/db.php';

$db = perf_db();
perf_slice_banner($db);
$q = static fn(string $sql): array => array_map(
    static fn(array $r): array => PerfMask::row($r, false),
    $db->query($sql)->fetch_all(MYSQLI_ASSOC)
);
$firstBy = static function (array $rows, string $col): array {
    $seen = [];

    return array_values(array_filter($rows, static function (array $r) use ($col, &$seen): bool {
        if (isset($seen[$r[$col]])) {
            return false;
        }

        return $seen[$r[$col]] = true;
    }));
};

$sections = [
    'Окно и объём' => $q('SELECT MIN(DATE_HIT) date_from, MAX(DATE_HIT) date_to, COUNT(*) hits, ROUND(SUM(PAGE_TIME)) page_s,
        ROUND(AVG(PAGE_TIME), 3) avg_page_s, SUM(QUERIES) queries, ROUND(SUM(QUERIES_TIME)) sql_s,
        ROUND(SUM(QUERIES_TIME) / NULLIF(SUM(QUERIES), 0) * 1000, 2) ms_per_query, SUM(COMPONENTS) components, ROUND(SUM(COMPONENTS_TIME)) components_s
        FROM b_perf_hit'),
    'Хиты по серверам' => $q('SELECT SERVER_NAME, COUNT(*) hits, ROUND(SUM(PAGE_TIME)) page_s FROM b_perf_hit GROUP BY SERVER_NAME ORDER BY hits DESC'),
    'Пороги регламента (>300 запросов, >1 с)' => $q('SELECT SUM(QUERIES > 300) hits_over_300q, SUM(PAGE_TIME > 1) hits_over_1s FROM b_perf_hit'),
    'Пороги по страницам' => $q('SELECT SCRIPT_NAME, SUM(PAGE_TIME > 1) over_1s, SUM(QUERIES > 300) over_300q, COUNT(*) hits
        FROM b_perf_hit GROUP BY SCRIPT_NAME HAVING over_1s > 0 OR over_300q > 0 ORDER BY over_1s DESC, over_300q DESC LIMIT 40'),
    'Страницы по суммарному времени' => $q('SELECT SCRIPT_NAME, COUNT(*) hits, ROUND(AVG(PAGE_TIME), 3) avg_t, ROUND(MAX(PAGE_TIME), 2) max_t,
        SUM(PAGE_TIME > 1) over_1s, MAX(QUERIES) max_q, SUM(QUERIES > 300) over_300q, ROUND(SUM(PAGE_TIME)) sum_t, ROUND(SUM(QUERIES_TIME)) sql_t,
        ROUND(AVG(MEMORY_PEAK_USAGE) / 1048576) mem_mb
        FROM b_perf_hit GROUP BY SCRIPT_NAME ORDER BY SUM(PAGE_TIME) DESC LIMIT 40'),
    'Страницы по шаблону пути (числа в пути → *, зеркала /home/ и /work/ свёрнуты)' => perf_group_pages($q('SELECT SCRIPT_NAME, COUNT(*) hits,
        SUM(PAGE_TIME) sum_t, MAX(PAGE_TIME) max_t, SUM(PAGE_TIME > 1) over_1s, MAX(QUERIES) max_q, SUM(QUERIES > 300) over_300q, SUM(QUERIES_TIME) sql_t
        FROM b_perf_hit GROUP BY SCRIPT_NAME'), 30),
    'Худший хит страницы' => $firstBy($q('SELECT h.ID hit_id, h.SCRIPT_NAME, h.REQUEST_METHOD m, h.REQUEST_URI, ROUND(h.PAGE_TIME, 3) t,
        h.COMPONENTS comps, ROUND(h.COMPONENTS_TIME, 2) comps_t, h.QUERIES q, ROUND(h.QUERIES_TIME, 3) q_t
        FROM b_perf_hit h JOIN (SELECT SCRIPT_NAME, MAX(PAGE_TIME) mt FROM b_perf_hit GROUP BY SCRIPT_NAME ORDER BY mt DESC LIMIT 25) w
          ON w.SCRIPT_NAME = h.SCRIPT_NAME AND w.mt = h.PAGE_TIME
        ORDER BY h.PAGE_TIME DESC'), 'SCRIPT_NAME'),
    'Компоненты по суммарному времени (время включает вложенные)' => $q('SELECT COMPONENT_NAME, COUNT(*) calls, ROUND(AVG(COMPONENT_TIME) * 1000) avg_ms,
        ROUND(MAX(COMPONENT_TIME), 3) max_t, ROUND(SUM(COMPONENT_TIME)) sum_t, SUM(COMPONENT_TIME >= 1) over_1s, SUM(QUERIES) q,
        ROUND(SUM(QUERIES_TIME)) q_t, GROUP_CONCAT(DISTINCT CACHE_TYPE) cache_param
        FROM b_perf_component GROUP BY COMPONENT_NAME ORDER BY SUM(COMPONENT_TIME) DESC LIMIT 30'),
    'Худший вызов компонента' => $firstBy($q('SELECT c.HIT_ID hit_id, c.COMPONENT_NAME, ROUND(c.COMPONENT_TIME, 3) t, c.QUERIES q,
        ROUND(c.QUERIES_TIME, 3) q_t, c.CACHE_TYPE cache_param, h.SCRIPT_NAME
        FROM b_perf_component c
        JOIN (SELECT COMPONENT_NAME, MAX(COMPONENT_TIME) mt FROM b_perf_component GROUP BY COMPONENT_NAME ORDER BY mt DESC LIMIT 20) w
          ON w.COMPONENT_NAME = c.COMPONENT_NAME AND w.mt = c.COMPONENT_TIME
        JOIN b_perf_hit h ON h.ID = c.HIT_ID
        ORDER BY c.COMPONENT_TIME DESC'), 'COMPONENT_NAME'),
    'Фазы хита по страницам (среднее, с)' => $q('SELECT SCRIPT_NAME, COUNT(*) hits, ROUND(AVG(PAGE_TIME), 3) page, ROUND(AVG(PROLOG_BEFORE_TIME), 3) prolog_before,
        ROUND(AVG(AGENTS_TIME), 3) agents, ROUND(AVG(PROLOG_AFTER_TIME), 3) prolog_after, ROUND(AVG(WORK_AREA_TIME), 3) work_area, ROUND(AVG(EPILOG_TIME), 3) epilog
        FROM b_perf_hit GROUP BY SCRIPT_NAME ORDER BY SUM(PAGE_TIME) DESC LIMIT 20'),
    'Самые долгие запросы (маска SQL; план — perf_explain.php <sql_id>)' => $q('SELECT s.ID sql_id, s.HIT_ID hit_id, ROUND(s.QUERY_TIME, 3) qt,
        IFNULL(c.COMPONENT_NAME, \'\') component, h.SCRIPT_NAME, LEFT(s.SQL_TEXT, 1000) sql_text
        FROM b_perf_sql s LEFT JOIN b_perf_component c ON c.ID = s.COMPONENT_ID JOIN b_perf_hit h ON h.ID = s.HIT_ID
        WHERE s.QUERY_TIME >= 0.5 ORDER BY s.QUERY_TIME DESC LIMIT 25'),
    'Оценка «Производительность конфигурации» (b_perf_history, больше — лучше)' => $q('SELECT TIMESTAMP_X, TOTAL_MARK, ACCELERATOR_ENABLED
        FROM b_perf_history ORDER BY ID DESC LIMIT 4'),
];

/**
 * @param list<array<string, ?string>> $rows
 *
 * @return list<array<string, string>>
 */
function perf_group_pages(array $rows, int $limit): array
{
    $g = [];
    foreach ($rows as $r) {
        $mirror = preg_match('~^/(home|work)/~', (string) $r['SCRIPT_NAME'], $m) ? $m[1] : null;
        $key = preg_replace(['~^/(?:home|work)/~', '~/\d+(?=/)~'], ['/', '/*'], (string) $r['SCRIPT_NAME']) ?? '';
        $g[$key] ??= ['hits' => 0, 'sum_t' => 0.0, 'max_t' => 0.0, 'over_1s' => 0, 'max_q' => 0, 'over_300q' => 0, 'sql_t' => 0.0, 'pages' => [], 'mirrors' => []];
        $a = &$g[$key];
        $a['hits'] += (int) $r['hits'];
        $a['sum_t'] += (float) $r['sum_t'];
        $a['max_t'] = max($a['max_t'], (float) $r['max_t']);
        $a['over_1s'] += (int) $r['over_1s'];
        $a['max_q'] = max($a['max_q'], (int) $r['max_q']);
        $a['over_300q'] += (int) $r['over_300q'];
        $a['sql_t'] += (float) $r['sql_t'];
        $a['pages'][(string) $r['SCRIPT_NAME']] = true;
        if ($mirror !== null) {
            $a['mirrors'][$mirror] = true;
        }
        unset($a);
    }
    uasort($g, static fn(array $a, array $b): int => $b['sum_t'] <=> $a['sum_t']);
    $out = [];
    foreach (array_slice($g, 0, $limit, true) as $key => $a) {
        $out[] = [
            'page_pattern' => $key . ($a['mirrors'] !== [] ? ' (+/' . implode(', /', array_keys($a['mirrors'])) . ')' : ''),
            'pages' => (string) count($a['pages']),
            'hits' => (string) $a['hits'],
            'avg_t' => number_format($a['sum_t'] / max(1, $a['hits']), 3, '.', ''),
            'max_t' => number_format($a['max_t'], 2, '.', ''),
            'over_1s' => (string) $a['over_1s'],
            'max_q' => (string) $a['max_q'],
            'over_300q' => (string) $a['over_300q'],
            'sum_t' => (string) round($a['sum_t']),
            'sql_t' => (string) round($a['sql_t']),
        ];
    }

    return $out;
}

echo "# Обзор среза perfmon\n";
foreach ($sections as $title => $rows) {
    echo "\n## $title\n\n" . perf_md_table($rows);
}

if (isset($argv[1])) {
    $keep = ['Окно и объём', 'Хиты по серверам', 'Пороги регламента (>300 запросов, >1 с)', 'Пороги по страницам', 'Страницы по суммарному времени', 'Страницы по шаблону пути (числа в пути → *, зеркала /home/ и /work/ свёрнуты)',
        'Компоненты по суммарному времени (время включает вложенные)', 'Оценка «Производительность конфигурации» (b_perf_history, больше — лучше)'];
    file_put_contents($argv[1], json_encode(array_intersect_key($sections, array_flip($keep)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, "JSON: {$argv[1]}\n");
}
