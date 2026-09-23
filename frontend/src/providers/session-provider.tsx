"use client";

import {
  createContext,
  useContext,
  useEffect,
  useState,
  useSyncExternalStore,
} from "react";
import {
  createSessionStore,
  type SessionSnapshot,
  type SessionStore,
} from "@/lib/auth/session-store";
import { browserSessionStorage } from "@/lib/auth/storage";

const SessionContext = createContext<{
  store: SessionStore;
  snapshot: SessionSnapshot;
} | null>(null);

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [store] = useState(() =>
    createSessionStore(
      process.env.NEXT_PUBLIC_API_URL ?? "",
      browserSessionStorage,
    ),
  );
  const snapshot = useSyncExternalStore(
    store.subscribe,
    store.getSnapshot,
    store.getServerSnapshot,
  );
  useEffect(() => {
    void store.restore();
    return () => store.cancelPending();
  }, [store]);
  return (
    <SessionContext.Provider value={{ store, snapshot }}>
      {children}
    </SessionContext.Provider>
  );
}

export function useSession() {
  const value = useContext(SessionContext);
  if (!value) throw new Error("SessionProvider is required");
  return value;
}
