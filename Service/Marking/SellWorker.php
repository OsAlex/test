<?php

namespace Service\Marking;

use Models\MarkingSellPendingAck;
use Models\MarkingSellQueue;

/**
 * Воркер marking_sell_queue — обработка ОДНОЙ claim-нутой строки (SPEC §2.1).
 * Вынесен из process_sells.php в класс для тестирования (COMMENTS-11 #3).
 *
 * COMMENTS-11 #3: идемпотентный retry — при attempts > 1 (claim уже инкрементировал attempts,
 * COMMENTS-11 #1) перед /cis/sell сверяемся с /cis/sold: КИ, зарегистрированные в ЛМ ЧЗ
 * предыдущей попыткой (ответ KKT пришёл до сбоя), НЕ отправляем повторно. Всё уже продано →
 * done БЕЗ вызова sell. Выгрузка — пагинацией по 100 (лимит ЛМ ЧЗ) до total или caps-а 5000 КИ.
 *
 * COMMENTS-11 #1: продление lease (extendLease) перед каждым долгим HTTP-вызовом.
 */
final class SellWorker
{
    public const RESULT_DONE    = 'done';
    public const RESULT_RETRY   = 'retry';
    public const RESULT_FAILED  = 'failed';
    public const RESULT_BLOCKED = 'blocked';

    /**
     * COMMENTS-11 #3 + COMMENTS-12 2.8: компромисс — не бесконечная пагинация /cis/sold.
     * Кап 10000 КИ (100 страниц по 100); превышение — метрика marking_sell_sold_cap_exceeded_total
     * + WARN-лог (неизбежная повторная отправка — алерт оператора, SPEC §5).
     */
    private const SOLD_SCAN_CAP = 10000;

    public function __construct(
        private LmChzService $lmchz,
        private MarkingSellQueue $queue,
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
     * @param MarkingSellQueue $row claim-нутая строка (status=processing, attempts — после claim)
     * @return string self::RESULT_*
     */
    public function processRow(MarkingSellQueue $row): string
    {
        $id = (int) $row->id;
        $cisList = MarkingSellQueue::toCisList($row);
        if ($cisList === []) {
            MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_FAILED, 'пустой cis_list', true);
            MarkingLogger::error('worker_empty_cis_list', ['queue_id' => $id, 'order_id' => $row->order_id]);

            return self::RESULT_FAILED;
        }

        $checkUuid = $row->check_uuid ?? 'unknown';
        $useAudit = $this->pendingAck !== null && $this->usePendingAck();

        try {
            // COMMENTS-11 #1: lease продлевается перед каждым потенциально долгим вызовом.
            $this->queue->extendLease($id);

            // Офлайн-валидация перед продажей.
            $out = $this->lmchz->outCheck($cisList);

            if ($out['blocked'] !== []) {
                MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_FAILED, 'КИ под запретом в ЛМ ЧЗ', true);
                MarkingLogger::error('worker_blocked_code', [
                    'queue_id' => $id,
                    'order_id' => $row->order_id,
                    'codes'    => $out['blocked'],
                ]);
                MarkingMetrics::inc('marking_check_block_total');

                return self::RESULT_BLOCKED;
            }

            if ($out['sold'] !== []) {
                MarkingLogger::warn('worker_duplicate_codes', [
                    'queue_id' => $id,
                    'order_id' => $row->order_id,
                    'codes'    => $out['sold'],
                ]);
            }

            $toSell = array_values(array_diff($cisList, $out['sold']));

            $checkUuid = $row->check_uuid ?? 'unknown';
            $useAudit = $this->pendingAck !== null && $this->usePendingAck();

            // COMMENTS-16 §6: audit — регистрируем то, что реально отправляем (после out['sold']).
            if ($useAudit && $this->pendingAck !== null) {
                $this->pendingAck->record('sell', $toSell, $row->check_uuid ?? 'unknown');
            }

            // Retry-ветка: audit-first + /cis/sold fallback.
            if ((int) $row->attempts > 1 && $toSell !== []) {
                if ($useAudit && $this->pendingAck !== null) {
                    // Audit-first: проверяем, какие КИ уже acked=1.
                    $pending = $this->pendingAck->pending('sell', $checkUuid);

                    // Все подтверждены предыдущей попыткой → done без вызова sell.
                    if (empty($pending)) {
                        MarkingMetrics::inc('marking_sell_already_acked_total');
                        MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_DONE, 'Все КИ подтверждены audit-таблицей', true);
                        MarkingLogger::info('worker_sell_already_acked', [
                            'queue_id' => $id,
                            'order_id' => $row->order_id,
                            'codes'    => count($cisList),
                        ]);

                        return self::RESULT_DONE;
                    }

                    // Есть неподтверждённые → сверяемся с /cis/sold только по ним.
                    $stillSold = $this->soldIntersection($pending);
                    if (!empty($stillSold)) {
                        $this->pendingAck->acknowledge('sell', $stillSold, $checkUuid);
                        MarkingMetrics::inc('marking_sell_idempotent_skip_total', count($stillSold));
                    }

                    // Остаток — те, что всё ещё в sold → отправляем повторно.
                    $toSell = array_values(array_diff($pending, $stillSold));

                    // Все найденные в /cis/sold → done без resend.
                    if (empty($toSell)) {
                        MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_DONE, 'КИ зарегистрированы ЛМ ЧЗ ранее', true);
                        MarkingLogger::info('worker_sell_already_applied', [
                            'queue_id' => $id,
                            'order_id' => $row->order_id,
                            'codes'    => count($cisList),
                        ]);

                        return self::RESULT_DONE;
                    }
                } else {
                    // Legacy-путь: полная сверка /cis/sold по всему списку.
                    $registered = $this->soldIntersection($cisList);
                    if ($registered !== []) {
                        MarkingMetrics::inc('marking_sell_idempotent_skip_total', count($registered));
                    }
                    $toSell = array_values(array_diff($toSell, $registered));
                    if ($toSell === []) {
                        MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_DONE, 'КИ зарегистрированы ЛМ ЧЗ ранее', true);
                        MarkingLogger::info('worker_sell_already_applied', [
                            'queue_id' => $id,
                            'order_id' => $row->order_id,
                            'codes'    => count($cisList),
                        ]);

                        return self::RESULT_DONE;
                    }
                }
            }

            if ($toSell !== []) {
                $this->queue->extendLease($id);
                $this->lmchz->sell($toSell);
            }

            // COMMENTS-16 §6: audit — подтверждаем отправленные КИ (то, что реально ушло в /cis/sell).
            if ($useAudit && $this->pendingAck !== null) {
                $this->pendingAck->acknowledge('sell', $toSell, $checkUuid);
            }

            MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_DONE, null, true);
            MarkingLogger::info('worker_sell_done', [
                'queue_id' => $id,
                'order_id' => $row->order_id,
                'codes'    => count($toSell !== [] ? $toSell : $cisList),
            ]);

            return self::RESULT_DONE;
        } catch (\Throwable $e) {
            // COMMENTS-11 #1: attempts = число claim'ов (claim уже +1) → retry-путь НЕ инкрементирует.
            if ((int) $row->attempts >= MarkingSellQueue::maxAttempts()) {
                MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_FAILED, mb_substr($e->getMessage(), 0, 250), true);
                MarkingLogger::error('worker_failed_after_retries', [
                    'queue_id' => $id,
                    'order_id' => $row->order_id,
                    'attempts' => (int) $row->attempts,
                    'error'    => $e->getMessage(),
                ]);

                return self::RESULT_FAILED;
            }

            MarkingSellQueue::setStatus($id, MarkingSellQueue::STATUS_PENDING, mb_substr($e->getMessage(), 0, 250));
            MarkingLogger::warn('worker_retry', [
                'queue_id' => $id,
                'attempts' => (int) $row->attempts,
                'error'    => $e->getMessage(),
            ]);

            return self::RESULT_RETRY;
        }
    }

    /**
     * COMMENTS-11 #3 + COMMENTS-12 2.8 + COMMENTS-13 3.1: пересечение КИ строки с
     * выгрузкой /cis/sold — ПАГИНАЦИЯ С КОНЦА (самые свежие проданные; /cis/sold?skip=0
     * отдаёт самые старые, при total > капа свежий КИ в первых 10000 не находится).
     * total — из ответа первой страницы; при total > кап сканируем последние
     * SOLD_SCAN_CAP (skip от total - cap). КИ за пределами выгрузки — повторная отправка
     * /cis/sell (ЛМ ЧЗ отклонит как дубль) + метрика marking_sell_sold_cap_exceeded_total.
     */
    private function soldIntersection(array $cisList): array
    {
        $found = [];
        $remaining = array_flip($cisList);
        $maxPages = intdiv(self::SOLD_SCAN_CAP, 100);

        $first = $this->lmchz->sold(0, 100);
        $total = (int) ($first['total'] ?? 0);
        $start = max(0, $total - self::SOLD_SCAN_CAP);

        if ($start === 0) {
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
            $total = max($total, (int) ($res['total'] ?? 0));
            $skip += 100;
            $pages++;
        }
        $complete = ($start === 0) && ($skip >= $total);

        if (!$complete && $remaining !== []) {
            MarkingMetrics::inc('marking_sell_sold_cap_exceeded_total', count($remaining));
            MarkingLogger::warn('sell_sold_scan_cap_exceeded', [
                'codes_unchecked' => count($remaining),
                'scanned'         => min($total, $start + $pages * 100),
            ]);
        }

        return $found;
    }
}