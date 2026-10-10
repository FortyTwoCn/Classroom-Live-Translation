import { chromium, expect } from "@playwright/test";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";
if (!process.argv[2])
  throw new Error("Pass the isolated phase-C account fixture.");
const fixture = JSON.parse(await readFile(process.argv[2], "utf8"));
const origin = "http://localhost:18080";
const report = new URL(
  "../../../reports/frontend-replay-simulated.json",
  import.meta.url,
);
const artifactDirectory = new URL(
  "../artifacts/actual-frontend-replay/",
  import.meta.url,
);
const psqlPath = fileURLToPath(
  new URL("../../../../../work/runtime/pg/pgsql/bin/psql.exe", import.meta.url),
);
const passwordPath = new URL(
  "../../../../../work/runtime/pg-password.txt",
  import.meta.url,
);
await mkdir(artifactDirectory, { recursive: true });
let database,
  buffer = "",
  pendingQuery = null;
const databasePassword = (await readFile(passwordPath, "utf8")).trim();
database = spawn(
  psqlPath,
  [
    "-X",
    "-qAt",
    "-h",
    "127.0.0.1",
    "-p",
    "55432",
    "-U",
    "classroom_dev",
    "-d",
    "classroom_test",
  ],
  {
    env: { ...process.env, PGPASSWORD: databasePassword },
    windowsHide: true,
    stdio: ["pipe", "pipe", "pipe"],
  },
);
database.stdout.on("data", (bytes) => {
  buffer += bytes.toString();
  for (let index; (index = buffer.indexOf("\n")) >= 0;) {
    const line = buffer.slice(0, index).trim();
    buffer = buffer.slice(index + 1);
    if (line.startsWith("{") && pendingQuery) {
      const pending = pendingQuery;
      pendingQuery = null;
      clearTimeout(pending.timer);
      pending.resolve(JSON.parse(line));
    }
  }
});
database.stderr.on("data", () => {
  if (pendingQuery) {
    const pending = pendingQuery;
    pendingQuery = null;
    clearTimeout(pending.timer);
    pending.reject(
      new Error(
        "Isolated database fixture query failed; raw database errors omitted.",
      ),
    );
  }
});
database.on("error", () => {
  pendingQuery?.reject(new Error("Isolated psql fixture unavailable."));
  pendingQuery = null;
});
function query(sql) {
  if (pendingQuery) throw new Error("Fixture queries must be sequential.");
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      pendingQuery = null;
      reject(new Error("Isolated database fixture timeout."));
    }, 12000);
    pendingQuery = { resolve, reject, timer };
    database.stdin.write(sql + "\n");
  });
}
const databaseIdentity = await query(
  "SELECT json_build_object('database',current_database());",
);
if (databaseIdentity.database !== "classroom_test")
  throw new Error("Refuse a non-isolated database.");
const browser = await chromium.launch({ channel: "msedge", headless: true }),
  contexts = [],
  checks = [],
  errors = [];
let currentCheck = "setup",
  ownerPage,
  adminPage,
  lessonId,
  courseId,
  replayEvidence,
  pauseEvidence;
const courseName = `SYNTHETIC FRONTEND REPLAY ${crypto.randomUUID()}`;
const redact = (value) =>
  String(value).replace(/https?:\/\/[^\s"'<>]+/g, "[redacted-url]");
async function pageFor(role, capture = false) {
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
        tracks: [],
        trackStops: [],
        sockets: [],
        snapshots: [],
        subscriptions: [],
        wire: [],
        pauseOnNextAck: false,
        pauseResult: null,
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
          if (message.type === "subscribe")
            window.__capture.subscriptions.push(message.last_seq);
        }
        return nativeSend.call(this, data);
      };
      window.WebSocket = class extends NativeSocket {
        constructor(...args) {
          super(...args);
          window.__capture.sockets.push(this);
          this.addEventListener("message", ({ data }) => {
            if (typeof data !== "string") return;
            const message = JSON.parse(data);
            const summary = {
              type: message.type,
              event_type: message.event_type,
              seq: message.seq,
              code: message.code,
              status: message.payload?.lesson?.status,
              epoch: message.payload?.lesson?.recorder_epoch,
              holder: message.payload?.lesson?.recorder_user_id,
            };
            window.__capture.lastWire = summary;
            window.__capture.wire.push(summary);
            if (message.type === "snapshot")
              window.__capture.snapshots.push({
                last_seq: message.last_seq,
                recovery: message.recovery,
                missed_events: (message.missed_events || []).map((event) => ({
                  seq: event.seq,
                  event_type: event.event_type,
                  unit_id: event.payload?.unit?.id,
                  version: event.payload?.unit?.version,
                })),
              });
            if (message.type === "audio.ack") {
              window.__capture.acks.push(message.sequence);
              if (window.__capture.pauseOnNextAck) {
                window.__capture.pauseOnNextAck = false;
                void window.__pauseFixture().then((value) => {
                  window.__capture.pauseResult = value;
                });
              }
            }
          });
        }
      };
      if (capture) {
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
            window.__capture.trackStops.push({
              wire: window.__capture.lastWire,
              packets: window.__capture.packets.length,
            });
            stop();
            oscillator.stop();
            void context.close();
          };
          window.__capture.tracks.push(track);
          return destination.stream;
        };
      } else {
        const original = navigator.mediaDevices.getUserMedia.bind(
          navigator.mediaDevices,
        );
        navigator.mediaDevices.getUserMedia = (constraints) => {
          window.__capture.calls++;
          return original(constraints);
        };
      }
    },
    { capture },
  );
  await page.goto(`${origin}/courses`);
  await page.getByRole("button", { name: "登录", exact: true }).click();
  const modal = page.getByRole("dialog");
  await modal
    .getByLabel("学号", { exact: true })
    .fill(fixture.accounts[role].student_id);
  await modal
    .getByLabel("密码", { exact: true })
    .fill(fixture.accounts[role].password);
  await modal.getByRole("button", { name: "登录", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "退出当前设备", exact: true }),
  ).toBeVisible();
  return page;
}
async function mutate(page, path, body, method = "POST") {
  const me = await (
    await page.context().request.get(`${origin}/api/me`)
  ).json();
  return page.context().request.fetch(`${origin}${path}`, {
    method,
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
try {
  const admin = (adminPage = await pageFor("admin")),
    owner = (ownerPage = await pageFor("member", true));
  const configuration = await (
    await admin.context().request.get(`${origin}/api/admin/settings`)
  ).json();
  if (
    !/^ws:\/\/(?:127\.0\.0\.1|localhost):18082\//.test(
      configuration.model.endpoint,
    )
  )
    throw new Error(
      "Refuse a provider endpoint that is not the explicit local mock.",
    );
  const courseResponse = await mutate(admin, "/api/admin/courses", {
    name: courseName,
    target_language: "en",
    guest_access: "public",
    enabled: true,
  });
  expect(courseResponse.ok()).toBe(true);
  courseId = (await courseResponse.json()).course.id;
  const lessonResponse = await mutate(
    owner,
    `/api/courses/${courseId}/lessons`,
    {
      name: "EXPLICIT SYNTHETIC FRONTEND REPLAY / MOCK PROVIDER",
      idempotency_key: crypto.randomUUID(),
    },
  );
  expect(lessonResponse.ok()).toBe(true);
  lessonId = (await lessonResponse.json()).lesson.id;
  await owner.goto(`${origin}/lessons/${lessonId}`);
  await expect(owner.getByText("课堂已连接", { exact: true })).toBeVisible();
  await owner.locator(".recorder-panel input[type=checkbox]").check();
  await owner.getByRole("button", { name: "开始收音", exact: true }).click();
  await expect
    .poll(() => owner.evaluate(() => window.__capture.packets.length), {
      timeout: 20000,
    })
    .toBeGreaterThanOrEqual(6);
  await owner.getByRole("button", { name: /暂停收音$/ }).click();
  await expect
    .poll(
      async () => {
        const data = await state(owner);
        return (
          data.lesson.status === "paused" &&
          data.units.some(
            (unit) => unit.kind === "source" && unit.status === "completed",
          )
        );
      },
      { timeout: 15000 },
    )
    .toBe(true);
  await expect
    .poll(async () => (await state(owner)).lesson.translation_status, {
      timeout: 15000,
    })
    .not.toBe("finishing");
  const unit = (await state(owner)).units.find(
    (item) => item.kind === "source" && item.status === "completed",
  );
  await admin.goto(`${origin}/lessons/${lessonId}`);
  await expect(admin.getByText("课堂已连接", { exact: true })).toBeVisible();
  await check(
    "actual viewer reconnect replays one subtitle correction and preserves its unsaved draft without duplicate display",
    async () => {
      await admin
        .locator(`[data-unit-id="${unit.id}"]`)
        .getByRole("button", { name: "校对", exact: true })
        .click();
      const draft = "Explicit synthetic unsaved draft ІЇЄҐ";
      await admin.getByRole("dialog").locator("textarea").fill(draft);
      const requestedAfter = await admin.evaluate(() =>
        window.__capture.subscriptions.at(-1),
      );
      expect(requestedAfter).toBeGreaterThan(0);
      await admin.context().setOffline(true);
      await admin.evaluate(() =>
        window.__capture.sockets.forEach((socket) => socket.close()),
      );
      const correction = "EXPLICIT SYNTHETIC REPLAY TEXT ІЇЄҐ";
      const update = await mutate(
        owner,
        `/api/lessons/${lessonId}/subtitles`,
        {
          changes: [
            {
              unit_id: unit.id,
              expected_version: unit.version,
              text: correction,
            },
          ],
          idempotency_key: crypto.randomUUID(),
        },
        "PATCH",
      );
      expect(update.ok()).toBe(true);
      await admin.context().setOffline(false);
      await expect
        .poll(
          () =>
            admin.evaluate(() =>
              window.__capture.snapshots.some(
                (snapshot) =>
                  snapshot.recovery?.mode === "replay" &&
                  snapshot.recovery.count > 0,
              ),
            ),
          { timeout: 20000 },
        )
        .toBe(true);
      await expect(admin.getByRole("dialog").locator("textarea")).toHaveValue(
        draft,
      );
      const snapshot = await admin.evaluate(() =>
        window.__capture.snapshots.find(
          (snapshot) =>
            snapshot.recovery?.mode === "replay" && snapshot.recovery.count > 0,
        ),
      );
      expect(snapshot.recovery.count).toBe(1);
      expect(snapshot.recovery.from_seq).toBe(requestedAfter);
      expect(snapshot.recovery.truncated).toBe(false);
      expect(snapshot.missed_events).toHaveLength(1);
      expect(snapshot.missed_events[0].event_type).toBe("subtitle.updated");
      expect(snapshot.missed_events[0].unit_id).toBe(unit.id);
      await admin
        .getByRole("dialog")
        .getByRole("button", { name: "取消", exact: true })
        .click();
      await expect(admin.locator(`[data-unit-id="${unit.id}"] p`)).toHaveText(
        correction,
      );
      await expect(admin.locator(`[data-unit-id="${unit.id}"]`)).toHaveCount(1);
      expect(await admin.evaluate(() => window.__capture.calls)).toBe(0);
      replayEvidence = {
        request_last_seq: requestedAfter,
        recovery: snapshot.recovery,
        snapshot_last_seq: snapshot.last_seq,
        missed_events: snapshot.missed_events,
        expected_unit_id: unit.id,
        persisted_version: (await state(owner)).units.find(
          (item) => item.id === unit.id,
        ).version,
        unsaved_draft_preserved: true,
        visible_unit_count: 1,
        viewer_microphone_requests: 0,
      };
    },
  );
  await check(
    "actual same-holder same-epoch paused outbox event stops the browser track before any audio rejection",
    async () => {
      const before = await owner.evaluate(
        () => window.__capture.packets.length,
      );
      await owner
        .getByRole("button", { name: "继续收音", exact: true })
        .click();
      await expect
        .poll(() => owner.evaluate(() => window.__capture.packets.length), {
          timeout: 20000,
        })
        .toBeGreaterThanOrEqual(before + 6);
      const live = (await state(owner)).lesson;
      expect(live.status).toBe("live");
      expect(live.recorder_user_id).toBe(fixture.accounts.member.id);
      await owner.exposeFunction("__pauseFixture", async () => {
        const sql = `WITH changed AS (UPDATE lessons SET status='paused',recorder_connection=NULL,lease_until=NULL,version=version+1,updated_at=now() WHERE id=${Number(lessonId)} AND course_id=${Number(courseId)} AND status='live' AND recorder_epoch=${Number(live.recorder_epoch)} AND recorder_user_id=${Number(live.recorder_user_id)} AND EXISTS(SELECT 1 FROM courses WHERE id=${Number(courseId)} AND name='${courseName}') RETURNING *), event AS (INSERT INTO lesson_events(lesson_id,event_type,payload) SELECT l.id,'lesson.updated',jsonb_build_object('lesson',(to_jsonb(l)-ARRAY['recorder_connection','idempotency_key','lease_until','model_settings_version','storage_version'])||jsonb_build_object('course_name',c.name,'access_mode',c.guest_access,'allow_audio_download',c.allow_audio_download,'recorder_name',u.name)) FROM changed l JOIN courses c ON c.id=l.course_id LEFT JOIN users u ON u.id=l.recorder_user_id RETURNING seq) SELECT json_build_object('seq',event.seq,'status',changed.status,'epoch',changed.recorder_epoch,'holder',changed.recorder_user_id) FROM event CROSS JOIN changed;`;
        return query(sql);
      });
      await owner.evaluate(() => {
        window.__capture.pauseOnNextAck = true;
      });
      await expect
        .poll(() => owner.evaluate(() => window.__capture.pauseResult), {
          timeout: 12000,
        })
        .toBeTruthy();
      await expect
        .poll(
          () =>
            owner.evaluate(() =>
              window.__capture.tracks.every(
                (track) => track.readyState === "ended",
              ),
            ),
          { timeout: 5000 },
        )
        .toBe(true);
      const observed = await owner.evaluate(() => ({
        fixture: window.__capture.pauseResult,
        stop: window.__capture.trackStops.at(-1),
        calls: window.__capture.calls,
        packets: window.__capture.packets.length,
        acks: window.__capture.acks.length,
      }));
      expect(observed.fixture.epoch).toBe(live.recorder_epoch);
      expect(observed.fixture.holder).toBe(live.recorder_user_id);
      expect(observed.stop.wire.type).toBe("event");
      expect(observed.stop.wire.event_type).toBe("lesson.updated");
      expect(observed.stop.wire.seq).toBe(observed.fixture.seq);
      expect(observed.stop.wire.status).toBe("paused");
      await owner.waitForTimeout(450);
      expect(await owner.evaluate(() => window.__capture.packets.length)).toBe(
        observed.packets,
      );
      expect((await state(owner)).lesson.status).toBe("paused");
      pauseEvidence = {
        fixture_injection:
          "isolated PostgreSQL lesson status and real gateway outbox; no production endpoint added",
        previous_status: "live",
        authoritative_status: "paused",
        same_epoch: true,
        same_holder: true,
        epoch: live.recorder_epoch,
        holder_id: live.recorder_user_id,
        server_event_seq: observed.fixture.seq,
        track_stop_caused_by: observed.stop.wire,
        all_tracks_stopped: true,
        packets_at_stop: observed.packets,
        packets_after_450ms: observed.packets,
        microphone_requests: observed.calls,
      };
    },
  );
  const end = await mutate(admin, `/api/admin/lessons/${lessonId}/end`, {
    confirmed: true,
  });
  expect(end.ok()).toBe(true);
  await expect
    .poll(async () => (await state(owner)).lesson.status, { timeout: 15000 })
    .toBe("ended");
  await admin.screenshot({
    path: fileURLToPath(
      new URL("actual-viewer-one-event-replay.png", artifactDirectory),
    ),
    fullPage: true,
  });
  expect(errors).toEqual([]);
  await writeFile(
    report,
    JSON.stringify(
      {
        classification:
          "ACTUAL_PHP_WEBSOCKET_POSTGRESQL_FRONTEND_REPLAY_SYNTHETIC_AUDIO_MOCK_PROVIDER",
        status: "passed",
        testedAt: new Date().toISOString(),
        real_php: true,
        real_postgresql: true,
        real_gateway: true,
        http_mocks: false,
        websocket_mocks: false,
        provider: "explicit local mock Qwen",
        real_model: false,
        real_oss: false,
        synthetic_audio: true,
        real_microphone: false,
        borrowed_archive_touched: false,
        long_test_services_touched: false,
        fixture: {
          database: "classroom_test",
          course_id: courseId,
          lesson_id: lessonId,
        },
        checks,
        replay: replayEvidence,
        server_pause: pauseEvidence,
        js_errors: errors,
      },
      null,
      2,
    ),
  );
} catch (error) {
  await writeFile(
    new URL("failure.json", artifactDirectory),
    JSON.stringify(
      {
        status: "failed",
        currentCheck,
        message: redact(error),
        checks,
        replay: replayEvidence,
        server_pause: pauseEvidence,
        js_errors: errors,
        lessonId,
        capture: ownerPage
          ? await ownerPage.evaluate(() => ({
              calls: window.__capture.calls,
              packets: window.__capture.packets.length,
              trackStops: window.__capture.trackStops,
              wire: window.__capture.wire.slice(-30),
            }))
          : null,
      },
      null,
      2,
    ),
  );
  throw error;
} finally {
  if (adminPage && lessonId) {
    try {
      if ((await state(adminPage)).lesson.status !== "ended")
        await mutate(adminPage, `/api/admin/lessons/${lessonId}/end`, {
          confirmed: true,
        });
    } catch {}
  }
  database.stdin.end();
  await browser.close();
}
