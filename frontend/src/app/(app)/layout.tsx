import { AppShell } from "@/components/layout/app-shell";
import { AuthGuard } from "@/features/auth/auth-guard";
import { EstablecimientoGate } from "@/features/establecimientos/establecimiento-gate";

export default function PrivateLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <AuthGuard>
      <AppShell>
        <EstablecimientoGate>{children}</EstablecimientoGate>
      </AppShell>
    </AuthGuard>
  );
}
