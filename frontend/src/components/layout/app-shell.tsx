"use client";

import Link from "next/link";
import { useState } from "react";
import { Brand } from "@/components/ui/brand";
import { useAuth } from "@/hooks/use-auth";
import { EstablecimientoSelector } from "@/features/establecimientos/establecimiento-selector";

const futurePages = [
  "Agenda",
  "Tareas",
  "Registros",
  "Incidencias",
  "Plantillas",
  "Configuración",
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  return (
    <div className="app-shell">
      <a href="#main-content" className="skip-link">
        Saltar al contenido
      </a>
      <aside className={`sidebar ${menuOpen ? "sidebar-open" : ""}`}>
        <div className="sidebar-brand">
          <Brand />
        </div>
        <button
          className="mobile-menu-close button button-quiet"
          type="button"
          onClick={() => setMenuOpen(false)}
        >
          Cerrar menú
        </button>
        <nav id="main-navigation" aria-label="Navegación principal">
          <p className="nav-label">ESPACIO DE TRABAJO</p>
          <Link
            href="/dashboard"
            className="nav-item nav-active"
            aria-current="page"
            onClick={() => setMenuOpen(false)}
          >
            <span aria-hidden="true">▦</span>Dashboard
          </Link>
          {futurePages.map((name, index) => (
            <button
              type="button"
              className="nav-item"
              disabled
              key={name}
              title="Disponible en una próxima versión"
            >
              <span className="nav-index" aria-hidden="true">
                {String(index + 2).padStart(2, "0")}
              </span>
              {name}
              <span className="soon-label">Próx.</span>
            </button>
          ))}
        </nav>
        <div className="sidebar-note">
          <span className="status-dot" />
          <strong>Tu base de control APPCC</strong>
          <p>
            Organiza el trabajo de cada establecimiento desde un único lugar.
          </p>
        </div>
        <div className="sidebar-bottom">
          APPCC Demo ES <span>Base inicial</span>
        </div>
      </aside>
      <div className="app-main">
        <header className="app-header">
          <button
            type="button"
            className="button button-secondary mobile-menu-toggle"
            aria-expanded={menuOpen}
            aria-controls="main-navigation"
            onClick={() => setMenuOpen(!menuOpen)}
          >
            Menú
          </button>
          <EstablecimientoSelector />
          <div className="header-user">
            <span className="avatar" aria-hidden="true">
              {user?.nombre.charAt(0)}
              {user?.apellidos.charAt(0)}
            </span>
            <div>
              <strong>
                {user?.nombre} {user?.apellidos}
              </strong>
              <span>{user?.email}</span>
            </div>
          </div>
          <button
            className="button button-secondary logout-button"
            type="button"
            onClick={logout}
          >
            Cerrar sesión
          </button>
        </header>
        <main id="main-content" className="page-content" tabIndex={-1}>
          {children}
        </main>
        <footer className="app-footer">
          APPCC Demo ES <span>Seguridad alimentaria · Espacio de trabajo</span>
        </footer>
      </div>
    </div>
  );
}
