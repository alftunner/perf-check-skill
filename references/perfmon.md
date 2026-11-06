# perfmon: таблицы, подвохи, рецепты

## Содержание
- Таблицы и что в них есть
- Подвохи данных
- Рецепты запросов для perf_sql.php
- Реестр известных тем и тикетов (снимок 05.11.2026)

## Таблицы и что в них есть

БД `app_test` на etalon (MySQL 5.7: нет оконных функций, CTE и `REGEXP_REPLACE`; включён `ONLY_FULL_GROUP_BY`).
Срез — всё содержимое таблиц: перед новым сбором коллега их очищает.

| Таблица | Строк на срез | Что внутри |
|---|---|---|
| `b_perf_hit` | ~40 тыс. | хит: `SCRIPT_NAME`, `REQUEST_URI`, `REQUEST_METHOD`, `SERVER_NAME`, `PAGE_TIME`, `QUERIES`/`QUERIES_TIME`, `COMPONENTS`/`COMPONENTS_TIME`, фазы `PROLOG_BEFORE_TIME`, `AGENTS_TIME`, `PROLOG_AFTER_TIME`, `WORK_AREA_TIME`, `EPILOG_*`, `MEMORY_PEAK_USAGE` |
| `b_perf_sql` | ~1 млн, ~1,2 ГБ | запрос: `HIT_ID`, `COMPONENT_ID`, `NN` (порядок в хите), `QUERY_TIME`, `SQL_TEXT`, `SELECTED_ROWS` |
| `b_perf_component` | ~0,5 млн | вызов компонента: `HIT_ID`, `NN`, `COMPONENT_NAME`, `COMPONENT_TIME`, `QUERIES`, `QUERIES_TIME`, `CACHE_TYPE` |
| `b_perf_history` | по строке на срез | оценка «Производительность конфигурации» `TOTAL_MARK` (больше — лучше): 01.09.2026 — 18,2; 01.10 — 22,92; 02.11 — 21,98 |

Пустые и в разбор не идут: `b_perf_error`, `b_perf_cache`, `b_perf_cache_hitrate`, `b_perf_sql_backtrace`, `b_perf_index_complete`.
`b_perf_index_suggest` — 26 автоподсказок по ядровым таблицам с ничтожным временем.

Данные клиентов в perfmon — только в `REQUEST_URI` и `SQL_TEXT` (литералы); остальные колонки технические.

## Подвохи данных

- `b_perf_sql.COMPONENT_NAME` почти всегда пуст — компонент берётся через `LEFT JOIN b_perf_component c ON c.ID = s.COMPONENT_ID`;
  пустой `COMPONENT_ID` — запрос вне компонентов (пролог, эпилог, ajax-скрипт).
- `COMPONENT_TIME` включает вложенные компоненты: суммы по компонентам больше страничного времени, сравнивать только внутри одного компонента.
- `CACHE_TYPE` — параметр компонента (A/Y/N/пусто), а не факт попадания в кэш. Промах виден по числу `QUERIES` в вызове:
  кэшированный вызов почти без SQL.
- `CACHE_COUNT_*` всегда 0 — сбор статистики кэша выключен.
- `SELECTED_ROWS` ненадёжен (0 у legacy-запросов); число строк проверять `perf_explain.php` (контрольный прогон).
- `b_perf_sql_backtrace` пуст (`sql_backtrace` = N): места вызова в срезе нет — искать в коде по таблице, ORM-алиасу и полям.
- `SQL_TEXT` бывает обрезан (длинные `INSERT`): EXPLAIN такого запроса падает; маска закрывает незакрытый литерал до конца строки.
- Фазы у ajax-скриптов без пролога шаблона — `NULL`.
- Зеркала публички `/home/…` и `/work/…` — те же страницы с другим шаблоном; `perf_overview.php` сворачивает их в «страницы по шаблону пути».
- Хиты ботов видны по URL (обход разделов с `SECTION=`, гайды) и по высокой доле гостей без города; UA в perfmon нет.

## Рецепты запросов для perf_sql.php

Запросы разделяются `;` и переводом строки. Вывод уже маскирован; `VERTICAL=1` — построчно без обрезки.

```sql
-- хиты страницы с фазами
SELECT ID, REQUEST_METHOD, REQUEST_URI, ROUND(PAGE_TIME,3) t, QUERIES q, ROUND(QUERIES_TIME,3) qt, ROUND(PROLOG_BEFORE_TIME,3) pb,
       ROUND(PROLOG_AFTER_TIME,3) pa, ROUND(WORK_AREA_TIME,3) wa, ROUND(MEMORY_PEAK_USAGE/1048576) mb
FROM b_perf_hit WHERE SCRIPT_NAME = '/personal/cart/index.php' ORDER BY PAGE_TIME DESC LIMIT 20;

-- конкретные выполнения шаблона (sql_id — для perf_explain.php)
SELECT s.ID sql_id, s.HIT_ID, ROUND(s.QUERY_TIME,3) qt, IFNULL(c.COMPONENT_NAME,'') comp, h.SCRIPT_NAME, LEFT(s.SQL_TEXT, 300) sql_text
FROM b_perf_sql s JOIN b_perf_hit h ON h.ID = s.HIT_ID LEFT JOIN b_perf_component c ON c.ID = s.COMPONENT_ID
WHERE s.SQL_TEXT LIKE '%hl_apiship_terminal%' ORDER BY s.QUERY_TIME DESC LIMIT 10;

-- компоненты внутри хита
SELECT NN, COMPONENT_NAME, ROUND(COMPONENT_TIME,3) t, QUERIES q, ROUND(QUERIES_TIME,3) qt, CACHE_TYPE
FROM b_perf_component WHERE HIT_ID = 300 ORDER BY NN;

-- кэш компонента работает? (вызовы без SQL против вызовов с SQL)
SELECT COMPONENT_NAME, SUM(QUERIES = 0) no_sql, SUM(QUERIES > 0) with_sql, ROUND(AVG(COMPONENT_TIME)*1000) avg_ms
FROM b_perf_component WHERE CACHE_TYPE IN ('A','Y') GROUP BY COMPONENT_NAME ORDER BY with_sql DESC LIMIT 20;

-- размер таблицы и индексы (метаданные — без маски)
SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024) mb FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hl_erp_partner_doc';
SHOW INDEX FROM hl_erp_partner_doc;

-- распределение по значениям в таблице с данными: коды и счётчики видны, остальное — псевдонимы
SELECT UF_STATUS, COUNT(*) cnt FROM hl_commercial_offers GROUP BY UF_STATUS ORDER BY cnt DESC;

-- настройки модуля (служебная таблица)
SELECT NAME, VALUE FROM b_option WHERE MODULE_ID = 'statistic';
```

## Реестр известных тем и тикетов (снимок 05.11.2026)

Перед заведением сверить состояние через `window.__ytIssues([...])` — реестр устаревает.

| Тема | Тикет | Сервис | Состояние |
|---|---|---|---|
| Коллекция ПВЗ (5Post) не помещается в memcached, грузится в прологе и в корзине | mg-180953 | Корзина | Open |
| `/personal/cart/` — самая медленная страница | mg-175335 | — | Open, симптом mg-180953 |
| Корзина и оформление: права на тип цены по 4 запроса на позицию, до 755–1 648 запросов на хит | mg-178664 | Заказ товаров | Open |
| Опрос `/ajax/desk/orders.php`, три поллера на вкладку | mg-180949 | Заказы (Сотрудникам ОКТ) | Open |
| Список КП в деске: нужен индекс `(UF_STATUS, UF_IDP, UF_DATE_CREATE)` | mg-180950 | Коммерческие предложения | Open |
| Модуль `statistic`: запись `b_stat_*` на каждом хите, читает только `samson.guard` | mg-180951 | Веб-аналитика | Open |
| Страница бренда: запрос на каждый раздел ради фото | mg-180952 | Каталог по брендам | Open |
| HL-таблицы без индексов (договоры, `hl_crm_account_generate`, `hl_erp_partner_doc`), N+1 договоров в `kor:client.list` | mg-180096 | Установка договоров… в публичной части сайта | Open |
| Баннеры: пул не кэшируется | mg-180098 | Баннерная реклама | Open |
| Сертификаты в карточке товара | mg-180099 | Карточка товара | Open |
| Главная: промахи кэша витрин `public:showcase` | mg-180101 | Главная страница | Open |
| `/desk/clients/routes/`: выборка всех результатов | mg-178670 | — | Open |
| Пагинация пользователей деска | mg-178668 | — | Closed 12.10.2026 (в срезе 02.11 ещё 3 хита по 1,6–1,9 с) |
| Каскад ElasticSearch в поиске | mg-180100 | Поиск товаров | Closed 06.10 «пока не нужно»; обновление ES — mg-176129 |
| Карта корзины | mg-178630 | — | Closed, релиз 06.10.2026 (после него корзина грузит всю коллекцию ПВЗ) |
| Поиск по артикулам, баннеры (кэш результата) | mg-176235, mg-176236 | — | Closed, выпущены |

Кандидаты без тикета (ноябрь 2026): `UF_DISCOUNT_OPTION` партнёра без мемоизации (~71 тыс. запросов за 10 минут,
`src/Env/Discount/Client/*Storage.php`); фильтр каталога `public:filter.catalog` (`COUNT(DISTINCT)` по фасетам); поиск — ждёт ES
(mg-176129); `/ajax/eat_cart_service.php` — таймаут внешнего вызова 60 с; перенос КП в заказ — 10,6 с вне БД; `/desk/promo/goods-indicators.php` — 5,1 с.
