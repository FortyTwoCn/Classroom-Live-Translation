import { api, ApiError, type Snapshot, type Lesson, type Unit, type Participant, type Handoff } from './api';
export type WireMessage = { type: string; seq?: number; event_type?: string; payload?: { lesson?: Lesson; unit?: Unit; units?: Unit[]; count?: number }; lesson?: Lesson;lesson_seq?:number; units?: Unit[]; last_seq?: number; unit?: Unit; epoch?: number; next_sequence?: number; media_samples?: number; sequence?: number; code?: string; count?: number; participants?: Participant[];current_client_id?:string; items?: Handoff[]; id?: string; live?: boolean };
export class LessonSocket {
  socket: WebSocket | null = null;
  lastSeq = 0;
  private generation = 0;
  private stopped = true;
  private retries = 0;
  private heartbeat: number | null = null;
  private retryTimer: number | null = null;
  onMessage: (message: WireMessage) => void = () => {};
  onState: (state: string) => void = () => {};
  onDisconnect: () => void = () => {};
  onError: (code: string) => void = () => {};
  constructor(private lessonId: number, private url: string) {}
  async open(): Promise<void> {
    this.stopped = false;
    const generation = ++this.generation;
    this.onState('connecting');
    try {
      const { ticket } = await api.request<{ ticket: string }>(`/api/lessons/${this.lessonId}/ticket`, {});
      if (generation !== this.generation || this.stopped) return;
      const url = new URL(this.url, location.href);
      if (!['ws:', 'wss:'].includes(url.protocol) || (location.protocol === 'https:' && url.protocol !== 'wss:')) throw new ApiError('secure_context_required');
      const socket = new WebSocket(url.href);
      this.socket = socket;
      socket.onopen = () => {
        if (generation !== this.generation) { socket.close(); return; }
        socket.send(JSON.stringify({ type: 'subscribe', ticket, last_seq: this.lastSeq }));
        this.heartbeat = window.setInterval(() => this.command('heartbeat'), 5000);
      };
      socket.onmessage = event => {
        if (generation !== this.generation || typeof event.data !== 'string') return;
        let message: WireMessage;
        try { message = JSON.parse(event.data); } catch { this.onError('protocol_error'); socket.close(); return; }
        if (message.type === 'snapshot') {
          if (!message.lesson || !Array.isArray(message.units) || !Number.isSafeInteger(message.last_seq)) { this.onError('protocol_error'); socket.close(); return; }
          this.lastSeq = message.last_seq!;
          this.retries = 0;
          this.onState('connected');
        }
        if (message.type === 'event') {
          if (!Number.isSafeInteger(message.seq)) { this.onError('protocol_error'); socket.close(); return; }
          if (message.seq! <= this.lastSeq) return;
          // The database outbox uses one global sequence across all lessons.
          // Gaps can belong to a different lesson and are not packet loss.
          this.lastSeq = message.seq!;
        }
        if (message.type === 'error') this.onError(message.code || 'generic');
        this.onMessage(message);
      };
      socket.onerror = () => this.onError('network_error');
      socket.onclose = () => {
        if (generation !== this.generation) return;
        this.cleanupHeartbeat();
        this.socket = null;
        this.onState('disconnected');
        this.onDisconnect();
        this.scheduleRetry();
      };
    } catch (error) {
      if (generation !== this.generation) return;
      this.onState('disconnected');
      this.onError(error instanceof ApiError ? error.code : 'network_error');
      if (!(error instanceof ApiError) || ![401, 403, 404].includes(error.status)) this.scheduleRetry();
    }
  }
  command(type: string, fields: Record<string, unknown> = {}): boolean {
    if (this.socket?.readyState !== WebSocket.OPEN) return false;
    this.socket.send(JSON.stringify({ type, ...fields }));
    return true;
  }
  seed(snapshot: Snapshot): void { this.lastSeq = snapshot.last_seq; }
  private scheduleRetry(): void {
    if (this.stopped || this.retryTimer !== null) return;
    const delay = Math.min(30000, 1000 * 2 ** this.retries++);
    this.retryTimer = window.setTimeout(() => { this.retryTimer = null; void this.open(); }, delay);
  }
  private cleanupHeartbeat(): void { if (this.heartbeat !== null) window.clearInterval(this.heartbeat); this.heartbeat = null; }
  close(): void {
    this.stopped = true;
    this.generation++;
    this.cleanupHeartbeat();
    if (this.retryTimer !== null) window.clearTimeout(this.retryTimer);
    this.retryTimer = null;
    this.socket?.close();
    this.socket = null;
    this.onState('idle');
  }
}
