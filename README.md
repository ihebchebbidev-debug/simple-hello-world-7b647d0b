# Agri-Sync — Admin Web

Web administration interface for Agri-Sync (managers & administrators).

## StackS

- **Vite 5** + **React 18** + **TypeScript 5**
- **TailwindCSS 3** with the project design tokens (HSL, dark-first)
- **shadcn/ui** primitives on top of Radix UI
- **TanStack Query** for server-state, **react-router-dom v6** for routing
- **react-i18next** (FR default, EN fallback)
- **Recharts** for dashboard analytics

Talks to the Laravel API in `../api` over JSON + Sanctum personal access tokens.

## Getting started

```bash
cp .env.example .env       # then edit VITE_API_URL
npm install
npm run dev                # http://localhost:5173
```

## Scripts

| Command           | Purpose                              |
| ----------------- | ------------------------------------ |
| `npm run dev`     | Vite dev server with HMR             |
| `npm run build`   | Type-check + production bundle       |
| `npm run preview` | Serve the built bundle locally       |
| `npm run lint`    | ESLint over `src/`                   |
| `npm run test`    | Vitest unit tests                    |

## Layout

```
src/
  app/            # routing, providers, layout shells
  features/       # feature-sliced modules (auth, plots, reports, …)
  components/ui/  # shadcn primitives
  lib/            # api client, formatters, helpers
  i18n/           # fr.ts / en.ts dictionaries
  styles/         # tailwind entry + design tokens
```

> This package is the production target for the admin app currently
> prototyped at the repository root. Code is migrated feature by feature.
