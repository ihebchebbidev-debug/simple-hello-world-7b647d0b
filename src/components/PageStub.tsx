interface PageStubProps {
  title: string;
  description?: string;
}

const PageStub = ({ title, description }: PageStubProps) => (
  <section className="space-y-2">
    <h1 className="text-2xl font-bold">{title}</h1>
    <p className="text-sm text-muted-foreground">
      {description ?? 'Module à brancher sur l\'API Laravel.'}
    </p>
  </section>
);

export default PageStub;
