import { ApiError } from "@/lib/api/errors";

const TOKEN_KEY = "appcc.token";
const ESTABLECIMIENTO_KEY = "appcc.establecimiento";

export interface SessionStorage {
  getToken(): string | null;
  setToken(token: string): void;
  getEstablecimiento(): number | null;
  setEstablecimiento(id: number | null): void;
  clear(): void;
}

/** Only token persistence adapter. Never mirror the token into React state or cookies. */
export const browserSessionStorage: SessionStorage = {
  getToken() {
    try {
      return window.sessionStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  },
  setToken(token) {
    try {
      window.sessionStorage.setItem(TOKEN_KEY, token);
    } catch {
      throw new ApiError(
        0,
        "El navegador no permite guardar la sesión. Habilita el almacenamiento del sitio.",
      );
    }
  },
  getEstablecimiento() {
    try {
      const id = Number(window.sessionStorage.getItem(ESTABLECIMIENTO_KEY));
      return Number.isSafeInteger(id) && id > 0 ? id : null;
    } catch {
      return null;
    }
  },
  setEstablecimiento(id) {
    try {
      if (id === null) window.sessionStorage.removeItem(ESTABLECIMIENTO_KEY);
      else window.sessionStorage.setItem(ESTABLECIMIENTO_KEY, String(id));
    } catch {
      /* A preference is optional; the validated in-memory selection still works. */
    }
  },
  clear() {
    try {
      window.sessionStorage.removeItem(TOKEN_KEY);
    } catch {
      /* Storage may be disabled. */
    }
    this.setEstablecimiento(null);
  },
};
