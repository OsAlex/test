<?php
/**
 * Воркер marking_return_queue (SPEC §2.9/§3.4).
 * Перепроводит возвраты (/cis/returned), не выполненные синхронно в CheckService::refundCheck().
 * Отдельная трасса: падение return-воркера не блокирует продажи.
 *
 * COMMENTS-9 Fix #11: атомарный claim (MarkingReturnQueue::claimNextPending).
 * COMMENTS-9 Fix #10 (A): sell-очередь ВОЗВРАТОМ НЕ ТРОГАЕТСЯ —
 * marking_return_queue — единственный источник истины по возвратам.
 * COMMENTS-11 #1: lease semantics — heartbeat_at = NOW + lease; extendLease() перед
 * долгими вызовами ЛМ ЧЗ; «зомби» (истёкший lease) reclaim'ятся автоматически.
 * COMMENTS-12 2.1: идемпотентный retry — при attempts > 1 перед /cis/returned сверка /cis/sold
 * (логика — в ReturnWorker, тестируется отдельно); attempts — число claim'ов.
 *
 * Логика строки (ReturnWorker::processRow):
 *  1. Атомарно снять pending-запись (attempts < max; claim: attempts+1, lease).
 *  2. ЛМ ЧЗ /cis/returned (повторная отправка — только остаток, см. 2.1).
 *  3. done — по одной; ошибка — retry (pending, БЕЗ +1 к attempts — claim делает это);
 *     attempts >= max → failed + алерт персонала.
 *
 * CLI: php Service/Marking/process_returns.php [--limit=10]
 * Cron: каждые 15 минут.
 */

require __DIR__ . '/autoload_marking.php';

use Models\MarkingReturnQueue;
use Service\Marking\LmChzService;
use Service\Marking\MarkingLogger;
use Service\Marking\ReturnWorker;

$limit = 10;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

$lmchz = new LmChzService();
$queue = new MarkingReturnQueue();
$worker = new ReturnWorker($lmchz, $queue);
$processed = 0;
$failed = 0;

// COMMENTS-9 Fix #11: атомарный claim вместо SELECT pending + UPDATE markDone.
$claimed = [];
while ($processed + $failed < $limit) {
    $id = $queue->claimNextPending();
    if ($id === null) {
        break; // нет pending (или все с attempts >= max)
    }
    if (in_array($id, $claimed, true)) {
        // Строка вернулась в pending после retry — не переснимаем в том же run
        // (backoff ждёт следующего cron). Отпускаем claim и завершаем прогон.
        MarkingReturnQueue::setStatus($id, MarkingReturnQueue::STATUS_PENDING);
        break;
    }
    $claimed[] = $id;

    $row = $queue->findById($id);
    if ($row === null) {
        break; // строка исчезла (гонка с другим воркером) — выходим, не зацикливаемся
    }

    $result = $worker->processRow($row);
    if ($result === ReturnWorker::RESULT_DONE) {
        $processed++;
    } else {
        $failed++;
    }
}

MarkingLogger::info('return_worker_run_finished', ['processed' => $processed, 'failed' => $failed, 'limit' => $limit]);

exit($failed > 0 ? 1 : 0);