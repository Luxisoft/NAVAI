# @navai/playground-web

<p align="center">
  <a href="./README.es.md"><img alt="Spanish" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="../playground-api/README.es.md"><img alt="Playground API ES" src="https://img.shields.io/badge/Playground%20API-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-api/README.en.md"><img alt="Playground API EN" src="https://img.shields.io/badge/Playground%20API-EN-1D9A6C?style=for-the-badge"></a>
  <a href="../playground-web/README.es.md"><img alt="Playground Web ES" src="https://img.shields.io/badge/Playground%20Web-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-web/README.en.md"><img alt="Playground Web EN" src="https://img.shields.io/badge/Playground%20Web-EN-1D9A6C?style=for-the-badge"></a>
  <a href="../playground-mobile/README.es.md"><img alt="Playground Mobile ES" src="https://img.shields.io/badge/Playground%20Mobile-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-mobile/README.en.md"><img alt="Playground Mobile EN" src="https://img.shields.io/badge/Playground%20Mobile-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="../../packages/voice-backend/README.es.md"><img alt="Voice Backend ES" src="https://img.shields.io/badge/Voice%20Backend-ES-0A66C2?style=for-the-badge"></a>
  <a href="../../packages/voice-backend/README.en.md"><img alt="Voice Backend EN" src="https://img.shields.io/badge/Voice%20Backend-EN-1D9A6C?style=for-the-badge"></a>
  <a href="../../packages/voice-frontend/README.md"><img alt="Voice Frontend Docs" src="https://img.shields.io/badge/Voice%20Frontend-Docs-146EF5?style=for-the-badge"></a>
  <a href="../../packages/voice-mobile/README.md"><img alt="Voice Mobile Docs" src="https://img.shields.io/badge/Voice%20Mobile-Docs-0B8F6A?style=for-the-badge"></a>
</p>

Frontend React de ejemplo para navegacion voice-first con OpenAI Realtime y TTS hibrido opcional con ElevenLabs.

En este playground ya no se usa `src/ai/functions-modules`. La estructura activa es `src/ai/<agent>/...`.

Este frontend:

- pide `client_secret` al backend
- genera loaders y carga funciones frontend desde `src/ai`
- resuelve agentes segun `NAVAI_AGENTS_FOLDERS`
- carga funciones backend
- ejecuta tools via `execute_app_function`
- usa `useWebVoiceAgent` desde `@navai/voice-frontend`
- incluye un ejemplo multiagente con `main`, `support`, `sales` y `food`

## Inicio rapido

1. Instala dependencias desde la raiz:

```bash
npm install
```

2. Crea `.env`:

```powershell
Copy-Item .env.example .env
```

3. Configura `.env`:

```env
NAVAI_API_URL=http://localhost:3000
NAVAI_AGENTS_FOLDERS=main,support,sales,food
```

4. Ejecuta backend en otra terminal:

```bash
npm run dev --workspace @navai/playground-api
```

5. Ejecuta frontend:

```bash
npm run dev --workspace @navai/playground-web
```

`dev/build/typecheck/lint` ejecutan antes `generate:module-loaders`, que produce `src/ai/generated-module-loaders.ts` con solo las rutas configuradas.

Estructura del ejemplo:

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

Regla importante:

- solo el primer nivel debajo de `src/ai/` define el agente.
- todo lo que venga despues es solo organizacion interna del agente.
- `src/ai/main/session/logout.fn.ts` pertenece al agente `main`.
- `src/ai/main/support/open-help.fn.ts` tambien pertenece al agente `main`.
- carpetas como `session`, `support`, `help` o `utils` dentro de `main` no son obligatorias.

Estructura del ejemplo:

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
```

Para regenerar manualmente el archivo:

```bash
npm run generate:module-loaders --workspace @navai/playground-web
```

Atajo: `npm run dev` desde la raiz levanta ambas apps.

## Flujo de voz actual

1. `VoiceNavigator` usa `useWebVoiceAgent` de `@navai/voice-frontend`.
2. El hook pide `POST /navai/realtime/client-secret` y carga funciones backend.
3. El hook resuelve runtime frontend (rutas + funciones locales) con `resolveNavaiFrontendRuntimeConfig`.
4. El hook construye agente principal + especialistas con `buildNavaiAgent` y conecta `RealtimeSession`.

Si el backend `playground-api` usa `NAVAI_TTS_PROVIDER=elevenlabs`:

- la sesion realtime sigue usando OpenAI para microfono, tools y orquestacion.
- `useWebVoiceAgent` cambia la salida del asistente a texto.
- la voz final del asistente se reproduce localmente desde `POST /navai/speech/synthesize`.

En web, la delegacion multiagente actual usa `handoffs` entre `RealtimeAgent`s.

Cuando el agente llama `execute_app_function`:

- intenta primero funcion local (frontend)
- si no existe local, ejecuta en backend via `POST /navai/functions/execute`

## Estado del agente (voz)

`useWebVoiceAgent` ahora expone estado de voz del asistente:

- `agentVoiceState`: `idle | speaking`
- `isAgentSpeaking`: `boolean`

El `VoiceNavigator` de este playground ya usa estos valores para cambiar texto, boton y color cuando el agente empieza y termina de responder por voz.

## Variables y personalizacion

- `NAVAI_API_URL`: URL base del backend.
- `NAVAI_AGENTS_FOLDERS`: CSV con los agentes permitidos y el agente principal primero.

En este playground, `NAVAI_FUNCTIONS_FOLDERS` y `NAVAI_ROUTES_FILE` ya no son necesarios porque el ejemplo fija `src/ai` y `src/ai/routes.ts`.

Las variables `NAVAI_TTS_PROVIDER` y `ELEVENLABS_*` se configuran en `@navai/playground-api`, no en este frontend.

Si necesitas forzar otra URL en runtime, puedes pasar `apiBaseUrl` al componente:

```tsx
<VoiceNavigator apiBaseUrl="https://mi-api.com" />
```

## Debug

- `Function loader warnings` muestra advertencias de carga local/backend.
- `Realtime history (debug)` muestra historial de eventos de sesion.
