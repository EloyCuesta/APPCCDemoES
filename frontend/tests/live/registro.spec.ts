import { test, expect } from "@playwright/test";
import path from "node:path";

const api = "http://127.0.0.1:8011";

test("trabajador registra conforme y no conforme con foto contra Symfony real", async ({
  page,
  request,
}, testInfo) => {
  // Preparar dos ejecuciones mediante el endpoint de dominio permite repetir esta prueba sin borrar históricos.
  const loginAdmin = await request.post(`${api}/api/login_check`, {
    data: { email: "admin@appccdemo.local", password: "AppccDemo2026!" },
  });
  expect(loginAdmin.status()).toBe(200);
  const adminHeaders = {
    Authorization: `Bearer ${(await loginAdmin.json()).token}`,
  };
  const me = await (
    await request.get(`${api}/api/me`, { headers: adminHeaders })
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
    ...adminHeaders,
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
  const executions: number[] = [];
  for (const seconds of [120, 60]) {
    const response = await request.post(
      `${api}/api/tareas/${tarea.id}/programaciones`,
      {
        headers,
        data: {
          fechaProgramada: new Date(Date.now() - seconds * 1000)
            .toISOString()
            .replace(/\.\d{3}Z$/, "Z"),
        },
      },
    );
    expect(response.status()).toBe(201);
    executions.push((await response.json()).id);
  }

  await page.goto("/login");
  await page
    .getByLabel("Email", { exact: true })
    .fill("trabajador@appccdemo.local");
  await page.getByLabel("Contraseña", { exact: true }).fill("AppccDemo2026!");
  const loginResponse = page.waitForResponse(
    (r) =>
      r.url().endsWith("/api/login_check") && r.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Entrar a mi espacio" }).click();
  const workerToken = (await (await loginResponse).json()).token;
  const workerHeaders = { ...headers, Authorization: `Bearer ${workerToken}` };
  await expect(page).toHaveURL(/\/dashboard$/);
  const selector = page
    .getByRole("banner")
    .getByLabel("Establecimiento", { exact: true });
  await selector.selectOption(String(tenantId));
  await page.goto("/agenda");
  await expect(
    page.getByRole("heading", { name: "Agenda APPCC" }),
  ).toBeVisible();
  await page
    .getByLabel("Tarea / control", { exact: true })
    .selectOption(`/api/tareas/${tarea.id}`);
  await page.getByRole("button", { name: "Aplicar filtros" }).click();

  for (const [index, executionId] of executions.entries()) {
    const row = page.locator(`[data-programacion-id="${executionId}"]`);
    await row
      .getByRole("button", { name: "Registrar control", exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name: tarea.nombre }),
    ).toBeVisible();
    await page
      .getByLabel("Valor numérico (°C)")
      .fill(index === 0 ? "80" : "20");
    await page
      .getByLabel(/Observaciones/)
      .fill(
        `Demostración de aceptación ${testInfo.project.name}: ${index === 0 ? "conforme" : "no conforme"}.`,
      );
    if (index === 1) {
      await expect(
        page.getByText(/Se requiere una fotografía válida/),
      ).toBeVisible();
      const subida = page.waitForResponse(
        (r) =>
          r.url().endsWith("/api/evidencias/subidas") &&
          r.request().method() === "POST",
      );
      await page
        .getByLabel("Subir fotografía")
        .setInputFiles(path.resolve("../backend/tests/Fixtures/evidencia.png"));
      expect((await subida).status()).toBe(201);
      await expect(page.getByText("evidencia.png · Fotografía")).toBeVisible();
      await page.screenshot({
        path: testInfo.outputPath("no-conforme-foto.png"),
        fullPage: true,
      });
    }
    await page.getByRole("checkbox", { name: /Confirmo que/ }).check();
    const saved = page.waitForResponse(
      (r) =>
        r.url().endsWith("/api/registros") && r.request().method() === "POST",
    );
    await page
      .getByRole("button", { name: "Confirmar y registrar control" })
      .click();
    const response = await saved;
    expect(response.status()).toBe(201);
    const registro = await response.json();
    expect(registro.conforme).toBe(index === 0);
    expect(registro.confirmadoPor).toBe(registro.usuario);
    expect(registro.confirmadoAt).toBeTruthy();
    await expect(
      page.getByText("Control registrado y confirmado correctamente."),
    ).toBeVisible();
    await expect(
      page.getByRole("region", { name: "Resultados de agenda" }),
    ).toHaveAttribute("aria-busy", "false");
    await expect(row).toHaveCount(0);
    const consulta = await (
      await request.get(
        `${api}/api/registros?tareaProgramada=${encodeURIComponent(`/api/tareas-programadas/${executionId}`)}`,
        { headers: workerHeaders },
      )
    ).json();
    expect(consulta.member.map((r: { id: number }) => r.id)).toContain(
      registro.id,
    );
    const incidencias = await (
      await request.get(
        `${api}/api/incidencias?registro=${encodeURIComponent(`/api/registros/${registro.id}`)}`,
        { headers: workerHeaders },
      )
    ).json();
    expect(incidencias.member).toHaveLength(index === 0 ? 0 : 1);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
  }
  await page.screenshot({
    path: testInfo.outputPath("agenda-completada.png"),
    fullPage: true,
  });
});
