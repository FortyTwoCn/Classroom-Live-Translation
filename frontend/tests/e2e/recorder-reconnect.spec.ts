import { expect, test } from '@playwright/test';

test('the same recording resumes with its first unacknowledged frame after a WebSocket drop', async ({ page, browserName }, testInfo) => {
  test.skip(browserName !== 'chromium', 'Exercises the Chromium microphone Web Audio fixture');
  const token = 'a'.repeat(64);
  const lesson = { id: 1, course_id: 1, name: '热力学课堂', course_name: '航空工程', status: 'ready',
    translation_status: 'idle', archive_status: 'pending', recorder_user_id: 9, recorder_epoch: 0,
    media_samples: 0, target_language: 'zh' };
  const live = { ...lesson, status: 'live', recorder_epoch: 1, translation_status: 'ready' };
  const units = [
    { id: 1, kind: 'source', text: 'We can regard the engine as a heat engine.', status: 'completed', start_sample: 0, end_sample: 16000, timing_precision: 'server_segment' },
    { id: 2, kind: 'translation', text: '我们可以把发动机看作一台热机。工质与周围环境交换热量。这个过程可以用热力学来解释。刚才的例子说明了能量是怎样传递的。'.repeat(3), status: 'completed', start_sample: 16000, end_sample: 32000, timing_precision: 'server_segment' },
  ];
  let connection = 0;
  let receivedToken = '';
  const replayed: number[] = [];
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => {
    localStorage.setItem('classroom.locale', 'zh-CN');
    navigator.mediaDevices.getUserMedia = async () => {
      const context = new AudioContext();
      const oscillator = context.createOscillator();
      const destination = context.createMediaStreamDestination();
      oscillator.connect(destination);
      oscillator.start();
      await context.resume();
      return destination.stream;
    };
  });
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname;
    const json = path === '/api/me'
      ? { user: { id: 9, name: '课堂管理员', role: 'admin', must_change_password: false }, csrf_token: 'fixture', ws_url: 'ws://recorder.fixture/ws' }
      : path === '/api/lessons/1' ? { lesson, units, last_seq: 0 }
      : path.endsWith('/ticket') ? { ticket: 'fixture' } : {};
    await route.fulfill({ json });
  });
  await page.routeWebSocket('ws://recorder.fixture/**', socket => {
    const attempt = ++connection;
    const send = (data: object) => socket.send(JSON.stringify(data));
    socket.onMessage(message => {
      if (typeof message === 'string') {
        const command = JSON.parse(message);
        if (command.type === 'start')
          send({ type: 'recorder.ready', lesson: live, lesson_seq: 1, epoch: 1, next_sequence: 0, recorder_token: token });
        if (command.type === 'subscribe') {
          if (attempt === 1) send({ type: 'snapshot', lesson, units, last_seq: 0 });
          else {
            receivedToken = command.recorder_token;
            send({ type: 'snapshot', lesson: live, units, last_seq: 1, recorder_resume: { epoch: 1, next_sequence: 3 } });
          }
        }
        return;
      }
      const sequence = message.readUInt32BE(4);
      if (attempt === 1 && sequence === 3) { socket.close({ code: 1001 }); return; }
      if (attempt > 1) replayed.push(sequence);
      send({ type: 'audio.ack', sequence, media_samples: (sequence + 1) * 1600 });
    });
  });
  await page.goto('/lessons/1');
  await expect(page.locator('.caption-controls')).toBeVisible();
  await expect(page.locator('[data-unit-id="2"] .unit-text p')).not.toHaveCount(1);
  await page.locator('.recorder-panel input[type="checkbox"]').check();
  await page.locator('.record-actions .primary').click();
  await expect(page.locator('.room-record-dock button')).toBeVisible();
  await expect.poll(() => receivedToken).toBe(token);
  await expect.poll(() => replayed.at(-1) ?? 0).toBeGreaterThan(6);
  expect(replayed[0]).toBe(3);
  expect(replayed.every((number, index) => number === index + 3)).toBe(true);
  await expect(page.locator('.room-recording')).toContainText('录音中');
  await page.evaluate(() => scrollTo(0, 0));
  await page.screenshot({ path: testInfo.outputPath('recording-room-mobile.png') });
});
