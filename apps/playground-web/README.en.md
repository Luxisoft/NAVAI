# @navai/playground-web

<p align="center">
  <a href="./README.es.md"><img alt="Spanish" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="../playground-api/README.es.md"><img alt="Playground API ES" src="https://img.shields.io/badge/Playground%20API-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-api/README.en.md"><img alt="Playground API EN" src="https://img.shields.io/badge/Playground%20API-EN-1D9A6C?style=for-the-badge"></a>
  <a href="../playground-mobile/README.es.md"><img alt="Playground Mobile ES" src="https://img.shields.io/badge/Playground%20Mobile-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-mobile/README.en.md"><img alt="Playground Mobile EN" src="https://img.shields.io/badge/Playground%20Mobile-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="../../packages/voice-backend/README.md"><img alt="Voice Backend Docs" src="https://img.shields.io/badge/Voice%20Backend-Docs-ff9023?style=for-the-badge"></a>
  <a href="../../packages/voice-frontend/README.md"><img alt="Voice Frontend Docs" src="https://img.shields.io/badge/Voice%20Frontend-Docs-146EF5?style=for-the-badge"></a>
  <a href="../../packages/voice-mobile/README.md"><img alt="Voice Mobile Docs" src="https://img.shields.io/badge/Voice%20Mobile-Docs-0B8F6A?style=for-the-badge"></a>
</p>

Sample React frontend for voice-first navigation with OpenAI Realtime and optional hybrid ElevenLabs TTS.

This playground no longer uses `src/ai/functions-modules`. The active structure is `src/ai/<agent>/...`.

This frontend:

- requests `client_secret` from backend
- generates loaders and loads frontend functions from `src/ai`
- resolves agents based on `NAVAI_AGENTS_FOLDERS`
- loads backend functions
- executes tools through `execute_app_function`
- uses `useWebVoiceAgent` from `@navai/voice-frontend`
- includes a multi-agent sample with `main`, `support`, `sales`, and `food`

## Quick start

1. Install dependencies from repo root:

```bash
npm install
```

2. Create `.env`:

```powershell
Copy-Item .env.example .env
```

3. Configure `.env`:

```env
NAVAI_API_URL=http://localhost:3000
NAVAI_AGENTS_FOLDERS=main,support,sales,food
```

4. Run backend in another terminal:

```bash
npm run dev --workspace @navai/playground-api
```

5. Run frontend:

```bash
npm run dev --workspace @navai/playground-web
```

`dev/build/typecheck/lint` run `generate:module-loaders` first, which produces `src/ai/generated-module-loaders.ts` with only configured paths.

Example structure:

```text
src/ai/
  main/
    agent.config.ts
    ...
  support/
    agent.config.ts
    ...
  sales/
    agent.config.ts
    ...
  food/
    agent.config.ts
    ...
```

Important rule:

- only the first level under `src/ai/` defines the agent.
- everything after that is just internal organization for that agent.
- `src/ai/main/session/logout.fn.ts` belongs to the `main` agent.
- `src/ai/main/support/open-help.fn.ts` also belongs to the `main` agent.
- folders such as `session`, `support`, `help`, or `utils` inside `main` are optional.

To regenerate the file manually:

```bash
npm run generate:module-loaders --workspace @navai/playground-web
```

Shortcut: `npm run dev` from root starts both apps.

## Current voice flow

1. `VoiceNavigator` uses `useWebVoiceAgent` from `@navai/voice-frontend`.
2. The hook requests `POST /navai/realtime/client-secret` and loads backend functions.
3. The hook resolves frontend runtime (routes + local functions) with `resolveNavaiFrontendRuntimeConfig`.
4. The hook builds a primary agent + specialists with `buildNavaiAgent` and connects `RealtimeSession`.

If backend `playground-api` uses `NAVAI_TTS_PROVIDER=elevenlabs`:

- the realtime session still uses OpenAI for microphone, tools, and orchestration.
- `useWebVoiceAgent` switches assistant output to text.
- final assistant speech is played locally from `POST /navai/speech/synthesize`.

In web realtime, the current multi-agent delegation uses `handoffs` between `RealtimeAgent` instances.

When the agent calls `execute_app_function`:

- it tries local function first (frontend)
- if not found locally, it executes on backend via `POST /navai/functions/execute`

## Agent voice state

`useWebVoiceAgent` now exposes assistant voice state:

- `agentVoiceState`: `idle | speaking`
- `isAgentSpeaking`: `boolean`

This playground `VoiceNavigator` already uses these values to change text, button label, and colors when the agent starts/stops speaking.

## Variables and customization

- `NAVAI_API_URL`: backend base URL.
- `NAVAI_AGENTS_FOLDERS`: CSV with allowed agents and the primary agent first.

In this playground, `NAVAI_FUNCTIONS_FOLDERS` and `NAVAI_ROUTES_FILE` are no longer required because the sample pins `src/ai` and `src/ai/routes.ts`.

`NAVAI_TTS_PROVIDER` and `ELEVENLABS_*` live in `@navai/playground-api`, not in this frontend.

To force another URL at runtime, pass `apiBaseUrl` to the component:

```tsx
<VoiceNavigator apiBaseUrl="https://my-api.com" />
```

## Debug

- `Function loader warnings` shows local/backend loading warnings.
- `Realtime history (debug)` shows session event history.
