# Final concurrency regressions

Classification: **real local PostgreSQL, Laravel database queue worker and cache locks; synthetic application data; no real Qwen or OSS calls**.

Run from the application directory:

```powershell
& '../../work/runtime/php/php.exe' vendor/bin/phpunit tests/Feature/FinalConcurrencyTest.php --log-junit reports/phpunit-final-concurrency.xml
```

Result: **4 tests, 58 assertions passed** on PHP 8.5.11 / PHPUnit 12.5.38.

| Regression | Actual execution and result |
| --- | --- |
| Expiry during a timed export with no usable timing | A database query listener invokes `ExportService::cleanup()` after the immutable export rows are selected. `ExportService::build()` then renders the real formatter. The task remains expired; its file reference and snapshot rows stay absent. |
| First recording start concurrent with model settings save | Two PHP processes against a dedicated PostgreSQL database invoke the actual Gateway start method and `Settings::save()`. Start holds the actor lock while the settings process waits. PostgreSQL reports an advisory lock wait, both processes complete, and the classroom retains its frozen model version while the active version changes. A detected optimistic version conflict is explicitly reported and retried with the current version. |
| Export waiting for the shared classroom mutex | The actual Laravel worker reserves and releases a database job six times while another owner holds the same cache lock. Attempts exceed the former three-attempt limit without a failed job. After releasing the mutex, the worker produces a downloadable VTT containing the synthetic source text. |
| Permanent classroom cleanup waiting for the same mutex | The actual worker reserves and releases the cleanup job six times, exceeding its former four-attempt limit without failure. Releasing the lock allows the empty synthetic classroom fixture to reach `purged`, with no residual events or queued job. No audio or remote objects exist in this fixture. |

The queue tests advance Laravel's test clock by 16 seconds between middleware releases; they do not wait six times in real time. They execute the actual worker, database queue serialization and reservation, retry handling, middleware lock and final job handler. Unique queue names prevent consuming unrelated jobs. Tests on the shared test database use transaction rollback.

The separate-process test runs migrations in a new database named `classroom_integration_<random hex>` and uses an isolated storage directory under `work/start-settings-<random hex>`. It does not run the provider event loop. Its fake credentials cannot make real model or OSS calls.

Evidence: `reports/phpunit-final-concurrency.xml` and `reports/start-settings-postgresql-concurrency.json`. The first helper attempt's constructor error is retained in `reports/phpunit-final-concurrency-harness-failed.xml`; later harness corrections used the Workerman 5 connection constructor and a complete timed-export fixture (`media_samples=16000`). Those fixture failures are not reported as successful production tests.

This verifies the observed lock ordering, expiry race and queue wait behavior. It does not simulate a worker remaining blocked for an entire 24-hour retry deadline, a real 90-minute Qwen session, provider delivery exactly once, or a physical microphone.
