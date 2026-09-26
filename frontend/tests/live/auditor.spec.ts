import { test, expect as baseExpect } from "@playwright/test";

const api = "http://127.0.0.1:8011";
const expect = baseExpect.configure({ timeout: 20_000 });

test("auditor consulta datos reales, recupera sesión y no puede escribir", async ({ page, request }) => {
  await page.goto("/login");
  await page.getByLabel("Email", { exact: true }).fill("auditor@appccdemo.local");
  await page.getByLabel("Contraseña", { exact: true }).fill("AppccDemo2026!");
  const login = page.waitForResponse((r) => r.url().endsWith("/api/login_check"));
  await page.getByRole("button", { name: "Entrar a mi espacio" }).click();
  const response = await login;
  expect(response.status()).toBe(200);
  const auth = { Authorization: `Bearer ${(await response.json()).token}` };
  await expect(page).toHaveURL(/\/dashboard$/);
  const me = await (await request.get(`${api}/api/me`, { headers: auth })).json();
  const member = me.membresias.find((m: { establecimiento: { nombre: string } }) =>
    m.establecimiento.nombre === "Restaurante APPCC Demo");
  expect(member.rol).toBe("auditor");
  const headers = { ...auth, "X-Establecimiento-Id": String(member.establecimiento.id), Accept: "application/ld+json" };
  await page.getByRole("banner").getByLabel("Establecimiento", { exact: true }).selectOption(String(member.establecimiento.id));
  await page.reload();
  await expect(page.getByRole("region", { name: "Resumen operativo", exact: true })).toHaveAttribute("aria-busy", "false");
  await page.goto("/agenda");
  await expect(page.getByRole("region", { name: "Resultados de agenda" })).toHaveAttribute("aria-busy", "false");
  expect(await page.locator("[data-programacion-id]").count()).toBeGreaterThan(0);
  await expect(page.getByRole("button", { name: "Registrar control", exact: true })).toHaveCount(0);

  const incidents = await (await request.get(`${api}/api/incidencias?estado=abierta`, { headers })).json();
  expect(incidents.member.length).toBeGreaterThan(0);
  const incident = incidents.member[0];
  await page.goto("/incidencias");
  await page.getByRole("button", { name: `Abrir incidencia #${incident.id}`, exact: true }).click();
  const detail = page.getByRole("region", { name: "Detalle de incidencia" });
  await expect(detail).toHaveAttribute("aria-busy", "false");
  await expect(page.getByText("Acceso de auditor: solo lectura.", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: /Guardar acción correctiva|Resolver incidencia|Poner en proceso/ })).toHaveCount(0);
  expect((await request.patch(`${api}/api/incidencias/${incident.id}`, {
    headers: { ...headers, "Content-Type": "application/merge-patch+json" }, data: { estado: "en_proceso" },
  })).status()).toBe(403);
  expect((await request.post(`${api}/api/acciones-correctivas`, {
    headers: { ...headers, "Content-Type": "application/ld+json" },
    data: { incidencia: `/api/incidencias/${incident.id}`, descripcion: "Escritura que debe ser rechazada." },
  })).status()).toBe(403);
  expect((await request.get(`${api}/api/incidencias/${incident.id}`, { headers })).status()).toBe(200);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.goto("/registros");
  await expect(page.getByRole("heading", { name: "Registros APPCC", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: /^Ver registro #/ }).first()).toBeVisible();
});
