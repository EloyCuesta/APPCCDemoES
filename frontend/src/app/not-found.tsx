import Link from "next/link";

export default function NotFound() {
  return (
    <main className="centered-page">
      <section className="card recovery-card">
        <p className="eyebrow">404</p>
        <h1>Página no encontrada</h1>
        <p className="muted">La página que buscas no está disponible.</p>
        <Link href="/dashboard" className="button button-primary">
          Volver al dashboard
        </Link>
      </section>
    </main>
  );
}
