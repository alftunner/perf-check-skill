<?php

declare(strict_types=1);

/**
 * Проверка текста перед отправкой наружу (YouTrack, артефакт, документ, сообщение): нет ли данных клиентов.
 *   php .claude/skills/perf-check/scripts/pii_check.php <файл|папка>... ["-" — stdin]
 * Код выхода 1 — есть находки. Сами значения не печатаются: только вид и контекст, где найденное и соседнее уже замаскировано.
 * Ложное срабатывание не обходится, а переписывается (число — с пробелами по разрядам, ID — словами, хост — «etalon»).
 */

require __DIR__ . '/lib/mask.php';

$args = array_slice($argv, 1);
if ($args === []) {
    fwrite(STDERR, "Укажите файлы или папки (\"-\" — stdin)\n");
    exit(2);
}
$sources = [];
foreach ($args as $a) {
    if ($a === '-') {
        $sources['stdin'] = (string) stream_get_contents(STDIN);
    } elseif (is_dir($a)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && preg_match('~\.(?:md|txt|json|js|html?|csv|ya?ml)$~i', $f->getFilename())) {
                $sources[$f->getPathname()] = null;
            }
        }
    } elseif (is_file($a)) {
        $sources[$a] = null;
    } else {
        fwrite(STDERR, "Нет файла: $a\n");
        exit(2);
    }
}
ksort($sources);

/** JSON — строки значений через пустую строку; JS с вшитым JSON — с раскрытыми \n, чтобы границы слов были настоящими. */
$normalize = static function (string $path, string $text): string {
    if (str_ends_with($path, '.json')) {
        $data = json_decode($text, true);
        if ($data !== null) {
            $strings = [];
            array_walk_recursive($data, static function ($v) use (&$strings): void {
                if (is_string($v)) {
                    $strings[] = $v;
                }
            });

            return implode("\n\n", $strings);
        }
    }
    if (str_ends_with($path, '.js')) {
        return str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $text);
    }

    return $text;
};

$total = 0;
foreach ($sources as $path => $content) {
    $text = PerfMask::scrub($normalize($path, $content ?? (string) file_get_contents($path)));
    foreach (PerfMask::find($text) as $f) {
        ++$total;
        $line = substr_count(substr($text, 0, $f['offset']), "\n") + 1;
        printf("%s:%d [%s] %s\n", $path, $line, $f['kind'], PerfMask::context($text, $f));
    }
}
if ($total > 0) {
    printf("\nНайдено: %d. Отправлять нельзя — убрать или обобщить, затем проверить снова.\n", $total);
    exit(1);
}
printf("OK: данных клиентов не найдено (%d %s)\n", count($sources), count($sources) === 1 ? 'источник' : 'источников');
