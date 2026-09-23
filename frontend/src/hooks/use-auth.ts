"use client";

import { useSession } from "@/providers/session-provider";

export function useAuth() {
  const { store, snapshot } = useSession();
  return {
    user: snapshot.session?.user ?? null,
    isAuthenticated: snapshot.status === "authenticated",
    isLoading:
      snapshot.status === "restoring" || snapshot.status === "authenticating",
    status: snapshot.status,
    error: snapshot.error,
    login: store.login,
    logout: () => store.logout(),
    retry: store.restore,
  };
}
