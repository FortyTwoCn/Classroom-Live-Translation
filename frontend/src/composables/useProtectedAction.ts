import { ref } from "vue";
import { api, ApiError, errorCode } from "../api";
export function useProtectedAction() {
  const busy = ref(false),
    error = ref(""),
    reauth = ref(false),
    password = ref("");
  let pending: (() => Promise<void>) | null = null;
  async function run(callback: () => Promise<void>) {
    if (busy.value) return;
    busy.value = true;
    error.value = "";
    try {
      await callback();
    } catch (e) {
      if (e instanceof ApiError && e.code === "reauth_required") {
        pending = callback;
        reauth.value = true;
      } else error.value = errorCode(e);
    } finally {
      busy.value = false;
    }
  }
  function cancel() {
    reauth.value = false;
    password.value = "";
    pending = null;
  }
  async function authorize() {
    await run(async () => {
      await api.request("/api/reauth", { password: password.value });
      const callback = pending;
      cancel();
      if (callback) await callback();
    });
  }
  return { busy, error, reauth, password, run, cancel, authorize };
}
