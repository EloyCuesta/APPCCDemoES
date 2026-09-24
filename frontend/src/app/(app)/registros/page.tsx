import type { Metadata } from "next";
import { HistoricoScreen } from "@/features/registros/historico-screen";

export const metadata: Metadata = { title: "Registros APPCC" };
export default function RegistrosPage() { return <HistoricoScreen />; }
