"use client";

import { useSession } from "@/providers/session-provider";

export function useApi() {
  return useSession().store.api;
}
