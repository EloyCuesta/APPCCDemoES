"use client";

import { useState, type FormEvent } from "react";
import { estados, gravedades } from "./contracts";
import {
  emptyFilters,
  filtersError,
  type IncidenciasFiltersValue,
} from "./query";

export function IncidenciasFilters({
  onApply,
}: {
  onApply: (value: IncidenciasFiltersValue) => void;
}) {
  const [value, setValue] = useState(emptyFilters);
  const [error, setError] = useState<string | null>(null);
  function submit(event: FormEvent) {
    event.preventDefault();
    const message = filtersError(value);
    setError(message);
    if (!message) onApply(value);
  }
  return (
    <form
      className="card agenda-filters"
      onSubmit={submit}
      aria-label="Filtros de incidencias"
    >
      <div className="section-heading">
        <h2>Filtrar incidencias</h2>
      </div>
      <div className="incidencias-filter-grid">
        <div className="agenda-filter-field">
          <label htmlFor="inc-estado">Estado</label>
          <select
            id="inc-estado"
            value={value.estado}
            onChange={(e) =>
              setValue({
                ...value,
                estado: e.target.value as IncidenciasFiltersValue["estado"],
              })
            }
          >
            <option value="">Todos los estados</option>
            {Object.entries(estados).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="agenda-filter-field">
          <label htmlFor="inc-gravedad">Gravedad</label>
          <select
            id="inc-gravedad"
            value={value.gravedad}
            onChange={(e) =>
              setValue({
                ...value,
                gravedad: e.target.value as IncidenciasFiltersValue["gravedad"],
              })
            }
          >
            <option value="">Todas las gravedades</option>
            {Object.entries(gravedades).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="agenda-filter-field">
          <label htmlFor="inc-registro">Registro de origen (número)</label>
          <input
            id="inc-registro"
            inputMode="numeric"
            value={value.registro}
            onChange={(e) => setValue({ ...value, registro: e.target.value })}
          />
        </div>
        <div className="agenda-filter-field">
          <label htmlFor="inc-desde">Apertura desde</label>
          <input
            id="inc-desde"
            type="date"
            value={value.desde}
            onChange={(e) => setValue({ ...value, desde: e.target.value })}
          />
        </div>
        <div className="agenda-filter-field">
          <label htmlFor="inc-hasta">Apertura hasta</label>
          <input
            id="inc-hasta"
            type="date"
            value={value.hasta}
            onChange={(e) => setValue({ ...value, hasta: e.target.value })}
          />
        </div>
        <div className="agenda-filter-field">
          <label htmlFor="inc-orden">Orden de apertura</label>
          <select
            id="inc-orden"
            value={value.orden}
            onChange={(e) =>
              setValue({
                ...value,
                orden: e.target.value as IncidenciasFiltersValue["orden"],
              })
            }
          >
            <option value="desc">Más recientes primero</option>
            <option value="asc">Más antiguas primero</option>
          </select>
        </div>
      </div>
      {error && <p role="alert">{error}</p>}
      <div className="agenda-filter-actions">
        <button type="submit" className="button button-primary">
          Aplicar filtros
        </button>
        <button
          type="button"
          className="button button-secondary"
          onClick={() => {
            setValue(emptyFilters);
            setError(null);
            onApply(emptyFilters);
          }}
        >
          Limpiar filtros
        </button>
      </div>
    </form>
  );
}
