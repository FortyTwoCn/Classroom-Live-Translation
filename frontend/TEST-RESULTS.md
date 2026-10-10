# Phase A browser verification — 2026-10-09

- `pnpm install --frozen-lockfile`: passed. Lockfile included; only esbuild's required build script is allowed.
- `pnpm build`: passed, including strict TypeScript checks. Publishes `public/app.html` and hashed assets; keeps Laravel's `index.php`.
- `pnpm test`: **13 passed**. Synthetic sample-rate conversion, continuous blocks, mono conversion, anti-alias filtering, PCM clipping/endianness, binary framing, bounded backpressure and all three locale key sets.
- Playwright with locally installed Microsoft Edge 154.0.4258.62 (Chromium engine): **3 passed**. Mock HTTP and a loopback WebSocket test fixture; guest viewing requests no microphone, three-language switching preserves viewer state, Ukrainian mobile-size layout has no horizontal overflow, and an explicitly synthetic 440 Hz MediaStream passes through the actual AudioWorklet/resampler into PCM packets. Locale switching does not request a second microphone or a second start command; pause ends every track and stops packet upload.
- Downloaded Playwright Chromium 156 failed to launch on this Windows machine with a side-by-side configuration error (`spawn UNKNOWN`). This is an environment failure, not a passing browser test. Reproduction: default `pnpm test:e2e --project=chromium-simulated`. Working engine override in PowerShell: `$env:PLAYWRIGHT_CHROMIUM_CHANNEL='msedge'`, then the same command.
- WebKit 27.2 on Windows: **2 passed, 1 skipped**. Guest reading, language switching and mobile layout passed. This Windows WebKit build omits `AudioContext` and native microphone/MediaStream generation, so the audio capture test is explicitly skipped with a capability-based reason. It does not verify Safari or iOS audio capture. Earlier attempted audio runs failed on that platform limitation and are not counted as passing. Test fixtures now use a real loopback WebSocket rather than browser-specific `routeWebSocket` interception.

Generated screenshots under `tests/artifacts/` are explicitly named **simulated** and use desktop/mobile/tablet viewports. They do not represent physical phones or tablets, real model output or actual audio recordings.

## Actual PHP HTTP integration, empty PostgreSQL course list

`pnpm test:actual-http` connects to the real `http://localhost:8080` app through locally installed Edge 154.0.4258.62. It installs no HTTP/WebSocket routes and no fake database data. **9 cases passed:** Chinese, English and Ukrainian at desktop, mobile and tablet viewport sizes. The real `/api/me` returned a guest and `/api/courses` returned an empty accessible course list. All cases had **0 microphone calls, 0 JavaScript exceptions, 0 failed requests, 0 HTTP errors and no horizontal overflow**. Outputs are `tests/artifacts/actual-http/actual-empty-*.png` and `actual-http-results.json`.

The first deployment attempt exposed an incorrect PHP built-in-server static-file router returning HTML for JavaScript assets. The project router was fixed, and the actual integration above passed after restarting the server. These actual-empty screenshots prove application/API loading, not real model, OSS, classroom audio or physical-device support.

## Actual browser audio → PHP gateway → PostgreSQL, mocked Qwen

`node tests/e2e/actual-browser-capture.mjs <isolated gateway-fixture.json>` passed against `http://localhost:18080` and the actual Workerman process in the isolated `classroom_test` PostgreSQL database. It uses the production built browser assets, logs in through the real UI, and opens one authenticated recorder plus two guest viewers. **Only the microphone input (440 Hz oscillator) and upstream Qwen are synthetic.** There are no HTTP interceptions or browser WebSocket mocks.

The recorder started, paused, resumed and ended through the UI. Switching to Ukrainian during capture did not request a new microphone or restart the session. **25 PCM packets**, including **2 partial tail packets**, totaled exactly **39,040 samples** in the database across **2 recorder epochs**. All packets received durable acknowledgements; both pauses/end stopped the actual MediaStream tracks. All three clients displayed completed source/translation output from the explicit upstream fixture (**2 source units and 2 translation units**). Guest microphone calls, JavaScript errors and failed requests were all zero.

Outputs are `tests/artifacts/actual-browser-capture/actual-browser-capture-results.json` and three `actual-php-mocked-qwen-*.png` screenshots. Archive status was `pending`; this test does **not** verify OSS upload, original-audio playback, real Qwen recognition or a physical microphone. Those external tests must be reported separately. An initial test attempt used an overly exact selector for the pause button's icon; the corrected script passed on a new isolated ready lesson.

## Real Qwen + real private OSS, synthetic English speech

`node tests/e2e/actual-browser-real-services.mjs <isolated real-gateway-fixture.json> <synthetic-english-speech.wav>` **passed** against the production-built browser, actual PHP/Workerman/PostgreSQL, actual Beijing Qwen service and private OSS archive/upload workers. The database and OSS prefix were isolated from production. It installed no HTTP or WebSocket mocks and authenticated through the UI. The microphone was replaced explicitly with **16.965 seconds of synthetic English speech**, routed through a real browser AudioContext/MediaStream and the production AudioWorklet. This does **not** verify microphone hardware.

The recorder and two guest viewers completed the loop in **25.38 seconds**. **175 PCM packets**, including **1 partial tail packet**, totaled **278,571 samples**; all received durable acknowledgements and matched PostgreSQL's sample count. All tracks stopped. Actual Qwen produced **1 completed source unit and 1 completed translation unit**. Both viewer clients and the recorder received the same IDs and text as the persisted database; guests made no microphone calls.

The archive reached `playable`. An authorized private playback URL loaded into the native browser audio element, decoded a **17.411-second** recording, and playback advanced. The test muted playback locally. A separate request to the real signed OSS URL returned **HTTP 206 and 1024 bytes** for `Range: bytes=0-1023`. No key, authorization ticket or signed URL is included in test reports. **0 JavaScript errors and 0 unexpected failed requests** were observed; **1 native media `ERR_ABORTED`** cancellation is counted separately after successful decoding, playback and Range validation.

Outputs are `tests/artifacts/actual-real-services/real-services-results.json` and two `real-qwen-real-oss-synthetic-speech-*.png` screenshots. The earlier attempt completed the substantive loop but failed its strict no-cancellation assertion; its failure report is preserved. It also exposed a redundant `audio.load()` after binding a new source. That call was removed to avoid canceling an initial media download; the fresh-lesson rerun above passed. Screenshots were visually checked and show actual Qwen output and the playable archive.

## Not verified

Actual microphone hardware, iPhone/iPad Safari, physical Android devices, full 90-minute recording, 100 real viewers and production HTTPS/WSS. The app itself has no fake provider fallback; the mocked provider is configured only in its separate isolated test service. The real-services test above verifies real Qwen and OSS with synthetic speech, independently from both mocked suites.
