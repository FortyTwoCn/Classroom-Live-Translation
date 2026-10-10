import { describe, it, expect, vi, afterEach } from "vitest";
import {
  mergeUnits,
  activeUnits,
  timedUnit,
  hasSubtitleText,
} from "../../src/recovery";
import {
  displayDate,
  publicSettings,
  normalizeUtcDate,
} from "../../src/public-settings";
import { locale, t, missingMessageKeys } from "../../src/i18n";
import { api } from "../../src/api";
import { LessonSocket } from "../../src/realtime";
import type { Unit, Lesson } from "../../src/api";
const unit = (id: number, extra: Partial<Unit> = {}): Unit => ({
  id,
  kind: "source",
  text: `unit ${id}`,
  status: "completed",
  start_sample: 16000,
  end_sample: 32000,
  timing_precision: "server_segment",
  ...extra,
});
describe("historical recovery and playback evidence", () => {
  it("rejects empty and invisible captions while retaining punctuation, zero and multilingual text", () => {
    for (const text of [
      "",
      " \t\r\n",
      "\u00a0\u3000",
      "\u200b\u200f\ufeff",
      "\u180e\u2060\u202e",
    ])
      expect(hasSubtitleText(text)).toBe(false);
    for (const text of ["0", "，", " 中文 ", "ЇЄҐ", " hello "])
      expect(hasSubtitleText(text)).toBe(true);
  });
  it("removes blank cached and replayed captions without deleting unrelated historical content", () => {
    const result = mergeUnits(
      [unit(1), unit(2, { text: "\u3000" }), unit(3)],
      [
        unit(1, { text: "\u200b" }),
        unit(4, { text: "" }),
        unit(5, { kind: "translation", text: "译文" }),
      ],
    );
    expect(result.map((item) => item.id)).toEqual([3, 5]);
    expect(
      activeUnits(
        [unit(1, { text: "" }), unit(2, { deleted_at: "2026-10-10" }), unit(3)],
        1,
      ).map((item) => item.id),
    ).toEqual([3]);
  });
  it("keeps snapshot-excluded historical units while applying explicit updates and tombstones", () => {
    const result = mergeUnits(
      [unit(1), unit(2), unit(3)],
      [
        unit(2, { text: "edited", version: 2 }),
        unit(3, { deleted_at: "2026-10-09" }),
        unit(400),
      ],
    );
    expect(result.map((item) => item.id)).toEqual([1, 2, 400]);
    expect(result[1]?.text).toBe("edited");
  });
  it("highlights separate source/translation units only within trustworthy half-open ranges", () => {
    const units = [
      unit(1),
      unit(2, { kind: "translation" }),
      unit(3, { timing_precision: "unavailable" }),
      unit(4, { start_sample: null }),
    ];
    expect(activeUnits(units, 1).map((item) => item.id)).toEqual([1, 2]);
    expect(activeUnits(units, 2)).toEqual([]);
    expect(timedUnit(units[2]!)).toBe(false);
  });
  it("formats UTC dates in configured site time zone independently of interface language", () => {
    locale.value = "en";
    publicSettings.value.time_zone = "Asia/Shanghai";
    expect(normalizeUtcDate("2026-10-09 00:00:00")).toBe(
      "2026-10-09T00:00:00Z",
    );
    expect(normalizeUtcDate("2026-10-09 00:00:00.123456+00")).toBe(
      "2026-10-09T00:00:00.123456+00:00",
    );
    expect(displayDate("2026-10-09T00:00:00Z")).toContain("8:00");
    publicSettings.value.time_zone = "UTC";
    expect(displayDate("2026-10-09T00:00:00Z")).toContain("12:00");
    publicSettings.value.time_zone = "Asia/Shanghai";
  });
});
afterEach(() => vi.unstubAllGlobals());
describe("replay order and API mutations", () => {
  it("delivers missed events before merged snapshot and never decreases acknowledged event sequence", async () => {
    vi.stubGlobal("location", { href: "http://localhost/", protocol: "http:" });
    vi.stubGlobal("window", {
      setInterval: () => 1,
      clearInterval: () => {},
      setTimeout: () => 1,
      clearTimeout: () => {},
    });
    class Socket {
      static OPEN = 1;
      readyState = 1;
      onopen?: () => void;
      onmessage?: (event: { data: string }) => void;
      onclose?: () => void;
      onerror?: () => void;
      send = vi.fn();
      close = vi.fn();
      constructor(public url: string) {}
    }
    vi.stubGlobal("WebSocket", Socket);
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          new Response(JSON.stringify({ ticket: "explicit-test-ticket" })),
        ),
    );
    const socket = new LessonSocket(1, "ws://localhost/");
    socket.lastSeq = 10;
    const received: string[] = [];
    socket.onMessage = (message) =>
      received.push(`${message.type}:${message.seq ?? message.last_seq}`);
    await socket.open();
    (socket.socket as unknown as Socket).onmessage?.({
      data: JSON.stringify({
        type: "snapshot",
        lesson: { id: 1 } as Lesson,
        units: [],
        last_seq: 13,
        missed_events: [
          {
            type: "event",
            seq: 12,
            event_type: "subtitle.updated",
            payload: { unit: unit(1) },
          },
          {
            type: "event",
            seq: 11,
            event_type: "subtitle.updated",
            payload: { unit: unit(2) },
          },
        ],
      }),
    });
    expect(received).toEqual(["event:11", "event:12", "snapshot:13"]);
    expect(socket.lastSeq).toBe(13);
    socket.close();
  });
  it("sends PUT for versioned settings without converting decimal token prices to floats", async () => {
    const fetch = vi.fn().mockResolvedValue(new Response("{}"));
    vi.stubGlobal("fetch", fetch);
    const body = {
      values: { prices: [{ unit_price: "0.000000000001" }] },
      expected_version: null,
      confirmed: true,
    };
    await api.request("/api/admin/settings/policies", body, "PUT");
    expect(fetch.mock.calls[0]![1].method).toBe("PUT");
    expect(
      JSON.parse(fetch.mock.calls[0]![1].body).values.prices[0].unit_price,
    ).toBe("0.000000000001");
  });
});
it("covers dynamic C states, policy fields and safe probe steps in every locale", () => {
  missingMessageKeys.clear();
  const keys = [
    "termByteLimits",
    "settingsConflictHelp",
    ...["queued", "processing", "failed", "purged"].map(
      (status) => `purge_${status}`,
    ),
    ...[
      "model",
      "region",
      "workspace_id",
      "endpoint",
      "api_key",
      "type",
      "bucket",
      "key",
      "secret",
      "token",
      "prefix",
      "presign_seconds",
      "default_locale",
      "time_zone",
      "retention_days",
      "audio_download",
      "auto_purge",
      "max_lesson_seconds",
      "max_active_lessons",
      "max_daily_audio_seconds",
      "warning_ratio",
      "rotation_soft_seconds",
      "rotation_hard_seconds",
      "min_disk_bytes",
      "max_import_bytes",
      "max_import_rows",
    ].map((field) => `setting_${field}`),
    ...[
      "connection",
      "configuration",
      "session_updated_text",
      "session_finished",
      "put",
      "get",
      "head",
      "signed_get",
      "range",
      "private",
      "multipart_put_head",
      "multipart_get",
      "delete",
      "deleted_absent",
      "cleanup",
    ].map((step) => `probe_${step}`),
  ];
  for (const language of ["zh-CN", "en", "uk"] as const) {
    locale.value = language;
    for (const key of keys) t(key);
  }
  expect([...missingMessageKeys]).toEqual([]);
});
