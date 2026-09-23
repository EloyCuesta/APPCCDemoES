import type { Metadata, Viewport } from "next";
import { SessionProvider } from "@/providers/session-provider";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "APPCC Demo ES", template: "%s | APPCC Demo ES" },
  description: "Espacio de gestión APPCC para establecimientos alimentarios.",
  robots: { index: false, follow: false },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: "#153d36",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="es">
      <body>
        <SessionProvider>{children}</SessionProvider>
      </body>
    </html>
  );
}
