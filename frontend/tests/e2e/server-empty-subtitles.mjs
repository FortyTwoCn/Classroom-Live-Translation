import { chromium, expect } from "@playwright/test";
import { mkdir, writeFile } from "node:fs/promises";

// Read-only public lesson check. No transcript text, tickets or cookies in reports.
const origin = process.env.ACTUAL_CAPTURE_URL || "https://t.xuanknow.cn";
const lessonId = Number(process.argv[2]);
if (!Number.isSafeInteger(lessonId) || lessonId < 1) throw new Error("Provide an accessible public lesson ID");
const browser = await chromium.launch({ channel: "msedge", headless: true });
const report = { classification: "REAL_PUBLIC_HTTP_WSS_BROWSER_READ_ONLY", tested_at: new Date().toISOString(), new_real_qwen_call: false, real_oss_request: false, physical_device: false, httpMocks: false, webSocketMocks: false, lesson_id: lessonId, checks: [] };
const blank = (text) => !/[^\s\u0085\u00a0\u1680\u180e\u2000-\u200f\u2028-\u202f\u205f\u2060-\u206f\u3000\ufeff]/u.test(text);
try {
  const contexts = [];
  for (let i = 0; i < 2; i++) {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    contexts.push(context);
    const page = await context.newPage();
    let microphoneRequests = 0;
    await page.exposeFunction("reportForbiddenMicRequest", () => microphoneRequests++);
    await page.addInitScript(() => { navigator.mediaDevices.getUserMedia = async () => { await window.reportForbiddenMicRequest(); throw new Error("Read-only test"); }; });
    let snapshot = null;
    page.on("websocket", (socket) => socket.on("framereceived", (frame) => { try { const message = JSON.parse(String(frame.payload)); if (message.type === "snapshot") snapshot = message; } catch {} }));
    const apiResponse = await page.request.get(`${origin}/api/lessons/${lessonId}`);
    expect(apiResponse.status()).toBe(200);
    const apiData = await apiResponse.json();
    expect(apiData.units.length).toBeGreaterThan(0);
    expect(apiData.units.filter((unit) => blank(unit.text))).toHaveLength(0);
    const paginated = await page.request.get(`${origin}/api/lessons/${lessonId}/subtitles`);
    expect(paginated.status()).toBe(200);
    const history = await paginated.json();
    expect(history.units.filter((unit) => blank(unit.text))).toHaveLength(0);
    await page.goto(`${origin}/lessons/${lessonId}`);
    await expect(page.locator(".subtitle-unit")).toHaveCount(apiData.units.length);
    await expect.poll(() => snapshot?.units?.length || 0).toBe(apiData.units.length);
    expect(snapshot.units.filter((unit) => blank(unit.text))).toHaveLength(0);
    const ui = await page.locator(".subtitle-unit").evaluateAll((nodes) => ({ count: nodes.length, blank: nodes.filter((node) => !/[^\s\u0085\u00a0\u1680\u180e\u2000-\u200f\u2028-\u202f\u205f\u2060-\u206f\u3000\ufeff]/u.test(node.querySelector("p")?.textContent || "")).length }));
    expect(ui.blank).toBe(0);
    expect(microphoneRequests).toBe(0);
    report.checks.push({ client: i + 1, api_visible_units: apiData.units.length, history_visible_units: history.units.length, history_total: history.total, websocket_visible_units: snapshot.units.length, displayed_units: ui.count, blank_units: ui.blank, microphone_requests: microphoneRequests });
  }
  report.status = "passed";
} finally {
  await browser.close();
  if (!report.status) report.status = "failed";
  const output = new URL("../../../reports/server-empty-subtitles-browser.json", import.meta.url);
  await mkdir(new URL(".", output), { recursive: true });
  await writeFile(output, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
}
