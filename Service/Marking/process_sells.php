<?php
/**
 * Воркер marking_sell_queue (SPEC §2.1, roadmap шаг 6).
 *
 * CLI: php Service/Marking/process_sells.php [--limit=10]
 * Cron: каждые 30 минут + при старте смены кассы (в crontab: интервал 30 минут).
 *
 * COMMENTS-9 Fix #11: захват строк — атомарный (MarkingSellQueue::claimNextPending).
 * COMMENTS-11 #1: lease semantics — heartbeat_at = NOW + 15 мин; extendLease() перед
 * долгими вызовами ЛМ ЧЗ; «зомби» (истёкший lease) reclaim'ятся автоматически.
 * COMMENTS-11 #3: идемпотентный retry — перед /cis/sell при attempts > 1 сверка /cis/sell
 * (логика — в SellWorker, тестируется отдельно).
 *
 * Логика строки (SellWorker::processRow):
 *  1. Атомарно снять pending-запись (attempts < 3; claim: attempts+1, lease = NOW+15 мин).
 *  2. ЛМ ЧЗ /outCheck → /cis/sell (подтверждение продажи; повторная отправка — только остаток).
 *  3. done — по одной; ошибка — retry (pending, БЕЗ +1 к attempts — claim делает это);
 *     attempts >= 3 → failed + алерт персонала (SPEC §2.1).
 *  4. Дубли (КИ уже продан) — WARN в историю + алерт (SPEC §2.4 «duplicate»).
 */

require __DIR__ . '/autoload_marking.php';

use Models\MarkingSellQueue;
use Service\Marking\LmChzService;
use Service\Marking\MarkingLogger;
use Service\Marking\SellWorker;

$limit = 10;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

$lmchz = new LmChzService();
$queue = new MarkingSellQueue();
$worker = new SellWorker($lmchz, $queue);
$processed = 0;
$failed = 0;

// COMMENTS-9 Fix #11: атомарный claim вместо SELECT pending + UPDATE setStatus.
$claimed = [];
while ($processed + $failed < $limit) {
    $id = $queue->claimNextPending();
    if ($id === null) {
        break; // нет pending (или все с attempts >= max)
    }
    if (in_array($id, $claimed, true)) {
        // Строка вернулась в pending после retry — не переснимаем в том же run
        // (backoff ждёт следующего cron). Отпускаем claim и завершаем прогон.
        MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_PENDING);
        break;
    }
    $claimed[] = $id;

    $row = $queue->findById($id);
    if ($row === null) {
        break; // строка исчезла (гонка с другим воркером) — выходим, не зацикливаемся
    }

    $result = $worker->processRow($row);
    if ($result === SellWorker::RESULT_DONE) {
        $processed++;
    } else {
        $failed++;
    }
}

MarkingLogger::info('worker_run_finished', ['processed' => $processed, 'failed' => $failed, 'limit' => $limit]);

exit($failed > 0 ? 1 : 0);