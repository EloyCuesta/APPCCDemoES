"use client";

import { useId } from "react";
import { useEstablecimiento } from "@/hooks/use-establecimiento";

export function EstablecimientoSelector() {
  const id = useId();
  const {
    establecimientos,
    establecimientoActual,
    seleccionarEstablecimiento,
  } = useEstablecimiento();
  return (
    <div className="establishment-selector">
      <label htmlFor={id}>Establecimiento</label>
      <select
        id={id}
        value={establecimientoActual?.id ?? ""}
        disabled={establecimientos.length === 0}
        onChange={(event) =>
          seleccionarEstablecimiento(Number(event.target.value))
        }
      >
        {!establecimientoActual && (
          <option value="" disabled>
            Selecciona un establecimiento
          </option>
        )}
        {establecimientos.map((item) => (
          <option key={item.id} value={item.id}>
            {item.nombre}
          </option>
        ))}
      </select>
    </div>
  );
}
