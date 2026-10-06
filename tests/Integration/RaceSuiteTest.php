<?php
declare(strict_types=1);

namespace Tests\Integration;

/**
 * RaceSuite — 15 сценариев гонок (COMMENTS-16, AUDIT §5 #1), АДАПТИРОВАН под
 * реальный API репозитория veira-souz (Вариант 1 из COMMENTS-16 «Два варианта»).
 *
 * Запуск: php tests/run_all.php --filter=RaceSuite   (из корня приложения)
 * Зависимости (есть только в app-репозитории): tests/TestHarness.php,
 *   tests/Fake/{SqliteDb,FakeHttpClient,FakeHttpParallelTransport,FakeEmergencyState}.php,
 *   Models/{MarkingSellQueue,MarkingReturnQueue,MarkingEmergencyState,MarkingSellPendingAck},
 *   Service/Marking/{LmChzService,SellWorker,ReturnWorker,MarkingConfig}.
 *
 * Реальные сигнатуры (проверено по коду docs-репозитория, окт. 2026):
 *   SellWorker::__construct(LmChzService $lmchz, MarkingSellQueue $queue,
 *                           ?MarkingSellPendingAck $pendingAck = null)
 *     — БЕЗ MarkingLogger/MarkingMetrics (в COMMENTS-16 были лишние аргументы);
 *     логирование — статическое MarkingLogger::*, конфигурация — MarkingConfig::get().
 *   LmChzService::__construct(HttpClient ...) + override хостов через env
 *     MARKING_LM_CHZ_HOST (config/marking.php «hosts.test.lm_chz») — fake-транспорт
 *     подаётся как FakeHttpClient (интерфейс HttpClient, CurlHttpClient — реализация).
 *   Kill-switch pending-ack: MarkingConfig 'audit.use_pending_ack'
 *     (env MARKING_USE_PENDING_ACK) — в suite переключается напрямую для веток 3/4/13.
 *
 * Маппинг 15 сценариев COMMENTS-16 → реальная поверхность API:
 *   1  lease extend перед долгим вызовом ......... processRow() + heartbeat_at (claimNextPending)
 *   2  stale processing → reclaim ................ MarkingSellQueue::reclaimStaleProcessing()
 *   3  retry /cis/sell идемпотентен .............. record→pending→soldIntersection(pending)→acknowledge
 *   4  retry /cis/returned идемпотентен .......... ReturnWorker — симметрия 3
 *   5  emergency 203 hybrid (/codes/check) ....... activate + source=EMERGENCY без merge
 *   6  emergency 203 online-only ................. activate через CdnService::onEmergency203()
 *   7  emergency 203 от /cdn/info ................ activate + парсинг хостов
 *   8  emergency 203 от /cdn/health/check ........ activate + circuit breaker не срабатывает
 *   9  auto-expire без подтверждения (>7 дн) ..... expireIfStale()/cron: is_active=0 + метрика
 *   10 auto-expire через 3 дня после actual_end_at expireIfStale(): expected vs actual
 *   11 cross-tenant __unknown__ не утекает ........ local-flag transientEmergency203 (нет глобальной записи)
 *   12 no-INN 203 не пишет глобальный sentinel .... то же, assert по marking_emergency_state
 *   13 /cis/sold в обратном порядке .............. FIFO-независимость pending-ack
 *   14 deadlock retry с backoff ............... .. attempts/MARKING_DEADLOCK_MAX, exponential+jitter
 *   15 heartbeat не сбрасывается при re-activate  started_at preserved, last_seen_at обновляется
 *
 * Статус: КАРКАС. Тело каждого теста помечено TODO c точной строкой проверки;
 * заполняется и прогоняется в app-репозитории (там есть TestHarness/Fake/*).
 * Ожидание по COMMENTS-16: падения = реальные баги, чинить одним коммитом.
 */
final class RaceSuiteTest
{
    private \PDO $pdo;
    private \Tests\TestHarness $t;

    public function run(\Tests\TestHarness $t): void
    {
        $this->t = $t;
        // Единственный поддерживаемый путь создания in-memory БД (wave-1 FINAL_REPORT §«DDL»):
        $this->pdo = \Tests\Fake\SqliteDb::inMemory();
        \Tests\Fake\SqliteDb::applyMarkingSchema($this->pdo); // DDL == sql/marking_tables.sql

        $scenarios = [
            'testLeaseExtendsBeforeLongCall',        // 1
            'testStaleProcessingReclaimed',          // 2
            'testSellRetryIdempotent',               // 3
            'testReturnRetryIdempotent',             // 4
            'testEmergency203HybridCodesCheck',      // 5
            'testEmergency203OnlineOnly',            // 6
            'testEmergency203CdnInfo',               // 7
            'testEmergency203CdnHealth',             // 8
            'testAutoExpireWithoutConfirmation',     // 9
            'testAutoExpireAfterConfirmation',       // 10
            'testCrossTenantEmergencyIsolation',     // 11
            'testNoInnDoesNotPersistGlobalSentinel', // 12
            'testSoldNonFifoOrdering',               // 13
            'testDeadlockBackoffExponential',        // 14
            'testActivatePreservesStartedAt',        // 15
        ];
        foreach ($scenarios as $m) {
            $this->$m();
        }
    }

    // ── Фабрики (реальные сигнатуры) ────────────────────────────────────────

    /** SellWorker: 3 аргумента, logger/metrics — статика/config (см. шапку). */
    private function sellWorker(\Tests\Fake\FakeHttpClient $http, object $queue, ?object $ack = null): \Service\Marking\SellWorker
    {
        return new \Service\Marking\SellWorker(
            new \Service\Marking\LmChzService($http), // точный порядок параметров — сверить с app
            $queue,
            $ack
        );
    }

    // ── 1–2: Lease ───────────────────────────────────────────────────────────

    private function testLeaseExtendsBeforeLongCall(): void
    {
        // enqueue → claimNextPending() (heartbeat_at = NOW+lease) → заморозить heartbeat
        // в прошлое → SellWorker::processRow($row) (реальный метод; НЕ process($id))
        // TODO(app): assertEquals('done', status) && heartbeat продлён (extendLease до /cis/sell).
        $this->t->markIncomplete('RACE-01 lease extend');
    }

    private function testStaleProcessingReclaimed(): void
    {
        // heartbeat_at < NOW → reclaimStale → status='pending', attempts+1.
        $this->t->markIncomplete('RACE-02 stale reclaim');
    }

    // ── 3–4: Идемпотентность retry (pending-ack, волна 2.6) ─────────────────

    private function testSellRetryIdempotent(): void
    {
        // MARKING_USE_PENDING_ACK=1; первый /cis/sell «потерян» (timeout) → record/pending;
        // второй проход: soldIntersection(pending) — попадание → acknowledge(), повторного POST нет.
        $this->t->markIncomplete('RACE-03 sell retry idempotent');
    }

    private function testReturnRetryIdempotent(): void
    {
        $this->t->markIncomplete('RACE-04 return retry idempotent');
    }

    // ── 5–8: Emergency 203 (централизация CdnService::onEmergency203, 2.2) ──

    private function testEmergency203HybridCodesCheck(): void
    {
        $this->t->markIncomplete('RACE-05 203 hybrid');
    }

    private function testEmergency203OnlineOnly(): void
    {
        $this->t->markIncomplete('RACE-06 203 online-only');
    }

    private function testEmergency203CdnInfo(): void
    {
        $this->t->markIncomplete('RACE-07 203 /cdn/info hosts parsing');
    }

    private function testEmergency203CdnHealth(): void
    {
        // 203 на /cdn/health/check → activate, но НЕ в marking_cdn_host_state (breaker молчит).
        $this->t->markIncomplete('RACE-08 203 health ≠ host-failure');
    }

    // ── 9–10: Auto-expire (cron/expire_emergency.php, волны 2.3/2.4) ────────

    private function testAutoExpireWithoutConfirmation(): void
    {
        // started_at > 7 суток, actual_end_at NULL → is_active=0 + marking_emergency_auto_expired_total.
        $this->t->markIncomplete('RACE-09 auto-expire 7d');
    }

    private function testAutoExpireAfterConfirmation(): void
    {
        // actual_end_at + 3 суток → expire.
        $this->t->markIncomplete('RACE-10 auto-expire 3d after actual');
    }

    // ── 11–12: Cross-tenant изоляция (__unknown__, волна 2.4) ───────────────

    private function testCrossTenantEmergencyIsolation(): void
    {
        // inn=__unknown__ → MarkingEmergencyState::$transientEmergency203 (per-process),
        // в marking_emergency_state строк нет.
        $this->t->markIncomplete('RACE-11 __unknown__ isolation');
    }

    private function testNoInnDoesNotPersistGlobalSentinel(): void
    {
        $this->t->markIncomplete('RACE-12 no-INN local flag');
    }

    // ── 13: FIFO-независимость ───────────────────────────────────────────────

    private function testSoldNonFifoOrdering(): void
    {
        // /cis/sold возвращает КИ в обратном относительно enqueue порядке →
        // все pending подтверждаются, ни одна продажа не потеряна/задвоена.
        $this->t->markIncomplete('RACE-13 non-FIFO ack');
    }

    // ── 14: Deadlock backoff ─────────────────────────────────────────────────

    private function testDeadlockBackoffExponential(): void
    {
        // 1213/1062 подряд ≤ MARKING_DEADLOCK_MAX (5), экспонента + jitter, затем failed.
        $this->t->markIncomplete('RACE-14 deadlock backoff');
    }

    // ── 15: Re-activate сохраняет started_at ─────────────────────────────────

    private function testActivatePreservesStartedAt(): void
    {
        // повторный 203: ON DUPLICATE KEY UPDATE трогает только last_seen_at/updated_at.
        $this->t->markIncomplete('RACE-15 started_at preserved');
    }
}
