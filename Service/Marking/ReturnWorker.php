<?php

namespace Service\Marking;

use Models\MarkingReturnQueue;
use Models\MarkingSellPendingAck;

/**
 * Воркер marking_return_queue — обработка ОДНОЙ claim-нутой строки (SPEC §2.9).
 * Вынесен из process_returns.php в класс для тестирования (COMMENTS-12 2.1, симметрия SellWorker).
 *
 * COMMENTS-12 2.1: идемпотентный retry — при attempts > 1 (claim уже инкрементировал attempts,
 * COMMENTS-12 2.1/2.4) перед /cis/returned сверяемся с /cis/sold: КИ, КОТОРЫХ НЕТ в выгрузке
 * проданных, — возврат применён предыдущей попыткой (ответ ЛМ ЧЗ потерялся, но он обработал
 * запрос) — повторно НЕ отправляем. Ничего не числится проданным → done БЕЗ вызова /cis/returned.
 * Частично: отправляем только КИ, всё ещё числящиеся проданными (остаток).
 *
 * COMMENTS-11 #1: продление lease (extendLease) перед каждым долгим HTTP-вызовом.
 */
final class ReturnWorker
{
    public const RESULT_DONE   = 'done';
    public const RESULT_RETRY  = 'retry';
    public const RESULT_FAILED = 'failed';

    /**
     * COMMENTS-11 #3 + COMMENTS-12 2.8: компромисс — не бесконечная пагинация /cis/sold.
     * Кап 10000 КИ (100 страниц по 100); превышение — метрика marking_return_sold_cap_exceeded_total
     * + WARN-лог (КИ строки за пределами выгрузки отправятся повторно — ЛМ ЧЗ должен отклонить
     * повторный возврат как уже применённый, вариант A из COMMENTS-12 2.1).
     */
    private const SOLD_SCAN_CAP = 10000;

    public function __construct(
        private LmChzService $lmchz,
        private MarkingReturnQueue $queue,
        private ?MarkingSellPendingAck $pendingAck = null
    ) {
    }

    /**
     * Kill-switch для audit-пути retry.
     * Deferred: можно отключить через MARKING_USE_PENDING_ACK=0 без релиза.
     */
    private function usePendingAck(): bool
    {
        return (bool) \Service\Marking\MarkingConfig::get('audit.use_pending_ack', true);
    }

    /**
     * @param MarkingReturnQueue $row claim-нутая строка (status=processing, attempts — после claim)
     * @return string self::RESULT_*
     */
    public function processRow(MarkingReturnQueue $row): string
    {
        $id = (int) $row->id;
        $cisList = MarkingReturnQueue::toCisList($row);
        if ($cisList === []) {
            MarkingReturnQueue::setStatus($id, MarkingReturnQueue::STATUS_FAILED, 'пустой cis_list', true);
            MarkingLogger::error('worker_return_empty_cis_list', ['queue_id' => $id, 'order_id' => $row->order_id]);

            return self::RESULT_FAILED;
        }

        try {
            // COMMENTS-11 #1: lease продлевается перед каждым потенциально долгим вызовом.
            $this->queue->extendLease($id);

            $checkUuid = $row->check_uuid ?? 'unknown';
            $useAudit = $this->pendingAck !== null && $this->usePendingAck();

            // COMMENTS-16 §6: audit — регистрируем возврат до вызова ЛМ ЧЗ.
            if ($useAudit && $this->pendingAck !== null) {
                $this->pendingAck->record('return', $cisList, $row->check_uuid ?? 'unknown');
            }

            $toReturn = $cisList;

            // Retry-ветка: audit-first + /cis/sold fallback.
            if ((int) $row->attempts > 1) {
                if ($useAudit && $this->pendingAck !== null) {
                    // Audit-first: проверяем, какие КИ уже acked=1 (возврат уже подтверждён).
                    $pending = $this->pendingAck->pending('return', $checkUuid);

                    // Все подтверждены предыдущей попыткой → done без вызова returned.
                    if (empty($pending)) {
                        MarkingMetrics::inc('marking_return_already_acked_total');
                        MarkingReturnQueue::setStatus($id, MarkingReturnQueue::STATUS_DONE, 'Все КИ подтверждены audit-таблицей', true);
                        MarkingLogger::info('worker_return_already_acked', [
                            'queue_id' => $id,
                            'order_id' => $row->order_id,
                            'codes'    => count($cisList),
                        ]);

                        return self::RESULT_DONE;
                    }

                    // Есть неподтверждённые → сверяемся с /cis/sold только по ним.
                    // Для возврата: КИ, которых НЕТ в /cis/sold → возврат уже применён.
                    $stillSold = $this->soldIntersection($pending);
                    $alreadyReturned = array_values(array_diff($pending, $stillSold));

                    if (!empty($alreadyReturned)) {
                        $this->pendingAck->acknowledge('return', $alreadyReturned, $checkUuid);
                        MarkingMetrics::inc('marking_return_idempotent_skip_total', count($alreadyReturned));
                    }

                    // Остаток — те, что всё ещё в sold → нужен возврат.
                    $toReturn = $stillSold;

                    // Все найденные в /cis/sold → done без resend.
                    if (empty($toReturn)) {
                        MarkingReturnQueue::setStatus($id, MarkingReturnQueue::STATUS_DONE, 'Возврат подтверждён audit-таблицей + /cis/sold', true);
                        MarkingLogger::info('worker_return_audit_done', [
                            'queue_id' => $id,
                            'order_id' => $row->order_id,
                            'codes'    => count($cisList),
                        ]);

                        return self::RESULT_DONE;
                    }
                } else {
                    // Legacy-путь: полная сверка /cis/sold по всему списку.
                    $stillSold = $this->soldIntersection($cisList);
                    if ($stillSold === []) {
                        MarkingReturnQueue::setStatus($id, MarkingReturnQueue::STATUS_DONE, 'КИ не числятся проданными — возврат применён ЛМ ЧЗ ранее', true);
                        MarkingLogger::info('worker_return_already_applied', [
                            'queue_id' => $id,
                            'order_id' => $row->order_id,
                            'codes'    => count($cisList),
                        ]);
                        MarkingMetrics::inc('marking_return_already_applied_total');

                        return self::RESULT_DONE;
                    }

                    $toReturn = array_values(array_intersect($cisList, $stillSold));
                    if (count($toReturn) < count($cisList)) {
                        MarkingMetrics::inc('marking_return_idempotent_skip_total', count($cisList) - count($toReturn));
                        MarkingLogger::warn('worker_return_partial_skip', [
                            'queue_id' => $id,
                            'order_id' => $row->order_id,
                            'skipped'  => count($cisList) - count($toReturn),
                        ]);
                    }
                }
            }

            $this->queue->extendLease($id);
            $this->lmchz->returned($toReturn);

            // COMMENTS-16 §6: audit — подтверждаем успешный возврат в audit-таблице.
            if ($useAudit && $this->pendingAck !== null) {
                $this->pendingAck->acknowledge('return', $toReturn, $checkUuid);
            }

            MarkingReturnQueue::setStatus($id, MarkingReturnQueue::STATUS_DONE, null, true);
            MarkingLogger::info('worker_return_done', [
                'queue_id' => $id,
                'order_id' => $row->order_id,
                'codes'    => count($toReturn),
            ]);

            return self::RESULT_DONE;
        } catch (\Throwable $e) {
            // COMMENTS-12 2.1: attempts = число claim'ов (claim уже +1) → retry-путь НЕ инкрементирует.
            if ((int) $row->attempts >= MarkingReturnQueue::maxAttempts()) {
                MarkingReturnQueue::setStatus($id, MarkingReturnQueue::STATUS_FAILED, mb_substr($e->getMessage(), 0, 250), true);
                MarkingLogger::error('worker_return_failed_after_retries', [
                    'queue_id' => $id,
                    'order_id' => $row->order_id,
                    'attempts' => (int) $row->attempts,
                    'error'    => $e->getMessage(),
                ]);

                return self::RESULT_FAILED;
            }

            MarkingReturnQueue::setStatus($id, MarkingReturnQueue::STATUS_PENDING, mb_substr($e->getMessage(), 0, 250));
            MarkingLogger::warn('worker_return_retry', [
                'queue_id' => $id,
                'attempts' => (int) $row->attempts,
                'error'    => $e->getMessage(),
            ]);

            return self::RESULT_RETRY;
        }
    }

    /**
     * COMMENTS-12 2.1: пересечение КИ строки с выгрузкой /cis/sold (пагинация по 100,
     * кап SOLD_SCAN_CAP). Возвращает КИ, ВСЁ ЕЩЕ числящиеся проданными (их нужно вернуть);
     * КИ строки, которых НЕТ в выгрузке, — возврат к ним уже применён.
     *
     * COMMENTS-13 3.1: пагинация — С КОНЦА (самые свежие проданные). /cis/sold?skip=0
     * отдаёт САМЫЕ СТАРЫЕ; при total > капа отчётный КИ (свежая продажа) в первых
     * 10000 записей не находится → ложное «возврат применён»/дубль. total известен
     * только из ответа (отдельного count-метода нет) — первый запрос (страница 0)
     * берёт total; при total > кап сканируем только последние SOLD_SCAN_CAP записей
     * (skip от total - cap). КИ, проданные РАНЬШЕ последних 10000, проверке не
     * подлежат — метрика cap-exceeded (как и раньше, COMMENTS-12 2.8).
     */
    private function soldIntersection(array $cisList): array
    {
        $found = [];
        $remaining = array_flip($cisList);
        $maxPages = intdiv(self::SOLD_SCAN_CAP, 100);

        // total — из первой страницы (самого старого конца; сам по себе он нам НЕ нужен
        // при total > кап — нужна только цифра total для расчёта точки старта).
        $first = $this->lmchz->sold(0, 100);
        $total = (int) ($first['total'] ?? 0);
        $start = max(0, $total - self::SOLD_SCAN_CAP);

        if ($start === 0) {
            // Выгрузка целиком в капе — страница 0 уже загружена, обрабатываем её.
            foreach (array_values((array) ($first['codes'] ?? [])) as $code) {
                if (isset($remaining[$code])) {
                    $found[] = $code;
                    unset($remaining[$code]);
                }
            }
        }

        $skip = $start === 0 ? 100 : $start;
        $pages = 0;
        while ($remaining !== [] && $skip < $total && $pages < $maxPages) {
            $res = $this->lmchz->sold($skip, 100);
            $batch = array_values((array) ($res['codes'] ?? []));
            foreach ($batch as $code) {
                if (isset($remaining[$code])) {
                    $found[] = $code;
                    unset($remaining[$code]);
                }
            }
            $total = max($total, (int) ($res['total'] ?? 0)); // total может уточниться
            $skip += 100; // шаг по skip (НЕ по числу кодов) — пустые страницы не зацикливают
            $pages++;
        }
        // «Всё проверено» — только если выгрузка целиком в капе (start=0) и долистали
        // до конца. При start>0 старые (total - cap) записи проверке НЕ подлежат —
        // при остатке remaining метрика cap-exceeded обязательна.
        $complete = ($start === 0) && ($skip >= $total);

        if (!$complete && $remaining !== []) {
            // COMMENTS-12 2.8: кап выгрузки исчерпан — часть КИ строки не проверена
            // (проданы раньше последних SOLD_SCAN_CAP) — будут отправлены повторно.
            MarkingMetrics::inc('marking_return_sold_cap_exceeded_total', count($remaining));
            MarkingLogger::warn('return_sold_scan_cap_exceeded', [
                'codes_unchecked' => count($remaining),
                'scanned'         => min($total, $start + $pages * 100),
            ]);
        }

        return $found;
    }
}