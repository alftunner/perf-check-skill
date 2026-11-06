// Playwright MCP: browser_run_code_unsafe filename=.claude/skills/perf-check/scripts/yt/verify.js — сверка всех открытых черновиков.
async (page) => {
  const out = [];
  for (const p of page.context().pages().filter(x => x.url().includes('draftId='))) {
    const summary = await p.getByRole('textbox', { name: 'Заголовок' }).inputValue().catch(() => '');
    const svc = ((await p.getByRole('cell', { name: 'Сервис', exact: true }).locator('xpath=following-sibling::td//button').first().textContent().catch(() => '')) || '').trim();
    const descLen = await p.locator('.ProseMirror').first().evaluate(e => e.textContent.length).catch(() => 0);
    const body = await p.evaluate(() => document.body.innerText);
    const block = (title, len) => { const i = body.indexOf(title); return i < 0 ? '' : body.slice(i, i + len); };
    out.push({
      draft: p.url().replace(/.*draftId=/, ''),
      summary: summary.slice(0, 70),
      marker: summary.includes('(из регулярной проверки Bitrix)'),
      service: svc,
      descLen,
      links: [...new Set(block('Связана с', 600).match(/\b(?:mg|bxkor)-\d+\b/g) || [])],
      similar: [...new Set(block('Похожие задачи', 1500).match(/\b[a-z]+-\d+\b/gi) || [])],
    });
  }
  return JSON.stringify(out, null, 1);
}
