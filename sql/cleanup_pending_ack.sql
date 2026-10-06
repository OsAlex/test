-- cleanup_pending_ack.sql — ротация audit-таблицы marking_sell_pending_ack (COMMENTS-17, вариант B).
-- Эквивалент MarkingSellPendingAck::cleanupOlderThan(30): удаляет ПОДТВЕРЖДЁННЫЕ (acked_at)
-- записи старше 30 суток. Неподтверждённые (acked_at IS NULL) НЕ удаляются — они нужны
-- retry-логике SellWorker/ReturnWorker как источник pending() (Q10, FIFO-независимость).
-- Идемпотентен: повторный запуск безопасен.
-- Cron (SPEC §9): 30 3 * * * mysql veira-souz < sql/cleanup_pending_ack.sql

DELETE FROM `marking_sell_pending_ack`
 WHERE `acked_at` IS NOT NULL
   AND `acked_at` < (NOW() - INTERVAL 30 DAY);
