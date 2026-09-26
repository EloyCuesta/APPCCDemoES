import { defineConfig, devices } from "@playwright/test";

// Ejecución explícita: usa la BD dev preparada con app:demo:seed y crea registros reales.
export default defineConfig({
  testDir: "./tests/live",
  forbidOnly: Boolean(process.env.CI),
  workers: 1,
  retries: 0,
  timeout: 90_000,
  reporter: "list",
  use: {
    baseURL: "http://127.0.0.1:3101",
    channel: process.env.PLAYWRIGHT_CHANNEL || undefined,
    trace: "off",
    screenshot: "only-on-failure",
  },
  projects: [
    {
      name: "desktop",
      use: {
        ...devices["Desktop Chrome"],
        viewport: { width: 1440, height: 1000 },
      },
    },
    { name: "mobile", use: { ...devices["Pixel 7"] } },
  ],
  webServer: [
    {
      // cli-server no importa el entorno del proceso en $_SERVER. Dotenv necesita
      // $_ENV (E) para respetar DATABASE_URL, APP_ENV y JWT_* externos a .env.
      command:
        "php -d variables_order=EGPCS -d upload_max_filesize=12M -d post_max_size=24M -S 127.0.0.1:8011 -t public",
      cwd: "../backend",
      url: "http://127.0.0.1:8011/api/me",
      env: { APP_ENV: "dev", APP_DEBUG: "0" },
      reuseExistingServer: false,
      timeout: 60_000,
      stderr: "ignore",
    },
    {
      command: "npm run dev -- --hostname 127.0.0.1 --port 3101",
      url: "http://127.0.0.1:3101/login",
      env: { NEXT_PUBLIC_API_URL: "http://127.0.0.1:8011" },
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
});
