# Agri-Sync — Mobile (Ionic + Capacitor)

Field technician application — Android-first, offline-capable.

## Stack

- **Ionic React 8** + **Capacitor 6**
- **Vite 5** + **React 18** + **TypeScript 5**
- **react-router-dom v6** (matches the admin/web app — Ionic page animations
  via `<IonPage>` per route, no `IonReactRouter`)
- **TanStack Query** for server cache, **react-i18next** (FR/EN)
- **Capacitor plugins**: Geolocation, Network, Preferences, Camera,
  StatusBar, SplashScreen

## Getting started

```bash
cp .env.example .env
npm install
npm run dev                 # web preview at http://localhost:5174
```

## Android build

```bash
npm run build
npx cap add android         # one-time
npx cap sync android
npx cap open android        # opens Android Studio
```

Loop while developing natively:

```bash
npm run build && npx cap sync android && npx cap run android
```

## Layout

```
src/
  app/                  # routing + Ionic shell
  features/             # auth, operations (irrigation/fert/phyto/harvest), sync
  lib/
    native.ts           # Capacitor wrappers with web fallbacks
    offlineQueue.ts     # Preferences-backed retry queue
    api.ts              # axios client with Sanctum bearer
  styles/
    ionic-theme.css     # maps Ionic CSS vars → project HSL tokens
    index.css           # tailwind entry
```

## Identity

- `appId`: `tn.agrisync.app`
- `appName`: `Agri-Sync`
- `webDir`: `dist`

> This package is the production target for the mobile app currently
> prototyped at `/mobileapp` in the repo root. Code is migrated screen by
> screen.
