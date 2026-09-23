"use client";

import { useSession } from "@/providers/session-provider";

export function useEstablecimiento() {
  const { store, snapshot } = useSession();
  const session = snapshot.session;
  return {
    establecimientos:
      session?.membresias.map((item) => item.establecimiento) ?? [],
    establecimientoActual: session?.establecimientoActual ?? null,
    membresiaActual:
      session?.membresias.find(
        (item) => item.establecimiento.id === session.establecimientoActual?.id,
      ) ?? null,
    seleccionarEstablecimiento: store.seleccionarEstablecimiento,
    refresh: store.restore,
  };
}
