export function Brand({ compact = false }: { compact?: boolean }) {
  return (
    <div className="brand">
      <span className="brand-mark" aria-hidden="true">
        a<span>✓</span>
      </span>
      <span>
        APPCC<span className="brand-suffix"> / ES</span>
        {!compact && <small>SEGURIDAD ALIMENTARIA</small>}
      </span>
    </div>
  );
}
