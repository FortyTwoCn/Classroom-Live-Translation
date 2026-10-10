import { chromium, expect } from "@playwright/test";
import { readFile, mkdir, writeFile } from "node:fs/promises";
if (!process.argv[2])
  throw new Error(
    "Pass isolated phase-c fixture; gateway must use the explicit mock Qwen provider.",
  );
const fixture = JSON.parse(await readFile(process.argv[2], "utf8"));
const origin = process.env.ACTUAL_CAPTURE_URL || "http://localhost:18080";
const directory = new URL(
  "../artifacts/actual-phase-c-capture/",
  import.meta.url,
);
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({ channel: "msedge", headless: true });
const checks = [],
  errors = [],
  contexts = [];
let lessonId,
  currentCheck = "setup";
const redact = (value) =>
  String(value).replace(/https?:\/\/[^\s"'<>]+/g, "[redacted-url]");
async function authenticated(role, capture = false) {
  const context = await browser.newContext();
  contexts.push(context);
  context.setDefaultTimeout(15000);
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(redact(error.message)));
  await page.addInitScript(
    ({ capture }) => {
      localStorage.setItem("classroom.locale", "zh-CN");
      window.__capture = {
        calls: 0,
        packets: [],
        acks: [],
        commands: [],
        tracks: [],
        contexts: [],
      };
      const NativeSocket = WebSocket,
        nativeSend = WebSocket.prototype.send;
      WebSocket.prototype.send = function (data) {
        if (data instanceof ArrayBuffer) {
          const view = new DataView(data);
          window.__capture.packets.push({
            epoch: view.getUint32(0),
            sequence: view.getUint32(4),
            samples: (data.byteLength - 8) / 2,
          });
        } else if (typeof data === "string")
          window.__capture.commands.push(JSON.parse(data).type);
        return nativeSend.call(this, data);
      };
      window.WebSocket = class extends NativeSocket {
        constructor(...args) {
          super(...args);
          this.addEventListener("message", ({ data }) => {
            if (typeof data === "string") {
              const message = JSON.parse(data);
              if (message.type === "audio.ack")
                window.__capture.acks.push(message.sequence);
            }
          });
        }
      };
      if (!capture) return;
      const NativeContext = AudioContext;
      window.AudioContext = class extends NativeContext {
        constructor(...args) {
          super(...args);
          const module = this.audioWorklet.addModule.bind(this.audioWorklet);
          this.audioWorklet.addModule = (...values) => {
            this.__isCapture = true;
            return module(...values);
          };
          window.__capture.contexts.push(this);
        }
      };
      navigator.mediaDevices.getUserMedia = async () => {
        window.__capture.calls++;
        const context = new AudioContext();
        await context.resume();
        const oscillator = context.createOscillator();
        oscillator.frequency.value = 440;
        const destination = context.createMediaStreamDestination();
        oscillator.connect(destination);
        oscillator.start();
        const track = destination.stream.getAudioTracks()[0],
          stop = track.stop.bind(track);
        track.stop = () => {
          stop();
          oscillator.stop();
          void context.close();
        };
        window.__capture.tracks.push(track);
        return destination.stream;
      };
    },
    { capture },
  );
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
async function mutation(page, path, body) {
  const me = await (
    await page.context().request.get(`${origin}/api/me`)
  ).json();
  return page
    .context()
    .request.post(`${origin}${path}`, {
      headers: { "X-CSRF-TOKEN": me.csrf_token },
      data: body,
    });
}
async function state(page) {
  return (
    await page.context().request.get(`${origin}/api/lessons/${lessonId}`)
  ).json();
}
async function check(name, fn) {
  currentCheck = name;
  await fn();
  checks.push({ name, status: "passed" });
  console.log(`PASS ${name}`);
}
async function packets(page, min) {
  await expect
    .poll(() => page.evaluate(() => window.__capture.packets.length), {
      timeout: 20000,
    })
    .toBeGreaterThanOrEqual(min);
}
async function stopped(page, reason) {
  await expect
    .poll(async () => (await state(page)).lesson.status, { timeout: 15000 })
    .toBe("paused");
  await expect
    .poll(
      async () =>
        (await state(page)).issues.some(
          (issue) =>
            issue.kind === "capture_uncertain" &&
            issue.details.reason === reason,
        ),
      { timeout: 15000 },
    )
    .toBe(true);
  const issue = (await state(page)).issues.find(
    (issue) =>
      issue.kind === "capture_uncertain" && issue.details.reason === reason,
  );
  expect(issue.end_sample).toBeNull();
  expect(issue.details.estimated_lost_ms).toBeNull();
  expect(issue.details.media_strategy).toBe("skip_unreceived");
  expect(issue.details.last_ack_sequence).toBeGreaterThanOrEqual(0);
  expect(issue.details.next_sequence).toBeGreaterThan(
    issue.details.last_ack_sequence,
  );
  expect(
    await page.evaluate(() =>
      window.__capture.tracks.every((track) => track.readyState === "ended"),
    ),
  ).toBe(true);
  const count = await page.evaluate(() => window.__capture.packets.length);
  await page.waitForTimeout(450);
  expect(await page.evaluate(() => window.__capture.packets.length)).toBe(
    count,
  );
  await expect(
    page.getByRole("button", { name: "继续收音", exact: true }),
  ).toBeVisible();
  return {
    id: issue.id,
    reason: issue.details.reason,
    epoch: issue.epoch,
    start_sample: issue.start_sample,
    end_sample: issue.end_sample,
    last_ack_sequence: issue.details.last_ack_sequence,
    next_sequence: issue.details.next_sequence,
    estimated_lost_ms: issue.details.estimated_lost_ms,
  };
}
try {
  const admin = await authenticated("admin"),
    member = await authenticated("member", true);
  const created = await mutation(admin, "/api/admin/courses", {
    name: `SYNTHETIC C CAPTURE FAULT ${Date.now()}`,
    target_language: "en",
    guest_access: "public",
    enabled: true,
  });
  expect(created.ok()).toBe(true);
  const course = (await created.json()).course;
  const result = await mutation(member, `/api/courses/${course.id}/lessons`, {
    name: "SYNTHETIC TONE / MOCK QWEN / NO REAL MICROPHONE",
    idempotency_key: crypto.randomUUID(),
  });
  expect(result.ok()).toBe(true);
  lessonId = (await result.json()).lesson.id;
  await member.goto(`${origin}/lessons/${lessonId}`);
  await expect(member.getByText("课堂已连接", { exact: true })).toBeVisible();
  const issues = [];
  await check(
    "actual AudioWorklet microphone-ended event stops tracks, pauses actual Gateway and persists unknown audio range",
    async () => {
      await member.locator(".recorder-panel input[type=checkbox]").check();
      await member
        .getByRole("button", { name: "开始收音", exact: true })
        .click();
      await packets(member, 6);
      await member.evaluate(() =>
        window.__capture.tracks.at(-1).dispatchEvent(new Event("ended")),
      );
      issues.push(await stopped(member, "microphone_ended"));
      expect(await member.evaluate(() => window.__capture.calls)).toBe(1);
    },
  );
  await check(
    "explicit resume creates a new stream; AudioContext suspension pauses and persists a second actual capture issue",
    async () => {
      await expect
        .poll(async () => (await state(member)).lesson.translation_status, {
          timeout: 15000,
        })
        .not.toBe("finishing");
      const before = await member.evaluate(
        () => window.__capture.packets.length,
      );
      await member
        .getByRole("button", { name: "继续收音", exact: true })
        .click();
      await packets(member, before + 6);
      await member.evaluate(() =>
        window.__capture.contexts
          .findLast(
            (context) => context.__isCapture && context.state === "running",
          )
          .suspend(),
      );
      issues.push(await stopped(member, "context_suspended"));
      expect(await member.evaluate(() => window.__capture.calls)).toBe(2);
      expect(issues[1].epoch).toBeGreaterThan(issues[0].epoch);
    },
  );
  await check(
    "manual end retains both persisted uncertain ranges without restarting microphone or claiming archive playback",
    async () => {
      await member
        .getByRole("button", { name: "结束课堂", exact: true })
        .click();
      await member
        .getByRole("dialog")
        .getByRole("button", { name: "确认", exact: true })
        .click();
      await expect
        .poll(async () => (await state(member)).lesson.status, {
          timeout: 15000,
        })
        .toBe("ended");
      expect(
        (await state(member)).issues.filter(
          (issue) => issue.kind === "capture_uncertain",
        ),
      ).toHaveLength(2);
      expect(await member.locator("audio").count()).toBe(0);
      await member.locator(".capture-issues summary").click();
      await member.screenshot({
        path: new URL(
          "actual-http-capture-uncertain-mock-provider.png",
          directory,
        ).pathname.replace(/^\/([A-Za-z]:)/, "$1"),
        fullPage: true,
      });
    },
  );
  expect(errors).toEqual([]);
  const capture = await member.evaluate(() => ({
    calls: window.__capture.calls,
    packets: window.__capture.packets,
    acks: window.__capture.acks.length,
    tracksStopped: window.__capture.tracks.every(
      (track) => track.readyState === "ended",
    ),
  }));
  await writeFile(
    new URL("phase-c-capture-results.json", directory),
    JSON.stringify(
      {
        classification:
          "ACTUAL_PHP_WEBSOCKET_POSTGRESQL_AUDIOWORKLET_SYNTHETIC_TONE_MOCK_QWEN",
        status: "passed",
        testedAt: new Date().toISOString(),
        realPhp: true,
        realPostgreSql: true,
        webSocketMocks: false,
        httpMocks: false,
        real_model: false,
        real_oss: false,
        synthetic_audio: true,
        real_microphone: false,
        lessonId,
        checks,
        issues,
        capture,
        jsErrors: errors,
      },
      null,
      2,
    ),
  );
} catch (error) {
  await writeFile(
    new URL("phase-c-capture-failure.json", directory),
    JSON.stringify(
      {
        status: "failed",
        currentCheck,
        message: redact(error),
        checks,
        jsErrors: errors,
        lessonId,
      },
      null,
      2,
    ),
  );
  throw error;
} finally {
  await browser.close();
}
