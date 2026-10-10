import { chromium, expect } from '@playwright/test';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

// Real Qwen + private OSS, actual production frontend/PHP/Workerman/PostgreSQL.
// getUserMedia is explicitly replaced by supplied synthetic English speech.
// It is not a hardware microphone or physical-device validation.
if (!process.argv[2] || !process.argv[3]) throw new Error('Pass an isolated real-services fixture JSON and synthetic speech WAV path.');
const fixture = JSON.parse(await readFile(process.argv[2], 'utf8'));
const speechBase64 = (await readFile(process.argv[3])).toString('base64');
const origin = process.env.ACTUAL_CAPTURE_URL || 'http://localhost:19080';
const runLabel = process.env.REAL_RUN_LABEL || 'normal';
if (!/^[a-z0-9-]{1,40}$/.test(runLabel)) throw new Error('Invalid bounded artifact run label.');
const directory = new URL(`../artifacts/actual-phase-c-real-services/${runLabel}/`, import.meta.url);
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHROMIUM_CHANNEL || 'msedge', headless: true });
const contexts = [], jsErrors = [], requestErrors = [];
const redact = value => String(value).replace(/https?:\/\/[^\s"'<>]+/g, '[redacted-url]');
const started = Date.now();
const canonical = units => units.filter(unit => unit.status === 'completed' && unit.text.trim()).map(unit => ({ id: unit.id, kind: unit.kind, text: unit.text })).sort((left, right) => String(left.id).localeCompare(String(right.id)));
try {
  for (let index = 0; index < 3; index++) { const context = await browser.newContext(); context.setDefaultTimeout(15000); contexts.push(context); }
  const recorder = await contexts[0].newPage();
  await recorder.addInitScript(({ speech }) => {
    window.__realCapture = { microphoneRequests: 0, tracks: [], packets: [], acknowledgements: [], recorderReadies: [], rotations: [], completedUnits: {}, speechStarted: false, speechNaturallyFinished: false, speechDuration: 0, startSpeech: null };
    const nativeSend = WebSocket.prototype.send;
    WebSocket.prototype.send = function (data) {
      if (data instanceof ArrayBuffer) { const view = new DataView(data); window.__realCapture.packets.push({ epoch: view.getUint32(0, false), sequence: view.getUint32(4, false), samples: (data.byteLength - 8) / 2 }); }
      return nativeSend.call(this, data);
    };
    const NativeSocket = window.WebSocket;
    window.WebSocket = class extends NativeSocket {
      constructor(...arguments_) {
        super(...arguments_);
        this.addEventListener('message', event => {
          if (typeof event.data !== 'string') return;
          const data = JSON.parse(event.data);
          if (data.type === 'recorder.ready') { window.__realCapture.recorderReadies.push({ epoch:data.epoch, next_sequence:data.next_sequence }); window.__realCapture.startSpeech?.(); }
          if (data.type === 'event' && data.event_type === 'translation.rotation') window.__realCapture.rotations.push({ ...data.payload, microphoneRequests:window.__realCapture.microphoneRequests, liveTracks:window.__realCapture.tracks.filter(track=>track.readyState==='live').length, currentEpoch:window.__realCapture.packets.at(-1)?.epoch });
          if (data.type === 'audio.ack') window.__realCapture.acknowledgements.push({ sequence: data.sequence, media_samples: data.media_samples });
          const units = data.type === 'snapshot' ? data.units : data.type === 'event' && data.event_type === 'subtitle.updated' ? [data.payload.unit] : [];
          for (const unit of units || []) if (unit.status === 'completed' && unit.text.trim()) window.__realCapture.completedUnits[unit.id] = { id: unit.id, kind: unit.kind, text: unit.text, status: unit.status };
        });
      }
    };
    navigator.mediaDevices.getUserMedia = async () => {
      window.__realCapture.microphoneRequests++;
      const context = new AudioContext(); await context.resume();
      const bytes = Uint8Array.from(atob(speech), character => character.charCodeAt(0));
      const decoded = await context.decodeAudioData(bytes.buffer);
      window.__realCapture.speechDuration = decoded.duration;
      const source = context.createBufferSource(); source.buffer = decoded;
      const destination = context.createMediaStreamDestination(); source.connect(destination);
      let stopped = false;
      source.onended = () => { if (!stopped) window.__realCapture.speechNaturallyFinished = true; };
      window.__realCapture.startSpeech = () => { if (!window.__realCapture.speechStarted) { window.__realCapture.speechStarted = true; source.start(context.currentTime + 0.1); } };
      const track = destination.stream.getAudioTracks()[0], stop = track.stop.bind(track);
      track.stop = () => { stopped = true; stop(); if (window.__realCapture.speechStarted && !window.__realCapture.speechNaturallyFinished) source.stop(); void context.close(); };
      window.__realCapture.tracks.push(track);
      return destination.stream;
    };
  }, { speech: speechBase64 });
  const observe = page => {
    page.on('pageerror', error => jsErrors.push(redact(error.message)));
    page.on('requestfailed', request => requestErrors.push({ scope: request.url().startsWith(origin) ? new URL(request.url()).pathname : 'external-private-playback', error: redact(request.failure()?.errorText) }));
  };
  observe(recorder);
  await recorder.goto(`${origin}/courses`);
  await recorder.getByRole('button', { name: '登录', exact: true }).click();
  const login = recorder.getByRole('dialog');
  await login.getByLabel('学号', { exact: true }).fill(fixture.student_id);
  await login.getByLabel('密码', { exact: true }).fill('Fixture-password-2026');
  await login.getByRole('button', { name: '登录', exact: true }).click();
  await expect(recorder.getByRole('button', { name: '退出当前设备', exact: true })).toBeVisible();
  await recorder.goto(`${origin}/lessons/${fixture.lesson_id}`);
  await expect(recorder.getByText('课堂已连接', { exact: true })).toBeVisible();
  const viewers = [];
  for (let index = 1; index < 3; index++) {
    const page = await contexts[index].newPage();
    await page.addInitScript(() => {
      window.__realViewer = { microphoneRequests: 0, completedUnits: {} };
      const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
      navigator.mediaDevices.getUserMedia = constraints => { window.__realViewer.microphoneRequests++; return original(constraints); };
      const NativeSocket = window.WebSocket;
      window.WebSocket = class extends NativeSocket {
        constructor(...arguments_) {
          super(...arguments_);
          this.addEventListener('message', event => {
            if (typeof event.data !== 'string') return;
            const data = JSON.parse(event.data);
            const units = data.type === 'snapshot' ? data.units : data.type === 'event' && data.event_type === 'subtitle.updated' ? [data.payload.unit] : [];
            for (const unit of units || []) if (unit.status === 'completed' && unit.text.trim()) window.__realViewer.completedUnits[unit.id] = { id: unit.id, kind: unit.kind, text: unit.text, status: unit.status };
          });
        }
      };
    });
    observe(page); await page.goto(`${origin}/lessons/${fixture.lesson_id}`);
    await expect(page.getByText('课堂已连接', { exact: true })).toBeVisible();
    await expect(page.locator('.recorder-panel')).toHaveCount(0); viewers.push(page);
  }
  await recorder.bringToFront();
  await recorder.getByRole('checkbox').check();
  await recorder.getByRole('button', { name: '开始收音', exact: true }).click();
  await recorder.waitForFunction(() => window.__realCapture.speechDuration > 0 && window.__realCapture.speechStarted);
  const fixtureDuration = await recorder.evaluate(() => window.__realCapture.speechDuration);
  await recorder.waitForFunction(() => window.__realCapture.speechNaturallyFinished, null, { timeout: Math.ceil((fixtureDuration+25)*1000) });
  await recorder.waitForTimeout(300);
  await recorder.getByRole('button', { name: '结束课堂', exact: true }).click();
  await recorder.getByRole('dialog').getByRole('button', { name: '确认', exact: true }).click();
  await expect(recorder.getByText('已结束', { exact: true })).toBeVisible();
  await expect.poll(() => recorder.evaluate(() => window.__realCapture.tracks.every(track => track.readyState === 'ended'))).toBe(true);
  const getSnapshot = async () => {
    const response = await contexts[0].request.get(`${origin}/api/lessons/${fixture.lesson_id}`);
    if (response.status() !== 200) throw new Error(`Lesson readback HTTP ${response.status()}`);
    return response.json();
  };
  await expect.poll(async () => { const data = await getSnapshot(); return data.units.some(unit => unit.kind === 'source' && unit.status === 'completed' && unit.text.trim()) && data.units.some(unit => unit.kind === 'translation' && unit.status === 'completed' && unit.text.trim()); }, { timeout: 15000 }).toBe(true);
  await expect.poll(async () => (await getSnapshot()).lesson.translation_status, { timeout: 15000 }).toMatch(/finished|closed/);
  const saved = await getSnapshot(), savedUnits = canonical(saved.units);
  for (const page of viewers) {
    await expect.poll(async () => canonical(await page.evaluate(() => Object.values(window.__realViewer.completedUnits))), { timeout: 10000 }).toEqual(savedUnits);
    expect(await page.evaluate(() => window.__realViewer.microphoneRequests)).toBe(0);
    await expect(page.locator('.subtitle-unit.source p').first()).not.toBeEmpty();
    await expect(page.locator('.subtitle-unit.translation p').first()).not.toBeEmpty();
  }
  await expect.poll(async () => canonical(await recorder.evaluate(() => Object.values(window.__realCapture.completedUnits))), { timeout: 10000 }).toEqual(savedUnits);
  const evidence = await recorder.evaluate(() => ({ microphoneRequests: window.__realCapture.microphoneRequests, packets: window.__realCapture.packets, acknowledgements: window.__realCapture.acknowledgements.length, recorderReadies:window.__realCapture.recorderReadies, rotations:window.__realCapture.rotations, speechDuration: window.__realCapture.speechDuration, allTracksEnded: window.__realCapture.tracks.every(track => track.readyState === 'ended') }));
  expect(evidence.microphoneRequests).toBe(1);expect(evidence.recorderReadies.length).toBe(1);expect(new Set(evidence.packets.map(packet=>packet.epoch)).size).toBe(1);
  for(let index=0;index<evidence.packets.length;index++)expect(evidence.packets[index].sequence).toBe(index);
  if(runLabel.startsWith('rotation')){expect(evidence.rotations.filter(event=>event.phase==='switched').length).toBeGreaterThanOrEqual(1);for(const event of evidence.rotations)expect(event.microphoneRequests).toBe(1);for(const event of evidence.rotations.filter(event=>event.phase==='switched'))expect(event.liveTracks).toBe(1);}
  expect(saved.lesson.media_samples).toBe(evidence.packets.reduce((sum, packet) => sum + packet.samples, 0));
  expect(evidence.acknowledgements).toBe(evidence.packets.length);
  await expect.poll(async () => (await getSnapshot()).lesson.archive_status, { timeout: 30000 }).toBe('playable');
  await expect(recorder.getByRole('button', { name: '取得授权播放链接', exact: true })).toBeVisible();
  await recorder.getByRole('button', { name: '取得授权播放链接', exact: true }).click();
  const audio = recorder.locator('audio');
  await expect(audio).toBeVisible();
  await expect.poll(() => audio.evaluate(element => Number.isFinite(element.duration) && element.duration > 0 && element.readyState >= 1), { timeout: 20000 }).toBe(true);
  const playback = await audio.evaluate(async element => { element.muted = true; await element.play(); return { duration: element.duration }; });
  await expect.poll(() => audio.evaluate(element => element.currentTime), { timeout: 10000 }).toBeGreaterThan(0.2);
  await audio.evaluate(element => element.pause());
  const authorization = await contexts[0].request.get(`${origin}/api/lessons/${fixture.lesson_id}/playback`);
  if (authorization.status() !== 200) throw new Error(`Playback authorization HTTP ${authorization.status()}`);
  const privatePlayback = await authorization.json();
  if (typeof privatePlayback.url !== 'string' || !privatePlayback.url.startsWith('https://')) throw new Error('Playback did not return a private HTTPS signed URL.');
  const range = await contexts[0].request.get(privatePlayback.url, { headers: { Range: 'bytes=0-1023' }, failOnStatusCode: false, timeout: 15000 });
  expect(range.status()).toBe(206);
  const rangeBytes = await range.body(); expect(rangeBytes.byteLength).toBeGreaterThan(0);
  await recorder.screenshot({ path: fileURLToPath(new URL('real-qwen-real-oss-synthetic-speech-recorder.png', directory)), fullPage: true });
  await viewers[0].screenshot({ path: fileURLToPath(new URL('real-qwen-real-oss-synthetic-speech-guest.png', directory)), fullPage: true });
  // Browsers can cancel an obsolete media Range while advancing playback.
  // Count that explicitly, after decoding/playback and independent Range pass.
  const expectedMediaCancellations = requestErrors.filter(error => error.scope === 'external-private-playback' && error.error === 'net::ERR_ABORTED');
  const unexpectedRequestErrors = requestErrors.filter(error => !expectedMediaCancellations.includes(error));
  expect(jsErrors).toEqual([]); expect(unexpectedRequestErrors).toEqual([]);
  const report = {
    classification: 'REAL_QWEN_REAL_OSS_REAL_PHP_POSTGRESQL_BROWSER_AUDIOWORKLET_SYNTHETIC_SPEECH', status: 'passed', testedAt: new Date().toISOString(), elapsedSeconds: Number(((Date.now() - started) / 1000).toFixed(2)), browser: browser.version(), clients: 3,
    real_model: true, real_oss: true, synthetic_audio: true, real_microphone: false, real_physical_device: false, realPhp: true, realWorkerman: true, realPostgreSql: true, httpMocks: false, webSocketMocks: false, authenticatedThroughUi: true,
    runLabel, speechFixtureDurationSeconds: evidence.speechDuration, audioPackets: evidence.packets.length, partialTailPackets: evidence.packets.filter(packet => packet.samples < 1600).length, mediaSamples: saved.lesson.media_samples, allPacketsDurablyAcknowledged: true, allTracksStopped: evidence.allTracksEnded, microphoneRequests:evidence.microphoneRequests, recorderReadies:evidence.recorderReadies, rotationEvents:evidence.rotations, epochUnchanged:true, packetSequenceContiguous:true,
    completedSourceUnits: savedUnits.filter(unit => unit.kind === 'source').length, completedTranslationUnits: savedUnits.filter(unit => unit.kind === 'translation').length, viewerIdsAndTextsMatchDatabase: true, viewerMicrophoneRequests: 0, archiveStatus: 'playable', privatePlaybackAuthorized: true, browserAudioPlaybackPassed: true, playbackMutedDuringTest: true, playbackDurationSeconds: playback.duration, signedRangeHttpStatus: range.status(), signedRangeBytes: rangeBytes.byteLength, jsErrors, requestErrors: unexpectedRequestErrors, expectedMediaCancellations: expectedMediaCancellations.length,
    limitations: ['Synthetic speech source replaces physical microphone input', 'No physical Android/iOS device coverage', 'No 90-minute or 100-viewer capacity validation', 'This uses a local HTTP/WS test origin; production HTTPS/WSS is separate']
  };
  await writeFile(new URL('real-services-results.json', directory), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report));
} catch (error) {
  const report = { classification: 'REAL_SERVICES_SYNTHETIC_SPEECH_ATTEMPT', status: 'failed', testedAt: new Date().toISOString(), elapsedSeconds: Number(((Date.now() - started) / 1000).toFixed(2)), error: redact(error.message), jsErrors, requestErrors, real_microphone: false };
  await writeFile(new URL('real-services-failure.json', directory), JSON.stringify(report, null, 2));
  throw new Error(report.error);
} finally { for (const context of contexts) await context.close(); await browser.close(); }
