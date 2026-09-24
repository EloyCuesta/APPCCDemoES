import { DateTime } from "./date-time";
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
          <>
            <h3>
              Registro #{registro.id} · {tarea?.nombre}
            </h3>
            <dl className="incidencia-facts">
              <div>
                <dt>Resultado</dt>
                <dd>{registro.conforme ? "Conforme" : "No conforme"}</dd>
              </div>
              <div>
                <dt>Fecha del registro</dt>
                <dd>
                  <DateTime value={registro.fechaHora} />
                </dd>
              </div>
              <div>
                <dt>Registrado por</dt>
                <dd>{usuarios[registro.usuario]}</dd>
              </div>
              {registro.valorNumerico !== null && (
                <div>
                  <dt>Valor numérico</dt>
                  <dd>{registro.valorNumerico}</dd>
                </div>
              )}
              <div>
                <dt>Ejecución</dt>
                <dd>#{registro.tareaProgramada.split("/").at(-1)}</dd>
              </div>
              <div>
                <dt>Confirmación</dt>
                <dd>
                  {registro.confirmadoAt ? (
                    <>
                      <DateTime value={registro.confirmadoAt} />
                      {registro.confirmadoPor &&
                        ` · ${usuarios[registro.confirmadoPor]}`}
                    </>
                  ) : (
                    "Sin confirmación registrada"
                  )}
                </dd>
              </div>
            </dl>
            {registro.datos !== null && (
              <div>
                <h3>Datos registrados</h3>
                <dl className="incidencia-facts">
                  {Object.entries(registro.datos).map(([key, value]) => (
                    <div key={key}>
                      <dt>{key}</dt>
                      <dd>
                        {typeof value === "boolean"
                          ? value
                            ? "Sí"
                            : "No"
                          : typeof value === "string"
                            ? value
                            : JSON.stringify(value)}
                      </dd>
                    </div>
                  ))}
                </dl>
              </div>
            )}
            <h3>Observaciones del registro</h3>
            <p className="incidencia-text">
              {registro.observaciones ?? "Sin observaciones"}
            </p>
          </>
        )}
      </section>
    </>
  );
}
