# DEPLOY.md — Операционный деплой-чеклист (Sprint 01, волна 2.4+)

> Источник: `WORK_PLAN_WAVE2.md` T12 («SPEC §9 + новый DEPLOY.md»), `AUDIT.md` §5 п.4.
> Содержательная часть — `SPEC.md` §9 (DDL, cron-строки, мониторинг). Этот файл —
> операционная выжимка для DevOps: порядок действий при первом деплое и при откате.
> Все пути указаны относительно корня приложения (`/path/to/app`).

---

## 1. Предварительные требования

| Требование | Версия / комментарий |
|---|---|
| PHP | 8.1+ (plain PHP, без фреймворка; composer.json — только autoload) |
| MySQL/MariaDB | 5.7+ / 10.3+ (JSON-колонки, `INSERT ... ON DUPLICATE KEY UPDATE`) |
| Расширения PHP | `pdo_mysql`, `curl`, `json` |
| Доступ к ГИС МТ ЛМ ЧЗ | API-хост + токен (прод) или sandbox-токен (тесты) — **внешний блокер Q4a** |
| Cron-демон | доступен пользователю, от которого работает приложение |

## 2. Конфигурация (env-переменные)

Задаются в `/etc/environment` или в обёртке crontab-строки (см. SPEC §6, config `marking.php`):

| Переменная | Значение по умолчанию | Назначение |
|---|---|---|
| `MARKING_USE_PENDING_ACK` | `1` | Kill-switch retry-цепочки pending-ack (волна 2.6). `0` → legacy-fallback в SellWorker/ReturnWorker |
| `MARKING_LEASE_MINUTES` | `15` | Duration lease очереди (heartbeat_at = NOW + lease) |
| `MARKING_MAX_ATTEMPTS` | `5` | Максимум попыток перед `failed` |
| `MARKING_DEADLOCK_MAX` | `5` | Повторов deadlock-retry с exponential backoff + jitter |

Секреты (токен ЧЗ, БД) — только из env/config, **не** из репозитория.

## 3. Миграции БД (шаг 1 деплоя)

```bash
mysql veira-souz < /path/to/app/sql/marking_tables.sql
```

Создаёт/дополняет идемпотентно (`CREATE TABLE IF NOT EXISTS`):

1. `marking_sell_queue` (+ колонка `heartbeat_at` — для существующих БД см. ALTER-комментарий в файле)
2. `marking_check_history`
3. `marking_return_queue`
4. `marking_sell_pending_ack` (аудит-таблица волны 2.6)
5. `marking_emergency_state` (+ `last_seen_at`, backfill legacy-zombie SQL в конце файла)
6. `marking_cdn_host_state`
7. `marking_token_audit`

**Проверка:** `SHOW TABLES LIKE 'marking_%';` → 7 таблиц; `SELECT COUNT(*) FROM marking_sell_queue WHERE status='processing' AND heartbeat_at < NOW();` → 0 после backfill.

## 4. Деплой кода (шаг 2)

```bash
git fetch && git checkout <release-tag>
composer dump-autoload -o          # Service/Marking/autoload_marking.php как fallback
php -l Service/Marking/SellWorker.php   # smoke: синтаксис ключевых файлов
```

Порядок публикации: сначала миграции (§3), затем код — таблицы должны существовать до первого запуска воркеров.

## 5. Cron (шаг 3) — из SPEC §9

Установить в crontab пользователя приложения (`crontab -e`). **Обязательно** включить мониторинг ошибок (COMMENTS-15 §6): либо `MAILTO`, либо systemd-timer с `OnFailure=`.

```cron
MAILTO=ops@company.com

# Почасовой авто-expire аварийного режима (flock, try/catch, exit 1 при ошибке)
0 * * * * /usr/bin/php /path/to/app/cron/expire_emergency.php >> /var/log/marking-cron.log 2>&1

# Ежедневная очистка подтверждённых записей audit-таблицы (>30 дней, idempotent DELETE)
30 3 * * * mysql veira-souz < /path/to/app/sql/cleanup_pending_ack.sql
```

Альтернатива на systemd: `marking-expire-emergency.timer` + unit с `OnFailure=alert@company.service`.

**Ручная проверка перед включением cron:**

```bash
/usr/bin/php /path/to/app/cron/expire_emergency.php; echo "exit=$?"
# ожидание: exit=0, в логе MarkingLogger::info('expire_emergency_cron_ok')
```

## 6. Health-endpoint (шаг 4)

`GET /health/marking.php` → JSON со списком активных аварий (`inn`, `minutes_since_last_203`, `started_at`) на основе `MarkingEmergencyState::getActiveWithLastSeen()` (реализовано в этом коммите; SPEC §9 «Мониторинг»). Мониторинг-система должна опрашивать endpoint каждые 5 мин и алертить при HTTP ≠ 200.

## 7. Smoke-тест после деплоя (шаг 5)

1. `php tests/run_all.php --filter=RaceSuite` — все 15 сценариев зелёные (SQLite in-memory, сети не требует).
2. `process_sells.php` / `process_returns.php` на тестовом заказе → строка очереди `pending → done`.
3. Эмуляция 203 (sandbox) → запись в `marking_emergency_state`, `last_seen_at` обновлён.
4. Health-endpoint отдаёт активную аварию.

## 8. Откат (rollback)

1. Код: `git checkout <previous-tag>` + `composer dump-autoload -o`.
2. Pending-ack: при проблемах в retry-цепочке — `MARKING_USE_PENDING_ACK=0` (legacy-fallback), без отката схемы.
3. Таблицы **не удалять** — DDL обратимых миграций не требуется (колонки nullable, таблицы additive).
4. Выключить cron-строки (§5) на время разбора инцидента.

## 9. Известные внешние зависимости (не блокируют деплой mock-прогонов)

- Sandbox-токен ЛМ ЧЗ (Q4a) — нужен для шагов §7.3–7.4 на реальном контуре.
- JSON Атола (тег 1260, Q3), регионы/ЧЗ-ID (Q2a), норм-акт (Q1a), письменное утверждение SPEC — см. `AUDIT.md` §4 «Внешние блокеры».

---

*Файл создан по итогам ревизии бэклога `AUDIT.md` §5 (06.10.2026). Источники: SPEC §9, COMMENTS-15 §6, COMMENTS-17, `sql/marking_tables.sql`, `sql/cleanup_pending_ack.sql`.*
