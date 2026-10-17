import { chromium, expect } from "@playwright/test";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import jsQR from "jsqr";
import { PNG } from "pngjs";
if (!process.argv[2])
  throw new Error("Pass an isolated phase-b-fixture.json path.");
const fixture = JSON.parse(await readFile(process.argv[2], "utf8")),
  origin = process.env.ACTUAL_CAPTURE_URL || "http://localhost:18080";
const directory = new URL("../artifacts/actual-phase-b/", import.meta.url);
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({
  channel: process.env.PLAYWRIGHT_CHROMIUM_CHANNEL || "msedge",
  headless: true,
});
const corrected=`Corrected airfoil source. ІЇЄҐ 中文 ${Date.now()}`;
const contexts = [],
  checks = [],
  errors = [];
let currentCheck = "initialization";
const redact = (value) =>
  String(value).replace(/https?:\/\/[^\s"'<>]+/g, "[redacted-url]");
async function context() {
  const result = await browser.newContext({ acceptDownloads: true });
  result.setDefaultTimeout(15000);
  contexts.push(result);
  return result;
}
async function page(context) {
  const result = await context.newPage();
  await result.addInitScript(() => {
    window.__micRequests = 0;
    const original = navigator.mediaDevices.getUserMedia.bind(
      navigator.mediaDevices,
    );
    navigator.mediaDevices.getUserMedia = (constraints) => {
      window.__micRequests++;
      return original(constraints);
    };
    Object.defineProperty(navigator, "clipboard", {
      value: {
        writeText: async () => {
          throw new Error("explicit clipboard denial fixture");
        },
      },
      configurable: true,
    });
  });
  result.on("pageerror", (error) => errors.push(redact(error.message)));
  return result;
}
async function login(page, role, remember = false) {
  await page.goto(`${origin}/courses`);
  await page.getByRole("button", { name: "登录", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog
    .getByLabel("学号", { exact: true })
    .fill(fixture.accounts[role].student_id);
  await dialog
    .getByLabel("密码", { exact: true })
    .fill(fixture.accounts[role].password);
  if (remember)
    await dialog
      .getByRole("checkbox", { name: "90 天保持登录", exact: true })
      .check();
  await dialog.getByRole("button", { name: "登录", exact: true }).click();
  if (role !== "forced")
    await expect(
      page.getByRole("button", { name: "退出当前设备", exact: true }),
    ).toBeVisible();
}
async function mutation(context, path, body, method = "POST") {
  const identity = await (await context.request.get(`${origin}/api/me`)).json();
  return context.request.fetch(`${origin}${path}`, {
    method,
    headers: { "X-CSRF-TOKEN": identity.csrf_token },
    data: body,
  });
}
async function check(name, operation) {
  currentCheck = name;
  await operation();
  checks.push({ name, status: "passed" });
  console.log(`PASS ${name}`);
}
async function decodeCanvas(canvas) {
  const pixels = await canvas.evaluate((element) => {
    const context = element.getContext("2d");
    return {
      data: Array.from(
        context.getImageData(0, 0, element.width, element.height).data,
      ),
      width: element.width,
      height: element.height,
    };
  });
  return jsQR(new Uint8ClampedArray(pixels.data), pixels.width, pixels.height)
    ?.data;
}
try {
  const memberContext = await context(),
    guestContext = await context(),
    secondContext = await context(),
    adminContext = await context(),
    forcedContext = await context();
  const member = await page(memberContext),
    guest = await page(guestContext),
    second = await page(secondContext),
    admin = await page(adminContext),
    forced = await page(forcedContext);
  await login(admin,"admin");
  await mutation(adminContext,`/api/admin/users/${fixture.accounts.forced.id}/reset-password`,{confirm:true});
  await check("90-day remember login and device list", async () => {
    await login(member, "member", true);
    const identity = await (
      await memberContext.request.get(`${origin}/api/me`)
    ).json();
    expect(identity.user.id).toBe(fixture.accounts.member.id);
    const devices = await (
      await memberContext.request.get(`${origin}/api/devices`)
    ).json();
    expect(devices.devices.some((device) => device.current)).toBe(true);
    await member
      .getByRole("button", { name: "账户与设备", exact: true })
      .click();
    await expect(
      member.getByRole("dialog").getByText("当前设备", { exact: true }),
    ).toBeVisible();
    await member
      .getByRole("dialog")
      .getByRole("button", { name: "关闭", exact: true })
      .click();
  });
  await check(
    "forced password dialog cannot escape and server rejects export",
    async () => {
      await login(forced, "forced");
      const dialog = forced.getByRole("dialog");
      await expect(
        dialog
          .getByRole("heading", { name: "必须修改初始密码", exact: true })
          .first(),
      ).toBeVisible();
      await forced.keyboard.press("Escape");
      await expect(dialog).toBeVisible();
      expect(
        await dialog.getByRole("button", { name: "关闭", exact: true }).count(),
      ).toBe(0);
      const denied = await mutation(
        forcedContext,
        `/api/lessons/${fixture.ended_lesson_id}/exports`,
        {
          format: "txt",
          layout: "bilingual",
          text_version: "current",
          idempotency_key: crypto.randomUUID(),
        },
      );
      expect(denied.status()).toBe(403);
      await dialog
        .getByLabel("新密码", { exact: true })
        .fill("Fixture-new-password-2026");
      await dialog
        .getByRole("button", { name: "保存新密码", exact: true })
        .click();
      await expect(dialog).toHaveCount(0);
      await forced.reload();
      expect(
        (await (await forcedContext.request.get(`${origin}/api/me`)).json())
          .user.must_change_password,
      ).toBe(false);
    },
  );
  await login(second, "member");
  await member.goto(`${origin}/lessons/${fixture.ended_lesson_id}`);
  await second.goto(`${origin}/lessons/${fixture.ended_lesson_id}`);
  await guest.goto(`${origin}/lessons/${fixture.ended_lesson_id}`);
  await expect(guest.getByText("课堂已连接", { exact: true })).toBeVisible();
  await check(
    "local QR exact decode, image save, denied-copy fallback, focus restore",
    async () => {
      const trigger = member.getByRole("button", {
        name: "分享课堂",
        exact: true,
      });
      await trigger.click();
      const dialog = member.getByRole("dialog");
      await expect(dialog.locator("canvas")).toBeVisible();
      const link = await dialog
        .getByLabel("本课堂访问链接", { exact: true })
        .inputValue();
      expect(new URL(link).pathname).toBe(
        `/lessons/${fixture.ended_lesson_id}`,
      );
      expect(await decodeCanvas(dialog.locator("canvas"))).toBe(link);
      await dialog
        .getByRole("button", { name: "复制链接", exact: true })
        .click();
      await expect(
        dialog.getByText("复制失败，请选中下面链接手动复制。", { exact: true }),
      ).toBeVisible();
      expect(
        await dialog
          .getByLabel("本课堂访问链接", { exact: true })
          .evaluate((input) => input.selectionEnd - input.selectionStart),
      ).toBe(link.length);
      const downloadPromise = member.waitForEvent("download");
      await dialog
        .getByRole("button", { name: "保存二维码", exact: true })
        .click();
      const download = await downloadPromise;
      const path = fileURLToPath(
        new URL("actual-public-qr-with-names.png", directory),
      );
      await download.saveAs(path);
      const image = PNG.sync.read(await readFile(path));
      expect(
        jsQR(new Uint8ClampedArray(image.data), image.width, image.height)
          ?.data,
      ).toBe(link);
      expect(image.height).toBeGreaterThan(720);
      await dialog.getByRole("button", { name: "关闭", exact: true }).last().click();
      await expect(trigger).toBeFocused();
    },
  );
  await check(
    "optimistic correction conflict retains draft and broadcasts saved text",
    async () => {
      await member
        .locator(".subtitle-unit.source")
        .getByRole("button", { name: "校对", exact: true })
        .first()
        .click();
      await second
        .locator(".subtitle-unit.source")
        .getByRole("button", { name: "校对", exact: true })
        .first()
        .click();
      const firstDialog = member.getByRole("dialog"),
        secondDialog = second.getByRole("dialog");
      await firstDialog
        .locator("textarea")
        .fill(corrected);
      await secondDialog
        .locator("textarea")
        .fill("My concurrent draft kept. ІЇЄҐ");
      await firstDialog
        .getByRole("button", { name: "保存", exact: true })
        .click();
      await expect(guest.locator(".subtitle-unit.source p")).toContainText(
        "Corrected airfoil source.",
      );
      await secondDialog
        .getByRole("button", { name: "保存", exact: true })
        .click();
      await expect(
        secondDialog.getByRole("heading", { name: "校对冲突", exact: true }),
      ).toBeVisible();
      await expect(secondDialog.locator("textarea")).toHaveValue(
        "My concurrent draft kept. ІЇЄҐ",
      );
      await expect(
        secondDialog.getByText(corrected, {
          exact: true,
        }),
      ).toBeVisible();
      await secondDialog
        .getByRole("button", { name: "取消", exact: true })
        .click();
      await member
        .locator(".subtitle-unit.source")
        .getByRole("button", { name: "校对历史", exact: true })
        .first()
        .click();
      const history = member.getByRole("dialog");
      await expect(
        history
          .getByText(corrected, { exact: true })
          .first(),
      ).toBeVisible();
      await expect(
        history.getByRole("button", { name: "恢复模型原始版本", exact: true }),
      ).toBeVisible();
      await history.getByRole("button", { name: "关闭", exact: true }).last().click();
    },
  );
  await check(
    "four actual export files and current/original text difference",
    async () => {
      const files = {};
      for (const format of ["txt", "md", "srt", "vtt"]) {
        await member
          .getByRole("button", { name: "导出字幕", exact: true })
          .click();
        const dialog = member.getByRole("dialog");
        await dialog
          .getByLabel("文件格式", { exact: true })
          .selectOption(format);
        await dialog
          .getByRole("button", { name: "确认导出", exact: true })
          .click();
        const link = dialog.getByRole("link", {
          name: "下载文件",
          exact: true,
        });
        await expect(link).toBeVisible({ timeout: 30000 });
        const path = await link.getAttribute("href");
        const response = await memberContext.request.get(`${origin}${path}`);
        expect(response.status()).toBe(200);
        const text = await response.text();
        files[format] = text;
        await writeFile(
          new URL(`actual-synthetic-content-current.${format}`, directory),
          text,
        );
        if (format === "txt" || format === "md")
          expect(text).toContain("Corrected airfoil source.");
        if (format === "srt")
          expect(text).toMatch(/\d+\r?\n\d{2}:\d{2}:\d{2},\d{3} -->/);
        if (format === "vtt") expect(text.startsWith("WEBVTT")).toBe(true);
        expect(text).toContain("ІЇЄҐ");
        await dialog.getByRole("button", { name: "关闭", exact: true }).last().click();
      }
      await member
        .getByRole("button", { name: "导出字幕", exact: true })
        .click();
      const dialog = member.getByRole("dialog");
      await dialog
        .getByLabel("文本版本", { exact: true })
        .selectOption("original");
      await dialog
        .getByRole("button", { name: "确认导出", exact: true })
        .click();
      const download = dialog.getByRole("link", {
        name: "下载文件",
        exact: true,
      });
      await expect(download).toBeVisible({ timeout: 30000 });
      const original = await (
        await memberContext.request.get(
          `${origin}${await download.getAttribute("href")}`,
        )
      ).text();
      expect(original).not.toContain("Corrected airfoil source.");
      expect(original).not.toBe(files.txt);
      await writeFile(
        new URL("actual-synthetic-content-original.txt", directory),
        original,
      );
      await dialog.getByRole("button", { name: "关闭", exact: true }).last().click();
    },
  );
  await check("restore original produces a new revision", async () => {
    const before = await (
      await memberContext.request.get(
        `${origin}/api/lessons/${fixture.ended_lesson_id}/subtitles/${fixture.units.source}/history`,
      )
    ).json();
    await member
      .locator(".subtitle-unit.source")
      .getByRole("button", { name: "校对历史", exact: true })
      .first()
      .click();
    const dialog = member.getByRole("dialog");
    await dialog
      .getByRole("button", { name: "恢复模型原始版本", exact: true })
      .click();
    await expect
      .poll(async () => {
        const data = await (
          await memberContext.request.get(
            `${origin}/api/lessons/${fixture.ended_lesson_id}/subtitles/${fixture.units.source}/history`,
          )
        ).json();
        return data.revisions.length;
      })
      .toBe(before.revisions.length + 1);
    await dialog.getByRole("button", { name: "关闭", exact: true }).last().click();
  });
  await check(
    "search/sort persists in route and rename keeps same classroom",
    async () => {
      await member.goto(`${origin}/courses/${fixture.course_id}/lessons`);
      await member
        .getByPlaceholder("搜索课堂名称", { exact: true })
        .fill("字幕导出");
      await member
        .getByLabel("排序字段", { exact: true })
        .selectOption("duration");
      await member.getByLabel("排序方向", { exact: true }).selectOption("asc");
      await member.getByRole("button", { name: "搜索", exact: true }).click();
      expect(new URL(member.url()).searchParams.get("sort")).toBe("duration");
      await member.reload();
      await expect(member.getByLabel("排序字段", { exact: true })).toHaveValue(
        "duration",
      );
      await member.goto(`${origin}/lessons/${fixture.ended_lesson_id}`);
      await member
        .getByRole("button", { name: "重命名课堂", exact: true })
        .click();
      const dialog = member.getByRole("dialog");
      await dialog
        .getByLabel("名称", { exact: true })
        .fill("Renamed exact lesson ІЇЄҐ");
      await dialog.getByRole("button", { name: "保存", exact: true }).click();
      await expect(
        guest.getByRole("heading", {
          name: "Renamed exact lesson ІЇЄҐ",
          exact: true,
        }),
      ).toBeVisible();
    },
  );
  await check(
    "three-language large subtitles, size preferences, viewport and dialog focus",
    async () => {
      await guest.setViewportSize({ width: 390, height: 844 });
      await guest
        .getByRole("button", { name: "大字幕模式", exact: true })
        .click();
      for (const language of ["zh-CN", "en", "uk"]) {
        await guest.locator(".site-header select").selectOption(language);
        await expect(guest.locator(".large-mode")).toBeVisible();
      }
      await guest.locator('[aria-controls="reader-options"]').click();
      await guest.locator(".reader-options select").selectOption("64");
      await guest.locator('[aria-controls="reader-options"]').click();
      expect(
        await guest
          .locator(".subtitle-unit p")
          .first()
          .evaluate((element) => getComputedStyle(element).fontSize),
      ).toBe("64px");
      expect(
        await guest.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
      ).toBe(false);
      await guest
        .getByRole("button", { name: "Поділитися заняттям", exact: true })
        .click();
      const dialog = guest.getByRole("dialog");
      await expect(dialog.locator("canvas")).toBeVisible();
      await expect(dialog.getByRole("combobox", { name: "Мова інтерфейсу", exact: true })).toHaveCount(0);
      await expect(dialog.locator(".modal-title h2")).toHaveCount(1);
      await dialog.getByRole("button", { name: "Закрити", exact: true }).last().click();
      await guest.locator(".site-header select").selectOption("en");
      await guest.screenshot({
        path: fileURLToPath(
          new URL(
            "actual-http-synthetic-content-large-mobile-en.png",
            directory,
          ),
        ),
        fullPage: true,
      });
      await guest
        .getByRole("button", { name: "Exit large subtitles", exact: true })
        .click();
      expect(
        await guest.evaluate(() => localStorage.getItem("classroom.fontSize")),
      ).toBe("64");
      await guest.locator(".site-header select").selectOption("zh-CN");
    },
  );
  await check(
    "administrator user create, real Excel template/preview/async confirmation",
    async () => {
      await admin.goto(`${origin}/admin`);
      await expect(
        admin.getByRole("heading", { name: "管理后台", exact: true }),
      ).toBeVisible();
      await admin
        .getByRole("button", { name: "新增用户", exact: true })
        .click();
      let dialog = admin.getByRole("dialog");
      await dialog.getByLabel("姓名", { exact: true }).fill("UI Іван");
      await dialog
        .getByLabel("学号", { exact: true })
        .fill(`000UI${Date.now()}`);
      await dialog.getByRole("button", { name: "保存", exact: true }).click();
      await expect(dialog).toHaveCount(0);
      await admin
        .getByRole("button", { name: "Excel 批量导入", exact: true })
        .click();
      const downloadPromise = admin.waitForEvent("download");
      await admin
        .getByRole("link", { name: "下载 XLSX 模板", exact: true })
        .click();
      const download = await downloadPromise;
      const templatePath = fileURLToPath(
        new URL("actual-downloaded-users-template.xlsx", directory),
      );
      await download.saveAs(templatePath);
      expect((await readFile(templatePath)).byteLength).toBeGreaterThan(1000);
      await admin
        .getByLabel("选择 XLSX 文件", { exact: true })
        .setInputFiles(templatePath);
      await admin
        .getByRole("button", { name: "预检与预览", exact: true })
        .click();
      await expect(
        admin.getByRole("heading", {
          name: "预检完成，尚未写入用户",
          exact: true,
        }),
      ).toBeVisible();
      await admin
        .getByRole("button", { name: "确认执行导入", exact: true })
        .click();
      await expect(
        admin.getByRole("heading", { name: "导入已执行", exact: true }),
      ).toBeVisible({ timeout: 30000 });
      await admin.screenshot({
        path: fileURLToPath(
          new URL("actual-http-synthetic-import-desktop-zh.png", directory),
        ),
        fullPage: true,
      });
    },
  );
  await check(
    "admin course editing, sentence filtering and member admin denial",
    async () => {
      await admin
        .getByRole("button", { name: "课程管理", exact: true })
        .click();
      const card = admin
        .locator(".course-card")
        .filter({ hasText: "SYNTHETIC CONTENT / 空气动力学 Ї" })
        .first();
      await card.getByRole("button", { name: "编辑课程", exact: true }).click();
      const dialog = admin.getByRole("dialog");
      await dialog
        .getByLabel("说明", { exact: true })
        .fill("Browser edited course description.");
      await dialog.getByRole("button", { name: "保存", exact: true }).click();
      await expect(dialog).toHaveCount(0);
      await admin
        .getByRole("button", { name: "句子管理", exact: true })
        .click();
      await expect(
        admin.getByRole("heading", { name: "句子管理", exact: true }),
      ).toBeVisible();
      expect(
        (await memberContext.request.get(`${origin}/api/admin/users`)).status(),
      ).toBe(403);
    },
  );
  await check(
    "private share lands on exact lesson with no microphone or export grant",
    async () => {
      const share = await (
        await adminContext.request.get(
          `${origin}/api/lessons/${fixture.private_lesson_id}/share`,
        )
      ).json();
      const privateContext = await context(),
        privateViewer = await page(privateContext);
      await privateViewer.goto(share.url);
      await expect(privateViewer).toHaveURL(
        new RegExp(`/lessons/${fixture.private_lesson_id}$`),
      );
      await expect(
        privateViewer.getByText("课堂已连接", { exact: true }),
      ).toBeVisible();
      expect(await privateViewer.evaluate(() => window.__micRequests)).toBe(0);
      expect(
        (
          await privateContext.request.get(
            `${origin}/api/lessons/${fixture.ended_lesson_id}`,
          )
        ).status(),
      ).toBe(200);
      const exportDenied = await mutation(
        privateContext,
        `/api/lessons/${fixture.private_lesson_id}/exports`,
        {
          format: "txt",
          layout: "bilingual",
          text_version: "current",
          idempotency_key: crypto.randomUUID(),
        },
      );
      expect(exportDenied.status()).toBe(401);
      const revoked = await mutation(
        adminContext,
        `/api/admin/lessons/${fixture.private_lesson_id}/share/revoke`,
        { confirmed: true },
      );
      expect(revoked.status()).toBe(200);
      await privateViewer.goto(share.url);
      await expect(privateViewer).toHaveURL(
        /\/share-unavailable\?code=share_revoked/,
      );
      await expect(
        privateViewer.getByText("分享链接已撤销。", { exact: true }),
      ).toBeVisible();
    },
  );
  await check(
    "logout all devices revokes a second active session",
    async () => {
      await member
        .getByRole("button", { name: "账户与设备", exact: true })
        .click();
      const dialog = member.getByRole("dialog");
      await dialog
        .getByRole("button", { name: "退出全部设备", exact: true })
        .click();
      await dialog
        .getByLabel("当前密码", { exact: true })
        .fill(fixture.accounts.member.password);
      await dialog.getByRole("button", { name: "确认", exact: true }).click();
      await expect(
        member.getByRole("button", { name: "登录", exact: true }),
      ).toBeVisible();
      expect(
        (await (await secondContext.request.get(`${origin}/api/me`)).json())
          .user,
      ).toBe(null);
    },
  );
  for (const target of [member, guest, second, admin, forced])
    expect(await target.evaluate(() => window.__micRequests)).toBe(0);
  expect(errors).toEqual([]);
  const report = {
    status: "passed",
    classification: "ACTUAL_PHP_WS_POSTGRESQL_SYNTHETIC_CONTENT_NO_MODEL_CALL",
    testedAt: new Date().toISOString(),
    browser: browser.version(),
    checks,
    realPhp: true,
    realPostgreSql: true,
    httpMocks: false,
    webSocketMocks: false,
    synthetic_subtitles: true,
    real_model: false,
    real_oss: false,
    real_microphone: false,
    physicalDevice: false,
    microphoneRequests: 0,
    jsErrors: errors,
    limitations: [
      "No physical device test",
      "Recording/handoff has a separate synthetic-audio mock-provider integration suite",
      "No extra real model or OSS calls in this suite",
    ],
  };
  await writeFile(
    new URL("phase-b-results.json", directory),
    JSON.stringify(report, null, 2),
  );
  console.log(JSON.stringify(report));
} catch (error) {
  const dialogs=[];for(const item of contexts)for(const page of item.pages())dialogs.push(...(await page.locator('dialog').allTextContents()).map(redact));
  const report = {
    status: "failed",
    check: currentCheck,
    error: redact(error.message),
    checks,
    jsErrors: errors,
    classification: "ACTUAL_PHP_SYNTHETIC_CONTENT_ATTEMPT",
    dialogs,
  };
  await writeFile(
    new URL("phase-b-failure.json", directory),
    JSON.stringify(report, null, 2),
  );
  console.log(JSON.stringify(report));
  throw new Error(report.error);
} finally {
  for (const item of contexts) await item.close();
  await browser.close();
}

