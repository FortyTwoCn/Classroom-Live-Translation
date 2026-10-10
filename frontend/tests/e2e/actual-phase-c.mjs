import { chromium, expect } from "@playwright/test";
import { readFile, mkdir, writeFile } from "node:fs/promises";
if (!process.argv[2])
  throw new Error("Pass isolated phase-c/phase-b-fixture.json.");
const fixture = JSON.parse(await readFile(process.argv[2], "utf8")),
  origin = process.env.ACTUAL_CAPTURE_URL || "http://localhost:18080",
  directory = new URL("../artifacts/actual-phase-c/", import.meta.url);
fixture.early_unit_id ??= fixture.first_unit_id;
fixture.purge_lesson_name ??= fixture.purge_name;
if (
  fixture.purge_lesson_id &&
  fixture.purge_lesson_id === fixture.playable_lesson_id
)
  throw new Error("Never purge a borrowed real archive.");
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ channel: "msedge", headless: true }),
  contexts = [],
  checks = [],
  errors = [];
let currentCheck = "initialization",
  savedPolicies;
let savedCourseDownload,
  privatePlaybackEvidence = null;
async function newPage(role) {
  const context = await browser.newContext();
  context.setDefaultTimeout(15000);
  contexts.push(context);
  const page = await context.newPage();
  page.on("pageerror", (error) =>
    errors.push(error.message.replace(/https?:\/\/\S+/g, "[redacted-url]")),
  );
  await page.addInitScript(() => {
    localStorage.setItem("classroom.locale", "zh-CN");
    window.__micRequests = 0;
    window.__sockets = [];
    window.__recoveries = [];
    window.__subscriptionSequences = [];
    const NativeSocket = WebSocket;
    window.WebSocket = class extends NativeSocket {
      constructor(...args) {
        super(...args);
        window.__sockets.push(this);
        this.addEventListener("message", (event) => {
          if (typeof event.data === "string") {
            const message = JSON.parse(event.data);
            if (message.type === "snapshot" && message.recovery)
              window.__recoveries.push(message.recovery);
          }
        });
      }
      send(data) {
        if (typeof data === "string") {
          const message = JSON.parse(data);
          if (message.type === "subscribe")
            window.__subscriptionSequences.push(message.last_seq);
        }
        return super.send(data);
      }
    };
    const original = navigator.mediaDevices.getUserMedia.bind(
      navigator.mediaDevices,
    );
    navigator.mediaDevices.getUserMedia = (constraints) => {
      window.__micRequests++;
      return original(constraints);
    };
  });
  await page.goto(`${origin}/courses`);
  await page.getByRole("button", { name: "登录", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog
    .getByLabel("学号", { exact: true })
    .fill(fixture.accounts[role].student_id);
  await dialog
    .getByLabel("密码", { exact: true })
    .fill(fixture.accounts[role].password);
  await dialog.getByRole("button", { name: "登录", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "退出当前设备", exact: true }),
  ).toBeVisible();
  return page;
}
async function mutation(context, path, body, method = "POST") {
  const identity = await (await context.request.get(`${origin}/api/me`)).json();
  return context.request.fetch(`${origin}${path}`, {
    method,
    headers: { "X-CSRF-TOKEN": identity.csrf_token },
    data: body,
  });
}
async function adminTab(page, name) {
  await page.goto(`${origin}/admin`);
  await page
    .getByRole("navigation", { name: "管理后台", exact: true })
    .getByRole("button", { name, exact: true })
    .click();
}
async function check(name, callback) {
  currentCheck = name;
  await callback();
  checks.push({ name, status: "passed" });
  console.log(`PASS ${name}`);
}
async function browserRecovery() {
  const records = [];
  for (const context of contexts)
    for (const page of context.pages())
      records.push(
        await page.evaluate(() => ({
          recoveries: window.__recoveries,
          subscriptionSequences: window.__subscriptionSequences,
        })),
      );
  return records;
}
async function saveSettings(page) {
  await page
    .locator(".settings-form")
    .getByRole("button", { name: "保存", exact: true })
    .click();
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "确认", exact: true })
    .click();
}
try {
  const first = await newPage("admin"),
    second = await newPage("admin");
  savedPolicies = (
    await (
      await first.context().request.get(`${origin}/api/admin/settings`)
    ).json()
  ).policies;
  await check(
    "actual settings masks secrets and blank-secret saves preserve configuration",
    async () => {
      await adminTab(first, "系统设置");
      await expect(
        first.getByRole("heading", { name: "系统设置", exact: true }),
      ).toBeVisible();
      await expect(first.getByLabel("API Key", { exact: true })).toHaveValue(
        "",
      );
      const before = await (
        await first.context().request.get(`${origin}/api/admin/settings`)
      ).json();
      expect(before.model.api_key).toBeUndefined();
      expect(before.oss.secret).toBeUndefined();
      await saveSettings(first);
      await expect(
        first.getByRole("status").filter({ hasText: "已保存" }),
      ).toBeVisible();
      const after = await (
        await first.context().request.get(`${origin}/api/admin/settings`)
      ).json();
      expect(after.model.api_key_set).toBe(before.model.api_key_set);
      expect(after.versions.model).not.toBe(before.versions.model);
      expect(
        await first.getByLabel("API Key", { exact: true }).inputValue(),
      ).toBe("");
      await expect(
        first.getByRole("button", { name: "运行真实连接测试", exact: true }),
      ).toBeDisabled();
    },
  );
  await check(
    "actual settings version conflict retains draft and reloads latest policy without secret exposure",
    async () => {
      await adminTab(second, "系统设置");
      await first
        .getByRole("button", { name: "保留与用量策略", exact: true })
        .click();
      await second
        .getByRole("button", { name: "保留与用量策略", exact: true })
        .click();
      await first
        .getByLabel("录音保留天数（0 为永久）", { exact: true })
        .fill("45");
      await second
        .getByLabel("录音保留天数（0 为永久）", { exact: true })
        .fill("55");
      await saveSettings(first);
      await expect(
        first.getByRole("status").filter({ hasText: "已保存" }),
      ).toBeVisible();
      await saveSettings(second);
      await expect(
        second.getByText("其他管理员已修改设置，请载入最新版本。", {
          exact: true,
        }),
      ).toBeVisible();
      await expect(
        second.getByLabel("录音保留天数（0 为永久）", { exact: true }),
      ).toHaveValue("55");
      await second
        .getByRole("button", { name: "载入最新版本", exact: true })
        .click();
      await expect(
        second.getByLabel("录音保留天数（0 为永久）", { exact: true }),
      ).toHaveValue("45");
      await first.screenshot({
        path: new URL(
          "actual-http-settings-desktop-zh.png",
          directory,
        ).pathname.replace(/^\/([A-Za-z]:)/, "$1"),
        fullPage: true,
      });
    },
  );
  await check(
    "actual glossary UTF-8 limits, create, concurrent conflict, delete and restore",
    async () => {
      await adminTab(first, "专业术语表");
      await first
        .locator(".filter-bar select")
        .first()
        .selectOption(String(fixture.course_id));
      await first
        .getByRole("button", { name: "添加术语", exact: true })
        .click();
      let dialog = first.getByRole("dialog");
      await dialog
        .getByLabel("原文术语", { exact: true })
        .fill(`explicit-airfoil-${Date.now()}`);
      await dialog
        .getByLabel("译文术语", { exact: true })
        .fill("翼".repeat(22));
      await expect(
        dialog.getByRole("button", { name: "保存", exact: true }),
      ).toBeDisabled();
      await dialog.getByLabel("译文术语", { exact: true }).fill("翼型");
      await dialog.getByRole("button", { name: "保存", exact: true }).click();
      await expect(first.getByRole("dialog")).toHaveCount(0);
      await adminTab(second, "专业术语表");
      await second
        .locator(".filter-bar select")
        .first()
        .selectOption(String(fixture.course_id));
      await second
        .getByRole("button", { name: "添加术语", exact: true })
        .click();
      dialog = second.getByRole("dialog");
      const phrase = `retained-draft-${Date.now()}`;
      await dialog.getByLabel("原文术语", { exact: true }).fill(phrase);
      await dialog.getByLabel("译文术语", { exact: true }).fill("草稿");
      const glossary = await (
        await first
          .context()
          .request.get(
            `${origin}/api/admin/courses/${fixture.course_id}/glossary`,
          )
      ).json();
      const changed = await mutation(
        first.context(),
        `/api/admin/courses/${fixture.course_id}/glossary`,
        {
          source_language: "en",
          target_language: "zh",
          source_text: `other-admin-${Date.now()}`,
          target_text: "其他",
          enabled: true,
          note: "Explicit synthetic term, no model call",
          expected_glossary_version: glossary.version,
        },
      );
      expect(changed.ok()).toBe(true);
      await dialog.getByRole("button", { name: "保存", exact: true }).click();
      await expect(
        dialog.getByText("术语版本冲突，请载入最新版本。", { exact: true }),
      ).toBeVisible();
      await expect(dialog.getByLabel("原文术语", { exact: true })).toHaveValue(
        phrase,
      );
      await dialog
        .getByRole("button", { name: "载入最新版本", exact: true })
        .click();
      await expect(
        dialog.getByRole("button", { name: "保存", exact: true }),
      ).toBeEnabled();
      await dialog.getByRole("button", { name: "保存", exact: true }).click();
      await expect(second.getByRole("dialog")).toHaveCount(0);
      const row = second.getByRole("row").filter({ hasText: phrase });
      await row.getByRole("button", { name: "删除", exact: true }).click();
      await second
        .getByRole("dialog")
        .getByRole("button", { name: "确认", exact: true })
        .click();
      await expect(
        second.getByRole("row").filter({ hasText: phrase }),
      ).toHaveCount(0);
      await second.locator(".filter-bar select").last().selectOption("only");
      await second
        .locator(".filter-bar")
        .getByRole("button", { name: "搜索", exact: true })
        .click();
      await second
        .getByRole("row")
        .filter({ hasText: phrase })
        .getByRole("button", { name: "恢复术语", exact: true })
        .click();
      await second
        .getByRole("dialog")
        .getByRole("button", { name: "确认", exact: true })
        .click();
      await expect(
        second.getByRole("row").filter({ hasText: phrase }),
      ).toHaveCount(0);
    },
  );
  await check(
    "actual usage shows unknown costs with no prices and operational diagnostics load",
    async () => {
      await adminTab(first, "用量与费用");
      await expect(
        first.getByText("未配置价格，费用未知。", { exact: true }),
      ).toBeVisible();
      await expect(
        first.getByText(
          "费用为估算，不能替代官方账单。不同币种分别显示，不换算相加。",
          { exact: true },
        ),
      ).toBeVisible();
      await adminTab(first, "运行诊断");
      await expect(
        first.getByRole("heading", { name: "最近操作记录", exact: true }),
      ).toBeVisible();
    },
  );
  await check(
    "C admin pages localize immediately in English/Ukrainian mobile with no overflow or microphone",
    async () => {
      await adminTab(first, "系统设置");
      await first
        .getByRole("button", { name: "保留与用量策略", exact: true })
        .click();
      await first.setViewportSize({ width: 390, height: 844 });
      for (const language of ["en", "uk", "zh-CN"]) {
        await first.locator(".site-header select").selectOption(language);
        expect(
          await first.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
          ),
        ).toBe(true);
        await first.screenshot({
          path: new URL(
            `actual-http-settings-mobile-${language}.png`,
            directory,
          ).pathname.replace(/^\/([A-Za-z]:)/, "$1"),
          fullPage: true,
        });
      }
      expect(await first.evaluate(() => window.__micRequests)).toBe(0);
    },
  );
  if (fixture.early_unit_id && fixture.ended_lesson_id) {
    const member = await newPage("member");
    const early = await (
      await first
        .context()
        .request.get(
          `${origin}/api/lessons/${fixture.ended_lesson_id}/subtitles?around_id=${fixture.early_unit_id}`,
        )
    ).json();
    const earlyUnit = early.units.find(
      (unit) => String(unit.id) === String(fixture.early_unit_id),
    );
    const initialLesson = await (
      await first
        .context()
        .request.get(`${origin}/api/lessons/${fixture.ended_lesson_id}`)
    ).json();
    if (initialLesson.last_seq === 0) {
      const seedEvent = await mutation(
        first.context(),
        `/api/lessons/${fixture.ended_lesson_id}/subtitles`,
        {
          changes: [
            {
              unit_id: earlyUnit.id,
              expected_version: earlyUnit.version,
              text: earlyUnit.text,
            },
          ],
          idempotency_key: crypto.randomUUID(),
        },
        "PATCH",
      );
      expect(seedEvent.ok()).toBe(true);
    }
    await check(
      "actual course UTF-8 search links to the exact historical unit without microphone/autoplay",
      async () => {
        await member.goto(`${origin}/courses/${fixture.course_id}/lessons`);
        await member
          .getByText("搜索课程内全部课堂字幕", { exact: true })
          .click();
        await member
          .getByLabel("搜索字幕关键词", { exact: true })
          .fill(earlyUnit.text);
        await member
          .locator(".course-search")
          .getByRole("button", { name: "搜索", exact: true })
          .click();
        const result = member
          .locator(".course-search article")
          .filter({ hasText: earlyUnit.text })
          .first();
        await expect(result).toBeVisible();
        await result
          .getByRole("button", { name: "打开课堂并定位片段", exact: true })
          .click();
        await expect(member).toHaveURL(
          new RegExp(`/lessons/${fixture.ended_lesson_id}\\?unit_id=`),
        );
        await expect(
          member.locator(`[data-unit-id="${fixture.early_unit_id}"]`),
        ).toBeVisible();
        expect(await member.evaluate(() => window.__micRequests)).toBe(0);
        if (await member.locator("audio").count())
          expect(
            await member.locator("audio").evaluate((audio) => audio.paused),
          ).toBe(true);
      },
    );
    await check(
      "actual reconnect replays corrections outside the latest-200 snapshot and preserves loaded history",
      async () => {
        const cached = await (
          await first
            .context()
            .request.get(
              `${origin}/api/lessons/${fixture.ended_lesson_id}/subtitles?around_id=${fixture.early_unit_id}`,
            )
        ).json();
        const unit = cached.units.find(
          (item) => String(item.id) === String(fixture.early_unit_id),
        );
        await member
          .locator(`[data-unit-id="${unit.id}"]`)
          .getByRole("button", { name: "校对", exact: true })
          .click();
        const draft = `Retained unsaved draft ІЇЄҐ ${Date.now()}`;
        await member.getByRole("dialog").locator("textarea").fill(draft);
        await member.context().setOffline(true);
        await member.evaluate(() =>
          window.__sockets.forEach((socket) => socket.close()),
        );
        const changedText = `Actual HTTP correction, explicit synthetic text ІЇЄҐ ${Date.now()}`;
        const result = await mutation(
          first.context(),
          `/api/lessons/${fixture.ended_lesson_id}/subtitles`,
          {
            changes: [
              {
                unit_id: unit.id,
                expected_version: unit.version,
                text: changedText,
              },
            ],
            idempotency_key: crypto.randomUUID(),
          },
          "PATCH",
        );
        expect(result.ok()).toBe(true);
        await member.context().setOffline(false);
        await expect(
          member.getByRole("dialog").locator("textarea"),
        ).toHaveValue(draft);
        await expect
          .poll(
            () =>
              member.evaluate(() =>
                window.__recoveries.some(
                  (recovery) =>
                    recovery.mode === "replay" && recovery.count > 0,
                ),
              ),
            { timeout: 20000 },
          )
          .toBe(true);
        await expect(
          member.getByRole("dialog").locator("textarea"),
        ).toHaveValue(draft);
        await member
          .getByRole("dialog")
          .getByRole("button", { name: "取消", exact: true })
          .click();
        await expect(
          member.locator(`[data-unit-id="${unit.id}"] p`),
        ).toHaveText(changedText, { timeout: 20000 });
        await expect(member.getByText(/已补回.*遗漏事件/)).toBeVisible();
        expect(await member.evaluate(() => window.__micRequests)).toBe(0);
      },
    );
    if (fixture.playable_lesson_id) {
      await check(
        "real OSS borrowed archive decodes; rate, highlight, at_sample and dual download authorization use actual APIs",
        async () => {
          const audio = member.locator("audio");
          await expect(audio).toHaveCount(1);
          await expect
            .poll(
              () =>
                audio.evaluate(
                  (element) => element.readyState >= 1 && element.duration > 17,
                ),
              { timeout: 30000 },
            )
            .toBe(true);
          expect(await audio.evaluate((element) => element.paused)).toBe(true);
          await member
            .getByLabel("播放倍速", { exact: true })
            .selectOption("1.5");
          expect(await audio.evaluate((element) => element.playbackRate)).toBe(
            1.5,
          );
          await audio.evaluate((element) => {
            element.currentTime = 10;
          });
          await expect
            .poll(() => member.locator(".playback-active").count(), {
              timeout: 10000,
            })
            .toBeGreaterThan(0);
          const matched = await (
            await member
              .context()
              .request.get(
                `${origin}/api/lessons/${fixture.playable_lesson_id}/subtitles?at_sample=160000`,
              )
          ).json();
          expect(matched.units.length).toBeGreaterThan(0);
          const selected = matched.units.filter(
            (unit) => unit.start_sample <= 160000 && unit.end_sample > 160000,
          );
          expect(selected.length).toBeGreaterThan(0);
          for (const unit of selected)
            await expect(
              member.locator(`[data-unit-id="${unit.id}"]`),
            ).toHaveClass(/playback-active/);
          await member
            .getByLabel("跟随原声高亮片段", { exact: true })
            .uncheck();
          const before = await member
            .locator(".captions")
            .evaluate((element) => element.scrollTop);
          await audio.evaluate((element) => {
            element.currentTime = 12;
          });
          await expect
            .poll(() => audio.evaluate((element) => element.currentTime))
            .toBe(12);
          expect(
            await member
              .locator(".captions")
              .evaluate((element) => element.scrollTop),
          ).toBe(before);
          await member.screenshot({
            path: new URL(
              "actual-http-synthetic-index-real-oss-playback.png",
              directory,
            ).pathname.replace(/^\/([A-Za-z]:)/, "$1"),
            fullPage: true,
          });
          const initial = (
            await (
              await first
                .context()
                .request.get(
                  `${origin}/api/lessons/${fixture.playable_lesson_id}`,
                )
            ).json()
          ).lesson;
          savedCourseDownload = initial.allow_audio_download;
          const settings = await (
            await first.context().request.get(`${origin}/api/admin/settings`)
          ).json();
          const global = await mutation(
            first.context(),
            "/api/admin/settings/policies",
            {
              values: { audio_download: true },
              expected_version: settings.versions.policies,
              confirmed: true,
            },
            "PUT",
          );
          expect(global.ok()).toBe(true);
          const enabled = await mutation(
            first.context(),
            `/api/admin/courses/${fixture.course_id}`,
            { allow_audio_download: true },
            "PATCH",
          );
          expect(enabled.ok()).toBe(true);
          await member.reload();
          await expect(
            member.getByRole("link", { name: "下载课堂原声", exact: true }),
          ).toBeVisible();
          const redirect = await member
            .context()
            .request.get(
              `${origin}/api/lessons/${fixture.playable_lesson_id}/download`,
              { maxRedirects: 0 },
            );
          expect(redirect.status()).toBe(302);
          const range = await member
            .context()
            .request.get(redirect.headers().location, {
              headers: { Range: "bytes=0-1023" },
            });
          expect(range.status()).toBe(206);
          expect((await range.body()).length).toBe(1024);
          expect(range.headers()["content-disposition"]).toContain(
            "attachment",
          );
          privatePlaybackEvidence = {
            real_oss: true,
            borrowed_archive_read_only: true,
            synthetic_audio: true,
            synthetic_subtitle_index: true,
            durationSeconds: await member
              .locator("audio")
              .evaluate((element) => element.duration),
            rangeStatus: 206,
            rangeBytes: 1024,
            downloadAttachment: true,
          };
          await mutation(
            first.context(),
            `/api/admin/courses/${fixture.course_id}`,
            { allow_audio_download: false },
            "PATCH",
          );
          await member.reload();
          await expect(
            member.getByRole("link", { name: "下载课堂原声", exact: true }),
          ).toHaveCount(0);
          expect(
            (
              await member
                .context()
                .request.get(
                  `${origin}/api/lessons/${fixture.playable_lesson_id}/download`,
                  { maxRedirects: 0 },
                )
            ).status(),
          ).toBe(403);
          const guest = await browser.newContext();
          try {
            expect(
              (
                await guest.request.get(
                  `${origin}/api/lessons/${fixture.playable_lesson_id}/download`,
                  { maxRedirects: 0 },
                )
              ).status(),
            ).toBe(401);
          } finally {
            await guest.close();
          }
        },
      );
    }
  }
  if (fixture.purge_lesson_id) {
    await check(
      "actual permanent cleanup requires exact name and awaits the isolated worker",
      async () => {
        await adminTab(first, "课堂管理");
        await first.getByLabel("已删除课堂", { exact: true }).check();
        await first
          .getByPlaceholder("搜索课堂名称", { exact: true })
          .fill(fixture.purge_lesson_name);
        await first
          .locator(".filter-bar")
          .getByRole("button", { name: "搜索", exact: true })
          .click();
        const row = first
          .locator(".course-card")
          .filter({ hasText: fixture.purge_lesson_name });
        await row
          .getByRole("button", { name: "永久清理课堂", exact: true })
          .click();
        const dialog = first.getByRole("dialog");
        await expect(
          dialog.getByRole("button", { name: "确认", exact: true }),
        ).toBeDisabled();
        await dialog
          .getByLabel("输入完整课堂名", { exact: true })
          .fill("wrong name");
        await expect(
          dialog.getByRole("button", { name: "确认", exact: true }),
        ).toBeDisabled();
        await dialog
          .getByLabel("输入完整课堂名", { exact: true })
          .fill(fixture.purge_lesson_name);
        await dialog.getByRole("button", { name: "确认", exact: true }).click();
        await expect(first.getByRole("dialog")).toHaveCount(0);
        await expect(row.getByText("已永久清理", { exact: true })).toBeVisible({
          timeout: 30000,
        });
        await expect(
          row.getByRole("button", { name: "恢复课堂", exact: true }),
        ).toBeDisabled();
      },
    );
  }
  expect(errors).toEqual([]);
  for (const context of contexts)
    for (const page of context.pages())
      expect(await page.evaluate(() => window.__micRequests)).toBe(0);
  await writeFile(
    new URL("phase-c-results.json", directory),
    JSON.stringify(
      {
        classification: "ACTUAL_PHP_POSTGRESQL_C_UI_SYNTHETIC_CONTENT",
        status: "passed",
        testedAt: new Date().toISOString(),
        checks,
        realPhp: true,
        realPostgreSql: true,
        httpMocks: false,
        webSocketMocks: false,
        real_model: false,
        real_oss: !!privatePlaybackEvidence,
        privatePlaybackEvidence,
        real_microphone: false,
        providerUsage: "explicit fixture or unknown; not a billing claim",
        microphoneRequests: 0,
        jsErrors: errors,
        browserRecovery: await browserRecovery(),
      },
      null,
      2,
    ),
  );
} catch (error) {
  await writeFile(
    new URL("phase-c-failure.json", directory),
    JSON.stringify(
      {
        classification: "ACTUAL_PHP_POSTGRESQL_C_UI_SYNTHETIC_CONTENT",
        status: "failed",
        currentCheck,
        browserRecovery: await browserRecovery(),
        message: String(error).replace(/https?:\/\/\S+/g, "[redacted-url]"),
        checks,
        jsErrors: errors,
      },
      null,
      2,
    ),
  );
  throw error;
} finally {
  if (savedPolicies && contexts[0]) {
    try {
      const current = await (
        await contexts[0].request.get(`${origin}/api/admin/settings`)
      ).json();
      await mutation(
        contexts[0],
        "/api/admin/settings/policies",
        {
          values: savedPolicies,
          expected_version: current.versions.policies,
          confirmed: true,
        },
        "PUT",
      );
    } catch {}
  }
  if (savedCourseDownload !== undefined && contexts[0]) {
    try {
      await mutation(
        contexts[0],
        `/api/admin/courses/${fixture.course_id}`,
        { allow_audio_download: savedCourseDownload },
        "PATCH",
      );
    } catch {}
  }
  await browser.close();
}
