import { DateTime } from "@/components/ui/date-time";
import { RegistroFacts } from "@/features/registros/registro-facts";
import type { DetalleIncidencia } from "./service";

export function IncidenciaContent({ data }: { data: DetalleIncidencia }) {
  const { incidencia, registro, tarea, usuarios } = data;
  return (
    <>
      <div className="card incidencia-section">
        <dl className="incidencia-facts">
          <div>
            <dt>Apertura</dt>
            <dd>
              <DateTime value={incidencia.fechaApertura} />
            </dd>
          </div>
          <div>
            <dt>Cierre</dt>
            <dd>
              {incidencia.fechaCierre ? (
                <DateTime value={incidencia.fechaCierre} />
              ) : (
                "Sin cierre"
              )}
            </dd>
          </div>
        </dl>
      </div>
      <section
        className="card incidencia-section"
        aria-label="Registro APPCC de origen"
      >
        <h2>Registro APPCC de origen</h2>
        {!registro ? (
          <p className="muted">
            Esta incidencia no tiene un registro APPCC asociado.
          </p>
        ) : (
          <RegistroFacts
            registro={registro}
            tarea={tarea ?? ""}
            autor={usuarios[registro.usuario]}
            confirmadoPor={registro.confirmadoPor ? usuarios[registro.confirmadoPor] : null}
          />
        )}
      </section>
    </>
  );
}
