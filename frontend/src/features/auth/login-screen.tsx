"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/hooks/use-auth";
import { Brand } from "@/components/ui/brand";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";
import { SessionRecovery } from "./session-recovery";

export function LoginScreen() {
  const { login, status, error, isAuthenticated } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const busy = status === "authenticating";
  useEffect(() => {
    if (isAuthenticated) router.replace("/dashboard");
  }, [isAuthenticated, router]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    await login({ email, password });
    setPassword("");
  }

  if (status === "error") return <SessionRecovery />;
  if (status === "restoring" || isAuthenticated)
    return (
      <div className="centered-page">
        <LoadingState message="Preparando tu espacio…" />
      </div>
    );

  return (
    <main className="login-page">
      <aside className="login-story">
        <Brand />
        <div className="login-story-content">
          <p className="eyebrow">TU ESPACIO DE TRABAJO</p>
          <h1>La seguridad alimentaria empieza con un buen control.</h1>
          <p>
            Un espacio común para el seguimiento APPCC de tus establecimientos.
          </p>
          <div className="story-note">
            <span className="story-check" aria-hidden="true">
              ✓
            </span>
            <div>
              <strong>Cada establecimiento, en su contexto</strong>
              <p>Accede con tu cuenta y elige dónde vas a trabajar.</p>
            </div>
          </div>
        </div>
        <p className="story-footer">
          APPCC Demo ES <span>Gestión con criterio.</span>
        </p>
      </aside>
      <section className="login-form-area" aria-labelledby="login-title">
        <div className="login-form-container">
          <div className="mobile-brand">
            <Brand compact />
          </div>
          <p className="eyebrow">ACCESO A LA PLATAFORMA</p>
          <h2 id="login-title">Bienvenido de nuevo</h2>
          <p className="muted login-intro">
            Introduce tus credenciales para acceder a tu espacio de trabajo.
          </p>
          <form onSubmit={submit} aria-busy={busy}>
            <div className="field">
              <label htmlFor="email">Email</label>
              <input
                id="email"
                name="email"
                type="email"
                autoComplete="username"
                required
                maxLength={180}
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                disabled={busy}
                placeholder="tu@empresa.es"
              />
            </div>
            <div className="field">
              <label htmlFor="password">Contraseña</label>
              <input
                id="password"
                name="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                disabled={busy}
                placeholder="Introduce tu contraseña"
              />
            </div>
            {error && <ErrorNotice error={error} />}
            <button
              className="button button-primary login-submit"
              type="submit"
              disabled={busy}
            >
              {busy ? "Iniciando sesión…" : "Entrar a mi espacio"}
              <span aria-hidden="true">→</span>
            </button>
            {busy && (
              <p className="muted" role="status">
                Verificando tu cuenta y cargando establecimientos…
              </p>
            )}
          </form>
          <p className="login-help">
            ¿Necesitas acceso? Contacta con la persona administradora de tu
            establecimiento.
          </p>
        </div>
        <p className="login-footer">
          Control APPCC · Acceso de usuarios autorizados
        </p>
      </section>
    </main>
  );
}
