import { test, expect, type Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { socketFixture, closeFixtures } from './socket-fixture';
test.afterEach(closeFixtures);
// Every API and subtitle below is an explicit test fixture. These tests never
// assert that a real model, OSS bucket, microphone or physical device was used.
async function guestApi(page: Page) {
  const wsUrl = await socketFixture((client, bytes, binary) => {
    if (binary) return;
    const request = JSON.parse(bytes.toString());
    if (request.type === 'subscribe') client.send(JSON.stringify({ type: 'snapshot', lesson: { id: 1, course_id: 1, name: 'Fixture lesson', status: 'ready', translation_status: 'idle', archive_status: 'none', recorder_user_id: null, recorder_epoch: 0, media_samples: 0, target_language: 'zh' }, units: [], last_seq: 0 }));
  });
  await page.addInitScript(() => {
    (window as unknown as { microphoneRequests: number }).microphoneRequests = 0;
    if (navigator.mediaDevices) navigator.mediaDevices.getUserMedia = async () => {
      (window as unknown as { microphoneRequests: number }).microphoneRequests++;
      throw new DOMException('Test denial', 'NotAllowedError');
    };
  });
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    const data = path === '/api/me' ? { user: null, csrf_token: 'simulation-csrf', ws_url: wsUrl } : path === '/api/courses' ? { courses: [{ id: 1, name: 'Synthetic fixture — І Ї Є Ґ', target_language: 'zh', guest_access: 'public' }] } : path === '/api/lessons/1' ? { lesson: { id: 1, course_id: 1, course_name: 'Fixture course', name: 'Fixture lesson', status: 'ready', translation_status: 'idle', archive_status: 'none', recorder_user_id: null, recorder_epoch: 0, media_samples: 0, target_language: 'zh' }, units: [], last_seq: 0 } : { ticket: 'simulation-ticket' };
    await route.fulfill({ json: data });
  });
}
test('simulated guest reading and all three languages never request the microphone', async ({ page, browser }, testInfo) => {
  await guestApi(page); await page.goto('/lessons/1');
  const artifactDirectory = `tests/artifacts/${testInfo.project.name}`;
  await mkdir(artifactDirectory, { recursive: true });
  console.log(`Browser engine: ${browser.version()}; all API/WS/audio inputs in this suite are simulated.`);
  await expect(page.getByRole('heading', { name: 'Fixture lesson' })).toBeVisible();
  await expect(page.getByText('等待真实音频与模型输出。此页面不会生成演示字幕。')).toBeVisible();
  await expect(page.getByRole('button', { name: '开始收音' })).toHaveCount(0);
  await page.screenshot({ path: `${artifactDirectory}/simulated-zh-CN-desktop.png`, fullPage: true });
  await page.getByRole('combobox', { name: '界面语言' }).selectOption('uk');
  await expect(page.getByText('Режим перегляду. Дозвіл на мікрофон не запитуватиметься.')).toBeVisible();
  await page.screenshot({ path: `${artifactDirectory}/simulated-uk-desktop.png`, fullPage: true });
  await page.getByRole('combobox', { name: 'Мова інтерфейсу' }).selectOption('en');
  await expect(page.getByText('Viewing mode. Microphone permission will not be requested.')).toBeVisible();
  await page.screenshot({ path: `${artifactDirectory}/simulated-en-desktop.png`, fullPage: true });
  expect(await page.evaluate(() => (window as unknown as { microphoneRequests: number }).microphoneRequests)).toBe(0);
});
test('simulated mobile Ukrainian layout does not overflow horizontally', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 }); await guestApi(page); await page.goto('/courses');
  await page.getByRole('combobox', { name: '界面语言' }).selectOption('uk');
  await expect(page.getByRole('heading', { name: 'Synthetic fixture — І Ї Є Ґ' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  const artifactDirectory = `tests/artifacts/${testInfo.project.name}`;
  await mkdir(artifactDirectory, { recursive: true });
  await page.screenshot({ path: `${artifactDirectory}/simulated-uk-mobile.png`, fullPage: true });
  await page.getByRole('combobox', { name: 'Мова інтерфейсу' }).selectOption('en');
  await page.screenshot({ path: `${artifactDirectory}/simulated-en-mobile.png`, fullPage: true });
  await page.getByRole('combobox', { name: 'Interface language' }).selectOption('zh-CN');
  await page.screenshot({ path: `${artifactDirectory}/simulated-zh-CN-mobile.png`, fullPage: true });
  await page.setViewportSize({ width: 768, height: 1024 });
  await page.screenshot({ path: `${artifactDirectory}/simulated-zh-CN-tablet.png`, fullPage: true });
});

test('synthetic browser audio uses PCM packets and stops tracks on pause without restarting on locale changes', async ({ page }) => {
  await page.addInitScript(() => {
    const state = window as unknown as { microphoneRequests: number; simulatedTracks: MediaStreamTrack[] };
    state.microphoneRequests = 0; state.simulatedTracks = [];
    // Windows WebKit may omit the hardware-capture surface. This is an explicit
    // synthetic source stub, not a claim that native getUserMedia is supported.
    if (!navigator.mediaDevices) Object.defineProperty(navigator, 'mediaDevices', { value: { enumerateDevices: async () => [] }, configurable: true });
    navigator.mediaDevices.getUserMedia = async () => {
      state.microphoneRequests++;
      const context = new AudioContext();
      await context.resume();
      const oscillator = context.createOscillator(); oscillator.frequency.value = 440;
      const destination = context.createMediaStreamDestination(); oscillator.connect(destination); oscillator.start();
      const track = destination.stream.getAudioTracks()[0]!;
      const nativeStop = track.stop.bind(track);
      track.stop = () => { nativeStop(); oscillator.stop(); void context.close(); };
      state.simulatedTracks.push(track);
      return destination.stream;
    };
  });
  const lesson = { id: 1, course_id: 1, course_name: 'Synthetic capture fixture', name: 'Synthetic capture fixture', status: 'ready', translation_status: 'idle', archive_status: 'none', recorder_user_id: 7, recorder_epoch: 0, media_samples: 0, target_language: 'zh' };
  const packets: Uint8Array[] = [];
  const commands: string[] = [];
  const wsUrl = await socketFixture((client, message, binary) => {
    if (!binary) {
      const request = JSON.parse(message.toString()); commands.push(request.type);
      if (request.type === 'subscribe') client.send(JSON.stringify({ type: 'snapshot', lesson, units: [], last_seq: 0 }));
      if (request.type === 'start') client.send(JSON.stringify({ type: 'recorder.ready', epoch: 1, next_sequence: 1, media_samples: 0 }));
    } else {
      const bytes = Uint8Array.from(message); packets.push(bytes);
      const sequence = new DataView(bytes.buffer).getUint32(4, false);
      client.send(JSON.stringify({ type: 'audio.ack', sequence, media_samples: sequence * 1600 }));
    }
  });
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    await route.fulfill({ json: path === '/api/me' ? { user: { id: 7, name: 'Test recorder', role: 'user', must_change_password: false }, csrf_token: 'simulation-csrf', ws_url: wsUrl } : path === '/api/lessons/1' ? { lesson, units: [], last_seq: 0 } : { ticket: 'simulation-ticket' } });
  });
  await page.goto('/lessons/1');
  const audioCapability = await page.evaluate(() => ({ destination: typeof window.AudioContext?.prototype?.createMediaStreamDestination, stream: typeof window.MediaStream }));
  test.skip(audioCapability.destination !== 'function' || audioCapability.stream !== 'function', 'This Windows browser build omits AudioContext/MediaStream audio generation; real-device microphone support is unverified.');
  await page.getByRole('checkbox').check();
  await page.getByRole('button', { name: '开始收音', exact: true }).click();
  await expect.poll(() => packets.length).toBeGreaterThan(2);
  expect(packets[0]!.byteLength).toBe(3208);
  const header = new DataView(packets[0]!.buffer); expect(header.getUint32(0, false)).toBe(1); expect(header.getUint32(4, false)).toBe(1);
  expect(packets[0]!.subarray(8).some(byte => byte !== 0)).toBe(true);
  await page.getByRole('combobox', { name: '界面语言' }).selectOption('uk');
  expect(await page.evaluate(() => (window as unknown as { microphoneRequests: number }).microphoneRequests)).toBe(1);
  expect(commands.filter(command => command === 'start')).toHaveLength(1);
  await page.getByRole('button', { name: 'Призупинити запис' }).click();
  await expect.poll(() => commands.includes('pause')).toBe(true);
  expect(await page.evaluate(() => (window as unknown as { simulatedTracks: MediaStreamTrack[] }).simulatedTracks.every(track => track.readyState === 'ended'))).toBe(true);
  const stoppedCount = packets.length;
  await page.waitForTimeout(300);
  expect(packets).toHaveLength(stoppedCount);
});
