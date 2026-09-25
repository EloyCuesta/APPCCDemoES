"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { usePathname } from "next/navigation";
import { Brand } from "@/components/ui/brand";
import { useAuth } from "@/hooks/use-auth";
import { EstablecimientoSelector } from "@/features/establecimientos/establecimiento-selector";

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const pathname = usePathname();
  const [menuOpen, setMenuOpen] = useState(false);
  const main = useRef<HTMLElement>(null);
  const menuToggle = useRef<HTMLButtonElement>(null);
  useEffect(() => { main.current?.focus(); }, [pathname]);
  function closeMenu() { setMenuOpen(false); menuToggle.current?.focus(); }
  return (
    <div className="app-shell">
      <a href="#main-content" className="skip-link">
        Saltar al contenido
      </a>
      <aside className={`sidebar ${menuOpen ? "sidebar-open" : ""}`} onKeyDown={(event) => { if (event.key === "Escape") closeMenu(); }}>
        <div className="sidebar-brand">
          <Brand />
        </div>
        <button
          className="mobile-menu-close button button-quiet"
          type="button"
          onClick={closeMenu}
        >
          Cerrar menú
        </button>
        <nav id="main-navigation" aria-label="Navegación principal">
          <p className="nav-label">ESPACIO DE TRABAJO</p>
          {[{ href: "/dashboard", name: "Dashboard", symbol: "▦" }, { href: "/agenda", name: "Agenda", symbol: "▤" }, { href: "/registros", name: "Registros", symbol: "▣" }, { href: "/incidencias", name: "Incidencias", symbol: "!" }, { href: "/plantillas", name: "Plantillas", symbol: "▧" }].map((item) => {
            const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
            return <Link key={item.href} href={item.href} className={`nav-item ${active ? "nav-active" : ""}`} aria-current={active ? "page" : undefined} onClick={() => setMenuOpen(false)}><span aria-hidden="true">{item.symbol}</span>{item.name}</Link>;
          })}
        </nav>
        <div className="sidebar-note">
          <span className="status-dot" />
          <strong>Tu base de control APPCC</strong>
          <p>
            Organiza el trabajo de cada establecimiento desde un único lugar.
          </p>
        </div>
        <div className="sidebar-bottom">
          APPCC Demo ES <span>MVP</span>
        </div>
      </aside>
      <div className="app-main">
        <header className="app-header">
          <button
            type="button"
            className="button button-secondary mobile-menu-toggle"
            ref={menuToggle}
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
        <main id="main-content" className="page-content" tabIndex={-1} ref={main}>
          {children}
        </main>
        <footer className="app-footer">
          APPCC Demo ES <span>Seguridad alimentaria · Espacio de trabajo</span>
        </footer>
      </div>
    </div>
  );
}
