import { chromium, expect } from "@playwright/test";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";

// Read-only playback of an existing real Qwen/OSS synthetic-speech archive.
// No microphone, fresh audio, model request, or fabricated subtitle response.
const fixture = JSON.parse(await readFile(process.argv[2], "utf8"));
const scope = JSON.parse(await readFile(process.argv[3], "utf8"));
if (
  scope.temporary_user_id !== fixture.user_id ||
  scope.run_id !== fixture.run_id
)
  throw new Error("Owned fixture scope required");
const origin = process.env.ACTUAL_CAPTURE_URL || "https://t.xuanknow.cn";
const label = process.env.REAL_RUN_LABEL || "playback-after-fix";
if (!origin.startsWith("https://") || !/^[a-z0-9-]{1,40}$/.test(label))
  throw new Error("HTTPS and bounded artifact label required");
const directory = new URL(
  `../artifacts/server-deployment/${label}/`,
  import.meta.url,
);
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({
  channel: process.env.PLAYWRIGHT_CHROMIUM_CHANNEL || "msedge",
  headless: true,
});
const context = await browser.newContext();
const page = await context.newPage();
const emptyPlaybackWindows = [],
  jsErrors = [];
const report = {
  classification: "REAL_PUBLIC_OSS_ARCHIVED_QWEN_SUBTITLE_PLAYBACK_REGRESSION",
  tested_at: new Date().toISOString(),
  real_oss: true,
  stored_real_model_output: true,
  new_model_call: false,
  synthetic_audio: true,
  real_microphone: false,
  httpMocks: false,
  webSocketMocks: false,
  lesson_id: scope.lesson_id,
};
await page.addInitScript(() => {
  window.__microphoneRequests = 0;
  navigator.mediaDevices.getUserMedia = async () => {
    window.__microphoneRequests++;
    throw new Error("Ended classroom must not request microphone");
  };
});
page.on("pageerror", (error) =>
  jsErrors.push(error.message.replace(/https?:\/\/[^\s]+/g, "[redacted-url]")),
);
page.on("response", async (response) => {
  if (
    response.url().startsWith(origin + "/api/lessons/") &&
    response.url().includes("/subtitles?at_sample=") &&
    response.status() === 200
  ) {
    const data = await response.json();
    if (data.units.length === 0)
      emptyPlaybackWindows.push(
        Number(new URL(response.url()).searchParams.get("at_sample")),
      );
  }
});
try {
  await page.goto(origin + "/courses");
  await page.getByRole("button", { name: "登录", exact: true }).click();
  const login = page.getByRole("dialog");
  await login.getByLabel("学号", { exact: true }).fill(fixture.student_id);
  await login.getByLabel("密码", { exact: true }).fill(fixture.password);
  await login.getByRole("button", { name: "登录", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "退出当前设备", exact: true }),
  ).toBeVisible();
  await page.goto(origin + "/lessons/" + scope.lesson_id);
  await expect(page.getByText("课堂已连接", { exact: true })).toBeVisible();
  const saved = await (
    await context.request.get(origin + "/api/lessons/" + scope.lesson_id)
  ).json();
  const completed = saved.units.filter(
    (unit) => unit.status === "completed" && unit.text.trim(),
  );
  expect(saved.lesson.status).toBe("ended");
  expect(completed.length).toBeGreaterThanOrEqual(2);
  await expect(page.locator(".subtitle-unit")).toHaveCount(completed.length);
  const before = await page.locator(".subtitle-unit p").allTextContents();
  await page
    .getByRole("button", { name: "取得授权播放链接", exact: true })
    .click();
  const audio = page.locator("audio");
  await expect
    .poll(() => audio.evaluate((element) => element.readyState))
    .toBeGreaterThanOrEqual(1);
  await audio.evaluate(async (element) => {
    element.muted = true;
    await element.play();
  });
  await expect
    .poll(() => audio.evaluate((element) => element.currentTime))
    .toBeGreaterThan(0.3);
  await audio.evaluate((element) => element.pause());
  await expect.poll(() => emptyPlaybackWindows.length).toBeGreaterThan(0);
  await expect(page.locator(".subtitle-unit")).toHaveCount(completed.length);
  expect(await page.locator(".subtitle-unit p").allTextContents()).toEqual(
    before,
  );
  expect(await page.evaluate(() => window.__microphoneRequests)).toBe(0);
  expect(jsErrors).toEqual([]);
  Object.assign(report, {
    status: "passed",
    subtitle_count_before: completed.length,
    subtitle_count_after: await page.locator(".subtitle-unit").count(),
    empty_playback_window_samples: emptyPlaybackWindows,
    visible_texts_unchanged: true,
    microphone_requests: 0,
    jsErrors,
  });
  await page.screenshot({
    path: fileURLToPath(new URL("real-playback-subtitles.png", directory)),
    fullPage: true,
  });
  const me = await (await context.request.get(origin + "/api/me")).json();
  const disabled = await context.request.patch(
    origin + "/api/admin/courses/" + scope.course_id,
    {
      headers: {
        "X-CSRF-TOKEN": me.csrf_token,
        Origin: origin,
        Accept: "application/json",
      },
      data: { enabled: false },
    },
  );
  expect(disabled.status()).toBe(200);
  report.verification_course_disabled = true;
} catch (error) {
  Object.assign(report, {
    status: "failed",
    error: String(error.message)
      .replaceAll(fixture.password, "[redacted-password]")
      .replace(/https?:\/\/[^\s]+/g, "[redacted-url]"),
    subtitle_count_after: await page.locator(".subtitle-unit").count(),
    empty_playback_window_samples: emptyPlaybackWindows,
  });
  await page.screenshot({
    path: fileURLToPath(new URL("real-playback-subtitles.png", directory)),
    fullPage: true,
  });
  process.exitCode = 1;
} finally {
  await writeFile(
    new URL("regression.json", directory),
    JSON.stringify(report, null, 2),
  );
  console.log(JSON.stringify(report));
  await context.close();
  await browser.close();
}
