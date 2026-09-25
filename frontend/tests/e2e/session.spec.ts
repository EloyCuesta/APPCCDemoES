import { test, expect, type Page } from "@playwright/test";

// Explicit HTTP doubles: these tests exercise the real Next UI, not a live Symfony database.
const me = (ids: number[]) => ({
  id: 5,
  nombre: "Ana",
  apellidos: "García",
  email: "ana@example.test",
  membresias: ids.map((id) => ({
    id,
    iri: `/api/usuarios-establecimientos/${id}`,
    rol: "responsable",
    establecimiento: {
      id,
      iri: `/api/establecimientos/${id}`,
      nombre: `Establecimiento ${id}`,
      tipoActividad: "restaurante",
      entidadFiscal: { id, nombre: `Empresa ${id}` },
    },
  })),
  establecimientoPredeterminadoId: ids.length === 1 ? ids[0] : null,
});

async function login(page: Page, password = "test-password") {
  await page.getByLabel("Email", { exact: true }).fill("ana@example.test");
  await page.getByLabel("Contraseña", { exact: true }).fill(password);
  await page.getByLabel("Contraseña", { exact: true }).press("Enter");
}

test("protección, login, cambio de establecimiento, recarga y logout", async ({
  page,
}, testInfo) => {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  const tenantHeaders: string[] = [];
  await page.route("http://127.0.0.1:8010/api/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path === "/api/login_check") {
      expect(request.postDataJSON()).toEqual({
        email: "ana@example.test",
        password: "test-password",
      });
      expect(request.headers()["authorization"]).toBeUndefined();
      await route.fulfill({ json: { token: "e2e-only-token" } });
      return;
    }
    expect(request.headers()["authorization"]).toBe("Bearer e2e-only-token");
    if (path === "/api/me") {
      expect(request.headers()["x-establecimiento-id"]).toBeUndefined();
      await route.fulfill({ json: me([1, 2]) });
      return;
    }
    if (["/api/tareas-programadas/agenda", "/api/registros", "/api/incidencias"].includes(path)) {
      expect(["1", "2"]).toContain(request.headers()["x-establecimiento-id"]);
      await route.fulfill({ json: { member: [], totalItems: 0 } });
      return;
    }
    const id = Number(path.split("/").at(-1));
    tenantHeaders.push(request.headers()["x-establecimiento-id"]);
    expect(request.headers()["x-establecimiento-id"]).toBe(String(id));
    await route.fulfill({ json: { id, nombre: `Establecimiento ${id}` } });
  });
  await page.goto("/dashboard");
  await expect(page).toHaveURL(/\/login$/);
  await expect(
    page.getByRole("heading", { name: "Bienvenido de nuevo" }),
  ).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("login.png"),
    fullPage: true,
  });
  await login(page);
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(
    page.getByRole("heading", { name: "¿Dónde vas a trabajar hoy?" }),
  ).toBeVisible();
  await page
    .getByRole("banner")
    .getByLabel("Establecimiento")
    .selectOption("1");
  await expect(
    page.getByText("Conexión verificada", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("banner")
    .getByLabel("Establecimiento")
    .selectOption("2");
  await expect(
    page.getByRole("heading", { name: "Establecimiento 2", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Conexión verificada", { exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Establecimiento 2", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Conexión verificada", { exact: true }),
  ).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("dashboard.png"),
    fullPage: true,
  });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  if (testInfo.project.name === "mobile") {
    await page.getByRole("button", { name: "Menú", exact: true }).click();
    await expect(
      page.getByRole("navigation", { name: "Navegación principal" }),
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Cerrar menú", exact: true })
      .click();
  }
  await page.goto("/login");
  await expect(page).toHaveURL(/\/dashboard$/);
  await page
    .getByRole("button", { name: "Cerrar sesión", exact: true })
    .click();
  await expect(page).toHaveURL(/\/login$/);
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Bienvenido de nuevo" }),
  ).toBeVisible();
  expect(tenantHeaders).toContain("1");
  expect(tenantHeaders).toContain("2");
  expect(pageErrors).toEqual([]);
});

test("login incorrecto, establecimiento único, permisos y sesión expirada", async ({
  page,
}) => {
  let tenantStatus = 200;
  await page.route("http://127.0.0.1:8010/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === "/api/login_check") {
      const invalid = route.request().postDataJSON().password === "wrong";
      await route.fulfill({
        status: invalid ? 401 : 200,
        json: invalid
          ? { message: "Invalid credentials." }
          : { token: "e2e-only-token" },
      });
      return;
    }
    if (path === "/api/me") {
      await route.fulfill({ json: me([1]) });
      return;
    }
    if (["/api/tareas-programadas/agenda", "/api/registros", "/api/incidencias"].includes(path)) {
      await route.fulfill({ json: { member: [], totalItems: 0 } });
      return;
    }
    await route.fulfill({
      status: tenantStatus,
      json: tenantStatus === 200 ? { id: 1, nombre: "Establecimiento 1" } : {},
    });
  });
  await page.goto("/login");
  await login(page, "wrong");
  await expect(page.getByRole("main").getByRole("alert")).toContainText(
    "Email o contraseña incorrectos",
  );
  await login(page);
  await expect(
    page.getByText("Conexión verificada", { exact: true }),
  ).toBeVisible();
  tenantStatus = 403;
  await page.getByRole("button", { name: "Comprobar de nuevo" }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText(
    "No tienes permisos",
  );
  tenantStatus = 401;
  await page.getByRole("button", { name: "Actualizar mis accesos" }).click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("main").getByRole("alert")).toContainText(
    "Tu sesión ha caducado",
  );
});
