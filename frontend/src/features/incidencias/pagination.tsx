import type { ApiCollection } from "@/lib/api/collections";
import { PAGE_SIZE } from "./query";

export function Pagination({
  label,
  data,
  page,
  onPage,
  disabled = false,
}: {
  label: string;
  data: ApiCollection<unknown>;
  page: number;
  onPage: (page: number) => void;
  disabled?: boolean;
}) {
  const hasNext =
    data.next !== null ||
    (data.total !== null && page * PAGE_SIZE < data.total);
  return (
    <nav className="agenda-pagination" aria-label={`Paginación de ${label}`}>
      <button
        type="button"
        className="button button-secondary"
        disabled={disabled || page === 1}
        onClick={() => onPage(page - 1)}
      >
        Anterior
      </button>
      <p role="status">
        Página {page}
        {data.total !== null && ` · ${data.total} resultados`}
      </p>
      <button
        type="button"
        className="button button-secondary"
        disabled={disabled || !hasNext}
        onClick={() => onPage(page + 1)}
      >
        Siguiente
      </button>
    </nav>
  );
}
