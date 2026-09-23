"use client";

import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { useAuth } from "@/hooks/use-auth";
import { EstablecimientoSelector } from "./establecimiento-selector";

export function EstablecimientoGate({
  children,
}: {
  children: React.ReactNode;
}) {
  const { establecimientos, establecimientoActual, refresh } =
    useEstablecimiento();
  const { user } = useAuth();
  if (establecimientoActual) {
    // Remount all tenant-dependent UI on a switch, including pending component state.
    return (
      <div key={`${user?.id}:${establecimientoActual.id}`}>{children}</div>
    );
  }
  return (
    <section className="card selection-card">
      <span className="section-symbol" aria-hidden="true">
        ⌂
      </span>
      <p className="eyebrow">TU ESPACIO DE TRABAJO</p>
      <h1>
        {establecimientos.length
          ? "¿Dónde vas a trabajar hoy?"
          : "Sin establecimientos disponibles"}
      </h1>
      <p className="muted">
        {establecimientos.length
          ? "Selecciona un establecimiento para acceder a tu espacio de trabajo. Puedes cambiarlo en cualquier momento desde la cabecera."
          : "Tu cuenta está activa, pero no tiene establecimientos autorizados. Contacta con la persona administradora para que revise tu acceso."}
      </p>
      {establecimientos.length > 0 && <EstablecimientoSelector />}
      <button
        className="button button-secondary"
        type="button"
        onClick={() => void refresh()}
      >
        Actualizar mis accesos
      </button>
    </section>
  );
}
