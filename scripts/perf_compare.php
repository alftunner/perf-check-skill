<?php

declare(strict_types=1);

/**
 * Сравнение двух срезов по overview.json (perf_overview.php): итоги, пороги, страницы и компоненты с дельтами.
 *   php .claude/skills/perf-check/scripts/perf_compare.php <прошлый/raw/overview.json> <текущий/raw/overview.json> [top=25]
 */

[$prev, $cur] = array_map(static fn(string $f): array => json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR), [$argv[1] ?? '', $argv[2] ?? '']);
$top = (int) ($argv[3] ?? 25);
$fmt = static fn($v): string => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',') : (string) $v;
$delta = static function ($a, $b) use ($fmt): string {
    if (!is_numeric($a) || !is_numeric($b)) {
        return '';
    }
    $d = (float) $b - (float) $a;
    $pct = (float) $a != 0.0 ? sprintf(' (%+.0f %%)', $d / (float) $a * 100) : '';

    return ($d > 0 ? '+' : '') . $fmt($d) . $pct;
};

foreach (['Окно и объём', 'Пороги регламента (>300 запросов, >1 с)'] as $s) {
    echo "\n## $s\n\n| метрика | было | стало | Δ |\n|---|---|---|---|\n";
    foreach (($cur[$s][0] ?? []) as $k => $v) {
        $was = $prev[$s][0][$k] ?? '—';
        echo "| $k | {$fmt($was)} | {$fmt($v)} | {$delta($was, $v)} |\n";
    }
}

foreach (['Страницы по шаблону пути (числа в пути → *, зеркала /home/ и /work/ свёрнуты)' => 'page_pattern', 'Страницы по суммарному времени' => 'SCRIPT_NAME', 'Компоненты по суммарному времени (время включает вложенные)' => 'COMPONENT_NAME'] as $s => $key) {
    $old = array_column($prev[$s] ?? [], null, $key);
    $rows = array_slice($cur[$s] ?? [], 0, $top);
    if ($rows === []) {
        continue;
    }
    $cols = array_values(array_diff(array_keys($rows[0]), [$key, 'cache_param']));
    echo "\n## $s (текущий топ-$top; в скобках — было)\n\n| $key | " . implode(' | ', $cols) . " |\n|" . str_repeat('---|', count($cols) + 1) . "\n";
    foreach ($rows as $r) {
        $o = $old[$r[$key]] ?? null;
        echo '| ' . $r[$key] . ($o === null ? ' **(новое)**' : '') . ' | '
            . implode(' | ', array_map(static fn($c) => $fmt($r[$c]) . ($o !== null && ($o[$c] ?? null) !== $r[$c] ? ' (' . $fmt($o[$c] ?? '—') . ')' : ''), $cols)) . " |\n";
    }
}
