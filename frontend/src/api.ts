export interface User { id: number; name: string; role: 'admin' | 'user'; must_change_password: boolean; }
export interface Course { id: number; name: string; description?: string; target_language: string; guest_access: 'public' | 'link'; active_lesson_id?: number | null; lesson_count?: number; }
export interface Lesson { id: number; course_id: number; creator_id?: number; name: string; course_name?: string; status: 'ready' | 'live' | 'paused' | 'ended'; translation_status: string; archive_status: string; recorder_user_id: number | null; recorder_name?: string; recorder_epoch: number; media_samples: number; target_language: string; created_at?: string; }
export interface Unit { id: number | string; provider_unit_id?: string; kind: 'source' | 'translation'; text: string; status: string; start_sample: number | null; end_sample: number | null; timing_precision?: string; source_item_id?: string | null; }
export interface Snapshot { lesson: Lesson; units: Unit[]; last_seq: number; }
export class ApiError extends Error { constructor(readonly code: string, readonly status = 0) { super(code); } }
export const api = {
  csrf: '',
  async request<T>(path: string, body?: unknown): Promise<T> {
    let response: Response;
    try { response = await fetch(path, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf }) }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) }); }
    catch { throw new ApiError('network_error'); }
    let data: Record<string, unknown>;
    try { data = await response.json(); } catch { throw new ApiError('network_error', response.status); }
    if (typeof data.csrf_token === 'string') this.csrf = data.csrf_token;
    if (!response.ok) throw new ApiError(typeof data.code === 'string' ? data.code : typeof data.error === 'string' ? data.error : 'generic', response.status);
    return data as T;
  }
};
