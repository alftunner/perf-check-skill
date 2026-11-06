<?php

declare(strict_types=1);

/**
 * Комментарий в тикет YouTrack (итог в bxkor-тикет проверки или дополнение к открытому тикету): проверка текста на данные клиентов,
 * затем JS для browser_run_code_unsafe. Отправлять только по явной команде пользователя.
 *   php .claude/skills/perf-check/scripts/yt/comment.php <issue-id> <comment.md> <out.js>
 * JS не отправит дубль: если среди комментариев уже есть текст с тем же началом — вернёт already.
 */

require __DIR__ . '/../lib/mask.php';

[$id, $src, $out] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if (!preg_match('~^[a-z]+-\d+$~i', $id) || !is_file($src) || $out === '') {
    fwrite(STDERR, "Запуск: comment.php <issue-id> <comment.md> <out.js>\n");
    exit(2);
}
$text = trim((string) file_get_contents($src));
$found = PerfMask::find($text);
if ($found !== []) {
    foreach ($found as $f) {
        echo "данные клиентов [{$f['kind']}] — " . PerfMask::context($text, $f, 40) . "\n";
    }
    echo "\nJS не сгенерирован — исправьте текст и запустите снова.\n";
    exit(1);
}
$js = <<<'JS'
// ТОЛЬКО ПО ЯВНОЙ КОМАНДЕ ПОЛЬЗОВАТЕЛЯ: комментарий в __ID__ через REST (Markdown).
async (page) => {
  const ID = '__ID__';
  const TEXT = __TEXT__;
  const capture = async () => {
    const req = page.waitForRequest(r => r.url().includes('youtrack.example.com/api/') && !!r.headers()['authorization'], { timeout: 30000 });
    await page.reload();
    const auth = (await req).headers()['authorization'];
    await page.waitForTimeout(3000);
    return auth;
  };
  let auth = await page.evaluate(() => window.__ytAuth || null);
  if (!auth) auth = await capture();
  const run = (auth) => page.evaluate(async ({ auth, ID, TEXT }) => {
    const hdr = { Authorization: auth, Accept: 'application/json', 'Content-Type': 'application/json' };
    const cur = await fetch('/api/issues/' + ID + '?fields=comments(text,deleted)', { headers: hdr });
    if (cur.status === 401) return { auth401: true };
    const head = TEXT.slice(0, 80);
    if (((await cur.json()).comments || []).some(c => !c.deleted && (c.text || '').startsWith(head))) return { already: true };
    const post = await fetch('/api/issues/' + ID + '/comments?fields=id,text,usesMarkdown', { method: 'POST', headers: hdr, body: JSON.stringify({ text: TEXT, usesMarkdown: true }) });
    const c = post.ok ? await post.json() : null;
    return { posted: post.ok, status: post.status, id: c && c.id, len: c && (c.text || '').length };
  }, { auth, ID, TEXT });
  let res = await run(auth);
  if (res && res.auth401) { auth = await capture(); res = await run(auth); }
  return JSON.stringify(res);
}
JS;
file_put_contents($out, str_replace(['__ID__', '__TEXT__'], [$id, json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)], $js));
echo "OK: $out (проверка данных клиентов пройдена; отправлять только по команде пользователя)\n";
