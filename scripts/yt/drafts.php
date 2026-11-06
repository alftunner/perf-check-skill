<?php

declare(strict_types=1);

/**
 * Черновики тикетов YouTrack из <срез>/tickets/tickets.json: сначала проверка каждого текста (данные клиентов, маркер, сервис,
 * связь с bxkor, обязательные разделы), затем fill_<ключ>.js и create_all.js рядом с tickets.json. При любой находке файлы не пишутся.
 *   php .claude/skills/perf-check/scripts/yt/drafts.php .claude/tasks/perf-YYYY-MM-DD/tickets/tickets.json
 * tickets.json: {"01": {"summary": "…", "service": "Корзина", "links": ["bxkor-281", "mg-…"], "description": "Markdown…", "file": "01-….md"}, …}
 */

require __DIR__ . '/../lib/mask.php';

const MARKER = '(из регулярной проверки Bitrix)';
const SECTIONS = ['## Данные среза', '## Что делать', '## Критерий приёмки'];

$path = $argv[1] ?? '';
$tickets = json_decode((string) @file_get_contents($path), true);
if (!is_array($tickets) || $tickets === []) {
    fwrite(STDERR, "Нет tickets.json или он пуст: $path\n");
    exit(2);
}
$problems = [];
$bxkor = [];
foreach ($tickets as $key => $t) {
    $text = ($t['summary'] ?? '') . "\n" . ($t['description'] ?? '');
    foreach (PerfMask::find($text) as $f) {
        $problems[] = "$key: данные клиентов [{$f['kind']}] — " . PerfMask::context($text, $f, 40);
    }
    if (!str_contains($t['summary'] ?? '', MARKER)) {
        $problems[] = "$key: в заголовке нет маркера " . MARKER;
    }
    if (trim($t['service'] ?? '') === '') {
        $problems[] = "$key: не указан сервис (по нему воркфлоу ставит тег команды)";
    }
    $own = array_values(array_filter($t['links'] ?? [], static fn($l) => preg_match('~^bxkor-\d+$~', (string) $l)));
    if (count($own) !== 1) {
        $problems[] = "$key: в links должен быть ровно один bxkor-тикет проверки";
    }
    $bxkor[] = $own[0] ?? '';
    foreach (SECTIONS as $s) {
        if (!str_contains($t['description'] ?? '', $s)) {
            $problems[] = "$key: в описании нет раздела «$s»";
        }
    }
}
if (count(array_unique($bxkor)) > 1) {
    $problems[] = 'тикеты ссылаются на разные bxkor: ' . implode(', ', array_unique($bxkor));
}
if ($problems !== []) {
    echo implode("\n", $problems), "\n\nЧерновики не сгенерированы — исправьте tickets.json и запустите снова.\n";
    exit(1);
}

$dir = dirname($path);
$fill = <<<'JS'
// Playwright MCP: открыть https://youtrack.example.com/newIssue?project=mg, подождать ~6 с, затем browser_run_code_unsafe filename=<этот файл>.
// Заполняет черновик (сохраняется сам, draftId в URL); «Создать» не нажимает.
async (page) => {
  const t = __TICKET__;
  if (!page.url().includes('/newIssue')) return JSON.stringify({ key: t.key, error: 'вкладка не на /newIssue' });
  const closeCommand = async () => {
    if (await page.locator('[data-test=yt-command-dialog]').count()) { await page.keyboard.press('Escape'); await page.waitForTimeout(300); }
  };
  await closeCommand();
  const summary = page.getByRole('textbox', { name: 'Заголовок' });
  await summary.click();
  await summary.fill(t.summary);
  await page.waitForTimeout(500);
  const svcBtn = page.getByRole('button', { name: /Нет: сервис/i });
  if (await svcBtn.count()) {
    await svcBtn.first().click();
    await page.waitForTimeout(900);
    await page.keyboard.type(t.service, { delay: 25 });
    await page.waitForTimeout(1300);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(1000);
  }
  await closeCommand();
  const md = page.getByRole('button', { name: 'Markdown', exact: true });
  if (await md.count()) { await md.first().click(); await page.waitForTimeout(600); }
  const editor = page.locator('.ProseMirror').first();
  await editor.click();
  await page.waitForTimeout(300);
  // paste дописывает к имеющемуся тексту — сначала очистить
  await page.keyboard.press('Meta+A'); await page.waitForTimeout(200); await page.keyboard.press('Backspace'); await page.waitForTimeout(400);
  await page.evaluate((text) => {
    const el = document.querySelector('.ProseMirror');
    el.focus();
    const dt = new DataTransfer(); dt.setData('text/plain', text);
    el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
  }, t.description);
  await page.waitForTimeout(2500);
  const linked = {};
  for (const id of t.links) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await closeCommand();
    const btn = page.getByRole('button', { name: /Связать задачи/i }).first();
    if (!(await btn.count())) { linked[id] = 'нет кнопки «Связать задачи»'; continue; }
    await btn.click();
    await page.waitForTimeout(800);
    const type = page.getByRole('button', { name: /^связана с$/i }).or(page.getByRole('menuitem', { name: /^связана с$/i })).or(page.getByText(/^связана с$/i)).first();
    if (!(await type.count())) { await page.keyboard.press('Escape'); linked[id] = 'нет типа «связана с»'; continue; }
    await type.click();
    await page.waitForTimeout(700);
    const tag = await page.evaluate(() => document.activeElement ? document.activeElement.tagName : '');
    if (tag !== 'INPUT' && tag !== 'TEXTAREA') { await page.keyboard.press('Escape'); linked[id] = 'поле задачи не в фокусе — связать вручную'; continue; }
    await page.keyboard.type(id, { delay: 30 });
    await page.waitForTimeout(1500);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(1500);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    linked[id] = 'отправлено';
  }
  await closeCommand();
  await page.evaluate(() => window.scrollTo(0, 0));
  const body = await page.evaluate(() => document.body.innerText);
  const block = (title, len) => { const i = body.indexOf(title); return i < 0 ? '' : body.slice(i, i + len); };
  const svc = ((await page.getByRole('cell', { name: 'Сервис', exact: true }).locator('xpath=following-sibling::td//button').first().textContent().catch(() => '')) || '').trim();
  return JSON.stringify({
    key: t.key,
    summaryOk: (await summary.inputValue()) === t.summary,
    service: svc,
    descLen: await editor.evaluate(e => e.textContent.length),
    expectedDescLen: t.description.length,
    linked,
    links: [...new Set(block('Связана с', 600).match(/\b(?:mg|bxkor)-\d+\b/g) || [])],
    similar: [...new Set(block('Похожие задачи', 1500).match(/\b[a-z]+-\d+\b/gi) || [])],
    path: location.pathname,
  }, null, 1);
}
JS;
foreach ($tickets as $key => $t) {
    $t['key'] = (string) $key;
    $js = str_replace('__TICKET__', json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $fill);
    file_put_contents("$dir/fill_$key.js", $js);
    echo "fill_$key.js\n";
}

$create = <<<'JS'
// ТОЛЬКО ПО ЯВНОЙ КОМАНДЕ ПОЛЬЗОВАТЕЛЯ: создаёт задачи из всех открытых черновиков, прошедших сверку (маркер, сервис, связь с __BXKOR__).
async (page) => {
  const out = [];
  for (const p of page.context().pages().filter(x => x.url().includes('draftId='))) {
    const draft = p.url().replace(/.*draftId=/, '');
    try {
      await p.bringToFront();
      await p.waitForTimeout(500);
      if (await p.locator('[data-test=yt-command-dialog]').count()) { await p.keyboard.press('Escape'); await p.waitForTimeout(300); }
      const summary = await p.getByRole('textbox', { name: 'Заголовок' }).inputValue();
      const svc = (await p.getByRole('cell', { name: 'Сервис', exact: true }).locator('xpath=following-sibling::td//button').first().textContent()).trim();
      const descLen = await p.locator('.ProseMirror').first().evaluate(e => e.textContent.length);
      const body = await p.evaluate(() => document.body.innerText);
      const i = body.indexOf('Связана с');
      const links = i >= 0 ? [...new Set(body.slice(i, i + 600).match(/\b(?:mg|bxkor)-\d+\b/g) || [])] : [];
      const ok = summary.includes('(из регулярной проверки Bitrix)') && !/Нет: сервис/i.test(svc) && descLen > 1000 && links.includes('__BXKOR__');
      if (!ok) { out.push({ draft, skipped: true, summary: summary.slice(0, 70), svc, descLen, links }); continue; }
      await p.getByRole('button', { name: 'Создать' }).click();
      await p.waitForURL(/\/issue\/mg-\d+/, { timeout: 30000 });
      await p.waitForTimeout(1500);
      out.push({ draft, id: p.url().match(/mg-\d+/)[0], summary: summary.slice(0, 70), svc, descLen, links });
    } catch (e) {
      out.push({ draft, error: String(e).slice(0, 300), path: new URL(p.url()).pathname });
    }
  }
  return JSON.stringify(out, null, 1);
}
JS;
file_put_contents("$dir/create_all.js", str_replace('__BXKOR__', $bxkor[0], $create));
echo "create_all.js (только по команде пользователя)\nOK: " . count($tickets) . " черновиков к заполнению, проверка данных клиентов пройдена\n";
