import type { Metadata } from "next";
import { PlantillasScreen } from "@/features/plantillas/plantillas-screen";

export const metadata: Metadata = { title: "Plantillas APPCC" };

export default function PlantillasPage() {
  return <PlantillasScreen />;
}
