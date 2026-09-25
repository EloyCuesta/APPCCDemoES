import { test, expect as baseExpect } from "@playwright/test";
import path from "node:path";
import { readFile } from "node:fs/promises";
import { prepararProgramacion } from "./programacion";

const api = "http://127.0.0.1:8011";
// El servidor PHP de desarrollo atiende en serie las lecturas de cada detalle.
const expect = baseExpect.configure({ timeout: 20_000 });

test("login → Agenda → no conformidad → incidencia → acción → resolución → histórico → descarga", async ({
  page,
  request,
}, testInfo) => {
  // Prepara solo la ejecución con la API existente. Toda la operación posterior usa la interfaz real.
  const preparacion = await request.post(`${api}/api/login_check`, {
    data: { email: "responsable@appccdemo.local", password: "AppccDemo2026!" },
  });
  expect(preparacion.status()).toBe(200);
  const auth = { Authorization: `Bearer ${(await preparacion.json()).token}` };
  const me = await (
    await request.get(`${api}/api/me`, { headers: auth })
  ).json();
  const member = me.membresias.find(
    (m: {
      establecimiento: { nombre: string; entidadFiscal: { nombre: string } };
    }) =>
      m.establecimiento.nombre === "Restaurante APPCC Demo" &&
      m.establecimiento.entidadFiscal.nombre === "APPCC Demo local",
  );
  expect(member).toBeDefined();
  const tenantId = member.establecimiento.id;
  const headers = {
    ...auth,
    "X-Establecimiento-Id": String(tenantId),
    Accept: "application/ld+json",
    "Content-Type": "application/ld+json",
  };
  const tareas = await (
    await request.get(
      `${api}/api/tareas?frecuencia=bajo_demanda&itemsPerPage=100`,
      { headers },
    )
  ).json();
  const tarea = tareas.member.find(
    (t: { configuracion: { tipoRespuesta?: string } }) =>
      t.configuracion.tipoRespuesta === "numero",
  );
  expect(tarea).toBeDefined();
  const executionId = await prepararProgramacion(
    request,
    api,
    tarea.id,
    headers,
    30,
  );

  await page.goto("/login");
  await page
    .getByLabel("Email", { exact: true })
    .fill("responsable@appccdemo.local");
  await page.getByLabel("Contraseña", { exact: true }).fill("AppccDemo2026!");
  await page.getByRole("button", { name: "Entrar a mi espacio" }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
  await page
    .getByRole("banner")
    .getByLabel("Establecimiento", { exact: true })
    .selectOption(String(tenantId));

  async function navigate(name: string) {
    if (testInfo.project.name === "mobile")
      await page.getByRole("button", { name: "Menú", exact: true }).click();
    await page
      .getByRole("navigation", { name: "Navegación principal" })
      .getByRole("link", { name, exact: true })
      .click();
  }
  await navigate("Agenda");
  await page
    .getByLabel("Tarea / control", { exact: true })
    .selectOption(`/api/tareas/${tarea.id}`);
  await page.getByRole("button", { name: "Aplicar filtros" }).click();
  const row = page.locator(`[data-programacion-id="${executionId}"]`);
  await row
    .getByRole("button", { name: "Registrar control", exact: true })
    .click();
  await page.getByLabel("Valor numérico (°C)").fill("20");
  const observaciones = `Incidencia de aceptación ${testInfo.project.name}, ejecución ${executionId}.`;
  await page.getByLabel(/Observaciones/).fill(observaciones);
  await page
    .getByLabel("Subir fotografía")
    .setInputFiles(path.resolve("../backend/tests/Fixtures/evidencia.png"));
  await expect(page.getByText("evidencia.png · Fotografía")).toBeVisible();
  await page.getByRole("checkbox", { name: /Confirmo que/ }).check();
  const saved = page.waitForResponse(
    (r) =>
      r.url().endsWith("/api/registros") && r.request().method() === "POST",
  );
  await page
    .getByRole("button", { name: "Confirmar y registrar control" })
    .click();
  const savedResponse = await saved;
  expect(savedResponse.status()).toBe(201);
  const registro = await savedResponse.json();
  expect(registro.conforme).toBe(false);
  await expect(
    page.getByText("Control registrado y confirmado correctamente."),
  ).toBeVisible();
  await expect(
    page.getByRole("region", { name: "Resultados de agenda" }),
  ).toHaveAttribute("aria-busy", "false");
  await expect(row).toHaveCount(0);

  const lookup = await request.get(
    `${api}/api/incidencias?registro=${encodeURIComponent(`/api/registros/${registro.id}`)}`,
    { headers },
  );
  expect(lookup.status()).toBe(200);
  const incidencias = await lookup.json();
  expect(incidencias.member).toHaveLength(1);
  const id = incidencias.member[0].id;
  await navigate("Incidencias");
  await expect(
    page.getByRole("heading", { name: "Incidencias APPCC" }),
  ).toBeVisible();
  await page
    .getByLabel("Registro de origen (número)")
    .fill(String(registro.id));
  await page.getByRole("button", { name: "Aplicar filtros" }).click();
  await expect(page.locator("[data-incidencia-id]")).toHaveCount(1);
  await page
    .getByRole("button", { name: `Abrir incidencia #${id}`, exact: true })
    .click();
  const detail = page.getByRole("region", { name: "Detalle de incidencia" });
  const origen = page.getByRole("region", { name: "Registro APPCC de origen" });
  await expect(
    origen.getByRole("heading", {
      name: `Registro #${registro.id} · ${tarea.nombre}`,
      exact: true,
    }),
  ).toBeVisible();
  await expect(origen.getByText(observaciones, { exact: true })).toBeVisible();
  await expect(origen.getByText("No conforme", { exact: true })).toBeVisible();
  await expect(
    detail.getByText("Creación → Abierta", { exact: true }),
  ).toBeVisible();
  await expect(detail).toHaveAttribute("aria-busy", "false");
  await page.screenshot({
    path: testInfo.outputPath("incidencia-abierta.png"),
    fullPage: true,
  });

  await page
    .getByLabel("Descripción de la acción")
    .fill("Revisar y ajustar el equipo de cocción.");
  await page
    .getByLabel("Resultado de la acción (opcional)")
    .fill("Control posterior dentro de límites.");
  const actionSaved = page.waitForResponse(
    (r) =>
      r.url().endsWith("/api/acciones-correctivas") &&
      r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Guardar acción correctiva" }).click();
  const actionResponse = await actionSaved;
  expect(actionResponse.status()).toBe(201);
  expect(actionResponse.request().headers()["x-establecimiento-id"]).toBe(
    String(tenantId),
  );
  await expect(
    detail.getByText("Revisar y ajustar el equipo de cocción.", {
      exact: true,
    }),
  ).toBeVisible();
  await expect(detail).toHaveAttribute("aria-busy", "false");

  for (const [button, transition] of [
    ["Poner en proceso", "Abierta → En proceso"],
    ["Resolver incidencia", "En proceso → Resuelta"],
  ]) {
    const changed = page.waitForResponse(
      (r) =>
        r.url().endsWith(`/api/incidencias/${id}`) &&
        r.request().method() === "PATCH",
    );
    await page.getByRole("button", { name: button, exact: true }).click();
    const response = await changed;
    expect(response.status()).toBe(200);
    expect(response.request().headers()["content-type"]).toBe(
      "application/merge-patch+json",
    );
    await expect(detail.getByText(transition, { exact: true })).toBeVisible();
    await expect(detail).toHaveAttribute("aria-busy", "false");
  }
  await expect(detail.getByText("Resuelta", { exact: true })).toBeVisible();
  await expect(
    detail.getByRole("button", {
      name: /Guardar acción|Resolver incidencia|Poner en proceso|Reabrir/,
    }),
  ).toHaveCount(0);
  const final = await (
    await request.get(`${api}/api/incidencias/${id}`, { headers })
  ).json();
  expect(final.estado).toBe("resuelta");
  expect(final.fechaCierre).toBeTruthy();
  const acciones = await (
    await request.get(`${api}/api/incidencias/${id}/acciones`, { headers })
  ).json();
  expect(acciones.totalItems).toBe(1);
  expect(acciones.member[0].usuario).toBe(`/api/usuarios/${me.id}`);
  const history = await (
    await request.get(`${api}/api/incidencias/${id}/historial`, { headers })
  ).json();
  expect(
    history.member.map((h: { estadoNuevo: string }) => h.estadoNuevo),
  ).toEqual(["abierta", "en_proceso", "resuelta"]);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: testInfo.outputPath("incidencia-resuelta.png"),
    fullPage: true,
  });
  await page
    .getByRole("button", { name: "Volver a incidencias", exact: true })
    .click();
  await expect(page.getByLabel("Registro de origen (número)")).toHaveValue(
    String(registro.id),
  );
  await expect(
    page
      .locator(`[data-incidencia-id="${id}"]`)
      .getByText("Resuelta", { exact: true }),
  ).toBeVisible();

  await navigate("Registros");
  await expect(page.getByRole("heading", { name: "Registros APPCC", exact: true })).toBeVisible();
  await page.getByLabel("Tarea / control", { exact: true }).selectOption(`/api/tareas/${tarea.id}`);
  await page.getByLabel("Usuario", { exact: true }).selectOption(`/api/usuarios/${me.id}`);
  await page.getByLabel("Conformidad", { exact: true }).selectOption("false");
  await page.getByRole("button", { name: "Aplicar filtros", exact: true }).click();
  await page.getByRole("button", { name: `Ver registro #${registro.id}`, exact: true }).click();
  const recordDetail = page.getByRole("region", { name: "Detalle del registro" });
  await expect(recordDetail.getByText(observaciones, { exact: true })).toBeVisible();
  await expect(recordDetail.getByText("No conforme", { exact: true })).toBeVisible();
  await expect(recordDetail.getByText(tarea.nombre, { exact: true })).toBeVisible();
  const evidencias = await request.get(`${api}/api/registros/${registro.id}/evidencias`, { headers });
  expect(evidencias.status()).toBe(200);
  const evidenceData = await evidencias.json();
  expect(evidenceData.totalItems).toBe(1);
  expect(evidenceData.member[0]).not.toHaveProperty("storageKey");
  expect(evidenceData.member[0]).not.toHaveProperty("hashSha256");
  const evidenceId = evidenceData.member[0].id;
  const downloading = page.waitForEvent("download");
  const binary = page.waitForResponse((r) => r.url().endsWith(`/api/evidencias/${evidenceId}/descargar`));
  await recordDetail.getByRole("button", { name: `Descargar evidencia.png (#${evidenceId})`, exact: true }).click();
  const response = await binary;
  expect(response.status()).toBe(200);
  expect(response.request().headers()["authorization"]).toMatch(/^Bearer /);
  expect(response.request().headers()["x-establecimiento-id"]).toBe(String(tenantId));
  expect(response.headers()["access-control-expose-headers"]).toContain("Content-Disposition");
  const download = await downloading;
  expect(download.suggestedFilename()).toBe("evidencia.png");
  const output = testInfo.outputPath("evidencia-descargada.png");
  await download.saveAs(output);
  expect(await readFile(output)).toEqual(await readFile(path.resolve("../backend/tests/Fixtures/evidencia.png")));
  await expect(recordDetail.getByText("Descarga iniciada: evidencia.png")).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath("historico-evidencias.png"), fullPage: true });
  await page.getByRole("button", { name: "Volver a registros", exact: true }).click();
  await expect(page.getByLabel("Conformidad", { exact: true })).toHaveValue("false");
  await page.getByRole("button", { name: "Limpiar filtros", exact: true }).click();
  await expect(page.getByLabel("Conformidad", { exact: true })).toHaveValue("");
});
