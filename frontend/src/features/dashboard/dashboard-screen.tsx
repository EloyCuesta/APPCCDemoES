"use client";

import Link from "next/link";

import { useAuth } from "@/hooks/use-auth";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { ConnectionStatus } from "./connection-status";
import type { RolEstablecimiento } from "@/types/session";
import { Indicadores } from "./indicadores";

const roles: Record<RolEstablecimiento, string> = {
  admin: "Administrador",
  responsable: "Responsable",
  trabajador: "Trabajador",
  auditor: "Auditor",
};

export function DashboardScreen() {
  const { user } = useAuth();
  const { establecimientoActual: establishment, membresiaActual } =
    useEstablecimiento();
  if (!establishment || !user) return null;
  return (
    <>
      <div className="page-heading">
        <div>
          <p className="eyebrow">VISTA GENERAL</p>
          <h1>Dashboard</h1>
          <p className="muted">
            Hola, {user.nombre}. Este es tu espacio de control APPCC.
          </p>
        </div>
        <span className="badge">Control APPCC</span>
      </div>
      <section className="establishment-banner">
        <div>
          <p className="eyebrow">ESTABLECIMIENTO ACTUAL</p>
          <h2>{establishment.nombre}</h2>
          <p>{establishment.entidadFiscal.nombre}</p>
        </div>
        <span className="activity-tag">
          {establishment.tipoActividad.replaceAll("_", " ")}
        </span>
      </section>
      <Indicadores tenantId={establishment.id} />
      <div className="details-grid">
        <section className="card user-card">
          <div className="section-heading">
            <h2>Tu sesión</h2>
            <span className="eyebrow">CUENTA</span>
          </div>
          <dl>
            <div>
              <dt>Usuario</dt>
              <dd>
                {user.nombre} {user.apellidos}
              </dd>
            </div>
            <div>
              <dt>Email</dt>
              <dd>{user.email}</dd>
            </div>
            <div>
              <dt>Rol en este establecimiento</dt>
              <dd>{membresiaActual ? roles[membresiaActual.rol] : "—"}</dd>
            </div>
          </dl>
        </section>
        <ConnectionStatus establecimientoId={establishment.id} />
      </div>
      <section className="next-step">
        <span className="next-step-icon" aria-hidden="true">
          ▤
        </span>
        <div>
          <p className="eyebrow">SIGUIENTE PASO</p>
          <h2>Agenda APPCC</h2>
          <p className="muted">
            Consulta los controles pendientes, vencidos y próximos de este establecimiento.
          </p>
        </div>
        <Link className="button button-secondary" href="/agenda">Abrir agenda</Link>
      </section>
    </>
  );
}
