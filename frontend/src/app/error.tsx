"use client";

export default function ErrorPage({ reset }: { reset: () => void }) {
  return (
    <div className="centered-page">
      <section className="card recovery-card">
        <h1>No se ha podido mostrar esta página</h1>
        <p className="muted">Vuelve a intentarlo para recuperar tu espacio.</p>
        <button className="button button-primary" onClick={reset}>
          Volver a intentar
        </button>
      </section>
    </div>
  );
}
