import { ErrorNotice } from "@/components/ui/feedback";
import { useEstablecimiento } from "@/hooks/use-establecimiento";
import type { ApiError } from "@/lib/api/errors";

export function HistoricoError({ error, retry }: { error: ApiError; retry?: () => void }) {
  const { refresh } = useEstablecimiento();
  return <><ErrorNotice error={error} onRetry={error.retryable ? retry : undefined} />{[403, 404].includes(error.status) && <button type="button" className="button button-secondary" onClick={() => void refresh()}>Actualizar mis accesos</button>}</>;
}
