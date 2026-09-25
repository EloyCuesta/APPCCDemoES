import { test, expect as baseExpect } from "@playwright/test";

const api = "http://127.0.0.1:8011";
const expect = baseExpect.configure({ timeout: 20_000 });

test("plantilla real, límites, idempotencia, cambio de tenant y logout", async ({ page, request }, testInfo) => {
  await page.goto("/login");
  await page.getByLabel("Email", { exact: true }).fill("responsable@appccdemo.local");
  await page.getByLabel("Contraseña", { exact: true }).fill("AppccDemo2026!");
  const login = page.waitForResponse((r) => r.url().endsWith("/api/login_check"));
  await page.getByRole("button", { name: "Entrar a mi espacio" }).click();
  const response = await login;
  expect(response.status()).toBe(200);
  const auth = { Authorization: `Bearer ${(await response.json()).token}` };
  await expect(page).toHaveURL(/\/dashboard$/);
  const meResponse = await request.get(`${api}/api/me`, { headers: auth });
  expect(meResponse.status()).toBe(200);
  const me = await meResponse.json();
  const members: { establecimiento: { id: number; nombre: string } }[] = me.membresias;
  const obrador = members.find((m) => m.establecimiento.nombre === "Obrador APPCC Demo")!;
  const restaurante = members.find((m) => m.establecimiento.nombre === "Restaurante APPCC Demo")!;
  expect(obrador).toBeDefined();
  expect(restaurante).toBeDefined();
  const tenantId = obrador.establecimiento.id;
  const headers = { ...auth, "X-Establecimiento-Id": String(tenantId), Accept: "application/ld+json" };
  const selector = page.getByRole("banner").getByLabel("Establecimiento", { exact: true });
  await selector.selectOption(String(tenantId));
  await expect(page.getByRole("region", { name: "Resumen operativo", exact: true })).toHaveAttribute("aria-busy", "false");
  // El indicador debe coincidir con los totales reales del tenant seleccionado.
  let total = 0;
  for (const estado of ["abierta", "en_proceso"]) {
    const lookup = await request.get(`${api}/api/incidencias?estado=${estado}&itemsPerPage=1`, { headers });
    expect(lookup.status()).toBe(200);
    total += (await lookup.json()).totalItems;
  }
  await expect(page.getByRole("region", { name: "Incidencias sin resolver", exact: true }).locator(".kpi-value")).toHaveText(String(total));
  await page.goto("/plantillas");
  const nombre = "Obrador · inicio APPCC v1";
  await expect(page.getByRole("heading", { name: nombre, exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Aplicar Restaurante · inicio APPCC v1", exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Aplicar Catering · inicio APPCC v1", exact: true })).toBeDisabled();
  await page.getByText(`Ver contenido de ${nombre}`, { exact: true }).click();
  await expect(page.getByRole("heading", { name: "Puntos de control", exact: true })).toBeVisible();

  async function apply() {
    await page.getByRole("button", { name: `Aplicar ${nombre}`, exact: true }).click();
    await page.getByRole("checkbox", { name: /He revisado el contenido/ }).check();
    const applied = page.waitForResponse((r) => /\/api\/plantillas-appcc\/\d+\/aplicar$/.test(r.url()) && r.request().method() === "POST");
    await page.getByRole("button", { name: "Confirmar aplicación", exact: true }).click();
    const result = await applied;
    expect(result.status()).toBe(200);
    expect(result.request().headers()["x-establecimiento-id"]).toBe(String(tenantId));
    return result.json();
  }
  const first = await apply();
  const tasks: { id: number; configuracionPendiente: boolean }[] = first.resultadoInicial.tareas;
  const control = tasks.find((t) => t.configuracionPendiente)!;
  expect(control).toBeDefined();
  const current = await request.get(`${api}/api/tareas/${control.id}`, { headers });
  expect(current.status()).toBe(200);
  const initial = await current.json();
  if (!first.yaAplicada) {
    expect(initial.activa).toBe(false);
    expect(initial.configuracionPendiente).toBe(true);
    // API Platform omite propiedades nulas, igual que el contrato del cliente.
    expect(initial.limiteMinimo ?? null).toBeNull();
    expect(initial.limiteMaximo ?? null).toBeNull();
  }
  await page.getByRole("button", { name: `Configurar control #${control.id}`, exact: true }).click();
  const editor = page.getByRole("region", { name: `Configuración del control #${control.id}`, exact: true });
  if (!initial.activa) {
    // Valores simulados exclusivamente para la demo, nunca límites sanitarios reales.
    await editor.getByLabel("Límite mínimo", { exact: true }).fill("0");
    await editor.getByLabel("Límite máximo", { exact: true }).fill("5");
    await editor.getByLabel("Unidad", { exact: true }).fill("°C");
    await editor.getByLabel("Instrucciones del control", { exact: true }).fill("DEMO LOCAL: lectura simulada para aceptación.");
    await editor.getByRole("checkbox", { name: /Confirmo que los límites/ }).check();
    const saved = page.waitForResponse((r) => r.url().endsWith(`/api/tareas/${control.id}`) && r.request().method() === "PATCH");
    await editor.getByRole("button", { name: "Guardar límites y activar", exact: true }).click();
    expect((await saved).status()).toBe(200);
  }
  await expect(editor.getByText("El control está configurado y activo.", { exact: true })).toBeVisible();
  const configured = await (await request.get(`${api}/api/tareas/${control.id}`, { headers })).json();
  expect(configured.activa).toBe(true);
  expect(configured.configuracionPendiente).toBe(false);
  await page.reload();
  const second = await apply();
  expect(second.yaAplicada).toBe(true);
  expect(second.aplicacionId).toBe(first.aplicacionId);
  expect(second.creados).toEqual({ planes: 0, puntos: 0, tareas: 0 });
  expect(second.resultadoInicial).toEqual(first.resultadoInicial);
  await expect(page.getByRole("heading", { name: "La plantilla ya estaba aplicada", exact: true })).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath("plantilla-aplicada.png"), fullPage: true });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);

  await selector.selectOption(String(restaurante.establecimiento.id));
  await expect(page.getByText("Prepara los controles de Restaurante APPCC Demo.", { exact: true })).toBeVisible();
  await expect(page.getByRole("region", { name: "Resultado de aplicación", exact: true })).toHaveCount(0);
  const foreign = await request.get(`${api}/api/tareas/${control.id}`, {
    headers: { ...headers, "X-Establecimiento-Id": String(restaurante.establecimiento.id) },
  });
  expect(foreign.status()).toBe(404);
  await page.getByRole("button", { name: "Cerrar sesión", exact: true }).click();
  await expect(page).toHaveURL(/\/login$/);
  expect(await page.evaluate(() => [sessionStorage.getItem("appcc.token"), sessionStorage.getItem("appcc.establecimiento")])).toEqual([null, null]);
  expect((await request.get(`${api}/api/me`)).status()).toBe(401);
});
