<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, ApiError, type User, type Course, type Lesson, type Unit, type Snapshot } from './api';
import { locale, setLocale, t, type Locale } from './i18n';
import { LessonSocket, type WireMessage } from './realtime';
import { MicrophoneCapture } from './audio/capture';
import { audioFrame, exceedsBackpressure } from './audio/dsp';

const user = ref<User | null>(null), courses = ref<Course[]>([]), lessons = ref<Lesson[]>([]);
const lesson = ref<Lesson | null>(null), units = ref<Unit[]>([]), course = ref<Course | null>(null);
const connection = ref('idle'), loading = ref(true), busy = ref(false), errorCode = ref('');
const showLogin = ref(false), showCreate = ref<'course' | 'lesson' | null>(null), showEnd = ref(false);
const studentId = ref(''), password = ref(''), newPassword = ref(''), newName = ref('');
const targetLanguage = ref('zh'), publicAccess = ref(true), selectedDevice = ref('');
const devices = ref<MediaDeviceInfo[]>([]), microphoneState = ref('idleMic'), micLevel = ref(0), inputRate = ref<number | null>(null), consent = ref(false), recording = ref(false), preparing = ref(false), testing = ref(false), online = ref<number | null>(null);
const playbackUrl = ref(''), player = ref<HTMLAudioElement | null>(null), playbackError = ref(false), playbackRate = ref('1');
const captions = ref<HTMLElement | null>(null), autoFollow = ref(true), unseen = ref(0);
const selectedView = ref('both');
let socketClient: LessonSocket | null = null, wsUrl = '', epoch = 0, sequence = 0, commandPending = false;
let startTimer: number | null = null, startGeneration = 0, viewGeneration = 0;
const outstanding = new Set<number>();
const microphone = new MicrophoneCapture();
let wakeLock: WakeLockSentinel | null = null;
const permitted = computed(() => !!user.value && !user.value.must_change_password);
const isRecorder = computed(() => permitted.value && lesson.value?.recorder_user_id === user.value?.id && lesson.value?.status !== 'ended');
const canEnd = computed(() => permitted.value && lesson.value?.status !== 'ended' && (isRecorder.value || lesson.value?.creator_id === user.value?.id || user.value?.role === 'admin'));
const displayedUnits = computed(() => units.value.slice(-500).filter(unit => selectedView.value === 'both' || unit.kind === selectedView.value));
const microphoneRunning = computed(() => recording.value || testing.value || preparing.value);
const message = computed(() => errorCode.value ? t(`error_${errorCode.value}`) : '');
const duration = (samples: number | undefined) => {
  const seconds = Math.floor((samples || 0) / 16000);
  const minutes = Math.floor(seconds / 60), hours = Math.floor(minutes / 60);
  return hours ? `${hours}:${String(minutes % 60).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}` : `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
};
const getError = (error: unknown) => error instanceof ApiError ? error.code : error instanceof DOMException ? error.name : error instanceof Error ? error.message : 'generic';
async function action(callback: () => Promise<void>) { if (busy.value) return; busy.value = true; errorCode.value = ''; try { await callback(); } catch (error) { errorCode.value = getError(error); } finally { busy.value = false; } }

async function refreshIdentity() {
  const identity = await api.request<{ user: User | null; csrf_token: string; ws_url: string }>('/api/me');
  user.value = identity.user; wsUrl = identity.ws_url;
}
async function login() { await action(async () => { await api.request('/api/login', { student_id: studentId.value, password: password.value }); password.value = ''; showLogin.value = false; await refreshIdentity(); await loadView(); }); }
async function savePassword() { await action(async () => { await api.request('/api/password', { password: newPassword.value }); newPassword.value = ''; await refreshIdentity(); await loadView(); }); }
async function logout() { await action(async () => { await safePause(); socketClient?.close(); await api.request('/api/logout', {}); user.value = null; await refreshIdentity(); await loadView(); }); }
function openCreation(kind: 'course' | 'lesson') { showCreate.value = kind; newName.value = kind === 'lesson' ? t('newLesson') : ''; }
async function create() {
  await action(async () => {
    if (showCreate.value === 'course') { await api.request('/api/courses', { name: newName.value, target_language: targetLanguage.value, guest_access: publicAccess.value ? 'public' : 'link' }); showCreate.value = null; await loadView(); }
    else if (course.value) { const created = await api.request<{ lesson: Lesson }>(`/api/courses/${course.value.id}/lessons`, { name: newName.value, idempotency_key: crypto.randomUUID() }); showCreate.value = null; await navigate(`/lessons/${created.lesson.id}`); }
  });
}
async function navigate(path: string) { await safePause(); history.pushState({}, '', path); await loadView(); }
async function loadView() {
  const generation = ++viewGeneration;
  await stopCapture(true); socketClient?.close(); socketClient = null;
  lesson.value = null; course.value = null; units.value = []; playbackUrl.value = ''; online.value = null; loading.value = true; errorCode.value = ''; autoFollow.value = true; unseen.value = 0;
  try {
    const lessonRoute = location.pathname.match(/^\/lessons\/(\d+)\/?$/), courseRoute = location.pathname.match(/^\/courses\/(\d+)\/lessons\/?$/);
    if (lessonRoute) {
      const snapshot = await api.request<Snapshot>(`/api/lessons/${lessonRoute[1]}`);
      if (generation !== viewGeneration) return;
      applySnapshot(snapshot);
      socketClient = new LessonSocket(snapshot.lesson.id, wsUrl);
      socketClient.seed(snapshot);
      socketClient.onState = state => { connection.value = state; };
      socketClient.onMessage = receiveMessage;
      socketClient.onDisconnect = () => { if (microphoneRunning.value) void safePause('connection_lost', false); };
      socketClient.onError = code => { errorCode.value = code; if (microphoneRunning.value) void safePause(code); };
      void socketClient.open();
      if (isRecorder.value) await refreshDevices();
    } else {
      const result = await api.request<{ courses: Course[] }>('/api/courses');
      if (generation !== viewGeneration) return;
      courses.value = result.courses;
      if (courseRoute) {
        course.value = result.courses.find(item => item.id === Number(courseRoute[1])) || null;
        const resultLessons = await api.request<{ lessons: Lesson[] }>(`/api/courses/${courseRoute[1]}/lessons`);
        if (generation !== viewGeneration) return;
        lessons.value = resultLessons.lessons;
      }
    }
  } catch (error) { if (generation === viewGeneration) errorCode.value = getError(error); }
  finally { if (generation === viewGeneration) loading.value = false; }
}
function applySnapshot(snapshot: Snapshot) { lesson.value = snapshot.lesson; units.value = snapshot.units; }
function upsertUnit(unit: Unit) {
  const index = units.value.findIndex(existing => existing.id === unit.id);
  if (index === -1) units.value.push(unit); else units.value[index] = unit;
  if (!autoFollow.value && index === -1) unseen.value++;
  if (autoFollow.value) void nextTick(() => followCaptions());
}
function receiveMessage(message: WireMessage) {
  if (message.type === 'snapshot') applySnapshot(message as unknown as Snapshot);
  if (message.type === 'subtitle.partial' && message.unit) upsertUnit(message.unit);
  if (message.type === 'event') {
    if (message.event_type === 'subtitle.updated' && message.payload?.unit) upsertUnit(message.payload.unit);
    if (message.event_type === 'lesson.updated' && message.payload?.lesson) {
      const update = message.payload.lesson;
      if ((recording.value || preparing.value) && (update.status === 'ended' || update.recorder_user_id !== user.value?.id || (recording.value && epoch > 0 && update.recorder_epoch !== epoch))) void safePause('stale_epoch', false);
      lesson.value = update;
    }
    if (message.event_type === 'presence.updated' && typeof message.payload?.count === 'number') online.value = message.payload.count;
  }
  if (message.type === 'presence' && typeof message.count === 'number') online.value = message.count;
  if (message.type === 'audio.ack' && message.sequence !== undefined) {
    for (const sentSequence of outstanding) if (sentSequence <= message.sequence) outstanding.delete(sentSequence);
    if (lesson.value && typeof message.media_samples === 'number') lesson.value.media_samples = message.media_samples;
  }
  if (message.type === 'recorder.ready') {
    if (!preparing.value || !isRecorder.value || !microphone.active || !Number.isInteger(message.epoch) || !Number.isInteger(message.next_sequence)) { socketClient?.command('pause', { epoch: message.epoch }); return; }
    clearStartTimer();
    epoch = message.epoch!; sequence = message.next_sequence!; outstanding.clear();
    if (lesson.value) lesson.value.recorder_epoch = epoch;
    if (lesson.value && message.media_samples !== undefined) lesson.value.media_samples = message.media_samples;
    try { microphone.start(); recording.value = true; preparing.value = false; commandPending = false; microphoneState.value = 'activeMic'; void requestWakeLock(); }
    catch (error) { void safePause(getError(error)); }
  }
}
function followCaptions() { if (captions.value) captions.value.scrollTop = captions.value.scrollHeight; }
function returnToLive() { autoFollow.value = true; unseen.value = 0; followCaptions(); }
function captionScroll() { const element = captions.value; if (element && element.scrollHeight - element.scrollTop - element.clientHeight > 60) autoFollow.value = false; }
function pauseForSelection() { if (window.getSelection()?.toString()) autoFollow.value = false; }
async function refreshDevices() { if (!isRecorder.value) return; try { devices.value = (await navigator.mediaDevices?.enumerateDevices() || []).filter(device => device.kind === 'audioinput'); } catch { errorCode.value = 'NotFoundError'; } }
async function prepareMicrophone() { microphoneState.value = 'preparingMic'; await microphone.prepare(selectedDevice.value); inputRate.value = microphone.sampleRate; await refreshDevices(); }
async function testMicrophone() {
  if (!isRecorder.value || preparing.value || recording.value) return;
  errorCode.value = ''; const generation = ++startGeneration; preparing.value = true;
  try { await prepareMicrophone(); if (generation !== startGeneration) return; preparing.value = false; testing.value = true; microphoneState.value = 'localTest'; }
  catch (error) { await stopCapture(false); errorCode.value = getError(error); }
}
async function startCapture() {
  if (!isRecorder.value || !consent.value || connection.value !== 'connected' || commandPending || preparing.value || recording.value) return;
  errorCode.value = ''; commandPending = true; preparing.value = true; testing.value = false;
  const generation = ++startGeneration;
  try {
    await prepareMicrophone();
    if (generation !== startGeneration) return;
    microphoneState.value = 'pendingStart';
    const command = lesson.value?.status === 'paused' || lesson.value?.status === 'live' ? 'resume' : 'start';
    if (!socketClient?.command(command, command === 'start' ? {} : { epoch: lesson.value?.recorder_epoch })) throw new ApiError('connection_lost');
    startTimer = window.setTimeout(() => { void safePause('start_timeout'); }, 15000);
  } catch (error) { await safePause(getError(error)); }
}
function clearStartTimer() { if (startTimer !== null) window.clearTimeout(startTimer); startTimer = null; }
async function stopCapture(flush: boolean): Promise<boolean> {
  startGeneration++; clearStartTimer();
  const waitForAcknowledgements = flush && recording.value;
  // Keep recording=true until a requested flush reaches the socket in order.
  if (!flush) recording.value = false;
  await microphone.stop(flush && recording.value);
  recording.value = false; preparing.value = false; testing.value = false; commandPending = false;
  microphoneState.value = 'idleMic'; inputRate.value = null;
  const lock = wakeLock; wakeLock = null; await lock?.release().catch(() => {});
  if (waitForAcknowledgements && outstanding.size) {
    const acknowledged = await new Promise<boolean>(resolve => {
      const deadline = performance.now() + 1500;
      const check = () => {
        if (!outstanding.size) { resolve(true); return; }
        if (performance.now() >= deadline || socketClient?.socket?.readyState !== WebSocket.OPEN) { resolve(false); return; }
        window.setTimeout(check, 25);
      };
      check();
    });
    if (!acknowledged) { errorCode.value = 'audio_ack_timeout'; return false; }
  }
  return true;
}
let pausing = false;
async function safePause(code = '', sendCommand = true) {
  if (pausing) return; pausing = true;
  try {
    const hadCapture = recording.value || preparing.value;
    await stopCapture(code === '');
    if (hadCapture && sendCommand) socketClient?.command('pause', { epoch: lesson.value?.recorder_epoch || epoch });
    if (code) errorCode.value = code;
  } finally { pausing = false; }
}
async function endLesson() { showEnd.value = false; const acknowledged = await stopCapture(true); if (!acknowledged) { socketClient?.command('pause', { epoch: lesson.value?.recorder_epoch || epoch }); return; } socketClient?.command('end', { epoch: epoch || lesson.value?.recorder_epoch, confirmed: true }); }
async function requestWakeLock() {
  if ('wakeLock' in navigator && document.visibilityState === 'visible') try { const lock = await navigator.wakeLock.request('screen'); if (recording.value) wakeLock = lock; else await lock.release(); } catch { /* Foreground warning remains; wake lock is best effort. */ }
}
microphone.onMeter = rms => { micLevel.value = Math.min(1, rms * 5); };
microphone.onFailure = code => { void safePause(code); };
microphone.onPacket = pcm => {
  if (!recording.value) return;
  const socket = socketClient?.socket;
  if (!socket || socket.readyState !== WebSocket.OPEN) { void safePause('connection_lost', false); return; }
  if (exceedsBackpressure(socket.bufferedAmount, outstanding.size)) { void safePause('network_backpressure'); return; }
  const sentSequence = sequence++;
  try { socket.send(audioFrame(epoch, sentSequence, pcm)); outstanding.add(sentSequence); } catch { void safePause('connection_lost', false); }
};
async function loadPlayback() {
  await action(async () => {
    if (!lesson.value) return;
    const position = player.value?.currentTime || 0;
    const result = await api.request<{ url: string; expires_at: string }>(`/api/lessons/${lesson.value.id}/playback`);
    playbackError.value = false; playbackUrl.value = result.url;
    await nextTick();
    const audio = player.value;
    if (audio) {
      const restore = () => { audio.currentTime = position; audio.playbackRate = Number(playbackRate.value); };
      audio.addEventListener('loadedmetadata', restore, { once: true });
      // Setting src already starts loading. Calling load() here cancels and
      // duplicates that private Range download. Cached metadata may be ready.
      if (audio.readyState >= HTMLMediaElement.HAVE_METADATA) restore();
    }
  });
}
function seek(unit: Unit) { if (player.value && unit.start_sample !== null) player.value.currentTime = unit.start_sample / 16000; }
function onVisibility() { if (document.visibilityState !== 'visible' && microphoneRunning.value) void safePause('page_hidden'); }
function onUnload() { const hadCapture = recording.value || preparing.value; microphone.onPacket = () => {}; void microphone.stop(false); if (hadCapture && isRecorder.value) socketClient?.command('pause', { epoch: lesson.value?.recorder_epoch || epoch }); socketClient?.close(); }
function onPopState() { void safePause().then(loadView); }
watch(locale, value => { document.documentElement.lang = value; document.title = t('app'); });
watch(playbackRate, value => { if (player.value) player.value.playbackRate = Number(value); });
onMounted(async () => {
  document.documentElement.lang = locale.value;
  document.addEventListener('visibilitychange', onVisibility); window.addEventListener('pagehide', onUnload); window.addEventListener('popstate', onPopState);
  try { await refreshIdentity(); await loadView(); } catch (error) { errorCode.value = getError(error); loading.value = false; }
});
onUnmounted(() => { onUnload(); document.removeEventListener('visibilitychange', onVisibility); window.removeEventListener('pagehide', onUnload); window.removeEventListener('popstate', onPopState); });
</script>

<template>
  <div class="app-shell">
    <header class="site-header">
      <button class="brand" @click="navigate('/courses')">{{ t('app') }}</button>
      <div class="header-actions"><label class="language-label"><span class="sr-only">{{ t('language') }}</span><select :value="locale" @change="setLocale(($event.target as HTMLSelectElement).value as Locale)" :aria-label="t('language')"><option value="zh-CN">中文</option><option value="en">English</option><option value="uk">Українська</option></select></label><span class="identity">{{ user?.name || t('guest') }}</span><button v-if="user" @click="logout" :disabled="busy">{{ t('logout') }}</button><button v-else @click="showLogin = true">{{ t('login') }}</button></div>
    </header>
    <main>
      <aside class="phase-banner"><strong>{{ t('phase') }}</strong><p>{{ t('phaseNote') }}</p></aside>
      <div v-if="message" class="alert" role="alert">{{ message }}</div>
      <p v-if="loading" aria-live="polite">{{ t('loading') }}</p>
      <template v-else-if="lesson">
        <div class="title-row"><div><button class="back" @click="navigate(`/courses/${lesson.course_id}/lessons`)">← {{ t('lessons') }}</button><p class="eyebrow">{{ lesson.course_name }}</p><h1>{{ lesson.name }}</h1></div><span class="state-pill" :class="lesson.status">{{ t(`status_${lesson.status}`) }}</span></div>
        <section class="lesson-meta" :aria-label="t('status_' + lesson.status)">
          <div><span>{{ t('mediaDuration') }}</span><strong class="duration">{{ duration(lesson.media_samples) }}</strong></div><div><span>{{ t('recorder') }}</span><strong>{{ lesson.recorder_name || (isRecorder ? user?.name : t(lesson.recorder_user_id ? 'recorderAssigned' : 'notAssigned')) }}</strong></div><div><span>{{ t('targetLanguage') }}</span><strong>{{ lesson.target_language }}</strong><small>{{ t('automaticSource') }}</small></div><div><span>{{ t(`connection_${connection}`) }}</span><strong>{{ t(`translation_${lesson.translation_status || 'idle'}`) }}</strong><small v-if="online !== null">{{ t('online') }}: {{ online }}</small></div>
        </section>
        <section v-if="lesson.status === 'ended'" class="playback panel">
          <div class="section-header"><h2>{{ t('playback') }}</h2><span>{{ t(`archive_${lesson.archive_status || 'pending'}`) }}</span></div>
          <p class="muted">{{ t('playbackHelp') }}</p>
          <button v-if="lesson.archive_status === 'playable'" @click="loadPlayback" :disabled="busy">{{ t(playbackUrl ? 'refreshPlayback' : 'loadPlayback') }}</button>
          <template v-if="playbackUrl"><audio ref="player" :src="playbackUrl" controls preload="metadata" @error="playbackError = true"></audio><label>{{ t('speed') }} <select v-model="playbackRate"><option value="0.75">0.75×</option><option value="1">1×</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option><option value="2">2×</option></select></label></template>
          <p v-if="playbackError" class="alert" role="alert">{{ t('playbackFailed') }}</p>
        </section>
        <section class="subtitle-panel panel">
          <div class="section-header"><h2>{{ t('subtitles') }}</h2><label><span class="sr-only">{{ t('subtitles') }}</span><select v-model="selectedView"><option value="both">{{ t('original') }} + {{ t('translation') }}</option><option value="translation">{{ t('translation') }}</option><option value="source">{{ t('original') }}</option></select></label></div>
          <p class="muted caption-note">{{ t('sourceAndTranslation') }}</p>
          <div ref="captions" class="captions" @scroll="captionScroll" @mouseup="pauseForSelection" @touchend="pauseForSelection" aria-live="polite" aria-relevant="additions text">
            <div v-if="!displayedUnits.length" class="empty-state">{{ t('noSubtitles') }}</div>
            <article v-for="unit in displayedUnits" :key="`${unit.kind}:${unit.id}`" class="subtitle-unit" :class="unit.kind">
              <div class="unit-meta"><span>{{ t(unit.kind === 'source' ? 'original' : 'translation') }}</span><span :class="{ uncertain: unit.status !== 'completed' }">{{ t(`unit_${unit.status}`) }}</span><button v-if="unit.start_sample !== null" class="timestamp" :disabled="!playbackUrl" @click="seek(unit)">{{ duration(unit.start_sample) }} <span v-if="unit.timing_precision !== 'exact'">≈</span></button><span v-else>{{ t('noTiming') }}</span></div>
              <p>{{ unit.text }}</p>
            </article>
          </div>
          <button v-if="!autoFollow" class="follow-button" @click="returnToLive">↓ {{ t('follow') }} <span v-if="unseen">({{ unseen }})</span></button>
          <p v-if="units.length > 500" class="muted">{{ t('recentLimit') }}</p>
        </section>
        <section v-if="isRecorder" class="recorder-panel panel">
          <div class="section-header"><h2>{{ t('microphone') }}</h2><span class="mic-status" :class="{ active: recording }"><span class="status-dot"></span>{{ t(microphoneState) }}</span></div>
          <p class="muted">{{ t('recorderHelp') }}</p>
          <div class="device-row"><label>{{ t('device') }} <select v-model="selectedDevice" :disabled="microphoneRunning"><option value="">{{ t('defaultDevice') }}</option><option v-for="(device, index) in devices" :key="device.deviceId" :value="device.deviceId">{{ device.label || t('deviceNumber', { n: index + 1 }) }}</option></select></label><button @click="refreshDevices" :disabled="microphoneRunning">{{ t('refreshDevices') }}</button><button v-if="!microphoneRunning" @click="testMicrophone" :disabled="connection !== 'connected'">{{ t('testMicrophone') }}</button><button v-if="testing || preparing" @click="safePause()">{{ t(testing ? 'stopTest' : 'cancel') }}</button></div>
          <label class="meter-label">{{ t('level') }} <meter :value="micLevel" min="0" max="1" :aria-label="t('level')"></meter></label><p v-if="inputRate" class="muted">{{ t('inputRate') }}: {{ inputRate }} Hz · {{ t('outputFormat') }}</p>
          <label class="consent"><input v-model="consent" type="checkbox" :disabled="recording || preparing"> <span>{{ t('consent') }}</span></label>
          <p class="foreground-note">{{ t('foreground') }}</p>
          <div class="record-actions"><button v-if="!recording" class="primary" @click="startCapture" :disabled="!consent || preparing || connection !== 'connected'">{{ t(lesson.status === 'ready' ? 'start' : 'resume') }}</button><button v-else class="primary" @click="safePause()">⏸ {{ t('pause') }}</button><button v-if="canEnd" class="danger" @click="showEnd = true">{{ t('end') }}</button></div>
        </section>
        <p v-else class="muted">{{ t('permissionViewer') }}</p>
        <button v-if="canEnd && !isRecorder" class="danger" @click="showEnd = true">{{ t('end') }}</button>
      </template>
      <template v-else-if="course">
        <div class="title-row"><div><button class="back" @click="navigate('/courses')">← {{ t('courses') }}</button><h1>{{ course.name }}</h1></div><button v-if="permitted" class="primary" @click="openCreation('lesson')">+ {{ t('newLesson') }}</button></div>
        <p v-if="!lessons.length" class="empty-state">{{ t('emptyLessons') }}</p><div class="card-grid"><button v-for="item in lessons" :key="item.id" class="course-card" @click="navigate(`/lessons/${item.id}`)"><span class="state-pill" :class="item.status">{{ t(`status_${item.status}`) }}</span><h2>{{ item.name }}</h2><p>{{ t('mediaDuration') }} · {{ duration(item.media_samples) }}</p></button></div>
      </template>
      <template v-else>
        <div class="title-row"><h1>{{ t('courses') }}</h1><button v-if="user?.role === 'admin' && permitted" class="primary" @click="openCreation('course')">+ {{ t('newCourse') }}</button></div>
        <p v-if="!courses.length" class="empty-state">{{ t('emptyCourses') }}</p><div class="card-grid"><article v-for="item in courses" :key="item.id" class="course-card"><button class="card-main" @click="navigate(item.active_lesson_id ? `/lessons/${item.active_lesson_id}` : `/courses/${item.id}/lessons`)"><h2>{{ item.name }}</h2><p v-if="item.description">{{ item.description }}</p><p>{{ t('targetLanguage') }} · {{ item.target_language }}</p></button><button class="history-link" @click="navigate(`/courses/${item.id}/lessons`)">{{ t('allHistory') }} →</button></article></div>
        <button v-if="errorCode" @click="action(async () => { await refreshIdentity(); await loadView(); })">{{ t('retry') }}</button>
      </template>
    </main>

    <div v-if="showLogin || user?.must_change_password || showCreate || showEnd" class="dialog-backdrop">
      <section class="dialog" role="dialog" aria-modal="true" :aria-label="t(user?.must_change_password ? 'requiredPassword' : showLogin ? 'login' : showEnd ? 'end' : showCreate === 'course' ? 'newCourse' : 'newLesson')">
        <form v-if="user?.must_change_password" @submit.prevent="savePassword"><h2>{{ t('requiredPassword') }}</h2><p>{{ t('passwordHelp') }}</p><label>{{ t('newPassword') }}<input v-model="newPassword" type="password" autocomplete="new-password" minlength="12" required autofocus></label><p v-if="message" class="alert" role="alert">{{ message }}</p><div class="dialog-actions"><button type="button" @click="logout" :disabled="busy">{{ t('logout') }}</button><button type="submit" class="primary" :disabled="busy">{{ t('changePassword') }}</button></div></form>
        <form v-else-if="showLogin" @submit.prevent="login"><h2>{{ t('login') }}</h2><p class="muted">{{ t('loginHelp') }}</p><label>{{ t('studentId') }}<input v-model="studentId" autocomplete="username" required autofocus></label><label>{{ t('password') }}<input v-model="password" type="password" autocomplete="current-password" required></label><p v-if="message" class="alert" role="alert">{{ message }}</p><div class="dialog-actions"><button type="button" @click="showLogin = false; password = ''">{{ t('cancel') }}</button><button type="submit" class="primary" :disabled="busy">{{ t('login') }}</button></div></form>
        <form v-else-if="showCreate" @submit.prevent="create"><h2>{{ t(showCreate === 'course' ? 'newCourse' : 'newLesson') }}</h2><label>{{ t('name') }}<input v-model="newName" required maxlength="200" autofocus></label><template v-if="showCreate === 'course'"><label>{{ t('targetLanguage') }}<select v-model="targetLanguage"><option value="zh">中文</option><option value="en">English</option><option value="uk">Українська</option></select></label><label class="consent"><input type="checkbox" v-model="publicAccess">{{ t('guestAccess') }}</label></template><p v-if="message" class="alert" role="alert">{{ message }}</p><div class="dialog-actions"><button type="button" @click="showCreate = null">{{ t('cancel') }}</button><button type="submit" class="primary" :disabled="busy">{{ t('create') }}</button></div></form>
        <div v-else-if="showEnd"><h2>{{ t('end') }}</h2><p>{{ t('endConfirm') }}</p><div class="dialog-actions"><button @click="showEnd = false">{{ t('cancel') }}</button><button class="danger" @click="endLesson">{{ t('confirm') }}</button></div></div>
      </section>
    </div>
  </div>
</template>
