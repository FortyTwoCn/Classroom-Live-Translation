import { chromium, expect } from "@playwright/test";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
if (!process.argv[2])
  throw new Error(
    "Pass an isolated phase-b-fixture.json with a fresh ready lesson.",
  );
const fixture = JSON.parse(await readFile(process.argv[2], "utf8")),
  origin = process.env.ACTUAL_CAPTURE_URL || "http://localhost:18080",
  directory = new URL("../artifacts/actual-phase-b-audio/", import.meta.url);
await mkdir(directory, { recursive: true });
const browser = await chromium.launch({
  channel: process.env.PLAYWRIGHT_CHROMIUM_CHANNEL || "msedge",
  headless: true,
});
const contexts = [],
  errors = [],
  checks = [];
let currentCheck = "setup";
const redact = (value) =>
  String(value).replace(/https?:\/\/[^\s"'<>]+/g, "[redacted-url]");
async function makePage(authenticated) {
  const context = await browser.newContext();
  context.setDefaultTimeout(15000);
  contexts.push(context);
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(redact(error.message)));
  await page.addInitScript(() => {
    window.__capture = {
      calls: 0,
      tracks: [],
      packets: [],
      acks: [],
      commands: [],
      currentClientId: "",
      readySnapshots: 0,
      messages:[],
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
      } else if (typeof data === "string") {
        const message = JSON.parse(data);
        if (message.type !== "subscribe")
          window.__capture.commands.push(message.type);
      }
      return nativeSend.call(this, data);
    };
    window.WebSocket = class extends NativeSocket {
      constructor(...args) {
        super(...args);
        this.addEventListener("message", (event) => {
          if (typeof event.data !== "string") return;
          const message = JSON.parse(event.data);
          window.__capture.messages.push({type:message.type,event_type:message.event_type,code:message.code,epoch:message.epoch,lesson_epoch:message.lesson?.recorder_epoch||message.payload?.lesson?.recorder_epoch,lesson_status:message.lesson?.status||message.payload?.lesson?.status});
          if (message.type === "presence")
            window.__capture.currentClientId = message.current_client_id || "";
          if (message.type === "audio.ack")
            window.__capture.acks.push(message.sequence);
          if (message.type === "recorder.ready" && message.lesson)
            window.__capture.readySnapshots++;
        });
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
    Element.prototype.requestFullscreen = async () => {
      throw new Error("explicit unsupported-fullscreen fixture");
    };
  });
  await page.goto(`${origin}/courses`);
  if (authenticated) {
    await page.getByRole("button", { name: "登录", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog
      .getByLabel("学号", { exact: true })
      .fill(fixture.accounts.member.student_id);
    await dialog
      .getByLabel("密码", { exact: true })
      .fill(fixture.accounts.member.password);
    await dialog.getByRole("button", { name: "登录", exact: true }).click();
    await expect(
      page.getByRole("button", { name: "退出当前设备", exact: true }),
    ).toBeVisible();
  }
  await page.goto(`${origin}/lessons/${fixture.ready_lesson_id}`);
  await expect(page.getByText("课堂已连接", { exact: true })).toBeVisible();
  return page;
}
async function check(name, callback) {
  currentCheck = name;
  await callback();
  checks.push({ name, status: "passed" });
  console.log(`PASS ${name}`);
}
async function waitPackets(page, count) {
  await expect
    .poll(() => page.evaluate(() => window.__capture.packets.length),{timeout:20000})
    .toBeGreaterThanOrEqual(count);
}
async function invite(sender, recipient) {
  await expect
    .poll(() => recipient.evaluate(() => window.__capture.currentClientId))
    .not.toBe("");
  const client = await recipient.evaluate(
    () => window.__capture.currentClientId,
  );
  await sender.getByRole("button", { name: "转移录音权", exact: true }).click();
  const dialog = sender.getByRole("dialog");
  await dialog
    .locator("select")
    .filter({ has: sender.locator('option[value="' + client + '"]') })
    .selectOption(client);
  await dialog
    .getByRole("button", { name: "发送转移邀请", exact: true })
    .click();
  await expect(
    recipient
      .getByRole("dialog")
      .getByRole("button", { name: "接受", exact: true }),
  ).toBeVisible();
}
try {
  const source = await makePage(true),
    target = await makePage(true),
    guest = await makePage(false);
  await source.bringToFront();
  await check(
    "explicit capture starts one actual AudioWorklet stream",
    async () => {
      await source.locator(".recorder-panel input[type=checkbox]").check();
      await source
        .getByRole("button", { name: "开始收音", exact: true })
        .click();
      await waitPackets(source, 5);
      expect(await source.evaluate(() => window.__capture.calls)).toBe(1);
    },
  );
  await check(
    "large subtitles, QR, locale, font and fullscreen fallback keep same capture",
    async () => {
      const starts = await source.evaluate(
        () =>
          window.__capture.commands.filter(
            (type) => type === "start" || type === "resume",
          ).length,
      );
      await source
        .getByRole("button", { name: "大字幕模式", exact: true })
        .click();
      await source.locator(".site-header select").selectOption("uk");
      await source.locator('[aria-controls="reader-options"]').click();
      await source.locator(".reader-options select").selectOption("48");
      await source
        .getByRole("button", { name: "На весь екран", exact: true })
        .click();
      await expect(source.locator(".large-mode")).toBeVisible();
      await source
        .getByRole("button", { name: "Поділитися заняттям", exact: true })
        .click();
      await expect(source.getByRole("dialog").locator("canvas")).toBeVisible();
      await source
        .getByRole("dialog")
        .getByRole("button", { name: "Закрити", exact: true })
        .last()
        .click();
      await source.locator(".site-header select").selectOption("zh-CN");
      await source.waitForTimeout(400);
      expect(await source.evaluate(() => window.__capture.calls)).toBe(1);
      expect(
        await source.evaluate(
          () =>
            window.__capture.commands.filter(
              (type) => type === "start" || type === "resume",
            ).length,
        ),
      ).toBe(starts);
      await expect(source.locator(".mic-status.active")).toBeVisible();
      await source.screenshot({
        path: fileURLToPath(
          new URL(
            "actual-php-mock-qwen-synthetic-audio-large-recorder.png",
            directory,
          ),
        ),
        fullPage: true,
      });
    },
  );
  await check(
    "rejected invitation leaves the existing recorder active",
    async () => {
      await invite(source, target);
      const count = await source.evaluate(
        () => window.__capture.packets.length,
      );
      await target
        .getByRole("dialog")
        .getByRole("button", { name: "拒绝", exact: true })
        .click();
      await source.waitForTimeout(500);
      await expect(source.locator(".mic-status.active")).toBeVisible();
      expect(
        await source.evaluate(() => window.__capture.packets.length),
      ).toBeGreaterThan(count);
      expect(await target.evaluate(() => window.__capture.calls)).toBe(0);
    },
  );
  await check(
    "live handoff between two devices of one account prepares only by click and invalidates old epoch",
    async () => {
      await invite(source, target);
      await target
        .getByRole("dialog")
        .getByRole("button", { name: "接受", exact: true })
        .click();
      await expect(
        target
          .getByRole("dialog")
          .getByRole("button", { name: "准备本设备麦克风", exact: true }),
      ).toBeVisible();
      expect(await target.evaluate(() => window.__capture.calls)).toBe(0);
      const sourcePackets = await source.evaluate(
        () => window.__capture.packets.length,
      );
      await source.waitForTimeout(400);
      expect(
        await source.evaluate(() => window.__capture.packets.length),
      ).toBeGreaterThan(sourcePackets);
      await target
        .getByRole("dialog")
        .getByRole("button", { name: "准备本设备麦克风", exact: true })
        .click();
      await waitPackets(target, 5);
      await expect
        .poll(() =>
          source.evaluate(() =>
            window.__capture.tracks.every(
              (track) => track.readyState === "ended",
            ),
          ),
        )
        .toBe(true);
      const stop = await source.evaluate(() => window.__capture.packets.length);
      await source.waitForTimeout(400);
      expect(await source.evaluate(() => window.__capture.packets.length)).toBe(
        stop,
      );
      expect(await target.evaluate(() => window.__capture.calls)).toBe(1);
      expect(
        await target.evaluate(() => window.__capture.readySnapshots),
      ).toBeGreaterThan(0);
      const oldEpoch = await source.evaluate(
          () => window.__capture.packets.at(-1).epoch,
        ),
        newEpoch = await target.evaluate(
          () => window.__capture.packets[0].epoch,
        );
      expect(newEpoch).toBeGreaterThan(oldEpoch);
      await expect(target.locator(".mic-status.active")).toBeVisible();
    },
  );
  await check(
    "paused handoff preserves pause and stops prepared tracks until manual resume",
    async () => {
      await target.getByRole("button", { name: /暂停收音$/ }).click();
      await expect(target.getByText("已暂停", { exact: true })).toBeVisible();
      await invite(target, source);
      await source
        .getByRole("dialog")
        .getByRole("button", { name: "接受", exact: true })
        .click();
      await source
        .getByRole("dialog")
        .getByRole("button", { name: "准备本设备麦克风", exact: true })
        .click();
      await expect
        .poll(() => source.evaluate(() => window.__capture.calls))
        .toBe(2);
      await expect
        .poll(() =>
          source.evaluate(() =>
            window.__capture.tracks.every(
              (track) => track.readyState === "ended",
            ),
          ),
        )
        .toBe(true);
      const packets = await source.evaluate(
        () => window.__capture.packets.length,
      );
      await source.waitForTimeout(400);
      expect(await source.evaluate(() => window.__capture.packets.length)).toBe(
        packets,
      );
      await expect(source.getByText("已暂停", { exact: true })).toBeVisible();
      await source
        .getByRole("button", { name: "继续收音", exact: true })
        .click();
      await waitPackets(source, packets + 4);
      expect(await source.evaluate(() => window.__capture.calls)).toBe(3);
    },
  );
  await check(
    "end flushes all acknowledgements, stops every device and persists shared mock output",
    async () => {
      await source
        .getByRole("button", { name: "结束课堂", exact: true })
        .click();
      await source
        .getByRole("dialog")
        .getByRole("button", { name: "确认", exact: true })
        .click();
      await expect(source.getByText("已结束", { exact: true })).toBeVisible();
      for (const page of [source, target])
        expect(
          await page.evaluate(() =>
            window.__capture.tracks.every(
              (track) => track.readyState === "ended",
            ),
          ),
        ).toBe(true);
      const data = await (
        await contexts[0].request.get(
          `${origin}/api/lessons/${fixture.ready_lesson_id}`,
        )
      ).json();
      const packets = [
        ...(await source.evaluate(() => window.__capture.packets)),
        ...(await target.evaluate(() => window.__capture.packets)),
      ];
      expect(data.lesson.media_samples).toBe(
        packets.reduce((sum, packet) => sum + packet.samples, 0),
      );
      expect(
        (await source.evaluate(() => window.__capture.acks.length)) +
          (await target.evaluate(() => window.__capture.acks.length)),
      ).toBe(packets.length);
      expect(await guest.evaluate(() => window.__capture.calls)).toBe(0);
      await expect(
        guest.locator(".subtitle-unit.source p").first(),
      ).not.toBeEmpty();
      await expect(
        guest.locator(".subtitle-unit.translation p").first(),
      ).not.toBeEmpty();
    },
  );
  expect(errors).toEqual([]);
  const report = {
    classification:
      "ACTUAL_PHP_WS_POSTGRESQL_AUDIOWORKLET_SYNTHETIC_TONE_MOCK_QWEN",
    status: "passed",
    testedAt: new Date().toISOString(),
    browser: browser.version(),
    checks,
    clients: 3,
    sameAccountDevices: 2,
    realPhp: true,
    realPostgreSql: true,
    httpMocks: false,
    webSocketMocks: false,
    synthetic_audio: true,
    real_model: false,
    real_oss: false,
    real_microphone: false,
    physicalDevice: false,
    guestMicrophoneRequests: 0,
    jsErrors: errors,
    sourceMicrophoneRequests: await source.evaluate(
      () => window.__capture.calls,
    ),
    targetMicrophoneRequests: await target.evaluate(
      () => window.__capture.calls,
    ),
    audioPackets:
      (await source.evaluate(() => window.__capture.packets.length)) +
      (await target.evaluate(() => window.__capture.packets.length)),
    limitations: [
      "Synthetic 440Hz tone replaces physical microphone",
      "Qwen upstream is explicitly mocked",
      "Private audio archiving is excluded from this suite",
      "Fullscreen unavailability is explicitly injected to test fallback",
    ],
  };
  await writeFile(
    new URL("phase-b-audio-results.json", directory),
    JSON.stringify(report, null, 2),
  );
  console.log(JSON.stringify(report));
} catch (error) {
  const dialogs = [];
  for (const context of contexts)
    for (const page of context.pages())
      dialogs.push(
        ...(await page.locator("dialog").allTextContents()).map(redact),
      );
  const report = {
    classification: "ACTUAL_PHASE_B_AUDIO_ATTEMPT",
    status: "failed",
    check: currentCheck,
    error: redact(error.message),
    checks,
    jsErrors: errors,
    dialogs,
    captures:await Promise.all(contexts.map(async context=>context.pages()[0]?.evaluate(()=>({calls:window.__capture.calls,tracks:window.__capture.tracks.map(track=>track.readyState),packets:window.__capture.packets.length,commands:window.__capture.commands,messages:window.__capture.messages.slice(-50)})))),
  };
  await writeFile(
    new URL("phase-b-audio-failure.json", directory),
    JSON.stringify(report, null, 2),
  );
  console.log(JSON.stringify(report));
  throw new Error(report.error);
} finally {
  for (const context of contexts) await context.close();
  await browser.close();
}

