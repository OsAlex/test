-- ============================================================================
-- Честный Знак (ЧЗ) — миграции БД, Sprint 01 (волна 2, COMMENTS-8/9)
-- SPEC.md §2.1, §2.2, §2.4, §2.9, §1.4 (marking_emergency_state),
-- таблица правок #2 (marking_cdn_host_state — COMMENTS-9 Fix #3)
-- БД: veira-souz (MySQL 5.7+/8.0)
-- Стек: plain PHP 8 (composer.json без фреймворков) — миграции исполняются вручную/через сервис,
--       НЕ через Laravel Migrations (roadmap #22 «Миграции БД (Laravel?)» — уточнено: Laravel НЕТ).
--
-- Портативность: временные колонки state-таблиц — INT (Unix): та же SQL работает
-- в MySQL (прод) и SQLite (тесты, tests/DbSmokeTest.php).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- SPEC §2.1: очередь офлайн-авторизации продаж ЛМ ЧЗ.
-- COMMENTS-9 Fix #10 (решение A): статус 'return' УБРАН из ENUM — sell-строка
-- не мутируется возвратом (факты продаж); возвраты — только marking_return_queue.
-- «skipped» (товар не маркирован) — запись НЕ создаётся, определяется через
-- skip_reason в marking_check_history (COMMENTS-7).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marking_sell_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `check_uuid` VARCHAR(36) NOT NULL,
    `cis_list` JSON NOT NULL,
    `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `error` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `heartbeat_at` TIMESTAMP NULL DEFAULT NULL,  -- COMMENTS-11 #1: MOMENT ИСТЕЧЕНИЯ LEASE (NOW+15 мин при claim/extend), не last-seen
    PRIMARY KEY (`id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_status` (`status`),
    KEY `idx_uuid` (`check_uuid`),
    KEY `idx_processing_stale` (`status`, `heartbeat_at`)  -- поиск «зомби» (lease истёк)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- COMMENTS-10 2.1 / COMMENTS-11 #1: MIGRATION для СУЩЕСТВУЮЩИХ БД:
-- ALTER TABLE `marking_sell_queue`
--     ADD COLUMN `heartbeat_at` TIMESTAMP NULL DEFAULT NULL,
--     ADD INDEX `idx_processing_stale` (`status`, `heartbeat_at`);
-- Переходный период: строки с heartbeat_at в ПРОШЛОМ (last-seen волны 2.1) или NULL —
-- «lease истёк» → reclaim на первом же запуске воркера (консервативно: пересогласование
-- /cis/sell через /cis/sold делает повторную продажу нетокичной, COMMENTS-11 #3).
-- Примечание: с волной 2.2 `attempts` = число claim'ов (инкремент в claim), max 3 обработок.

-- COMMENTS-9 Fix #10: для СУЩЕСТВУЮЩИХ БД (если статус 'return' уже был записан):
-- миграция 1: перенести факты возврата в marking_return_queue,
--            2: вернуть sell-записям status='done'.
-- (Новые БД: не требуется.)
-- INSERT INTO `marking_return_queue` (`order_id`, `check_uuid`, `cis_list`, `status`, `returned_at`, `reason`, `created_at`, `processed_at`)
-- SELECT `order_id`, `check_uuid`, `cis_list`, 'done', NOW(), 'refund_check_migrated', NOW(), NOW()
-- FROM `marking_sell_queue` WHERE `status` = 'return';
-- UPDATE `marking_sell_queue` SET `status` = 'done' WHERE `status` = 'return';

-- ----------------------------------------------------------------------------
-- SPEC §2.2: история проверок. item_id/gtin/group_id/parent_cis NULL allowed,
-- found/verified/sold/is_blocked NULL allowed (офлайн не возвращает found/verified).
-- COMMENTS-8 #12 / COMMENTS-9 Fix #12: source дополнен EMERGENCY (аварийный режим
-- ГИС МТ по ИНН, allowAll без обращений), skip_reason — advance_check_no_goods.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marking_check_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` VARCHAR(36) NOT NULL,
    `item_id` VARCHAR(36) NULL,
    `cis` VARCHAR(255) NULL,
    `code` VARCHAR(255) NOT NULL,
    `gtin` VARCHAR(14) NULL,
    `group_id` INT NULL,
    `parent_cis` VARCHAR(255) NULL,
    `found` TINYINT(1) NULL,
    `verified` TINYINT(1) NULL,
    `sold` TINYINT(1) NULL,
    `is_blocked` TINYINT(1) NULL,
    `prohibition` ENUM('ALLOW','WARN','BLOCK') NOT NULL,
    `source` ENUM('ONLINE','OFFLINE','HYBRID','EMERGENCY','EMERGENCY_TIMEOUT','SKIPPED') NOT NULL,
    `skip_reason` ENUM('not_marked','duplicate_pre_fiscal','cancelled_pre_fiscal','advance_check_no_goods') NULL,
    `cdn_host` VARCHAR(255) NULL,
    `error_code` INT NULL,
    `package_type` VARCHAR(20) NULL,
    `response_time_ms` INT NULL,
    `order_id` BIGINT UNSIGNED NULL,
    `check_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_request` (`request_id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- COMMENTS-9 Fix #13: строка-пропуск авансового чека пишется со skip_reason:
-- DbMarkingHistoryWriter для source=SKIPPED пишет одну строку (code = skip_reason).

-- ----------------------------------------------------------------------------
-- SPEC §2.9: очередь возвратов (отдельная трасса от sell; COMMENTS-9 Fix #10:
-- единственный источник истины по возвратам).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marking_return_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `check_uuid` VARCHAR(36) NOT NULL,
    `cis_list` JSON NOT NULL,
    `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,   -- COMMENTS-12 2.1: число claim'ов (обработок), инкрементит claim; retry не добавляет
    `returned_at` TIMESTAMP NULL DEFAULT NULL,
    `reason` VARCHAR(255) NULL,
    `error` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `heartbeat_at` TIMESTAMP NULL DEFAULT NULL,  -- COMMENTS-11 #1: MOMENT ИСТЕЧЕНИЯ LEASE (NOW+15 мин при claim/extend)
    PRIMARY KEY (`id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_status` (`status`),
    KEY `idx_processing_stale` (`status`, `heartbeat_at`)  -- поиск «зомби» (lease истёк)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- COMMENTS-10 2.1 / COMMENTS-11 #1: MIGRATION для СУЩЕСТВУЮЩИХ БД:
-- ALTER TABLE `marking_return_queue`
--     ADD COLUMN `heartbeat_at` TIMESTAMP NULL DEFAULT NULL,
--     ADD INDEX `idx_processing_stale` (`status`, `heartbeat_at`);
CREATE TABLE IF NOT EXISTS `marking_sell_pending_ack` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` ENUM('sell','return') NOT NULL DEFAULT 'sell',
  `cis` VARCHAR(255) NOT NULL,
  `check_uuid` VARCHAR(36) NOT NULL,
  `sent_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `acked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acked_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_kind_cis_check` (`kind`, `cis`, `check_uuid`),
  INDEX `idx_kind_cis` (`kind`, `cis`),
  INDEX `idx_check_uuid` (`check_uuid`, `acked`),
  INDEX `idx_acked` (`acked`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- COMMENTS-13 3.3: Legacy zombie backfill — идемпотентно (запускать при деплое):
-- переводит processing+NULL heartbeat в pending, чтобы reclaim подхватил.
UPDATE `marking_sell_queue`
   SET `status` = 'pending', `heartbeat_at` = NULL
 WHERE `status` = 'processing' AND `heartbeat_at` IS NULL;
UPDATE `marking_return_queue`
   SET `status` = 'pending', `heartbeat_at` = NULL
 WHERE `status` = 'processing' AND `heartbeat_at` IS NULL;

-- COMMENTS-12 2.1: attempts (идемпотентный retry /cis/returned — симметрия sell-очереди):
-- ALTER TABLE `marking_return_queue`
--     ADD COLUMN `attempts` INT UNSIGNED NOT NULL DEFAULT 0;
-- COMMENTS-13 3.3: LEGACY-ЗОМБИ (деплой волны 2.3). Зомби-строки со status='processing'
-- и heartbeat_at IS NULL (созданы до волны 2.2, у них нет lease) reclaim'ятся как pending
-- (NULL-lease учитывается в reclaim), НО у них attempts=0: первый повторный claim даст
-- attempts=1 → worker без сверки /cis/sold (проверка только при attempts > 1) → возможен
-- ДУБЛЬНЫЙ /cis/returned. Поэтому после ALTER attempts бэкапится в 1: первый retry после
-- reclaim (attempts=2) пройдёт через идемпотентную сверку /cis/sold.
-- Актуальные (не легаси) зомби имеют attempts >= 1 — CASE их не меняет.
-- Проверка перед деплоем: SELECT COUNT(*) FROM `marking_return_queue`
--                        WHERE status = 'processing' AND heartbeat_at IS NULL;
-- UPDATE `marking_return_queue`
--    SET `attempts` = CASE WHEN `attempts` < 1 THEN 1 ELSE `attempts` END
--  WHERE `status` = 'processing';
-- Аналогично — sell-очередь (зомби до волны 2.2 с attempts в семантике волны 2.1):
-- UPDATE `marking_sell_queue`
--    SET `attempts` = CASE WHEN `attempts` < 1 THEN 1 ELSE `attempts` END
--  WHERE `status` = 'processing';

-- ----------------------------------------------------------------------------
-- SPEC §1.4 (COMMENTS-9 Fix #3): аварийный режим ГИС МТ (HTTP 203) — ГЛОБАЛЬНОЕ
-- состояние по ИНН организации. НЕ блокировки хостов (это marking_cdn_host_state).
-- Если режим активен для ИНН — обращения к ГИС МТ НЕ выполняются (MarkingCheckService::check
-- возвращает allowAll, source = EMERGENCY).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marking_emergency_state` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `inn` VARCHAR(12) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `started_at` INT UNSIGNED NOT NULL,
    `expected_end_at` INT UNSIGNED NULL,
    `actual_end_at` INT UNSIGNED NULL,
    `reason` VARCHAR(255) NULL,
    `source_cassa_id` INT NULL,
    `updated_at` INT UNSIGNED NOT NULL,
    `last_seen_at` INT UNSIGNED NULL,  -- COMMENTS-13 2.2: когда последний раз видели 203 (started_at — только на новом периоде)
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_inn` (`inn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- COMMENTS-9 Fix #3: для СУЩЕСТВУЮЩИХ БД (таблица была создана под хост-блокировки,
-- таблица правок #2 из COMMENTS-6):
-- ALTER TABLE `marking_emergency_state`
--   DROP KEY `uniq_host`, DROP COLUMN `host`, DROP COLUMN `blocked_until`,
--   ADD COLUMN `inn` VARCHAR(12) NOT NULL FIRST,
--   ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
--   ADD COLUMN `expected_end_at` INT UNSIGNED NULL,
--   ADD COLUMN `actual_end_at` INT UNSIGNED NULL,
--   ADD COLUMN `source_cassa_id` INT NULL,
--   ADD COLUMN `updated_at` INT UNSIGNED NOT NULL,
--   ADD UNIQUE KEY `uniq_inn` (`inn`);
-- COMMENTS-13 2.2: last_seen_at (повторный 203 обновляет только её — started_at
-- сохраняется, иначе 7-суточный авто-expire никогда не наступил бы при периодическом 203):
-- ALTER TABLE `marking_emergency_state`
--   ADD COLUMN `last_seen_at` INT UNSIGNED NULL AFTER `updated_at`;

-- ----------------------------------------------------------------------------
-- COMMENTS-9 Fix #3/#4: circuit breaker по CDN-хостам (свойство ХОСТА, не ГИС МТ).
-- Пишется CdnService (DbCdnHostStateStore) при каждом сбое/успехе; блокировка
-- видна ВСЕМ FPM-воркерам (в отличие от in-memory per-process).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marking_cdn_host_state` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `host` VARCHAR(255) NOT NULL,
    `fail_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `blocked_until` INT UNSIGNED NULL,
    `last_fail_at` INT UNSIGNED NULL,
    `last_check_at` INT UNSIGNED NULL,
    `avg_time_ms` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_host` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- SPEC §1.3.1: аудит ротации X-API-KEY (old_hash, new_hash — только sha1, НЕ ключи).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marking_token_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `old_hash` CHAR(40) NULL,
    `new_hash` CHAR(40) NULL,
    `reason` VARCHAR(255) NULL,
    `actor` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- SPEC §2.4 (COMMENTS-9 Fix #12): поля маркированных товаров в заказе.
-- Источник: `cassa_ord_det` (Models\Order::getByOrderId → SELECT * FROM cassa_ord_det
-- WHERE cod_ord_id=...). Заполнение: сканер на кассе / импорт 1С / API (владелец —
-- открытый вопрос Q9).
-- ----------------------------------------------------------------------------
ALTER TABLE `cassa_ord_det`
    ADD COLUMN `cod_marking_cis` VARCHAR(2000) NULL COMMENT 'КИ через ; (≤50 шт., ≤2000 байт)',
    ADD COLUMN `cod_marking_package_type` ENUM('ITEM','UNIT','GROUP','BUNDLE','PRODUCT_SET') NULL COMMENT 'тип позиции: единица/агрегат',
    ADD COLUMN `cod_marking_scan_at` DATETIME NULL COMMENT 'момент сканирования КМ';

ALTER TABLE `cassa_ord_det`
    ADD KEY `idx_marking_cis` (`cod_marking_package_type`);

-- Семантика (SPEC §2.4):
--  - is_marked (признак 1С) ИЛИ package_type задан → товар подлежит маркировке;
--  - подлежит + cod_marking_cis пуст → BLOCK до отправки (MarkingBlockedException);
--  - не подлежит → позиция не маркируется (SKIPPED-логики в истории: skip_reason='not_marked').
