import { ApiClient } from "@/lib/api/client";
import { endpoints } from "@/lib/api/endpoints";
import { ApiError, asApiError, isAborted } from "@/lib/api/errors";
import { parseContexto, parseLogin } from "@/features/auth/contracts";
import type { AuthSession, ContextoSesion, LoginInput } from "@/types/session";
import type { SessionStorage } from "./storage";

export interface SessionSnapshot {
  status:
    "restoring" | "anonymous" | "authenticating" | "authenticated" | "error";
  session: AuthSession | null;
  error: ApiError | null;
  revision: number;
}

const initial: SessionSnapshot = {
  status: "restoring",
  session: null,
  error: null,
  revision: 0,
};

/** One store per provider, never a server singleton. React and API headers share this source. */
export function createSessionStore(baseUrl: string, storage: SessionStorage) {
  let snapshot = initial;
  let generation = 0;
  const listeners = new Set<() => void>();
  function publish(next: Omit<SessionSnapshot, "revision">) {
    snapshot = { ...next, revision: snapshot.revision + 1 };
    listeners.forEach((listener) => listener());
  }

  const api = new ApiClient(baseUrl, {
    getToken: () => storage.getToken(),
    getEstablecimientoId: () =>
      snapshot.session?.establecimientoActual?.id ?? null,
    onUnauthorized: () => logout(new ApiError(401)),
  });

  function invalidate() {
    generation += 1;
    api.cancelPending();
    return generation;
  }

  function logout(error: ApiError | null = null) {
    invalidate();
    storage.clear();
    publish({ status: "anonymous", session: null, error });
  }

  function acceptContext(context: ContextoSesion) {
    const { membresias, id, nombre, apellidos, email } = context;
    const user = { id, nombre, apellidos, email };
    const remembered = storage.getEstablecimiento();
    const selected =
      membresias.find((item) => item.establecimiento.id === remembered) ??
      (membresias.length === 1 ? membresias[0] : undefined);
    const establecimientoActual = selected?.establecimiento ?? null;
    storage.setEstablecimiento(establecimientoActual?.id ?? null);
    publish({
      status: "authenticated",
      session: { user, membresias, establecimientoActual },
      error: null,
    });
  }

  async function restore() {
    const run = invalidate();
    publish({ status: "restoring", session: null, error: null });
    if (!storage.getToken()) {
      logout();
      return;
    }
    try {
      const context = parseContexto(await api.request(endpoints.me));
      if (run === generation) acceptContext(context);
    } catch (error) {
      if (run !== generation || isAborted(error)) return;
      // A transient outage does not discard credentials or open private content.
      publish({ status: "error", session: null, error: asApiError(error) });
    }
  }

  async function login(input: LoginInput) {
    const run = invalidate();
    storage.clear();
    publish({ status: "authenticating", session: null, error: null });
    try {
      const result = parseLogin(
        await api.request(endpoints.login, {
          method: "POST",
          body: { ...input, email: input.email.trim() },
        }),
      );
      if (run !== generation) return;
      storage.setToken(result.token);
      const context = parseContexto(await api.request(endpoints.me));
      if (run === generation) acceptContext(context);
    } catch (error) {
      if (run !== generation || isAborted(error)) return;
      const parsed = asApiError(error);
      if (storage.getToken())
        publish({ status: "error", session: null, error: parsed });
      else logout(parsed);
    }
  }

  function seleccionarEstablecimiento(id: number) {
    const session = snapshot.session;
    const member = session?.membresias.find(
      (item) => item.establecimiento.id === id,
    );
    if (!session || !member) throw new ApiError(403);
    if (session.establecimientoActual?.id === id) return;
    invalidate();
    storage.setEstablecimiento(id);
    publish({
      status: "authenticated",
      session: { ...session, establecimientoActual: member.establecimiento },
      error: null,
    });
  }

  return {
    api,
    login,
    logout,
    restore,
    seleccionarEstablecimiento,
    cancelPending: () => {
      invalidate();
    },
    getSnapshot: () => snapshot,
    getServerSnapshot: () => initial,
    subscribe(listener: () => void) {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
  };
}

export type SessionStore = ReturnType<typeof createSessionStore>;
