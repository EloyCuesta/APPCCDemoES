"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { useApi } from "@/hooks/use-api";
import { useAuth } from "@/hooks/use-auth";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import { ErrorNotice, LoadingState } from "@/components/ui/feedback";
import { ApiError, asApiError, isAborted, isRecord } from "@/lib/api/errors";
import { endpoints } from "@/lib/api/endpoints";
import {
  decimal,
  puedeRegistrar,
  previsionConformidad,
  type EvidenciaSubida,
} from "./contracts";
import { loadControl, subirEvidencia, type Control } from "./service";

const conflicto =
  "El control pudo haber cambiado o haber sido registrado por otra sesión. Vuelve a consultar la Agenda antes de continuar.";

export function RegistroScreen({
  id,
  tenantId,
  onBack,
  onSuccess,
}: {
  id: number;
  tenantId: number;
  onBack: () => void;
  onSuccess: () => void;
}) {
  const api = useApi();
  const { membresiaActual } = useEstablecimiento();
  const [state, setState] = useState<Control | ApiError | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    loadControl(api, id, tenantId, controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) setState(data);
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted && !isAborted(error))
          setState(asApiError(error));
      });
    return () => controller.abort();
  }, [api, id, tenantId]);
  if (!puedeRegistrar(membresiaActual?.rol))
    return <ErrorNotice error={new ApiError(403)} />;
  return (
    <section className="registro-screen" aria-label="Registro del control">
      <button
        type="button"
        className="button button-secondary"
        onClick={onBack}
      >
        Volver a consultar la Agenda
      </button>
      {state === null ? (
        <LoadingState message="Cargando el control…" />
      ) : state instanceof ApiError ? (
        <ErrorNotice
          error={state.status === 409 ? new ApiError(409, conflicto) : state}
        />
      ) : (
        <RegistroForm control={state} onSuccess={onSuccess} />
      )}
    </section>
  );
}

function RegistroForm({
  control,
  onSuccess,
}: {
  control: Control;
  onSuccess: () => void;
}) {
  const { tarea, programacion, requiereObservacion } = control;
  const api = useApi();
  const { user } = useAuth();
  const heading = useRef<HTMLHeadingElement>(null);
  const controller = useRef<AbortController | null>(null);
  const locked = useRef(false);
  const [valor, setValor] = useState("");
  const [resultado, setResultado] = useState("");
  const [campos, setCampos] = useState<Record<string, string>>({});
  const [observaciones, setObservaciones] = useState("");
  const [confirmado, setConfirmado] = useState(false);
  const [evidencias, setEvidencias] = useState<EvidenciaSubida[]>([]);
  const [busy, setBusy] = useState<"subida" | "registro" | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [incierto, setIncierto] = useState(false);
  const prevision = previsionConformidad(tarea, valor, resultado);
  useEffect(() => {
    const current = new AbortController();
    controller.current = current;
    heading.current?.focus();
    return () => current.abort();
  }, []);

  async function upload(file: File | undefined, tipo: EvidenciaSubida["tipo"]) {
    if (
      !file ||
      locked.current ||
      !controller.current ||
      controller.current.signal.aborted
    )
      return;
    locked.current = true;
    setBusy("subida");
    setError(null);
    const signal = controller.current.signal;
    try {
      const evidencia = await subirEvidencia(api, file, tipo, signal);
      signal.throwIfAborted();
      setEvidencias((current) => [...current, evidencia]);
      setConfirmado(false);
    } catch (error) {
      if (!signal.aborted && !isAborted(error)) setError(asApiError(error));
    } finally {
      locked.current = false;
      if (!signal.aborted) setBusy(null);
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (
      locked.current ||
      incierto ||
      !controller.current ||
      controller.current.signal.aborted
    )
      return;
    setError(null);
    if (!confirmado) {
      setError(
        new ApiError(422, "Confirma los datos del control antes de registrar."),
      );
      return;
    }
    if (tarea.respuesta === "numero" && !decimal.test(valor)) {
      setError(
        new ApiError(
          422,
          "Introduce un decimal con hasta 9 cifras enteras y 3 decimales.",
        ),
      );
      return;
    }
    if (
      (tarea.respuesta !== "numero" && !resultado) ||
      tarea.campos.some((campo) => !campos[campo]?.trim())
    ) {
      setError(
        new ApiError(
          422,
          "Completa los campos requeridos y el resultado del control.",
        ),
      );
      return;
    }
    if (evidencias.some((e) => Date.parse(e.expiresAt) <= Date.now())) {
      setError(
        new ApiError(
          422,
          "Hay evidencias caducadas. Retíralas y vuelve a subir los archivos.",
        ),
      );
      return;
    }
    if (prevision === false && !evidencias.some((e) => e.tipo === "foto")) {
      setError(
        new ApiError(
          422,
          "El registro no conforme requiere al menos una fotografía válida.",
        ),
      );
      return;
    }
    locked.current = true;
    setBusy("registro");
    const signal = controller.current.signal;
    try {
      const data = await api.request(endpoints.registros, {
        method: "POST",
        signal,
        body: {
          tareaProgramada: `/api/tareas-programadas/${programacion.id}`,
          ...(tarea.respuesta === "numero"
            ? { valorNumerico: valor }
            : tarea.respuesta === "boolean"
              ? { datos: { resultado: resultado === "true" } }
              : { datos: campos, conforme: resultado === "true" }),
          observaciones: observaciones.trim() || null,
          evidencias: evidencias.map(({ token, tipo }) => ({ token, tipo })),
          confirmarRegistro: true,
        },
      });
      signal.throwIfAborted();
      if (
        !isRecord(data) ||
        !Number.isSafeInteger(data.id) ||
        Number(data.id) <= 0 ||
        data.tareaProgramada !== `/api/tareas-programadas/${programacion.id}`
      )
        throw new ApiError(502);
      setEvidencias([]);
      onSuccess();
    } catch (error) {
      if (!signal.aborted && !isAborted(error)) {
        const parsed = asApiError(error);
        setError(parsed.status === 409 ? new ApiError(409, conflicto) : parsed);
        // Una escritura puede haberse confirmado aunque se pierda su respuesta.
        setIncierto(
          parsed.status === 0 || parsed.status === 409 || parsed.status >= 500,
        );
      }
    } finally {
      locked.current = false;
      if (!signal.aborted) setBusy(null);
    }
  }

  const disabled = busy !== null || incierto;
  return (
    <form className="card registro-form" onSubmit={submit}>
      <div>
        <p className="eyebrow">REGISTRAR CONTROL</p>
        <h1 ref={heading} tabIndex={-1}>
          {tarea.nombre}
        </h1>
      </div>
      {tarea.instrucciones && (
        <p className="registro-instrucciones">{tarea.instrucciones}</p>
      )}
      <fieldset disabled={disabled} onChange={() => setConfirmado(false)}>
        <legend>Resultado del control</legend>
        {tarea.respuesta === "numero" ? (
          <>
            <p id="limites">
              Unidad: {tarea.unidad ?? "Sin unidad"} · Mínimo:{" "}
              {tarea.limiteMinimo ?? "Sin límite"} · Máximo:{" "}
              {tarea.limiteMaximo ?? "Sin límite"}
            </p>
            <label htmlFor="valor">
              Valor numérico{tarea.unidad ? ` (${tarea.unidad})` : ""}
            </label>
            <input
              id="valor"
              inputMode="decimal"
              aria-describedby="limites"
              required
              value={valor}
              onChange={(e) => setValor(e.target.value.replace(",", "."))}
            />
          </>
        ) : (
          <>
            {tarea.campos.map((campo, index) => (
              <div className="registro-field" key={campo}>
                <label htmlFor={`campo-${index}`}>{campo}</label>
                <input
                  id={`campo-${index}`}
                  required
                  value={campos[campo] ?? ""}
                  onChange={(e) =>
                    setCampos((current) => ({
                      ...current,
                      [campo]: e.target.value,
                    }))
                  }
                />
              </div>
            ))}
            <label htmlFor="resultado">
              {tarea.respuesta === "boolean"
                ? "Resultado"
                : "Conformidad (evaluación manual)"}
            </label>
            <select
              id="resultado"
              required
              value={resultado}
              onChange={(e) => setResultado(e.target.value)}
            >
              <option value="">Selecciona un resultado</option>
              <option value="true">Conforme · Sí</option>
              <option value="false">No conforme · No</option>
            </select>
          </>
        )}
        <label htmlFor="observaciones">
          Observaciones
          {prevision === false && requiereObservacion ? " (obligatorias)" : ""}
        </label>
        <textarea
          id="observaciones"
          required={prevision === false && requiereObservacion}
          value={observaciones}
          onChange={(e) => setObservaciones(e.target.value)}
          rows={3}
        />
      </fieldset>
      {prevision !== null && (
        <p
          className={prevision ? "registro-preview" : "registro-photo-required"}
          role="status"
        >
          {prevision
            ? "Previsión: conforme. El servidor validará el resultado."
            : "Previsión: no conforme. Se requiere una fotografía válida. El servidor validará el resultado."}
        </p>
      )}
      <fieldset disabled={disabled}>
        <legend>Evidencias</legend>
        <p className="muted">
          Fotografías JPEG, PNG o WebP y documentos PDF. Hasta 10 archivos de 10
          MiB cada uno. Las subidas caducan; se descartan de este formulario al
          salir.
        </p>
        <label htmlFor="foto">Subir fotografía</label>
        <input
          id="foto"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          disabled={evidencias.length >= 10}
          onChange={(e) => {
            void upload(e.target.files?.[0], "foto");
            e.target.value = "";
          }}
        />
        <label htmlFor="documento">Subir documento PDF</label>
        <input
          id="documento"
          type="file"
          accept="application/pdf"
          disabled={evidencias.length >= 10}
          onChange={(e) => {
            void upload(e.target.files?.[0], "documento");
            e.target.value = "";
          }}
        />
        {evidencias.length > 0 && (
          <ul className="registro-files">
            {evidencias.map((e, index) => (
              <li key={index}>
                <span>
                  {e.nombreOriginal} ·{" "}
                  {e.tipo === "foto" ? "Fotografía" : "Documento"}
                </span>
                <button
                  type="button"
                  className="button button-secondary"
                  onClick={() => {
                    setEvidencias((current) =>
                      current.filter((_, i) => i !== index),
                    );
                    setConfirmado(false);
                  }}
                >
                  Retirar {e.nombreOriginal}
                </button>
              </li>
            ))}
          </ul>
        )}
      </fieldset>
      <div className="registro-confirmacion">
        <p>
          Confirmas este registro con tu usuario autenticado:{" "}
          <strong>
            {user?.nombre} {user?.apellidos}
          </strong>{" "}
          ({user?.email}).
        </p>
        <label>
          <input
            type="checkbox"
            required
            checked={confirmado}
            disabled={disabled}
            onChange={(e) => setConfirmado(e.target.checked)}
          />{" "}
          Confirmo que los datos introducidos son correctos y corresponden al
          control realizado.
        </label>
      </div>
      {error && <ErrorNotice error={error} />}
      {incierto && (
        <p role="status">
          Consulta la Agenda para comprobar si se completó antes de volver a
          registrar.
        </p>
      )}
      {busy && (
        <LoadingState
          message={
            busy === "subida" ? "Subiendo evidencia…" : "Registrando control…"
          }
        />
      )}
      <button
        type="submit"
        className="button button-primary"
        disabled={disabled}
      >
        Confirmar y registrar control
      </button>
    </form>
  );
}
