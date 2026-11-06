// Playwright MCP: browser_run_code_unsafe filename=.claude/skills/perf-check/scripts/yt/auth.js (во вкладке с youtrack.example.com).
// REST YouTrack с cookie отвечает 401 — фронт ходит с Bearer: перехватываем заголовок и вешаем помощники на window.
// Токен живёт ~30 мин; при ошибке «-> 401» запустить этот файл снова.
async (page) => {
  if (!page.url().includes('youtrack.example.com')) {
    await page.goto('https://youtrack.example.com/issues', { waitUntil: 'domcontentloaded' });
  }
  const req = page.waitForRequest(r => r.url().includes('youtrack.example.com/api/') && !!r.headers()['authorization'], { timeout: 30000 });
  await page.reload();
  const auth = (await req).headers()['authorization'];
  await page.waitForTimeout(3000);
  return await page.evaluate((auth) => {
    window.__ytAuth = auth;
    window.__yt = async (path, init = {}) => {
      const r = await fetch(path, { ...init, headers: { Authorization: window.__ytAuth, Accept: 'application/json', 'Content-Type': 'application/json' } });
      if (!r.ok) throw new Error(path.split('?')[0] + ' -> ' + r.status);
      return r.json();
    };
    const cf = (i, re) => (i.customFields || []).filter(f => re.test(f.name)).map(f => f.value ? (Array.isArray(f.value) ? f.value.map(v => v.name).join(',') : f.value.name) : '').join('|');
    // Поиск: простые запросы «project: mg <слова>» или фраза маркера в кавычках; or, скобки и * в кавычках дают 400.
    window.__ytSearch = async (queries, top = 30) => {
      const out = {};
      for (const q of queries) {
        try {
          const list = await window.__yt('/api/issues?query=' + encodeURIComponent(q) + '&fields=idReadable,summary,resolved,customFields(name,value(name))&$top=' + top);
          out[q] = list.map(i => [i.idReadable, cf(i, /^(State|Состояние)$/), cf(i, /^Сервис$/), i.summary]);
        } catch (e) {
          out[q] = 'ERROR ' + String(e).slice(0, 120);
        }
      }
      return out;
    };
    // Карточки известных тикетов: состояние, сервис, связи — для решения «новый тикет или комментарий».
    window.__ytIssues = async (ids) => {
      const out = {};
      for (const id of ids) {
        try {
          const i = await window.__yt('/api/issues/' + id + '?fields=idReadable,summary,resolved,customFields(name,value(name)),links(linkType(name),issues(idReadable)),tags(name)');
          out[id] = { summary: i.summary, state: cf(i, /^(State|Состояние)$/), service: cf(i, /^Сервис$/), resolved: !!i.resolved,
            links: [...new Set((i.links || []).flatMap(l => (l.issues || []).map(x => x.idReadable)))], tags: (i.tags || []).map(t => t.name) };
        } catch (e) {
          out[id] = 'ERROR ' + String(e).slice(0, 120);
        }
      }
      return out;
    };
    return auth ? 'ok: токен перехвачен, есть window.__yt, __ytSearch, __ytIssues' : 'нет токена';
  }, auth);
}
