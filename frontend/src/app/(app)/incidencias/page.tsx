import type { Metadata } from "next";
import { IncidenciasScreen } from "@/features/incidencias/incidencias-screen";

export const metadata: Metadata = { title: "Incidencias APPCC" };
export default function IncidenciasPage() {
  return <IncidenciasScreen />;
}
