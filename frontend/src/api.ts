export interface User {
  id: number;
  name: string;
  role: "admin" | "user";
  must_change_password: boolean;
  student_id?: string;
  disabled?: boolean;
}
export interface Course {
  id: number;
  name: string;
  description?: string;
  target_language: string;
  guest_access: "public" | "link";
  allow_audio_download?: boolean;
  active_lesson_id?: number | null;
  active_lesson_status?: string;
  lesson_count?: number;
  enabled?: boolean;
  archived?: boolean;
  archived_at?: string | null;
  sort_order?: number;
  deleted_at?: string | null;
}
export interface Lesson {
  id: number;
  course_id: number;
  creator_id?: number;
  name: string;
  course_name?: string;
  status: "ready" | "live" | "paused" | "ended";
  translation_status: string;
  archive_status: string;
  recorder_user_id: number | null;
  recorder_name?: string;
  recorder_epoch: number;
  recorder_offline_at?: string | null;
  media_samples: number;
  target_language: string;
  created_at?: string;
  version?: number;
  editing_frozen?: boolean;
  access_mode?: string;
  allow_audio_download?: boolean;
  purge_status?: string | null;
}
export interface Unit {
  id: number | string;
  provider_unit_id?: string;
  kind: "source" | "translation";
  text: string;
  original_text?: string;
  version?: number;
  revision_count?: number;
  edited?: boolean;
  deleted_at?: string | null;
  status: string;
  start_sample: number | null;
  end_sample: number | null;
  timing_precision?: string;
  source_item_id?: string | null;
}
export interface Participant {
  client_id: string;
  user_id: number;
  name: string;
}
export interface Handoff {
  id: string;
  kind: "invite" | "request";
  status: string;
  role: "sender" | "recipient" | "target";
  requester_name: string;
  target_name: string;
  expires_at: string;
  needs_prepare?: boolean;
}
export interface Snapshot {
  lesson: Lesson;
  units: Unit[];
  last_seq: number;
  issues?: CaptureIssue[];
}
export interface CaptureIssue {
  id: number;
  epoch?: number;
  kind?: string;
  reason?: string;
  start_sample?: number | null;
  end_sample?: number | null;
  estimated_lost_ms?: number | null;
  created_at?: string;
  details?: {
    reason?: string;
    estimated_lost_ms?: number | null;
    [key: string]: unknown;
  };
  [key: string]: unknown;
}
export class ApiError extends Error {
  constructor(
    readonly code: string,
    readonly status = 0,
    readonly params: Record<string, unknown> = {},
  ) {
    super(code);
  }
}
export const errorCode = (error: unknown) =>
  error instanceof ApiError
    ? error.code
    : error instanceof DOMException
      ? error.name
      : error instanceof Error
        ? error.message
        : "generic";
export const api = {
  csrf: "",
  async request<T>(
    path: string,
    body?: unknown,
    method?: "GET" | "POST" | "PATCH" | "DELETE" | "PUT",
  ): Promise<T> {
    let response: Response;
    const isForm = body instanceof FormData;
    try {
      response = await fetch(path, {
        method: method || (body === undefined ? "GET" : "POST"),
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          ...(body === undefined
            ? {}
            : {
                ...(isForm ? {} : { "Content-Type": "application/json" }),
                "X-CSRF-TOKEN": this.csrf,
              }),
        },
        ...(body === undefined
          ? {}
          : { body: isForm ? body : JSON.stringify(body) }),
      });
    } catch {
      throw new ApiError("network_error");
    }
    let data: Record<string, unknown>;
    try {
      data = await response.json();
    } catch {
      throw new ApiError("network_error", response.status);
    }
    if (typeof data.csrf_token === "string") this.csrf = data.csrf_token;
    if (!response.ok)
      throw new ApiError(
        typeof data.code === "string"
          ? data.code
          : typeof data.error === "string"
            ? data.error
            : "generic",
        response.status,
        (data.params || {}) as Record<string, unknown>,
      );
    return data as T;
  },
};
