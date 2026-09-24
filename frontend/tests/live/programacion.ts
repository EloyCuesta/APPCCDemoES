import { expect, type APIRequestContext } from "@playwright/test";

/** Solo preparación de pruebas: evita colisiones entre ejecuciones repetidas sin borrar históricos. */
export async function prepararProgramacion(
  request: APIRequestContext,
  api: string,
  tareaId: number,
  headers: Record<string, string>,
  seconds: number,
): Promise<number> {
  const base = Date.now() - seconds * 1000;
  for (let intento = 0; intento < 10; intento++) {
    const response = await request.post(
      `${api}/api/tareas/${tareaId}/programaciones`,
      {
        headers,
        data: {
          fechaProgramada: new Date(base - intento * 1000)
            .toISOString()
            .replace(/\.\d{3}Z$/, "Z"),
        },
      },
    );
    // Solo un rechazo confirmado 409 permite elegir otro instante. No reenvía ante red/timeout/5xx.
    if (response.status() === 409 && intento < 9) continue;
    expect(response.status()).toBe(201);
    return (await response.json()).id;
  }
  throw new Error("No se pudo preparar una ejecución demo libre.");
}
