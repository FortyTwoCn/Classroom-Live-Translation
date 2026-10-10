import { chromium, expect } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

// Real application integration: no HTTP routes, fake database data, WebSocket
// fixture or model output is installed. Does not request microphone permission.
const origin = process.env.ACTUAL_HTTP_URL || 'http://localhost:8080';
const directory = new URL('../artifacts/actual-http/', import.meta.url);
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHROMIUM_CHANNEL || 'msedge', headless: true });
const errors = [], networkErrors = [], httpErrors = [], results = [];
try {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.addInitScript(() => {
    window.__actualMicRequests = 0;
    if (navigator.mediaDevices?.getUserMedia) {
      const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
      navigator.mediaDevices.getUserMedia = constraints => { window.__actualMicRequests++; return original(constraints); };
    }
  });
  page.on('pageerror', error => errors.push(error.message));
  page.on('requestfailed', request => networkErrors.push({ path: new URL(request.url()).pathname, error: request.failure()?.errorText }));
  page.on('response', response => { if (response.status() >= 400) httpErrors.push({ path: new URL(response.url()).pathname, status: response.status() }); });
  const expectedResponses = Promise.all([
    page.waitForResponse(response => new URL(response.url()).pathname === '/api/me'),
    page.waitForResponse(response => new URL(response.url()).pathname === '/api/courses')
  ]);
  const documentResponse = await page.goto(`${origin}/courses`);
  expect(documentResponse.status()).toBe(200);
  const [identityResponse, coursesResponse] = await expectedResponses;
  const identity = await identityResponse.json();
  const courses = await coursesResponse.json();
  expect(identity.user).toBeNull();
  expect(courses.courses).toEqual([]);
  const headings = { 'zh-CN': '课程', en: 'Courses', uk: 'Курси' };
  const emptyText = { 'zh-CN': '暂无可访问课程。管理员可创建课程。', en: 'No accessible courses. An administrator can create one.', uk: 'Немає доступних курсів. Адміністратор може створити курс.' };
  for (const [viewportName, viewport] of Object.entries({ desktop: { width: 1280, height: 900 }, mobile: { width: 390, height: 844 }, tablet: { width: 768, height: 1024 } })) {
    await page.setViewportSize(viewport);
    for (const language of ['zh-CN', 'en', 'uk']) {
      await page.locator('.site-header select').selectOption(language);
      await expect(page.getByRole('heading', { name: headings[language], exact: true })).toBeVisible();
      await expect(page.getByText(emptyText[language], { exact: true })).toBeVisible();
      await expect(page.locator('.recorder-panel')).toHaveCount(0);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      expect(overflow).toBe(false);
      expect(await page.evaluate(() => window.__actualMicRequests)).toBe(0);
      const name = `actual-empty-${language}-${viewportName}.png`;
      await page.screenshot({ path: fileURLToPath(new URL(name, directory)), fullPage: true });
      results.push({ language, viewport: viewportName, horizontalOverflow: false, microphoneRequests: 0, screenshot: name });
    }
  }
  expect(errors).toEqual([]); expect(networkErrors).toEqual([]); expect(httpErrors).toEqual([]);
  const report = { testedAt: new Date().toISOString(), origin, browser: browser.version(), backend: 'actual PHP application and PostgreSQL, accessible course list empty', routeMocks: false, fakeDatabaseData: false, microphoneRequests: 0, jsErrors: errors, failedRequests: networkErrors, httpErrors, cases: results, doesNotVerify: ['Qwen real model', 'OSS', 'hardware microphone', 'physical phones/tablets', 'classroom WebSocket capture', 'HTTPS/WSS production'] };
  await writeFile(new URL('actual-http-results.json', directory), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ result: 'passed', browser: report.browser, cases: results.length, microphoneRequests: 0, jsErrors: 0, failedRequests: 0, httpErrors: 0, modelAndOssVerified: false }));
} finally { await browser.close(); }
