import { expect, test, type Page } from '@playwright/test';

const lesson = { id: 1, course_id: 1, name: 'Reading fixture', course_name: 'Aerodynamics', status: 'live', translation_status: 'ready', archive_status: 'none', recorder_user_id: 9, recorder_epoch: 1, media_samples: 6400000, target_language: 'zh', version: 1 };
const units = Array.from({ length: 40 }, (_, i) => ({ id: i + 1, kind: i % 2 ? 'translation' : 'source', text: i % 2 ? '机翼上下表面的压力差产生升力。比较相同迎角下的翼型。' : 'The pressure difference creates lift. Compare the airfoils at the same angle of attack.', status: 'completed', start_sample: i * 16000, end_sample: (i + 1) * 16000, timing_precision: 'server_segment' }));
const courses = [{ id: 1, name: 'Aerodynamics', target_language: 'zh', guest_access: 'public' }, { id: 2, name: 'Next semester', target_language: 'zh', guest_access: 'public' }];

async function fixture(page: Page, admin = false) {
  const mutations: Array<{ path: string; body: Record<string, unknown> }> = [];
  let deliver: ((message: object) => void) | undefined;
  await page.routeWebSocket('ws://reading.fixture/**', ws => {
    deliver = message => ws.send(JSON.stringify(message));
    ws.onMessage(raw => {
      if (JSON.parse(String(raw)).type === 'subscribe') deliver?.({ type: 'snapshot', lesson, units, last_seq: 0 });
    });
  });
  await page.route('**/api/**', async route => {
    const request = route.request(), path = new URL(request.url()).pathname;
    if (request.method() !== 'GET') mutations.push({ path, body: request.postDataJSON() });
    const json = path === '/api/me' ? { user: admin ? { id: 7, name: 'Fixture admin', role: 'admin', must_change_password: false } : null, csrf_token: 'fixture', ws_url: 'ws://reading.fixture/ws' }
      : path === '/api/courses' ? { courses }
      : path === '/api/lessons/1' ? { lesson, units, last_seq: 0 }
      : path.endsWith('/ticket') ? { ticket: 'fixture' }
      : path === '/api/admin/courses' ? { courses: { data: courses, last_page: 1, total: 2 } }
      : path === '/api/admin/users' ? { users: { data: [], total: 0 } }
      : path.endsWith('/glossary/copy') ? { copied: 35, skipped: 2, version: 8 }
      : path.endsWith('/glossary') ? { entries: [], version: path.includes('/2/') ? 7 : 3, total: 0, limits: { max_entries: 1000, max_source_bytes: 256, max_target_bytes: 64 } }
      : {};
    await route.fulfill({ json });
  });
  return { mutations, send: (message: object) => { if (!deliver) throw new Error('Socket not connected'); deliver(message); } };
}

for (const language of ['zh-CN', 'en', 'uk']) {
  for (const width of [320, 390, 430]) {
    test(`mobile reading uses the viewport at ${width}px in ${language}`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height: 844 });
      await page.addInitScript(value => localStorage.setItem('classroom.locale', value), language);
      await fixture(page);
      await page.goto('/lessons/1');
      await expect(page.locator('.subtitle-unit')).toHaveCount(40);
      expect(await page.locator('.lesson-details').evaluate(el => (el as HTMLDetailsElement).open)).toBe(false);
      const rect = await page.locator('.captions').boundingBox();
      expect(rect!.y).toBeLessThan(844 * .33);
      expect(rect!.height).toBeGreaterThan(844 * .6);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: testInfo.outputPath('phone-reading.png') });
      await page.locator('.caption-controls button').first().click();
      await expect(page.locator('.app-shell')).toHaveClass(/large-mode/);
      const focused = await page.locator('.captions').boundingBox();
      expect(focused!.height).toBeGreaterThan(844 * .7);
      await page.screenshot({ path: testInfo.outputPath('focused-reading.png') });
      await page.setViewportSize({ width: 844, height: 390 });
      expect((await page.locator('.captions').boundingBox())!.height).toBeGreaterThan(390 * .6);
      await page.screenshot({ path: testInfo.outputPath('landscape-reading.png') });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    });
  }
}

test('both empty and filled descriptions can be submitted from home without repeated language controls', async ({ page }) => {
  const { mutations } = await fixture(page, true);
  await page.goto('/courses');
  for (const description of ['', '课程简介 Ї Є']) {
    await page.getByRole('button', { name: '+ 新建课程', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('combobox', { name: '界面语言' })).toHaveCount(0);
    await expect(dialog.getByRole('heading', { name: '新建课程', exact: true })).toHaveCount(1);
    await dialog.getByLabel('名称', { exact: true }).fill('Fixture course');
    await dialog.getByLabel('课程简介（可选）').fill(description);
    await dialog.getByRole('button', { name: '创建', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    expect(mutations.at(-1)?.body.description).toBe(description);
  }
});

test('scrolling back pauses follow and exiting focused reading preserves live follow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const { send } = await fixture(page);
  await page.goto('/lessons/1');
  const captions = page.locator('.captions');
  await expect(page.locator('.subtitle-unit')).toHaveCount(40);
  await expect.poll(() => captions.evaluate(el => el.scrollHeight - el.clientHeight - el.scrollTop)).toBeLessThan(2);
  await captions.evaluate(el => { el.scrollTop = 0; });
  await expect(page.locator('.follow-button')).toBeVisible();
  send({ type: 'subtitle.partial', unit: { ...units[0], id: 41, text: 'New streaming caption', status: 'streaming' } });
  await expect(page.getByText('New streaming caption', { exact: true })).toBeVisible();
  expect(await captions.evaluate(el => el.scrollTop)).toBe(0);
  await page.locator('.follow-button').click();
  await expect.poll(() => captions.evaluate(el => el.scrollHeight - el.clientHeight - el.scrollTop)).toBeLessThan(2);
  await page.getByRole('button', { name: '大字幕模式', exact: true }).click();
  await page.locator('.header-exit').click();
  await expect(page.locator('.follow-button')).toHaveCount(0);
  await expect.poll(() => captions.evaluate(el => el.scrollHeight - el.clientHeight - el.scrollTop)).toBeLessThan(2);
});

test('glossary copy loads the target version and reports copied and skipped counts', async ({ page }) => {
  const { mutations } = await fixture(page, true);
  await page.goto('/admin');
  await page.getByRole('button', { name: '专业术语表', exact: true }).click();
  await page.getByRole('button', { name: '复制词表到其他课程', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('目标课程').selectOption('2');
  await expect(dialog.getByText('目标已有 0 条词条，词表版本 7。')).toBeVisible();
  await dialog.getByRole('button', { name: '复制词表到其他课程', exact: true }).click();
  await expect(dialog).toHaveCount(0);
  expect(mutations.at(-1)).toEqual({ path: '/api/admin/courses/2/glossary/copy', body: { source_course_id: 1, expected_glossary_version: 7, confirmed: true } });
  await expect(page.getByRole('status')).toContainText('复制 35 条词条');
  await expect(page.getByRole('status')).toContainText('跳过 2 条');
});
