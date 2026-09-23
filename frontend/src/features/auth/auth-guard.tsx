"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/hooks/use-auth";
import { LoadingState } from "@/components/ui/feedback";
import { SessionRecovery } from "./session-recovery";

export function AuthGuard({ children }: { children: React.ReactNode }) {
  const { status, isAuthenticated } = useAuth();
  const router = useRouter();
  useEffect(() => {
    if (status === "anonymous") router.replace("/login");
  }, [status, router]);
  if (status === "error") return <SessionRecovery />;
  if (!isAuthenticated)
    return (
      <div className="centered-page">
        <LoadingState message="Restaurando tu sesión…" />
      </div>
    );
  return children;
}
