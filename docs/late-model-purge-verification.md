# Late model callbacks after classroom deletion

An ended classroom can still have a Qwen peer draining its final response. Before this fix, a later subtitle or terminal callback could recreate content or outbox rows after `PurgeLesson` had removed them.

`Gateway` now checks the current classroom under its PostgreSQL row lock before writing subtitles, classroom state, issues or events. Any non-null `purge_status` blocks these writes, including `queued`, `processing`, `failed` and `purged`. State callbacks and finalization retain model session metadata and local sent-sample counts; official usage continues through its existing deduplicated ledger. They do not schedule another archive after purge. Preparing/switching rotation and stopping a classroom also check the current locked state.

`Recovery::interrupted()` locks and rereads the classroom instead of trusting a previously captured object. Startup recovery can close retained model metadata but cannot recreate content or classroom events for a purging classroom. Client issue reporting likewise rejects every non-null purge status. An ended classroom with no purge status still accepts its legitimate final source/translation results and proceeds to archive.

Run:

```powershell
& '../../work/runtime/php/php.exe' vendor/bin/phpunit tests/Feature/LateModelPurgeTest.php tests/Feature/GatewayPersistenceTest.php tests/Feature/SettingsRecoveryTest.php --log-junit reports/phpunit-late-model-purge.xml
```

Result: **25 tests / 169 assertions passed** on PHP 8.5.11, PHPUnit 12.5.38 and actual local PostgreSQL. The seven added cases cover all four purge states, updates to an existing partial unit and a new translated unit, a stale recovery object, the actual `newPeer` state callback/finalizer, a real empty-object `PurgeLesson` execution, failed preparing-peer/startup recovery, retained deduplicated official-shaped usage and a normal ended-tail control.

Classification: **real PHP/PostgreSQL application execution with synthetic events and fake queue dispatch; no real Qwen or OSS calls**. The Qwen object is constructed with explicit unused loopback fixture configuration but never connected. Its synthetic already-sent sample count is set only as test setup; it is not evidence of provider audio delivery. Queue dispatch is asserted, not consumed as a real archive in this regression.

Evidence is preserved in `reports/phpunit-late-model-purge.xml`. The broader final suite is recorded separately by the project acceptance report.
