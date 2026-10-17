<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import {
  api,
  ApiError,
  type User,
  type Course,
  type Lesson,
  type Unit,
  type Snapshot,
  type Participant,
  type Handoff,
  type CaptureIssue,
} from "./api";
import { locale, setLocale, t, type Locale } from "./i18n";
import { LessonSocket, type WireMessage } from "./realtime";
import { MicrophoneCapture } from "./audio/capture";
import { audioFrame, exceedsBackpressure } from "./audio/dsp";
import Modal from "./components/Modal.vue";
import Icon from "./components/Icon.vue";
import ShareDialog from "./components/ShareDialog.vue";
import ExportDialog from "./components/ExportDialog.vue";
import CorrectionDialog from "./components/CorrectionDialog.vue";
import AccountDialog from "./components/AccountDialog.vue";
import HandoffPanel from "./components/HandoffPanel.vue";
import Admin from "./components/Admin.vue";
import LessonAdmin from "./components/LessonAdmin.vue";
import UsagePanel from "./components/UsagePanel.vue";
import CourseSubtitleSearch from "./components/CourseSubtitleSearch.vue";
import {
  publicSettings,
  loadPublicSettings,
  displayDate,
  clockSeconds,
} from "./public-settings";
import { mergeUnits, activeUnits, timedUnit, hasSubtitleText } from "./recovery";

const user = ref<User | null>(null),
  courses = ref<Course[]>([]),
  lessons = ref<Lesson[]>([]);
const lesson = ref<Lesson | null>(null),
  units = ref<Unit[]>([]),
  course = ref<Course | null>(null);
const connection = ref("idle"),
  loading = ref(true),
  busy = ref(false),
  errorCode = ref("");
const showLogin = ref(false),
  showHeaderMenu = ref(false),
  showCreate = ref<"course" | "lesson" | null>(null),
  showEnd = ref(false);
const studentId = ref(""),
  password = ref(""),
  newPassword = ref(""),
  newName = ref(""),
  newDescription = ref("");
const existingLessonId = ref<number | null>(null);
const targetLanguage = ref("zh"),
  publicAccess = ref(true),
  selectedDevice = ref("");
const devices = ref<MediaDeviceInfo[]>([]),
  microphoneState = ref("idleMic"),
  micLevel = ref(0),
  inputRate = ref<number | null>(null),
  consent = ref(false),
  recording = ref(false),
  preparing = ref(false),
  testing = ref(false),
  online = ref<number | null>(null);
const playbackUrl = ref(""),
  player = ref<HTMLAudioElement | null>(null),
  playbackError = ref(false),
  playbackRate = ref("1");
const playbackPosition = ref(0),
  playbackDuration = ref(0),
  playbackFollow = ref(true),
  pendingSeek = ref<number | null>(null),
  locatedUnitId = ref<string | null>(null),
  issues = ref<CaptureIssue[]>([]),
  recoveryNotice = ref<{ mode: string; count: number } | null>(null),
  usageWarning = ref<{ code: string; seconds: number } | null>(null),
  rotationStatus = ref(""),
  showUsage = ref(false);
const playbackUnitIds = computed(
  () =>
    new Set(
      activeUnits(
        mergeUnits(units.value, historyUnits.value || []),
        playbackPosition.value,
      ).map((unit) => String(unit.id)),
    ),
);
const captions = ref<HTMLElement | null>(null),
  autoFollow = ref(true),
  unseen = ref(0);
const selectedView = ref("both");
const compactMedia = window.matchMedia("(max-width: 900px)");
const compactLayout = ref(compactMedia.matches),
  showReaderOptions = ref(false),
  showCaptionActions = ref(false),
  readingFontSize = ref([16, 18, 20, 24, 28].includes(Number(localStorage.getItem("classroom.readingFontSize")))
    ? Number(localStorage.getItem("classroom.readingFontSize")) : 18);
let followFrame: number | null = null;
let layoutFrame: number | null = null;
let captionLayoutChanging = false;
let captionLayoutGeneration = 0;
function updateCompactLayout(event: MediaQueryListEvent) { compactLayout.value = event.matches; }
function cancelCaptionLayout() {
  captionLayoutGeneration++;
  if (layoutFrame !== null) cancelAnimationFrame(layoutFrame);
  layoutFrame = null;
  captionLayoutChanging = false;
}
function settleCaptionLayout() {
  if (!autoFollow.value) return;
  cancelCaptionLayout();
  const generation = captionLayoutGeneration;
  captionLayoutChanging = true;
  void nextTick(() => {
    if (generation !== captionLayoutGeneration) return;
    followCaptions();
    layoutFrame = requestAnimationFrame(() => {
      layoutFrame = requestAnimationFrame(() => {
        layoutFrame = null;
        captionLayoutChanging = false;
      });
    });
  });
}
function setCaptionSize(event: Event) {
  const size = Number((event.target as HTMLSelectElement).value);
  if (largeMode.value) fontSize.value = size;
  else readingFontSize.value = size;
}
const view = ref("courses"),
  showShare = ref(false),
  showExport = ref(false),
  showAccount = ref(false),
  showRename = ref(false),
  renameText = ref(""),
  remember = ref(false);
const largeMode = ref(false),
  fontSize = ref(Number(localStorage.getItem("classroom.fontSize")) || 32),
  fullScreen = ref(false);
const correction = ref<{ unit: Unit; mode: "edit" | "history" } | null>(null),
  drafts = ref<Record<string, string>>({});
const currentClientId = ref("");
const participants = ref<Participant[]>([]),
  handoffs = ref<Handoff[]>([]),
  preparation = ref<{ id: string; live: boolean } | null>(null);
const lessonSearch = ref(""),
  lessonSort = ref("created_at"),
  lessonDirection = ref("desc"),
  lessonStatus = ref("all"),
  lessonPage = ref(1),
  lessonTotal = ref(0);
const subtitleSearch = ref(""),
  subtitleMatches = ref<Unit[] | null>(null),
  searchingSubtitles = ref(false);
const earlierAvailable = ref(true);
const historyUnits = ref<Unit[] | null>(null),
  subtitlePage = ref(1),
  subtitleTotal = ref(0);
const reauthPassword = ref(""),
  showReauth = ref(false);
let pendingForce = false,
  normalScroll = 0;
let socketClient: LessonSocket | null = null,
  wsUrl = "",
  epoch = 0,
  sequence = 0,
  commandPending = false;
let startTimer: number | null = null,
  startGeneration = 0,
  viewGeneration = 0;
const outstanding = new Set<number>();
const microphone = new MicrophoneCapture();
let wakeLock: WakeLockSentinel | null = null;
let lastLessonSeq = 0;
let lastAckSequence = -1;
let playbackLoading = false,
  lastPlaybackQuery = -1;
const pendingCaptureIssues = ref<
  Array<{ lessonId: number; body: Record<string, unknown> }>
>([]);
const permitted = computed(
  () => !!user.value && !user.value.must_change_password,
);
const isRecorder = computed(
  () =>
    permitted.value &&
    lesson.value?.recorder_user_id === user.value?.id &&
    lesson.value?.status !== "ended",
);
const canEnd = computed(
  () =>
    permitted.value &&
    lesson.value?.status !== "ended" &&
    (isRecorder.value ||
      lesson.value?.creator_id === user.value?.id ||
      user.value?.role === "admin"),
);
const displayedUnits = computed(() =>
  (subtitleMatches.value || historyUnits.value || units.value.slice(-500))
    .filter((unit) => !unit.deleted_at && hasSubtitleText(unit.text))
    .filter(
      (unit) =>
        selectedView.value === "both" || unit.kind === selectedView.value,
    ),
);
const hasExportable = computed(() =>
  units.value.some(
    (unit) =>
      unit.status === "completed" && hasSubtitleText(unit.text) && !unit.deleted_at,
  ),
);
const microphoneRunning = computed(
  () => recording.value || testing.value || preparing.value,
);
const message = computed(() =>
  errorCode.value ? t(`error_${errorCode.value}`) : "",
);
const duration = (samples: number | undefined) => {
  const seconds = Math.floor((samples || 0) / 16000);
  const minutes = Math.floor(seconds / 60),
    hours = Math.floor(minutes / 60);
  return hours
    ? `${hours}:${String(minutes % 60).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`
    : `${String(minutes).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;
};
const getError = (error: unknown) =>
  error instanceof ApiError
    ? error.code
    : error instanceof DOMException
      ? error.name
      : error instanceof Error
        ? error.message
        : "generic";
async function action(callback: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  errorCode.value = "";
  try {
    await callback();
  } catch (error) {
    errorCode.value = getError(error);
    if (error instanceof ApiError && error.code === "active_lesson_exists") {
      const id = Number(error.params.lesson_id);
      existingLessonId.value = Number.isSafeInteger(id) && id > 0 ? id : null;
    }
  } finally {
    busy.value = false;
  }
}

async function refreshIdentity() {
  const identity = await api.request<{
    user: User | null;
    csrf_token: string;
    ws_url: string;
  }>("/api/me");
  user.value = identity.user;
  wsUrl = identity.ws_url;
}
async function login() {
  await action(async () => {
    await api.request("/api/login", {
      student_id: studentId.value,
      password: password.value,
      remember: remember.value,
    });
    password.value = "";
    showLogin.value = false;
    await refreshIdentity();
    await loadView();
  });
}
async function savePassword() {
  await action(async () => {
    await api.request("/api/password", { password: newPassword.value });
    newPassword.value = "";
    await refreshIdentity();
    await loadView();
  });
}
async function logout() {
  await action(async () => {
    await safePause();
    socketClient?.close();
    await api.request("/api/logout", {});
    user.value = null;
    await refreshIdentity();
    await loadView();
  });
}
async function accountSignedOut() {
  showAccount.value = false;
  await safePause();
  socketClient?.close();
  await refreshIdentity();
  await loadView();
}
function openCreation(kind: "course" | "lesson") {
  existingLessonId.value = null;
  errorCode.value = "";
  showCreate.value = kind;
  newDescription.value = "";
  newName.value = kind === "lesson" ? t("newLesson") : "";
}
async function create() {
  await action(async () => {
    if (showCreate.value === "course") {
      await api.request("/api/courses", {
        name: newName.value,
        description: newDescription.value,
        target_language: targetLanguage.value,
        guest_access: publicAccess.value ? "public" : "link",
      });
      showCreate.value = null;
      await loadView();
    } else if (course.value) {
      const created = await api.request<{ lesson: Lesson }>(
        `/api/courses/${course.value.id}/lessons`,
        { name: newName.value, idempotency_key: crypto.randomUUID() },
      );
      showCreate.value = null;
      await navigate(`/lessons/${created.lesson.id}`);
    }
  });
}
async function navigate(path: string) {
  await safePause();
  history.pushState({}, "", path);
  await loadView();
}
async function loadView() {
  const generation = ++viewGeneration;
  if (followFrame !== null) { cancelAnimationFrame(followFrame); followFrame = null; }
  cancelCaptionLayout();
  showReaderOptions.value = false;
  showCaptionActions.value = false;
  await stopCapture(true);
  socketClient?.close();
  socketClient = null;
  epoch = 0;
  sequence = 0;
  lastAckSequence = -1;
  lastLessonSeq = 0;
  outstanding.clear();
  lesson.value = null;
  course.value = null;
  units.value = [];
  playbackUrl.value = "";
  online.value = null;
  loading.value = true;
  errorCode.value = "";
  autoFollow.value = localStorage.getItem("classroom.autoFollow") !== "false";
  unseen.value = 0;
  largeMode.value = false;
  participants.value = [];
  handoffs.value = [];
  preparation.value = null;
  subtitleMatches.value = null;
  historyUnits.value = null;
  earlierAvailable.value = true;
  subtitlePage.value = 1;
  subtitleTotal.value = 0;
  currentClientId.value = "";
  issues.value = [];
  recoveryNotice.value = null;
  lastPlaybackQuery = -1;
  usageWarning.value = null;
  rotationStatus.value = "";
  showUsage.value = false;
  playbackPosition.value = 0;
  playbackDuration.value = 0;
  pendingSeek.value = null;
  locatedUnitId.value = null;
  subtitleSearch.value = "";
  drafts.value = {};
  correction.value = null;
  showShare.value = false;
  showExport.value = false;
  view.value = "courses";
  try {
    const lessonRoute = location.pathname.match(/^\/lessons\/(\d+)\/?$/),
      courseRoute = location.pathname.match(/^\/courses\/(\d+)\/lessons\/?$/);
    if (location.pathname === "/admin") {
      view.value = "admin";
      if (user.value?.role !== "admin" || !permitted.value)
        throw new ApiError("forbidden");
      return;
    }
    if (location.pathname === "/share-unavailable") {
      view.value = "share-unavailable";
      errorCode.value =
        new URLSearchParams(location.search).get("code") || "share_invalid";
      return;
    }
    if (lessonRoute) {
      const snapshot = await api.request<Snapshot>(
        `/api/lessons/${lessonRoute[1]}`,
      );
      if (generation !== viewGeneration) return;
      applySnapshot(snapshot);
      socketClient = new LessonSocket(snapshot.lesson.id, wsUrl);
      socketClient.seed(snapshot);
      socketClient.onState = (state) => {
        connection.value = state;
        if (state === "connected") void retryCaptureIssues();
      };
      socketClient.onMessage = receiveMessage;
      socketClient.onDisconnect = () => {
        if (microphoneRunning.value) void safePause("connection_lost", false);
      };
      socketClient.onError = (code) => {
        errorCode.value = code;
        // A rejected invitation must not stop the valid current recorder.
        const fatal = new Set([
          "not_recorder",
          "recorder_stale",
          "stale_epoch",
          "session_revoked",
          "access_revoked",
          "model_unavailable",
          "translation_interrupted",
          "storage_unavailable",
          "database_unavailable",
          "invalid_audio_frame",
          "audio_sequence_conflict",
          "audio_sequence_gap",
          "audio_rate_limited",
          "translation_not_ready",
          "duration_limit",
          "daily_audio_limit",
          "disk_capacity_limit",
          "gateway_failure",
          "gateway_lock_lost",
          "connection_lost",
        ]);
        if (microphoneRunning.value && (preparing.value || fatal.has(code)))
          void safePause(code);
      };
      void socketClient.open();
      const deepUnit = new URLSearchParams(location.search).get("unit_id");
      if (deepUnit && /^\d+$/.test(deepUnit)) await locateUnit(deepUnit);
      if (isRecorder.value) await refreshDevices();
    } else {
      const result = await api.request<{ courses: Course[] }>("/api/courses");
      if (generation !== viewGeneration) return;
      courses.value = result.courses;
      if (courseRoute) {
        course.value =
          result.courses.find((item) => item.id === Number(courseRoute[1])) ||
          null;
        const query = new URLSearchParams(location.search);
        lessonSearch.value = query.get("search") || "";
        lessonSort.value = query.get("sort") || "created_at";
        lessonDirection.value = query.get("direction") || "desc";
        lessonStatus.value = query.get("status") || "all";
        lessonPage.value = Number(query.get("page")) || 1;
        const resultLessons = await api.request<{
          lessons: Lesson[];
          total?: number;
        }>(`/api/courses/${courseRoute[1]}/lessons?${lessonQuery()}`);
        if (generation !== viewGeneration) return;
        lessons.value = resultLessons.lessons;
        lessonTotal.value = resultLessons.total || resultLessons.lessons.length;
      }
    }
  } catch (error) {
    if (generation === viewGeneration) errorCode.value = getError(error);
  } finally {
    if (generation === viewGeneration) loading.value = false;
  }
}
function applySnapshot(snapshot: Snapshot) {
  lesson.value = snapshot.lesson;
  units.value = mergeUnits(units.value, snapshot.units);
  if (historyUnits.value)
    historyUnits.value = mergeUnits(
      historyUnits.value,
      snapshot.units.filter((unit) =>
        historyUnits.value?.some((existing) => existing.id === unit.id),
      ),
    );
  if (subtitleMatches.value)
    subtitleMatches.value = mergeUnits(
      subtitleMatches.value,
      snapshot.units.filter((unit) =>
        subtitleMatches.value?.some((existing) => existing.id === unit.id),
      ),
    );
  if (snapshot.issues) issues.value = snapshot.issues;
  lastLessonSeq = Math.max(lastLessonSeq, snapshot.last_seq);
  if (autoFollow.value) void nextTick(followCaptions);
}
function upsertUnit(unit: Unit) {
  const index = units.value.findIndex((existing) => existing.id === unit.id);
  if (unit.deleted_at || !hasSubtitleText(unit.text)) {
    if (index !== -1) units.value.splice(index, 1);
    for (const collection of [historyUnits, subtitleMatches])
      if (collection.value)
        collection.value = collection.value.filter(
          (existing) => existing.id !== unit.id,
        );
    return;
  }
  if (index === -1) units.value.push(unit);
  else units.value[index] = unit;
  for (const collection of [historyUnits, subtitleMatches]) {
    const position =
      collection.value?.findIndex((existing) => existing.id === unit.id) ?? -1;
    if (position !== -1 && collection.value) collection.value[position] = unit;
  }
  if (!autoFollow.value && index === -1) unseen.value++;
  if (autoFollow.value) void nextTick(() => followCaptions());
}
function receiveMessage(message: WireMessage) {
  if (message.type === "snapshot") {
    applySnapshot(message as unknown as Snapshot);
    if (message.recovery)
      recoveryNotice.value = {
        mode: message.recovery.mode,
        count: message.recovery.count,
      };
  }
  if (message.type === "usage.warning")
    usageWarning.value = {
      code: message.code || "lesson_duration_warning",
      seconds: Math.max(0, Math.floor(message.remaining_seconds || 0)),
    };
  if (message.type === "translation.rotation") {
    rotationStatus.value = ["preparing", "failed"].includes(message.phase || "")
      ? message.phase!
      : "";
  }
  if (message.type === "subtitle.partial" && message.unit)
    upsertUnit(message.unit);
  if (message.type === "event") {
    if (message.event_type === "usage.warning") {
      const info = message.payload as unknown as {
        code: string;
        remaining_seconds: number;
      };
      usageWarning.value = {
        code: info.code,
        seconds: Math.max(0, Math.floor(info.remaining_seconds || 0)),
      };
    }
    if (message.event_type === "translation.rotation") {
      const phase = (message.payload as unknown as { phase: string }).phase;
      rotationStatus.value = ["preparing", "failed"].includes(phase)
        ? phase
        : "";
    }
    if (message.event_type === "recording.issue") void reloadIssues();
    if (message.event_type === "subtitle.updated" && message.payload?.unit)
      upsertUnit(message.payload.unit);
    if (message.event_type === "subtitles.updated" && message.payload?.units)
      message.payload.units.forEach(upsertUnit);
    if (message.event_type === "lesson.updated" && message.payload?.lesson) {
      const update = message.payload.lesson;
      if ((message.seq || 0) <= lastLessonSeq) return;
      lastLessonSeq = message.seq || lastLessonSeq;
      // recorder.ready may overtake older outbox snapshots during handoff.
      // Their previous epoch must not revoke the newly prepared device.
      if (lesson.value && update.recorder_epoch < lesson.value.recorder_epoch)
        return;
      if (
        (recording.value || preparing.value) &&
        (update.status === "ended" ||
          update.recorder_user_id !== user.value?.id ||
          (recording.value && epoch > 0 && update.recorder_epoch !== epoch))
      )
        void safePause("stale_epoch", false);
      else if (recording.value && update.status === "paused")
        // An authoritative server pause (for example a rotation deadline)
        // must stop capture immediately, even if holder and epoch remain.
        void stopCapture(false);
      lesson.value = update;
    }
    if (
      message.event_type === "presence.updated" &&
      typeof message.payload?.count === "number"
    )
      online.value = message.payload.count;
  }
  if (message.type === "presence" && typeof message.count === "number") {
    online.value = message.count;
    participants.value = message.participants || [];
    currentClientId.value = message.current_client_id || "";
  }
  if (
    message.type === "lesson.progress" &&
    lesson.value &&
    typeof message.media_samples === "number"
  )
    lesson.value.media_samples = message.media_samples;
  if (message.type === "handoffs") handoffs.value = message.items || [];
  if (message.type === "recording.prepare" && message.id) {
    preparation.value = { id: message.id, live: !!message.live };
  }
  if (message.type === "recording.transferred") {
    preparation.value = null;
    if (!recording.value) void stopCapture(false);
  }
  if (message.type === "audio.ack" && message.sequence !== undefined) {
    lastAckSequence = Math.max(lastAckSequence, message.sequence);
    for (const sentSequence of outstanding)
      if (sentSequence <= message.sequence) outstanding.delete(sentSequence);
    if (lesson.value && typeof message.media_samples === "number")
      lesson.value.media_samples = message.media_samples;
  }
  if (message.type === "recorder.ready") {
    // Handoff readiness can arrive before the outbox's lesson.updated.
    // Apply the authoritative snapshot without rebuilding the microphone.
    if (message.lesson) {
      lesson.value = message.lesson;
      lastLessonSeq = Math.max(lastLessonSeq, message.lesson_seq || 0);
    }
    if (
      !preparing.value ||
      !isRecorder.value ||
      !microphone.active ||
      !Number.isInteger(message.epoch) ||
      !Number.isInteger(message.next_sequence)
    ) {
      socketClient?.command("pause", { epoch: message.epoch });
      return;
    }
    clearStartTimer();
    epoch = message.epoch!;
    sequence = message.next_sequence!;
    lastAckSequence = sequence - 1;
    outstanding.clear();
    if (lesson.value) lesson.value.recorder_epoch = epoch;
    if (lesson.value && message.media_samples !== undefined)
      lesson.value.media_samples = message.media_samples;
    try {
      microphone.start();
      recording.value = true;
      preparing.value = false;
      commandPending = false;
      preparation.value = null;
      microphoneState.value = "activeMic";
      void requestWakeLock();
    } catch (error) {
      void safePause(getError(error));
    }
  }
}
function followCaptions() {
  if (followFrame !== null) return;
  followFrame = requestAnimationFrame(() => {
    followFrame = null;
    if (autoFollow.value && captions.value)
      captions.value.scrollTop = captions.value.scrollHeight;
  });
}
function returnToLive() {
  autoFollow.value = true;
  unseen.value = 0;
  historyUnits.value = null;
  subtitleMatches.value = null;
  subtitleSearch.value = "";
  void nextTick(followCaptions);
}
function captionScroll() {
  if (captionLayoutChanging) return;
  const element = captions.value;
  if (
    element &&
    element.scrollHeight - element.scrollTop - element.clientHeight > 60
  )
    autoFollow.value = false;
}
function pauseForSelection() {
  if (window.getSelection()?.toString()) autoFollow.value = false;
}
function lessonQuery() {
  return new URLSearchParams({
    search: lessonSearch.value,
    sort: lessonSort.value,
    direction: lessonDirection.value,
    status: lessonStatus.value,
    page: String(lessonPage.value),
  }).toString();
}
async function filterLessons(reset = true) {
  if (!course.value) return;
  if (reset) lessonPage.value = 1;
  history.replaceState(
    {},
    "",
    `/courses/${course.value.id}/lessons?${lessonQuery()}`,
  );
  await action(async () => {
    const result = await api.request<{ lessons: Lesson[]; total: number }>(
      `/api/courses/${course.value!.id}/lessons?${lessonQuery()}`,
    );
    lessons.value = result.lessons;
    lessonTotal.value = result.total;
  });
}
function openCorrection(unit: Unit, mode: "edit" | "history") {
  autoFollow.value = false;
  correction.value = { unit: { ...unit }, mode };
}
function saveCorrections(saved: Unit[]) {
  saved.forEach(upsertUnit);
  for (const unit of saved) delete drafts.value[String(unit.id)];
}
async function renameLesson() {
  if (!lesson.value) return;
  await action(async () => {
    const result = await api.request<{ lesson: Lesson }>(
      `/api/lessons/${lesson.value!.id}`,
      { name: renameText.value, expected_version: lesson.value!.version },
      "PATCH",
    );
    lesson.value = result.lesson;
    showRename.value = false;
  });
}
async function toggleLarge() {
  if (!largeMode.value) normalScroll = captions.value?.scrollTop || 0;
  largeMode.value = !largeMode.value;
  await nextTick();
  if (autoFollow.value) followCaptions();
  else if (!largeMode.value && captions.value)
    captions.value.scrollTop = normalScroll;
}
async function toggleFullscreen() {
  try {
    if (document.fullscreenElement) await document.exitFullscreen();
    else await document.documentElement.requestFullscreen();
  } catch {
    /* The in-page large-subtitle layout remains usable. */
  }
}
function handoffCommand(type: string, fields: Record<string, unknown>) {
  if (!socketClient?.command(type, fields)) errorCode.value = "connection_lost";
  if (type === "recording.prepared" && fields.ready === false) {
    preparation.value = null;
    void stopCapture(false);
  }
}
async function prepareHandoff(id: string) {
  if (!permitted.value || preparing.value) return;
  errorCode.value = "";
  preparing.value = true;
  try {
    await prepareMicrophone();
    handoffCommand("recording.prepared", { id, ready: true });
    microphoneState.value = "pendingStart";
    startTimer = window.setTimeout(() => {
      handoffCommand("recording.prepared", { id, ready: false });
      void safePause("start_timeout", false);
    }, 20000);
  } catch (e) {
    handoffCommand("recording.prepared", { id, ready: false });
    await stopCapture(false);
    errorCode.value = getError(e);
  }
}
async function takeover(force: boolean) {
  if (!permitted.value) return;
  if (force && !pendingForce) {
    pendingForce = true;
    showReauth.value = true;
    return;
  }
  errorCode.value = "";
  preparing.value = true;
  try {
    await prepareMicrophone();
    handoffCommand("recording.takeover", {
      confirmed: true,
      prepared: true,
      ...(force ? { force: true } : {}),
    });
    microphoneState.value = "pendingStart";
    startTimer = window.setTimeout(
      () => void safePause("start_timeout", false),
      20000,
    );
  } catch (e) {
    await stopCapture(false);
    errorCode.value = getError(e);
  } finally {
    pendingForce = false;
  }
}
async function forceReauth() {
  await action(async () => {
    await api.request("/api/reauth", { password: reauthPassword.value });
    reauthPassword.value = "";
    showReauth.value = false;
    await takeover(true);
  });
}
async function freezeEditing() {
  if (!lesson.value) return;
  await action(async () => {
    await api.request(
      `/api/admin/lessons/${lesson.value!.id}/editing`,
      { frozen: !lesson.value!.editing_frozen, confirmed: true },
      "PATCH",
    );
  });
}
async function searchSubtitles(reset = true) {
  if (!lesson.value) return;
  if (reset) subtitlePage.value = 1;
  autoFollow.value = false;
  searchingSubtitles.value = true;
  try {
    if (!subtitleSearch.value.trim()) {
      subtitleMatches.value = null;
      return;
    }
    const result = await api.request<{
      units: Unit[];
      total: number;
      has_more: boolean;
    }>(
      `/api/lessons/${lesson.value.id}/subtitles?q=${encodeURIComponent(subtitleSearch.value)}&page=${subtitlePage.value}`,
    );
    subtitleMatches.value = result.units;
    subtitleTotal.value = result.total;
  } catch (e) {
    errorCode.value = getError(e);
  } finally {
    searchingSubtitles.value = false;
  }
}
async function loadEarlier() {
  if (!lesson.value || searchingSubtitles.value || !units.value.length) return;
  autoFollow.value = false;
  const oldHeight = captions.value?.scrollHeight || 0,
    oldTop = captions.value?.scrollTop || 0;
  searchingSubtitles.value = true;
  try {
    const base = historyUnits.value || units.value.slice(-500),
      firstId = base
        .filter((unit) => typeof unit.id === "number")
        .reduce(
          (minimum, unit) => Math.min(minimum, Number(unit.id)),
          Number.MAX_SAFE_INTEGER,
        );
    const result = await api.request<{ units: Unit[]; has_more: boolean }>(
      `/api/lessons/${lesson.value.id}/subtitles?before_id=${firstId}`,
    );
    const ids = new Set(base.map((unit) => unit.id));
    historyUnits.value = [
      ...result.units.filter((unit) => !ids.has(unit.id)),
      ...base,
    ].slice(0, 500);
    earlierAvailable.value = result.has_more;
    await nextTick();
    if (captions.value)
      captions.value.scrollTop =
        oldTop + Math.max(0, captions.value.scrollHeight - oldHeight);
  } catch (e) {
    errorCode.value = getError(e);
  } finally {
    searchingSubtitles.value = false;
  }
}
async function refreshDevices() {
  if (!isRecorder.value) return;
  try {
    devices.value = (
      (await navigator.mediaDevices?.enumerateDevices()) || []
    ).filter((device) => device.kind === "audioinput");
  } catch {
    errorCode.value = "NotFoundError";
  }
}
async function prepareMicrophone() {
  microphoneState.value = "preparingMic";
  await microphone.prepare(selectedDevice.value);
  inputRate.value = microphone.sampleRate;
  await refreshDevices();
}
async function testMicrophone() {
  if (!isRecorder.value || preparing.value || recording.value) return;
  errorCode.value = "";
  const generation = ++startGeneration;
  preparing.value = true;
  try {
    await prepareMicrophone();
    if (generation !== startGeneration) return;
    preparing.value = false;
    testing.value = true;
    microphoneState.value = "localTest";
  } catch (error) {
    await stopCapture(false);
    errorCode.value = getError(error);
  }
}
async function startCapture() {
  if (
    !isRecorder.value ||
    !consent.value ||
    connection.value !== "connected" ||
    commandPending ||
    preparing.value ||
    recording.value
  )
    return;
  errorCode.value = "";
  commandPending = true;
  preparing.value = true;
  testing.value = false;
  const generation = ++startGeneration;
  try {
    await prepareMicrophone();
    if (generation !== startGeneration) return;
    microphoneState.value = "pendingStart";
    const command =
      lesson.value?.status === "paused" || lesson.value?.status === "live"
        ? "resume"
        : "start";
    if (
      !socketClient?.command(
        command,
        command === "start" ? {} : { epoch: lesson.value?.recorder_epoch },
      )
    )
      throw new ApiError("connection_lost");
    startTimer = window.setTimeout(() => {
      void safePause("start_timeout");
    }, 15000);
  } catch (error) {
    await safePause(getError(error));
  }
}
function clearStartTimer() {
  if (startTimer !== null) window.clearTimeout(startTimer);
  startTimer = null;
}
async function stopCapture(flush: boolean): Promise<boolean> {
  startGeneration++;
  clearStartTimer();
  const waitForAcknowledgements = flush && recording.value;
  // Keep recording=true until a requested flush reaches the socket in order.
  if (!flush) recording.value = false;
  await microphone.stop(flush && recording.value);
  recording.value = false;
  preparing.value = false;
  testing.value = false;
  commandPending = false;
  microphoneState.value = "idleMic";
  rotationStatus.value = "";
  inputRate.value = null;
  const lock = wakeLock;
  wakeLock = null;
  await lock?.release().catch(() => {});
  if (waitForAcknowledgements && outstanding.size) {
    const acknowledged = await new Promise<boolean>((resolve) => {
      const deadline = performance.now() + 1500;
      const check = () => {
        if (!outstanding.size) {
          resolve(true);
          return;
        }
        if (
          performance.now() >= deadline ||
          socketClient?.socket?.readyState !== WebSocket.OPEN
        ) {
          resolve(false);
          return;
        }
        window.setTimeout(check, 25);
      };
      check();
    });
    if (!acknowledged) {
      errorCode.value = "audio_ack_timeout";
      return false;
    }
  }
  return true;
}
let pausing = false;
async function safePause(code = "", sendCommand = true) {
  if (pausing) return;
  pausing = true;
  try {
    const hadCapture = recording.value || preparing.value;
    const acknowledged = await stopCapture(code === "");
    if (!acknowledged && !code) code = "audio_ack_timeout";
    if (hadCapture && sendCommand)
      socketClient?.command("pause", {
        epoch: lesson.value?.recorder_epoch || epoch,
      });
    if (code) errorCode.value = code;
    if (code && hadCapture && epoch > 0) void reportCaptureIssue(code);
  } finally {
    pausing = false;
  }
}
async function endLesson() {
  showEnd.value = false;
  const acknowledged = await stopCapture(true);
  if (!acknowledged) {
    socketClient?.command("pause", {
      epoch: lesson.value?.recorder_epoch || epoch,
    });
    void reportCaptureIssue("audio_ack_timeout");
    return;
  }
  socketClient?.command("end", {
    epoch: epoch || lesson.value?.recorder_epoch,
    confirmed: true,
  });
}
async function requestWakeLock() {
  if ("wakeLock" in navigator && document.visibilityState === "visible")
    try {
      const lock = await navigator.wakeLock.request("screen");
      if (recording.value) wakeLock = lock;
      else await lock.release();
    } catch {
      /* Foreground warning remains; wake lock is best effort. */
    }
}
microphone.onMeter = (rms) => {
  micLevel.value = Math.min(1, rms * 5);
};
microphone.onFailure = (code) => {
  void safePause(code);
};
microphone.onPacket = (pcm) => {
  if (!recording.value) return;
  const socket = socketClient?.socket;
  if (!socket || socket.readyState !== WebSocket.OPEN) {
    void safePause("connection_lost", false);
    return;
  }
  if (exceedsBackpressure(socket.bufferedAmount, outstanding.size)) {
    void safePause("network_backpressure");
    return;
  }
  const sentSequence = sequence++;
  try {
    socket.send(audioFrame(epoch, sentSequence, pcm));
    outstanding.add(sentSequence);
  } catch {
    void safePause("connection_lost", false);
  }
};
async function loadPlayback() {
  await action(async () => {
    if (!lesson.value) return;
    const position = pendingSeek.value ?? player.value?.currentTime ?? 0;
    const result = await api.request<{ url: string; expires_at: string }>(
      `/api/lessons/${lesson.value.id}/playback`,
    );
    playbackError.value = false;
    playbackUrl.value = result.url;
    await nextTick();
    const audio = player.value;
    if (audio) {
      const restore = () => {
        audio.currentTime = position;
        audio.playbackRate = Number(playbackRate.value);
        pendingSeek.value = null;
        playbackPosition.value = position;
        playbackDuration.value = Number.isFinite(audio.duration)
          ? audio.duration
          : 0;
      };
      audio.addEventListener("loadedmetadata", restore, { once: true });
      // Setting src already starts loading. Calling load() here cancels and
      // duplicates that private Range download. Cached metadata may be ready.
      if (audio.readyState >= HTMLMediaElement.HAVE_METADATA) restore();
    }
  });
}
function seek(unit: Unit) {
  autoFollow.value = false;
  if (player.value && timedUnit(unit)) {
    player.value.currentTime = unit.start_sample! / 16000;
    playbackPosition.value = player.value.currentTime;
    locatedUnitId.value = String(unit.id);
    scrollToUnit(String(unit.id));
  }
}
function scrollToUnit(id: string) {
  void nextTick(() => {
    const element = captions.value?.querySelector<HTMLElement>(
      `[data-unit-id="${id}"]`,
    );
    if (element && captions.value)
      captions.value.scrollTop = Math.max(
        0,
        element.offsetTop - captions.value.offsetTop - 36,
      );
  });
}
async function locateUnit(id: string) {
  if (!lesson.value) return;
  const result = await api.request<{ units: Unit[]; has_more?: boolean }>(
    `/api/lessons/${lesson.value.id}/subtitles?around_id=${encodeURIComponent(id)}`,
  );
  historyUnits.value = mergeUnits(historyUnits.value || [], result.units);
  units.value = mergeUnits(units.value, result.units);
  autoFollow.value = false;
  locatedUnitId.value = id;
  const unit = result.units.find((item) => String(item.id) === id);
  if (unit && timedUnit(unit)) {
    pendingSeek.value = unit.start_sample! / 16000;
    playbackPosition.value = pendingSeek.value;
    if (lesson.value.archive_status === "playable") await loadPlayback();
  }
  scrollToUnit(id);
}
function onPlaybackTime() {
  if (!player.value) return;
  playbackPosition.value = player.value.currentTime;
  const active = activeUnits(
    mergeUnits(units.value, historyUnits.value || []),
    playbackPosition.value,
  )[0];
  if (
    !active ||
    !displayedUnits.value.some((unit) => String(unit.id) === String(active.id))
  ) {
    void loadAtPlaybackTime();
    return;
  }
  if (playbackFollow.value) {
    autoFollow.value = false;
    const current = activeUnits(
      mergeUnits(units.value, historyUnits.value || []),
      playbackPosition.value,
    )[0];
    if (current) scrollToUnit(String(current.id));
    else void loadAtPlaybackTime();
  }
}
async function loadAtPlaybackTime() {
  if (!lesson.value || playbackLoading) return;
  const sample = Math.floor(playbackPosition.value * 16000);
  if (lastPlaybackQuery >= 0 && Math.abs(lastPlaybackQuery - sample) < 16000)
    return;
  lastPlaybackQuery = sample;
  playbackLoading = true;
  const generation = viewGeneration;
  try {
    const result = await api.request<{ units: Unit[] }>(
      `/api/lessons/${lesson.value.id}/subtitles?at_sample=${sample}`,
    );
    if (generation !== viewGeneration || result.units.length === 0) return;
    units.value = mergeUnits(units.value, result.units);
    historyUnits.value = mergeUnits(historyUnits.value || [], result.units);
    if (playbackFollow.value) {
      const current = activeUnits(result.units, playbackPosition.value)[0];
      if (current) scrollToUnit(String(current.id));
    }
  } catch (e) {
    errorCode.value = getError(e);
  } finally {
    playbackLoading = false;
  }
}
async function reloadIssues() {
  if (!lesson.value) return;
  const id = lesson.value.id;
  try {
    const snapshot = await api.request<Snapshot>(`/api/lessons/${id}`);
    if (lesson.value?.id === id && snapshot.issues)
      issues.value = snapshot.issues;
  } catch {
    /* Reconnect snapshot restores persisted issues. */
  }
}
function issueBody(code: string) {
  const reason =
    code === "microphone_ended"
      ? "microphone_ended"
      : code === "audio_context_suspended"
        ? "context_suspended"
        : ["connection_lost", "audio_ack_timeout", "network_error"].includes(
              code,
            )
          ? "network_disconnected"
          : code === "network_backpressure"
            ? "buffer_limit"
            : code === "page_hidden"
              ? "page_hidden"
              : "capture_failed";
  return {
    epoch,
    reason,
    last_ack_sequence: lastAckSequence,
    next_sequence: sequence,
    estimated_lost_ms: null,
  };
}
async function reportCaptureIssue(code: string) {
  if (!lesson.value || !permitted.value || epoch < 1) return;
  const queued = { lessonId: lesson.value.id, body: issueBody(code) };
  pendingCaptureIssues.value = [
    ...pendingCaptureIssues.value.slice(-19),
    queued,
  ];
  await retryCaptureIssues();
}
let retryingIssues = false;
async function retryCaptureIssues() {
  if (retryingIssues || !permitted.value) return;
  retryingIssues = true;
  try {
    for (const item of [...pendingCaptureIssues.value]) {
      try {
        const result = await api.request<{ issue: CaptureIssue }>(
          `/api/lessons/${item.lessonId}/capture-issue`,
          item.body,
        );
        pendingCaptureIssues.value = pendingCaptureIssues.value.filter(
          (existing) => existing !== item,
        );
        if (lesson.value?.id === item.lessonId)
          issues.value = [
            ...issues.value.filter(
              (existing) => existing.id !== result.issue.id,
            ),
            result.issue,
          ];
      } catch {
        break;
      }
    }
  } finally {
    retryingIssues = false;
  }
}
function onVisibility() {
  if (document.visibilityState !== "visible" && microphoneRunning.value)
    void safePause("page_hidden");
}
function onUnload() {
  const hadCapture = recording.value || preparing.value;
  if (hadCapture && epoch > 0 && lesson.value && permitted.value)
    void fetch(`/api/lessons/${lesson.value.id}/capture-issue`, {
      method: "POST",
      credentials: "same-origin",
      keepalive: true,
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": api.csrf,
        Accept: "application/json",
      },
      body: JSON.stringify(issueBody("page_hidden")),
    }).catch(() => {});
  microphone.onPacket = () => {};
  void microphone.stop(false);
  if (hadCapture && isRecorder.value)
    socketClient?.command("pause", {
      epoch: lesson.value?.recorder_epoch || epoch,
    });
  socketClient?.close();
}
function onPopState() {
  void safePause().then(loadView);
}
watch(locale, (value) => {
  document.documentElement.lang = value;
  document.title = t("app");
});
watch(playbackRate, (value) => {
  if (player.value) player.value.playbackRate = Number(value);
});
watch(fontSize, (value) =>
  localStorage.setItem("classroom.fontSize", String(value)),
);
watch(readingFontSize, (value) => localStorage.setItem("classroom.readingFontSize", String(value)));
watch([selectedView, fontSize, readingFontSize, showReaderOptions, compactLayout, largeMode], settleCaptionLayout);
watch(selectedView, (value) =>
  localStorage.setItem("classroom.captionView", value),
);
watch(autoFollow, (value) =>
  localStorage.setItem("classroom.autoFollow", String(value)),
);
onMounted(async () => {
  compactMedia.addEventListener("change", updateCompactLayout);
  window.addEventListener("resize", settleCaptionLayout);
  document.documentElement.lang = locale.value;
  selectedView.value = localStorage.getItem("classroom.captionView") || "both";
  document.addEventListener("fullscreenchange", () => {
    fullScreen.value = !!document.fullscreenElement;
  });
  document.addEventListener("visibilitychange", onVisibility);
  window.addEventListener("pagehide", onUnload);
  window.addEventListener("popstate", onPopState);
  try {
    await loadPublicSettings();
    await refreshIdentity();
    await loadView();
  } catch (error) {
    errorCode.value = getError(error);
    loading.value = false;
  }
});
onUnmounted(() => {
  compactMedia.removeEventListener("change", updateCompactLayout);
  window.removeEventListener("resize", settleCaptionLayout);
  if (followFrame !== null) cancelAnimationFrame(followFrame);
  cancelCaptionLayout();
  onUnload();
  document.removeEventListener("visibilitychange", onVisibility);
  window.removeEventListener("pagehide", onUnload);
  window.removeEventListener("popstate", onPopState);
});
</script>

<template>
  <div
    class="app-shell"
    :class="{ 'large-mode': largeMode, 'has-lesson': !!lesson, 'show-caption-actions': showCaptionActions }"
    :style="{ '--caption-size': `${fontSize}px`, '--reading-caption-size': `${readingFontSize}px` }"
  >
    <header class="site-header">
      <button
        v-if="!largeMode"
        class="brand"
        :aria-label="t('app')"
        :title="t('app')"
        @click="navigate('/courses')"
      >
        <span class="brand-full">{{ t("app") }}</span>
        <span class="brand-compact" aria-hidden="true">{{
          t("appCompact")
        }}</span></button
      ><strong v-else class="immersive-title">{{ lesson?.name }}</strong>
      <div class="header-actions">
        <button
          v-if="lesson"
          class="icon-button qr-trigger"
          @click="showShare = true"
          :aria-label="t('share')"
          :title="t('share')"
        >
          <Icon name="qr" /></button
        ><label class="language-label"
          ><span class="sr-only">{{ t("language") }}</span
          ><select
            :value="locale"
            @change="
              setLocale(($event.target as HTMLSelectElement).value as Locale)
            "
            :aria-label="t('language')"
          >
            <option value="zh-CN">中文</option>
            <option value="en">English</option>
            <option value="uk">Українська</option>
          </select></label
        ><button
          v-if="largeMode"
          class="header-exit"
          :aria-label="t('exitLarge')"
          :title="t('exitLarge')"
          @click="toggleLarge"
        >
          <Icon name="close" /><span>{{ t("exitLarge") }}</span></button
        ><template v-else
          ><span class="identity" :title="user?.name || t('guest')">{{
            user?.name || t("guest")
          }}</span>
          <div v-if="user" class="desktop-account-actions">
            <button
              v-if="user?.role === 'admin' && permitted"
              @click="navigate('/admin')"
            >
              {{ t("admin") }}</button
            ><button v-if="permitted" @click="showAccount = true">
              {{ t("account") }}</button
            ><button v-if="user" @click="logout" :disabled="busy">
              {{ t("logout") }}
            </button>
          </div>
          <button
            v-if="user"
            class="icon-button mobile-account-trigger"
            :aria-label="t('account')"
            :title="t('account')"
            @click="showHeaderMenu = true"
          >
            <Icon name="person" />
          </button>
          <button v-else class="header-login" @click="showLogin = true">
            {{ t("login") }}
          </button></template
        >
      </div>
    </header>
    <main :class="{ 'lesson-main': !!lesson }">
      <div v-if="message" class="alert" role="alert">{{ message }}</div>
      <p v-if="loading" aria-live="polite">{{ t("loading") }}</p>
      <Admin
        v-if="
          !loading && view === 'admin' && user?.role === 'admin' && permitted
        "
        @navigate="navigate"
      />
      <h1 v-else-if="!loading && view === 'share-unavailable'">
        {{ t("shareUnavailable") }}
      </h1>
      <template v-else-if="lesson">
        <div class="title-row">
          <div>
            <button
              class="back"
              @click="navigate(`/courses/${lesson.course_id}/lessons`)"
            >
              ← {{ t("lessons") }}
            </button>
            <p class="eyebrow">{{ lesson.course_name }}</p>
            <h1>{{ lesson.name }}</h1>
          </div>
          <span class="state-pill" :class="lesson.status">{{
            t(`status_${lesson.status}`)
          }}</span>
        </div>
        <details class="lesson-details" :open="!compactLayout" @toggle="autoFollow && nextTick(followCaptions)">
          <summary>
            <span>{{ t(lesson.status === 'ended' ? "lessonDetailsPlayback" : "lessonDetails") }}</span>
            <span class="lesson-summary-status">{{ duration(lesson.media_samples) }} · {{ t(`connection_${connection}`) }}</span>
          </summary>
        <section class="lesson-meta" :aria-label="t('status_' + lesson.status)">
          <div>
            <span>{{ t("mediaDuration") }}</span
            ><strong class="duration">{{
              duration(lesson.media_samples)
            }}</strong>
          </div>
          <div>
            <span>{{ t("recorder") }}</span
            ><strong>{{
              lesson.recorder_name ||
              (isRecorder
                ? user?.name
                : t(
                    lesson.recorder_user_id
                      ? "recorderAssigned"
                      : "notAssigned",
                  ))
            }}</strong>
          </div>
          <div>
            <span>{{ t("targetLanguage") }}</span
            ><strong>{{ lesson.target_language }}</strong
            ><small>{{ t("automaticSource") }}</small>
          </div>
          <div>
            <span>{{ t(`connection_${connection}`) }}</span
            ><strong>{{
              t(`translation_${lesson.translation_status || "idle"}`)
            }}</strong
            ><small v-if="online !== null"
              >{{ t("online") }}: {{ online }}</small
            >
          </div>
        </section>
        <div v-if="permitted" class="lesson-tools">
          <button
            v-if="permitted && !largeMode"
            @click="
              showRename = true;
              renameText = lesson.name;
            "
          >
            <Icon name="edit" /> {{ t("rename") }}</button
          ><button v-if="permitted && !largeMode" @click="showExport = true">
            <Icon name="export" /> {{ t("export") }}</button
          ><button v-if="permitted && !largeMode" @click="showUsage = true">
            {{ t("usage") }}</button
          >
        </div>
        <LessonAdmin
          v-if="user?.role === 'admin' && !largeMode"
          :lesson="lesson"
        />
        <section v-if="lesson.status === 'ended'" class="playback panel">
          <div class="section-header">
            <h2>{{ t("playback") }}</h2>
            <span>{{
              t(`archive_${lesson.archive_status || "pending"}`)
            }}</span>
          </div>
          <details class="playback-details">
            <summary>{{ t("playbackDetails") }}</summary>
            <p class="muted">{{ t("playbackHelp") }}</p>
            <p class="muted">
              {{
                t(
                  publicSettings.retention_days
                    ? "retentionNotice"
                    : "retentionForever",
                  { days: publicSettings.retention_days },
                )
              }}
            </p>
            <p class="muted">{{ t("playbackLocateHelp") }}</p>
          </details>
          <a
            v-if="
              permitted &&
              publicSettings.audio_download &&
              lesson.allow_audio_download &&
              lesson.archive_status === 'playable'
            "
            class="button"
            :href="`/api/lessons/${lesson.id}/download`"
            >{{ t("downloadAudio") }}</a
          >
          <button
            v-if="lesson.archive_status === 'playable'"
            @click="loadPlayback"
            :disabled="busy"
          >
            {{ t(playbackUrl ? "refreshPlayback" : "loadPlayback") }}
          </button>
          <template v-if="playbackUrl"
            ><audio
              ref="player"
              :src="playbackUrl"
              controls
              preload="metadata"
              @error="playbackError = true"
              @timeupdate="onPlaybackTime"
              @seeked="onPlaybackTime"
              @loadedmetadata="
                playbackDuration = Number.isFinite(player?.duration)
                  ? player!.duration
                  : 0
              "
            ></audio
            ><label
              >{{ t("speed") }}
              <select v-model="playbackRate" :aria-label="t('speed')">
                <option value="0.75">0.75×</option>
                <option value="1">1×</option>
                <option value="1.25">1.25×</option>
                <option value="1.5">1.5×</option>
                <option value="2">2×</option>
              </select></label
            ></template
          >
          <p v-if="playbackError" class="alert" role="alert">
            {{ t("playbackFailed") }}
          </p>
          <p v-if="playbackUrl">
            {{ t("playbackPosition") }}: {{ clockSeconds(playbackPosition) }} /
            {{ clockSeconds(playbackDuration) }}
          </p>
          <label v-if="playbackUrl" class="consent"
            ><input type="checkbox" v-model="playbackFollow" />{{
              t("followPlayback")
            }}</label
          >
        </section>
        </details>
        <section class="subtitle-panel panel">
          <div class="section-header caption-header">
            <h2 class="subtitle-heading">{{ t("subtitles") }}</h2>
            <div class="caption-controls">
              <label><span class="sr-only">{{ t("subtitles") }}</span>
                <select v-model="selectedView">
                  <option value="both">{{ t("original") }} + {{ t("translation") }}</option>
                  <option value="translation">{{ t("translation") }}</option>
                  <option value="source">{{ t("original") }}</option>
                </select>
              </label>
              <button v-if="!largeMode" class="icon-button" @click="toggleLarge" :aria-label="t('largeSubtitles')" :title="t('largeSubtitles')"><Icon name="screen" /></button>
              <button class="icon-button" :aria-expanded="showReaderOptions" aria-controls="reader-options" @click="showReaderOptions = !showReaderOptions" :aria-label="t('readerOptions')" :title="t('readerOptions')"><Icon name="settings" /></button>
            </div>
          </div>
          <div v-if="showReaderOptions" id="reader-options" class="reader-options">
            <div class="inline-actions">
              <label>{{ t("fontSize") }}
                <select :value="largeMode ? fontSize : readingFontSize" @change="setCaptionSize">
                  <option v-for="size in (largeMode ? [24, 32, 40, 48, 64] : [16, 18, 20, 24, 28])" :key="size" :value="size">{{ size }} px</option>
                </select>
              </label>
              <label class="consent"><input v-model="autoFollow" type="checkbox" />{{ t("autoFollow") }}</label>
              <label v-if="permitted && !largeMode && compactLayout" class="consent"><input v-model="showCaptionActions" type="checkbox" />{{ t("captionActions") }}</label>
              <button v-if="largeMode" @click="toggleFullscreen">{{ t(fullScreen ? "exitFullscreen" : "fullscreen") }}</button>
            </div>
            <p class="muted caption-note">{{ t("sourceAndTranslation") }}</p>
          <p v-if="lesson.editing_frozen" class="muted">
            {{ t("editingFrozen") }}
          </p>
          <form
            v-if="!largeMode"
            class="search-form"
            @submit.prevent="searchSubtitles()"
          >
            <input
              v-model="subtitleSearch"
              :aria-label="t('searchSubtitle')"
              :placeholder="t('searchSubtitle')"
            /><button :disabled="searchingSubtitles">{{ t("search") }}</button
            ><button
              v-if="subtitleMatches"
              type="button"
              @click="
                subtitleSearch = '';
                subtitleMatches = null;
              "
            >
              {{ t("close") }}
            </button>
          </form>
          <button
            v-if="
              earlierAvailable && !subtitleMatches && !largeMode && units.length
            "
            @click="loadEarlier"
            :disabled="searchingSubtitles"
          >
            {{ t("loadEarlier") }}
          </button>
          </div>
          <div
            ref="captions"
            class="captions"
            tabindex="0"
            :aria-label="t('subtitles')"
            @scroll="captionScroll"
            @mouseup="pauseForSelection"
            @touchend="pauseForSelection"
            aria-live="polite"
            aria-relevant="additions text"
          >
            <div v-if="!displayedUnits.length" class="empty-state">
              {{ t(subtitleMatches ? "noSearchResults" : "noSubtitles") }}
            </div>
            <article
              v-for="unit in displayedUnits"
              :key="`${unit.kind}:${unit.id}`"
              class="subtitle-unit"
              :class="[
                unit.kind,
                {
                  'playback-active': playbackUnitIds.has(String(unit.id)),
                  'located-unit': locatedUnitId === String(unit.id),
                },
              ]"
              :data-unit-id="String(unit.id)"
            >
              <div class="unit-meta">
                <span>{{
                  t(unit.kind === "source" ? "original" : "translation")
                }}</span
                ><span class="unit-status" :class="{ uncertain: unit.status !== 'completed', completed: unit.status === 'completed' }">{{
                  t(`unit_${unit.status}`)
                }}</span
                ><button
                  v-if="timedUnit(unit)"
                  class="timestamp"
                  :disabled="!playbackUrl"
                  @click="seek(unit)"
                >
                  {{ duration(unit.start_sample ?? undefined) }}
                  <span v-if="unit.timing_precision !== 'exact'"
                    >≈</span
                  ></button
                ><span v-else>{{ t("noTiming") }}</span
                ><span v-if="(unit.version || 1) > 1">{{ t("edited") }}</span
                ><span class="unit-edit-actions"
                  v-if="permitted && unit.status === 'completed' && !largeMode"
                  ><button
                    v-if="!lesson.editing_frozen"
                    class="icon-button"
                    @click="openCorrection(unit, 'edit')"
                    :aria-label="t('edit')"
                    :title="t('edit')"
                  >
                    <Icon name="edit" /></button
                  ><button
                    class="icon-button"
                    @click="openCorrection(unit, 'history')"
                    :aria-label="t('history')"
                    :title="t('history')"
                  >
                    <Icon name="history" /></button
                  ><LessonAdmin
                    v-if="user?.role === 'admin'"
                    :lesson="lesson"
                    :unit="unit"
                /></span>
              </div>
              <p>{{ unit.text }}</p>
            </article>
          </div>
          <div v-if="subtitleMatches" class="pagination">
            <button
              @click="
                subtitlePage--;
                searchSubtitles(false);
              "
              :disabled="subtitlePage === 1"
            >
              {{ t("previous") }}</button
            ><span>{{
              t("pageOf", { page: subtitlePage, total: subtitleTotal })
            }}</span
            ><button
              @click="
                subtitlePage++;
                searchSubtitles(false);
              "
              :disabled="subtitlePage * 100 >= subtitleTotal"
            >
              {{ t("next") }}
            </button>
          </div>
          <button
            v-if="!autoFollow"
            class="follow-button"
            @click="returnToLive"
          >
            ↓ {{ t("follow") }} <span v-if="unseen">({{ unseen }})</span>
          </button>
          <p v-if="units.length > 500" class="muted">
            {{ t("historyWindow") }}
          </p>
        </section>
        <HandoffPanel
          v-if="permitted && user"
          :lesson="lesson"
          :user="user"
          :participants="participants"
          :current-client-id="currentClientId"
          :items="handoffs"
          :is-recorder="isRecorder"
          :preparation="preparation"
          :preparing="preparing"
          @command="handoffCommand"
          @prepare="prepareHandoff"
          @takeover="takeover"
        />
        <section v-if="isRecorder" class="recorder-panel panel">
          <div class="section-header">
            <h2>{{ t("microphone") }}</h2>
            <span class="mic-status" :class="{ active: recording }"
              ><span class="status-dot"></span>{{ t(microphoneState) }}</span
            >
          </div>
          <p class="muted">{{ t("recorderHelp") }}</p>
          <div class="device-row">
            <label
              >{{ t("device") }}
              <select v-model="selectedDevice" :disabled="microphoneRunning">
                <option value="">{{ t("defaultDevice") }}</option>
                <option
                  v-for="(device, index) in devices"
                  :key="device.deviceId"
                  :value="device.deviceId"
                >
                  {{ device.label || t("deviceNumber", { n: index + 1 }) }}
                </option>
              </select></label
            ><button @click="refreshDevices" :disabled="microphoneRunning">
              {{ t("refreshDevices") }}</button
            ><button
              v-if="!microphoneRunning"
              @click="testMicrophone"
              :disabled="connection !== 'connected'"
            >
              {{ t("testMicrophone") }}</button
            ><button v-if="testing || preparing" @click="safePause()">
              {{ t(testing ? "stopTest" : "cancel") }}
            </button>
          </div>
          <label class="meter-label"
            >{{ t("level") }}
            <meter
              :value="micLevel"
              min="0"
              max="1"
              :aria-label="t('level')"
            ></meter
          ></label>
          <p v-if="inputRate" class="muted">
            {{ t("inputRate") }}: {{ inputRate }} Hz · {{ t("outputFormat") }}
          </p>
          <p class="muted recording-scope">
            {{ t("sharingScope") }}:
            {{ t(lesson.access_mode === "link" ? "scopeLink" : "scopePublic") }}
          </p>
          <label class="consent"
            ><input
              v-model="consent"
              type="checkbox"
              :disabled="recording || preparing"
            />
            <span>{{ t("consent") }}</span></label
          >
          <p class="foreground-note">{{ t("foreground") }}</p>
          <div class="record-actions">
            <button
              v-if="!recording"
              class="primary"
              @click="startCapture"
              :disabled="!consent || preparing || connection !== 'connected'"
            >
              {{ t(lesson.status === "ready" ? "start" : "resume") }}</button
            ><button v-else class="primary" @click="safePause()">
              ⏸ {{ t("pause") }}</button
            ><button v-if="canEnd" class="danger" @click="showEnd = true">
              {{ t("end") }}
            </button>
          </div>
        </section>
        <p v-else-if="!isRecorder" class="muted viewer-note">{{ t("permissionViewer") }}</p>
        <p v-if="recoveryNotice" role="status" class="muted">
          {{
            t(
              recoveryNotice.mode === "replay"
                ? "recoveryReplay"
                : "recoverySnapshot",
              { n: recoveryNotice.count },
            )
          }}
        </p>
        <p v-if="usageWarning" class="alert" role="status">
          {{ t(usageWarning.code, { seconds: usageWarning.seconds }) }}
        </p>
        <p v-if="rotationStatus" role="status" class="muted">
          {{
            t(
              rotationStatus === "failed"
                ? "rotationPreparationFailed"
                : "rotationWaiting",
            )
          }}
        </p>
        <details v-if="issues.length" class="capture-issues panel">
          <summary>{{ t("captureIssues") }} ({{ issues.length }})</summary>
          <p>{{ t("issueHelp") }}</p>
          <article v-for="issue in issues" :key="issue.id">
            <p>
              {{ displayDate(issue.created_at) }} ·
              {{
                t(
                  issue.details?.reason
                    ? `error_${issue.details.reason}`
                    : `issue_${issue.kind || "translation_uncertain"}`,
                )
              }}
            </p>
            <p>
              {{
                t(
                  issue.kind === "capture_uncertain"
                    ? "audioUnknown"
                    : "translationUncertain",
                )
              }}
            </p>
            <p
              v-if="
                issue.details?.estimated_lost_ms !== null &&
                issue.details?.estimated_lost_ms !== undefined
              "
            >
              {{ t("lostEstimate", { ms: issue.details.estimated_lost_ms }) }}
            </p>
          </article>
        </details>
        <p
          v-if="
            pendingCaptureIssues.some((item) => item.lessonId === lesson?.id)
          "
          role="status"
          class="alert"
        >
          {{ t("pendingIssue") }}
        </p>
        <button
          v-if="canEnd && !isRecorder"
          class="danger"
          @click="showEnd = true"
        >
          {{ t("end") }}
        </button>
      </template>
      <template v-else-if="course">
        <CourseSubtitleSearch :course-id="course.id" @navigate="navigate" />
        <div class="title-row">
          <div>
            <button class="back" @click="navigate('/courses')">
              ← {{ t("courses") }}
            </button>
            <h1>{{ course.name }}</h1>
          </div>
          <button
            v-if="permitted"
            class="primary"
            @click="openCreation('lesson')"
          >
            + {{ t("newLesson") }}
          </button>
        </div>
        <form class="filter-bar" @submit.prevent="filterLessons()">
          <label
            >{{ t("search")
            }}<input
              v-model="lessonSearch"
              :placeholder="t('searchLesson')" /></label
          ><label
            >{{ t("sort")
            }}<select v-model="lessonSort" :aria-label="t('sort')">
              <option value="created_at">{{ t("createdAt") }}</option>
              <option value="name">{{ t("name") }}</option>
              <option value="duration">{{ t("mediaDuration") }}</option>
            </select></label
          ><label
            >{{ t("direction")
            }}<select v-model="lessonDirection" :aria-label="t('direction')">
              <option value="desc">{{ t("descending") }}</option>
              <option value="asc">{{ t("ascending") }}</option>
            </select></label
          ><label
            >{{ t("status")
            }}<select v-model="lessonStatus" :aria-label="t('status')">
              <option value="all">{{ t("all") }}</option>
              <option
                v-for="status in ['ready', 'live', 'paused', 'ended']"
                :key="status"
                :value="status"
              >
                {{ t(`status_${status}`) }}
              </option>
            </select></label
          ><button class="primary">{{ t("search") }}</button>
        </form>
        <p v-if="!lessons.length" class="empty-state">
          {{ t("emptyLessons") }}
        </p>
        <div class="card-grid">
          <button
            v-for="item in lessons"
            :key="item.id"
            class="course-card"
            @click="navigate(`/lessons/${item.id}`)"
          >
            <span class="state-pill" :class="item.status">{{
              t(`status_${item.status}`)
            }}</span>
            <h2>{{ item.name }}</h2>
            <p v-if="item.created_at">
              {{ t("createdAt") }} ·
              {{ displayDate(item.created_at) }}
            </p>
            <p>{{ t("mediaDuration") }} · {{ duration(item.media_samples) }}</p>
          </button>
        </div>
        <div class="pagination">
          <button
            @click="
              lessonPage--;
              filterLessons(false);
            "
            :disabled="lessonPage === 1"
          >
            {{ t("previous") }}</button
          ><span>{{
            t("pageOf", { page: lessonPage, total: lessonTotal })
          }}</span
          ><button
            @click="
              lessonPage++;
              filterLessons(false);
            "
            :disabled="lessonPage * 30 >= lessonTotal"
          >
            {{ t("next") }}
          </button>
        </div>
      </template>
      <template v-else>
        <div class="title-row">
          <h1>{{ t("courses") }}</h1>
          <button
            v-if="user?.role === 'admin' && permitted"
            class="primary"
            @click="openCreation('course')"
          >
            + {{ t("newCourse") }}
          </button>
        </div>
        <p v-if="!courses.length" class="empty-state">
          {{ t("emptyCourses") }}
        </p>
        <div class="card-grid">
          <article v-for="item in courses" :key="item.id" class="course-card">
            <button
              class="card-main"
              @click="
                navigate(
                  item.active_lesson_id
                    ? `/lessons/${item.active_lesson_id}`
                    : `/courses/${item.id}/lessons`,
                )
              "
            >
              <h2>{{ item.name }}</h2>
              <p v-if="item.description">{{ item.description }}</p>
              <p>
                {{ t("targetLanguage") }} · {{ item.target_language }}
              </p></button
            ><button
              class="history-link"
              @click="navigate(`/courses/${item.id}/lessons`)"
            >
              {{ t("allHistory") }} →
            </button>
          </article>
        </div>
        <button
          v-if="errorCode"
          @click="
            action(async () => {
              await refreshIdentity();
              await loadView();
            })
          "
        >
          {{ t("retry") }}
        </button>
      </template>
    </main>

    <Modal
      v-if="showHeaderMenu && user && !user.must_change_password"
      :title="t('account')"
      @close="showHeaderMenu = false"
    >
      <p class="account-menu-name">{{ user.name }}</p>
      <nav class="account-menu" :aria-label="t('account')">
        <button
          v-if="user.role === 'admin' && permitted"
          @click="
            showHeaderMenu = false;
            navigate('/admin');
          "
        >
          {{ t("admin") }}
        </button>
        <button
          v-if="permitted"
          @click="
            showHeaderMenu = false;
            showAccount = true;
          "
        >
          {{ t("account") }}
        </button>
        <button
          :disabled="busy"
          @click="
            showHeaderMenu = false;
            logout();
          "
        >
          {{ t("logout") }}
        </button>
      </nav>
    </Modal>

    <Modal
      v-if="showLogin || user?.must_change_password || showCreate || showEnd"
      :title="
        t(
          user?.must_change_password
            ? 'requiredPassword'
            : showLogin
              ? 'login'
              : showEnd
                ? 'end'
                : showCreate === 'course'
                  ? 'newCourse'
                  : 'newLesson',
        )
      "
      :locked="!!user?.must_change_password"
      @close="
        showLogin = false;
        showCreate = null;
        showEnd = false;
        password = '';
      "
    >
      <form v-if="user?.must_change_password" @submit.prevent="savePassword">
        <p>{{ t("passwordHelp") }}</p>
        <label
          >{{ t("newPassword")
          }}<input
            v-model="newPassword"
            type="password"
            autocomplete="new-password"
            minlength="12"
            required
            autofocus
        /></label>
        <p v-if="message" class="alert" role="alert">{{ message }}</p>
        <div class="dialog-actions">
          <button type="button" @click="logout" :disabled="busy">
            {{ t("logout") }}</button
          ><button type="submit" class="primary" :disabled="busy">
            {{ t("changePassword") }}
          </button>
        </div>
      </form>
      <form v-else-if="showLogin" @submit.prevent="login">
        <p class="muted">{{ t("loginHelp") }}</p>
        <label
          >{{ t("studentId")
          }}<input
            v-model="studentId"
            autocomplete="username"
            required
            autofocus /></label
        ><label
          >{{ t("password")
          }}<input
            v-model="password"
            type="password"
            autocomplete="current-password"
            required /></label
        ><label class="consent"
          ><input v-model="remember" type="checkbox" />{{
            t("remember")
          }}</label
        >
        <p v-if="message" class="alert" role="alert">{{ message }}</p>
        <div class="dialog-actions">
          <button
            type="button"
            @click="
              showLogin = false;
              password = '';
            "
          >
            {{ t("cancel") }}</button
          ><button type="submit" class="primary" :disabled="busy">
            {{ t("login") }}
          </button>
        </div>
      </form>
      <form v-else-if="showCreate" @submit.prevent="create">
        <label
          >{{ t("name")
          }}<input
            v-model="newName"
            required
            maxlength="200"
            autofocus /></label
        ><template v-if="showCreate === 'course'"
          ><label>{{ t("courseDescriptionOptional") }}
            <textarea v-model="newDescription" rows="3" maxlength="5000"></textarea>
          </label><label
            >{{ t("targetLanguage")
            }}<select v-model="targetLanguage">
              <option value="zh">中文</option>
              <option value="en">English</option>
              <option value="uk">Українська</option>
            </select></label
          ><label class="consent"
            ><input type="checkbox" v-model="publicAccess" />{{
              t("guestAccess")
            }}</label
          ></template
        >
        <p v-if="message" class="alert" role="alert">{{ message }}</p>
        <div class="dialog-actions">
          <button type="button" @click="showCreate = null">
            {{ t("cancel") }}</button
          ><button type="submit" class="primary" :disabled="busy">
            {{ t("create") }}
          </button>
        </div>
      </form>
      <div v-else-if="showEnd">
        <p>{{ t("endConfirm") }}</p>
        <div class="dialog-actions">
          <button @click="showEnd = false">{{ t("cancel") }}</button
          ><button class="danger" @click="endLesson">{{ t("confirm") }}</button>
        </div>
      </div>
      <button
        v-if="showCreate === 'lesson' && existingLessonId"
        type="button"
        @click="
          showCreate = null;
          navigate(`/lessons/${existingLessonId}`);
        "
      >
        {{ t("openExisting") }}
      </button>
    </Modal>
    <ShareDialog
      v-if="showShare && lesson"
      :lesson="lesson"
      @close="showShare = false"
    />
    <ExportDialog
      v-if="showExport && lesson && permitted"
      :lesson="lesson"
      :has-content="hasExportable"
      @close="showExport = false"
    />
    <AccountDialog
      v-if="showAccount && permitted"
      @close="showAccount = false"
      @signed-out="accountSignedOut"
      @password-changed="refreshIdentity"
    />
    <Modal
      v-if="showUsage && lesson && permitted"
      :title="t('usage')"
      @close="showUsage = false"
      ><UsagePanel :lesson-id="lesson.id"
    /></Modal>
    <CorrectionDialog
      v-if="correction && lesson"
      :lesson="lesson"
      :unit="correction.unit"
      :mode="correction.mode"
      :draft="drafts[String(correction.unit.id)] ?? correction.unit.text"
      @draft="drafts[String(correction!.unit.id)] = $event"
      @saved="saveCorrections"
      @close="correction = null"
    />
    <Modal
      v-if="showRename && lesson"
      :title="t('rename')"
      @close="showRename = false"
      ><form @submit.prevent="renameLesson">
        <label
          >{{ t("name")
          }}<input v-model="renameText" required maxlength="200" autofocus
        /></label>
        <p v-if="message" class="alert">{{ message }}</p>
        <div class="dialog-actions">
          <button type="button" @click="showRename = false">
            {{ t("cancel") }}</button
          ><button class="primary" :disabled="busy">{{ t("save") }}</button>
        </div>
      </form></Modal
    >
    <Modal
      v-if="showReauth"
      :title="t('reauth')"
      @close="
        showReauth = false;
        pendingForce = false;
        reauthPassword = '';
      "
      ><form @submit.prevent="forceReauth">
        <p>{{ t("reauthHelp") }}</p>
        <label
          >{{ t("currentPassword")
          }}<input
            v-model="reauthPassword"
            type="password"
            required
            autofocus
            autocomplete="current-password"
        /></label>
        <p v-if="message" class="alert">{{ message }}</p>
        <div class="dialog-actions">
          <button
            type="button"
            @click="
              showReauth = false;
              pendingForce = false;
              reauthPassword = '';
            "
          >
            {{ t("cancel") }}</button
          ><button class="primary" :disabled="busy">{{ t("confirm") }}</button>
        </div>
      </form></Modal
    >
  </div>
</template>

