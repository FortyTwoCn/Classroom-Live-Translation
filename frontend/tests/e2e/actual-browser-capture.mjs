import { chromium, expect } from '@playwright/test';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

// Actual production browser assets + PHP HTTP + Workerman + PostgreSQL.
// ONLY microphone signal and the upstream Qwen server are synthetic fixtures.
// No HTTP interception, browser WebSocket fixture or fake subtitle UI is used.
if (!process.argv[2]) throw new Error('Pass the isolated test database gateway-fixture.json path.');
const fixture = JSON.parse(await readFile(process.argv[2], 'utf8'));
const origin = process.env.ACTUAL_CAPTURE_URL || 'http://localhost:18080';
const directory = new URL('../artifacts/actual-browser-capture/', import.meta.url);
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHROMIUM_CHANNEL || 'msedge', headless: true });
const contexts = [], errors = [], requestErrors = [];
try {
  for (let index = 0; index < 3; index++) { const context = await browser.newContext(); context.setDefaultTimeout(10000); contexts.push(context); }
  const recorder = await contexts[0].newPage();
  await recorder.addInitScript(() => {
    window.__capture = { microphoneRequests: 0, tracks: [], packets: [], acks: [], commands: [] };
    const nativeSend = WebSocket.prototype.send;
    WebSocket.prototype.send = function (data) {
      if (data instanceof ArrayBuffer) { const view = new DataView(data); window.__capture.packets.push({ epoch: view.getUint32(0, false), sequence: view.getUint32(4, false), samples: (data.byteLength - 8) / 2 }); }
      else if (typeof data === 'string') { try { window.__capture.commands.push(JSON.parse(data).type); } catch {} }
      return nativeSend.call(this, data);
    };
    const nativeSocket = window.WebSocket;
    window.WebSocket = class extends nativeSocket {
      constructor(...arguments_) { super(...arguments_); this.addEventListener('message', event => { if (typeof event.data === 'string') { const data = JSON.parse(event.data); if (data.type === 'audio.ack') window.__capture.acks.push({ sequence: data.sequence, media_samples: data.media_samples }); } }); }
    };
    navigator.mediaDevices.getUserMedia = async () => {
      window.__capture.microphoneRequests++;
      const context = new AudioContext(); await context.resume();
      const oscillator = context.createOscillator(); oscillator.frequency.value = 440;
      const volume = context.createGain(); volume.gain.value = 0.2;
      const destination = context.createMediaStreamDestination(); oscillator.connect(volume).connect(destination); oscillator.start();
      const track = destination.stream.getAudioTracks()[0], stop = track.stop.bind(track);
      track.stop = () => { stop(); oscillator.stop(); void context.close(); };
      window.__capture.tracks.push(track);
      return destination.stream;
    };
  });
  recorder.on('pageerror', error => errors.push(error.message));
  recorder.on('requestfailed', request => requestErrors.push({ path: new URL(request.url()).pathname, error: request.failure()?.errorText }));
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
      window.__viewerMicRequests = 0;
      const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
      navigator.mediaDevices.getUserMedia = constraints => { window.__viewerMicRequests++; return original(constraints); };
    });
    page.on('pageerror', error => errors.push(error.message));
    page.on('requestfailed', request => requestErrors.push({ path: new URL(request.url()).pathname, error: request.failure()?.errorText }));
    await page.goto(`${origin}/lessons/${fixture.lesson_id}`);
    await expect(page.getByText('课堂已连接', { exact: true })).toBeVisible();
    await expect(page.locator('.recorder-panel')).toHaveCount(0);
    viewers.push(page);
  }
  await recorder.getByRole('checkbox').check();
  await recorder.getByRole('button', { name: '开始收音', exact: true }).click();
  await expect.poll(() => recorder.evaluate(() => window.__capture.acks.length)).toBeGreaterThan(12);
  await recorder.locator('.site-header select').selectOption('uk');
  expect(await recorder.evaluate(() => window.__capture.microphoneRequests)).toBe(1);
  await recorder.getByRole('button', { name: /Призупинити запис$/ }).click();
  await expect(recorder.getByText('Призупинене', { exact: true })).toBeVisible();
  await expect.poll(() => recorder.evaluate(() => window.__capture.tracks.every(track => track.readyState === 'ended'))).toBe(true);
  const paused = await (await contexts[0].request.get(`${origin}/api/lessons/${fixture.lesson_id}`)).json();
  expect(paused.lesson.status).toBe('paused'); expect(paused.lesson.media_samples).toBeGreaterThan(16000);
  for (const page of [recorder, ...viewers]) { await expect(page.locator('.subtitle-unit.source p')).toContainText('Lift coefficient.'); await expect(page.locator('.subtitle-unit.translation p')).toContainText('升力系数。'); }
  await recorder.screenshot({ path: fileURLToPath(new URL('actual-php-mocked-qwen-paused-uk.png', directory)), fullPage: true });
  await expect.poll(async () => (await (await contexts[0].request.get(`${origin}/api/lessons/${fixture.lesson_id}`)).json()).lesson.translation_status).toMatch(/finished|closed/);
  await recorder.getByRole('button', { name: 'Продовжити запис', exact: true }).click();
  await expect.poll(() => recorder.evaluate(() => new Set(window.__capture.packets.map(packet => packet.epoch)).size)).toBe(2);
  await expect.poll(() => recorder.evaluate(() => window.__capture.microphoneRequests)).toBe(2);
  await recorder.waitForTimeout(500);
  await recorder.getByRole('button', { name: 'Завершити заняття', exact: true }).click();
  await recorder.getByRole('dialog').getByRole('button', { name: 'Підтвердити', exact: true }).click();
  await expect(recorder.getByText('Завершене', { exact: true })).toBeVisible();
  await expect.poll(() => recorder.evaluate(() => window.__capture.tracks.every(track => track.readyState === 'ended'))).toBe(true);
  const saved = await (await contexts[0].request.get(`${origin}/api/lessons/${fixture.lesson_id}`)).json();
  const evidence = await recorder.evaluate(() => ({ microphoneRequests: window.__capture.microphoneRequests, packets: window.__capture.packets, acknowledgements: window.__capture.acks.length, commands: window.__capture.commands, tracksEnded: window.__capture.tracks.every(track => track.readyState === 'ended') }));
  expect(saved.lesson.status).toBe('ended');
  expect(saved.lesson.media_samples).toBe(evidence.packets.reduce((sum, packet) => sum + packet.samples, 0));
  expect(evidence.acknowledgements).toBe(evidence.packets.length);
  expect(evidence.packets.every(packet => packet.samples >= 1 && packet.samples <= 1600)).toBe(true);
  expect(saved.units.some(unit => unit.kind === 'source' && unit.status === 'completed')).toBe(true);
  expect(saved.units.some(unit => unit.kind === 'translation' && unit.status === 'completed')).toBe(true);
  for (const page of viewers) expect(await page.evaluate(() => window.__viewerMicRequests)).toBe(0);
  expect(errors).toEqual([]); expect(requestErrors).toEqual([]);
  await recorder.screenshot({ path: fileURLToPath(new URL('actual-php-mocked-qwen-ended-uk.png', directory)), fullPage: true });
  await viewers[0].screenshot({ path: fileURLToPath(new URL('actual-php-mocked-qwen-guest-zh-CN.png', directory)), fullPage: true });
  const report = { classification: 'ACTUAL_BROWSER_AUDIOWORKLET_REAL_PHP_POSTGRESQL_SIMULATED_MIC_AND_QWEN', status: 'passed', testedAt: new Date().toISOString(), browser: browser.version(), clients: 3, authenticatedThroughUi: true, actualHttp: true, actualWorkerman: true, actualPostgreSql: true, httpMocks: false, webSocketMocks: false, realQwen: false, realOss: false, physicalMicrophone: false, startPauseResumeEndViaUi: true, localeSwitchDidNotRestartCapture: true, viewerMicrophoneRequests: 0, audioPackets: evidence.packets.length, partialTailPackets: evidence.packets.filter(packet => packet.samples < 1600).length, mediaSamples: saved.lesson.media_samples, epochs: new Set(evidence.packets.map(packet => packet.epoch)).size, allPacketsDurablyAcknowledged: true, allTracksEnded: evidence.tracksEnded, completedSourceUnits: saved.units.filter(unit => unit.kind === 'source' && unit.status === 'completed').length, completedTranslationUnits: saved.units.filter(unit => unit.kind === 'translation' && unit.status === 'completed').length, archiveStatus: saved.lesson.archive_status, playbackVerified: false, jsErrors: errors, requestErrors };
  await writeFile(new URL('actual-browser-capture-results.json', directory), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report));
} catch (error) {
  await writeFile(new URL('actual-browser-capture-failure.json', directory), JSON.stringify({ status: 'failed', classification: 'REAL_PHP_POSTGRESQL_SYNTHETIC_MIC_AND_QWEN', error: error.message, jsErrors: errors, requestErrors }, null, 2));
  throw error;
} finally { for (const context of contexts) await context.close(); await browser.close(); }
