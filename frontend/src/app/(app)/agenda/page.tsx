import type { Metadata } from "next";
import { AgendaScreen } from "@/features/agenda/agenda-screen";

export const metadata: Metadata = { title: "Agenda APPCC" };
export default function AgendaPage() { return <AgendaScreen />; }
