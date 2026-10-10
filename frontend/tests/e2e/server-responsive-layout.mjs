import { chromium, expect } from "@playwright/test";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";

// Actual public UI with optional owned, ended fixture; no model/audio generation.
const baseline = process.env.LAYOUT_BASELINE === "true";
const origin = process.env.ACTUAL_CAPTURE_URL || "https://t.xuanknow.cn";
const directory = new URL(
  `../artifacts/server-deployment/layout-${baseline ? "before" : "after"}/`,
  import.meta.url,
);
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ channel: "msedge", headless: true });
const context = await browser.newContext({
  viewport: { width: 390, height: 844 },
});
const page = await context.newPage();
const checks = [],
  jsErrors = [];
const report = {
  classification: "REAL_PUBLIC_RESPONSIVE_UI_BROWSER_EMULATION",
  tested_at: new Date().toISOString(),
  physical_device: false,
  new_model_call: false,
  httpMocks: false,
  webSocketMocks: false,
  checks,
};
await page.addInitScript(() => {
  window.__layoutMicrophoneRequests = 0;
  navigator.mediaDevices.getUserMedia = async () => {
    window.__layoutMicrophoneRequests++;
    throw new Error("Read-only layout test must not request microphone");
  };
});
page.on("pageerror", (error) =>
  jsErrors.push(error.message.replace(/https?:\/\/[^\s]+/g, "[redacted-url]")),
);
async function inspect(
  label,
  width,
  locale,
  screenshot = false,
  compactHeader = true,
) {
  await page.setViewportSize({ width, height: width >= 768 ? 1024 : 844 });
  await page.locator(".site-header select").selectOption(locale);
  await page.waitForTimeout(100);
  const metrics = await page.evaluate(() => {
    const box = (selector) => {
      const e = document.querySelector(selector);
      if (!e || e.getClientRects().length === 0) return null;
      const r = e.getBoundingClientRect();
      return {
        x: r.x,
        y: r.y,
        width: r.width,
        height: r.height,
        centerY: r.y + r.height / 2,
      };
    };
    return {
      page_width: innerWidth,
      document_width: document.documentElement.scrollWidth,
      header: box(".site-header"),
      language: box(".site-header select"),
      identity: box(".site-header .identity"),
      login: box(".header-login") || box(".header-actions > button:last-child"),
      account: box(".mobile-account-trigger"),
      qr: box(".qr-trigger"),
      subtitle_panel: box(".subtitle-panel"),
      microphones: window.__layoutMicrophoneRequests,
    };
  });
  checks.push({ label, width, locale, ...metrics });
  if (!baseline) {
    expect(
      metrics.document_width,
      label + " page overflow",
    ).toBeLessThanOrEqual(width);
    expect(metrics.microphones).toBe(0);
    if (width <= 900 && compactHeader) {
      expect(
        metrics.header.height,
        label + " compact header",
      ).toBeLessThanOrEqual(64);
      const controls = [
        metrics.language,
        metrics.identity,
        metrics.login,
        metrics.account,
        metrics.qr,
      ].filter(Boolean);
      expect(
        Math.max(...controls.map((c) => c.centerY)) -
          Math.min(...controls.map((c) => c.centerY)),
      ).toBeLessThanOrEqual(5);
      for (const c of controls)
        expect(c.x + c.width).toBeLessThanOrEqual(width);
    }
  }
  if (screenshot)
    await page.screenshot({
      path: fileURLToPath(
        new URL(label + "-" + width + "-" + locale + ".png", directory),
      ),
      fullPage: true,
    });
}
try {
  await page.goto(origin + "/courses");
  await page.waitForFunction(() =>
    document.querySelector(".site-header .identity"),
  );
  for (const width of baseline ? [390] : [320, 360, 390, 430, 768, 1280])
    for (const locale of baseline ? ["zh-CN"] : ["zh-CN", "en", "uk"])
      await inspect("guest-home", width, locale, width === 390);
  if (!baseline && process.argv[2] && process.argv[3]) {
    const fixture = JSON.parse(await readFile(process.argv[2], "utf8"));
    const scope = JSON.parse(await readFile(process.argv[3], "utf8"));
    if (
      scope.temporary_user_id !== fixture.user_id ||
      scope.run_id !== fixture.run_id
    )
      throw new Error("Owned ended fixture required");
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator(".site-header select").selectOption("zh-CN");
    await page.locator(".header-login").click();
    const login = page.getByRole("dialog");
    await expect(login).toBeVisible();
    await inspect("login-dialog", 390, "zh-CN", true);
    await login.getByLabel("学号", { exact: true }).fill(fixture.student_id);
    await login.getByLabel("密码", { exact: true }).fill(fixture.password);
    await login.getByRole("button", { name: "登录", exact: true }).click();
    await expect(page.locator(".mobile-account-trigger")).toBeVisible();
    for (const [label, path] of [
      ["member-home", "/courses"],
      ["lesson-list", "/courses/" + scope.course_id + "/lessons"],
      ["lesson", "/lessons/" + scope.lesson_id],
      ["admin", "/admin"],
    ]) {
      await page.locator(".site-header select").selectOption("zh-CN");
      await page.goto(origin + path);
      if (label === "lesson")
        await expect(
          page.getByText("课堂已连接", { exact: true }),
        ).toBeVisible();
      for (const width of [320, 390, 768, 1280])
        for (const locale of ["zh-CN", "en", "uk"])
          await inspect(label, width, locale, width === 390);
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator(".site-header select").selectOption("zh-CN");
    await page.locator(".mobile-account-trigger").click();
    const menu = page.getByRole("dialog");
    await expect(
      menu.getByRole("button", { name: "管理后台", exact: true }),
    ).toBeVisible();
    await expect(
      menu.getByRole("button", { name: "账户与设备", exact: true }),
    ).toBeVisible();
    await expect(
      menu.getByRole("button", { name: "退出当前设备", exact: true }),
    ).toBeVisible();
    await page.screenshot({
      path: fileURLToPath(new URL("mobile-account-menu.png", directory)),
      fullPage: true,
    });
    await menu.getByRole("button", { name: "关闭", exact: true }).click();
    const navigation = page.getByRole("navigation", {
      name: "管理后台",
      exact: true,
    });
    const tabs = await navigation.getByRole("button").allTextContents();
    expect(tabs.length).toBe(9);
    for (const tab of tabs) {
      await navigation
        .getByRole("button", { name: tab.trim(), exact: true })
        .click();
      await inspect("admin-tab-" + checks.length, 390, "zh-CN");
    }
    await page.goto(origin + "/lessons/" + scope.lesson_id);
    await expect(page.locator(".subtitle-panel")).toBeVisible();
    await page.getByRole("button", { name: "大字幕模式", exact: true }).click();
    for (const width of [320, 390, 768])
      for (const locale of ["zh-CN", "en", "uk"])
        await inspect("large-subtitles", width, locale, width === 390);
    expect(jsErrors).toEqual([]);
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
  }
  report.status = baseline ? "observed_before_change" : "passed";
  report.jsErrors = jsErrors;
} catch (error) {
  report.status = "failed";
  report.error = String(error.message).replace(
    /https?:\/\/[^\s]+/g,
    "[redacted-url]",
  );
  await page.screenshot({
    path: fileURLToPath(new URL("failure.png", directory)),
    fullPage: true,
  });
  process.exitCode = 1;
} finally {
  await writeFile(
    new URL("layout.json", directory),
    JSON.stringify(report, null, 2),
  );
  console.log(
    JSON.stringify({
      status: report.status,
      checks: checks.length,
      error: report.error,
    }),
  );
  await context.close();
  await browser.close();
}
