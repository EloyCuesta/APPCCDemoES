import type { ApiError } from "@/lib/api/errors";

export function LoadingState({ message = "Cargando…" }: { message?: string }) {
  return (
    <div className="loading-state" role="status">
      <span className="spinner" aria-hidden="true" />
      <p>{message}</p>
    </div>
  );
}

export function ErrorNotice({
  error,
  onRetry,
}: {
  error: ApiError;
  onRetry?: () => void;
}) {
  return (
    <div className="error-notice" role="alert">
      <p>{error.message}</p>
      {error.violations.length > 0 && (
        <ul>
          {error.violations.map((item, index) => (
            <li key={index}>
              {item.field && `${item.field}: `}
              {item.message}
            </li>
          ))}
        </ul>
      )}
      {onRetry && (
        <button
          className="button button-secondary"
          type="button"
          onClick={onRetry}
        >
          Volver a intentar
        </button>
      )}
    </div>
  );
}
