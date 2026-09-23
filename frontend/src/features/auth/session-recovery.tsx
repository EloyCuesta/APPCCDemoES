"use client";

import { useAuth } from "@/hooks/use-auth";
import { ErrorNotice } from "@/components/ui/feedback";

export function SessionRecovery() {
  const { error, retry, logout } = useAuth();
  return (
    <div className="centered-page">
      <section className="card recovery-card">
        <p className="eyebrow">SESIÓN</p>
        <h1>No se ha podido cargar tu espacio</h1>
        <p className="muted">
          Vuelve a comprobar la conexión para recuperar tus establecimientos.
        </p>
        {error && <ErrorNotice error={error} onRetry={() => void retry()} />}
        <button type="button" className="button button-quiet" onClick={logout}>
          Cerrar sesión
        </button>
      </section>
    </div>
  );
}
