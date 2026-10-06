# Этот отчёт — тот же противоречивый документ, что и предыдущий

**§1:**
> «2.6 — `MarkingSellPendingAck` (audit table) — **ПОЛНОСТЬЮ ЗАКРЫТО** ✅»

**Через 8 строк ниже в том же §1:**
> «Полная retry-интеграция через `pending()` — **следующий архитектурный шаг**.»

**§4 и §5 (дважды):**
> «Полная retry-интеграция audit | `pending()` вместо `/cis/sold`»

**«Итог»:**
> «**2.6 (Q10 FIFO-независимость) — ЗАКРЫТО ✅**»

Одно и то же. Седьмой раз. Отчёт **не** соответствует фактам — в DIAGNOSIS.md подтверждено, что audit-first **работает**, но Q10-теста **нет**, и именно поэтому Q10 нельзя называть закрытым.

**Арифметика в отчёте тоже не сходится:** в шапке 463, в §2 — 463, в §5 — 463, в «Итог» — 463. **Но** в §3 (файлы) указано 16 production, а перечислено 14. В commit message — «+20 tests», в шапке — «(было 426, +20)» = 446, а заявлено 463. Плюс §4 и §5 — дубликаты.

---

## Что реально нужно

**Один артефакт — `Q10FifoIndependenceTest.php`.** Я его обещал в Phase 2' §2.2. Это **вся** оставшаяся работа. Не отчёт, не документ — тест.

Он докажет FIFO-независимость на данных, а не на словах. После него — да, «2.6 ЗАКРЫТО» станет правдой.

---

## Что мне нужно

Один вывод **четырёх команд** — без него я напишу тест под воображаемый API (это уже было 3 раза):

```bash
grep -n "function when\|function callCount\|function calls" tests/Fake/FakeHttpClient.php
grep -n "public function assert\|public function run" tests/TestHarness.php | head -20
sed -n '1,60p' tests/Unit/SellWorkerTest.php
grep -n "resetCache\|MarkingConfig::set" tests/bootstrap.php tests/Unit/SellWorkerTest.php | head
```

**По этому выводу** — я дам `Q10FifoIndependenceTest.php`, готовый к запуску, без TODO.

---

## Если присылать не хотите

Тогда — **сразу пишу с 3 TODO** в местах, где не знаю сигнатур:

1. `TODO: replace with real FakeHttpClient::when() API` — как регистрируется мок.
2. `TODO: replace with real TestHarness::assert* method` — 5 мест с assertions.
3. `TODO: verify FakeHttpClient::callCount signature` — подсчёт вызовов `/cis/sold`.

Вы поправите 3 строки — запустите.

---

## Что НЕ делать

- Не коммитить `Wave-1 Final Summary Report` — он противоречив, коммит закрепит ложь в истории.
- Не начинать RaceSuite.
- Не писать новый план.

Скажите: **«пришлю вывод 4 команд»** или **«пиши с TODO»** — и через 20 минут у вас будет работающий Q10-тест.