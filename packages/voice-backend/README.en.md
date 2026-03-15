# @navai/voice-backend

<p>
  <a href="./README.es.md"><img alt="Idioma Espanol" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="Language English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

Backend package for Navai voice applications.

This package solves three backend responsibilities:

1. Mint secure ephemeral `client_secret` tokens for OpenAI Realtime.
2. Proxy optional ElevenLabs speech synthesis for hybrid voice output.
3. Discover, validate, expose, and execute backend tools from your codebase.

## Installation

```bash
npm install @navai/voice-backend
```

`express` is a peer dependency.

## Architecture Overview

Runtime architecture has three layers:

1. `src/index.ts`
Entry layer. Exposes public API, client secret helpers, and Express route registration.

2. `src/runtime.ts`
Discovery layer. Resolves `NAVAI_FUNCTIONS_FOLDERS`, scans files, applies path matching rules, and builds module loaders.

3. `src/functions.ts`
Execution layer. Imports matched modules, transforms exports into normalized tool definitions, and executes them safely.

End-to-end request flow:

1. Frontend/mobile calls `POST /navai/realtime/client-secret`.
2. Backend validates options and API key policy.
3. Backend calls OpenAI `POST https://api.openai.com/v1/realtime/client_secrets`.
4. When `speech.provider=elevenlabs`, frontend/mobile later calls `POST /navai/speech/synthesize` with final assistant text.
5. Frontend/mobile calls `GET /navai/functions` to discover allowed tools.
6. Agent calls `POST /navai/functions/execute` with `function_name` and `payload`.
7. Backend executes only tool names loaded in the registry.

Note about agent voice state:

- The backend does not directly detect when assistant speech starts/stops, because realtime audio runs on the client side.
- That state is exposed by `@navai/voice-frontend`, `@navai/voice-mobile`, and the WordPress widget through frontend events.

## Public API

Client secret helpers:

- `getNavaiVoiceBackendOptionsFromEnv(env?)`
- `createRealtimeClientSecret(options, request?)`
- `createExpressClientSecretHandler(options)`
- `synthesizeSpeech(options, request?)`

Express integration:

- `registerNavaiExpressRoutes(app, options?)`

Dynamic runtime helpers:

- `resolveNavaiBackendRuntimeConfig(options?)`
- `loadNavaiFunctions(functionModuleLoaders)`

Key exported types:

- `NavaiVoiceBackendOptions`
- `CreateClientSecretRequest`
- `OpenAIRealtimeClientSecretResponse`
- `SynthesizeSpeechRequest`
- `SynthesizeSpeechResponse`
- `NavaiSpeechProvider`
- `ResolveNavaiBackendRuntimeConfigOptions`
- `NavaiFunctionDefinition`
- `NavaiFunctionModuleLoaders`
- `NavaiFunctionsRegistry`

## Detailed Route Behavior

`registerNavaiExpressRoutes` registers these routes by default:

- `POST /navai/realtime/client-secret`
- `POST /navai/speech/synthesize`
- `GET /navai/functions`
- `POST /navai/functions/execute`

Custom route paths are supported with:

- `clientSecretPath`
- `speechSynthesizePath`
- `functionsListPath`
- `functionsExecutePath`

`includeFunctionsRoutes` controls whether `/navai/functions*` routes are mounted.

Important runtime detail:

- tool runtime is lazy-loaded once and cached in-memory.
- first call to list/execute functions builds the registry.
- after initial load, file changes are not auto-reloaded unless process restarts.

## Client Secret Pipeline

`createRealtimeClientSecret` behavior:

1. Validates options.
- `clientSecretTtlSeconds` must be between `10` and `7200`.
- if backend key is missing and request keys are not allowed, it throws.

2. Resolves API key with strict priority.
- backend `openaiApiKey` always wins.
- request `apiKey` is fallback only if backend key is missing.

3. Builds session payload.
- `model` default: `gpt-realtime`
- `voice` default: `marin`
- `instructions` include base instructions plus optional language/accent/tone lines.
- when `NAVAI_TTS_PROVIDER=elevenlabs`, backend requests text-only Realtime output with `output_modalities: ["text"]`.

4. Calls OpenAI Realtime client secret endpoint and returns:
- `value`
- `expires_at`
- `speech.provider`

Request body accepted by route:

```json
{
  "model": "gpt-realtime",
  "voice": "marin",
  "instructions": "You are a helpful assistant.",
  "language": "Spanish",
  "voiceAccent": "neutral Latin American Spanish",
  "voiceTone": "friendly and professional",
  "apiKey": "sk-..."
}
```

Response:

```json
{
  "value": "ek_...",
  "expires_at": 1730000000,
  "speech": {
    "provider": "elevenlabs"
  }
}
```

Speech synthesis request body:

```json
{
  "text": "Hello, I am NAVAI.",
  "voiceId": "voice_id_optional",
  "modelId": "model_id_optional"
}
```

Speech synthesis response:

```json
{
  "provider": "elevenlabs",
  "mimeType": "audio/mpeg",
  "audioBase64": "SUQz..."
}
```

## Dynamic Function Loading Internals

`resolveNavaiBackendRuntimeConfig` reads:

- explicit options first.
- then env key `NAVAI_FUNCTIONS_FOLDERS`.
- then fallback default `src/ai/functions-modules`.

Matcher formats accepted in `NAVAI_FUNCTIONS_FOLDERS`:

- folder: `src/ai/functions-modules`
- recursive folder: `src/ai/functions-modules/...`
- wildcard: `src/features/*/voice-functions`
- explicit file: `src/ai/functions-modules/secret.ts`
- CSV list: `src/ai/functions-modules,...`

Scanner behavior:

- scans from `baseDir` recursively.
- includes extensions from `includeExtensions` (default `ts/js/mjs/cjs/mts/cts`).
- excludes patterns from `exclude` (default ignores `node_modules`, `dist`, hidden paths).
- ignores `*.d.ts`.

Fallback behavior:

- if configured folders match nothing, warning is emitted.
- loader falls back to `src/ai/functions-modules`.

## Export-to-Tool Mapping Rules

`loadNavaiFunctions` can transform these export shapes:

1. Exported function.
- creates one tool.

2. Exported class.
- creates one tool per callable instance method.
- constructor args come from `payload.constructorArgs`.
- method args come from `payload.methodArgs`.

3. Exported object.
- creates one tool per callable member.

Name normalization:

- converts to snake_case lowercase.
- removes unsafe characters.
- on collisions, appends suffix (`_2`, `_3`, ...).
- emits warning whenever a rename happens.

Invocation argument resolution:

- if `payload.args` exists, it is used as argument list.
- else if `payload.value` exists, it becomes first argument.
- else if payload has keys, whole payload is first argument.
- if callable arity expects one more arg, context is appended.

On `/navai/functions/execute`, context includes `{ req }`.

## HTTP Contracts for Tools

`GET /navai/functions` response:

```json
{
  "items": [
    {
      "name": "secret_password",
      "description": "Call exported function default.",
      "source": "src/ai/functions-modules/security.ts#default"
    }
  ],
  "warnings": []
}
```

`POST /navai/functions/execute` body:

```json
{
  "function_name": "secret_password",
  "payload": {
    "args": ["abc"]
  }
}
```

Success response:

```json
{
  "ok": true,
  "function_name": "secret_password",
  "source": "src/ai/functions-modules/security.ts#default",
  "result": "..."
}
```

Unknown function response:

```json
{
  "error": "Unknown or disallowed function.",
  "available_functions": ["..."]
}
```

## Configuration and Env Rules

Main env keys:

- `OPENAI_API_KEY`
- `OPENAI_REALTIME_MODEL`
- `OPENAI_REALTIME_VOICE`
- `OPENAI_REALTIME_INSTRUCTIONS`
- `OPENAI_REALTIME_LANGUAGE`
- `OPENAI_REALTIME_VOICE_ACCENT`
- `OPENAI_REALTIME_VOICE_TONE`
- `OPENAI_REALTIME_CLIENT_SECRET_TTL`
- `NAVAI_TTS_PROVIDER`
- `ELEVENLABS_API_KEY`
- `ELEVENLABS_BASE_URL`
- `ELEVENLABS_VOICE_ID`
- `ELEVENLABS_MODEL_ID`
- `ELEVENLABS_OUTPUT_FORMAT`
- `ELEVENLABS_OPTIMIZE_STREAMING_LATENCY`
- `ELEVENLABS_STABILITY`
- `ELEVENLABS_SIMILARITY_BOOST`
- `ELEVENLABS_STYLE`
- `ELEVENLABS_USE_SPEAKER_BOOST`
- `NAVAI_ALLOW_FRONTEND_API_KEY`
- `NAVAI_FUNCTIONS_FOLDERS`
- `NAVAI_FUNCTIONS_BASE_DIR`

API key policy from env:

- if `OPENAI_API_KEY` exists, request API keys are denied unless `NAVAI_ALLOW_FRONTEND_API_KEY=true`.
- if `OPENAI_API_KEY` is missing, request API keys are allowed as fallback.

## Minimal Integration Example

```ts
import express from "express";
import { registerNavaiExpressRoutes } from "@navai/voice-backend";

const app = express();
app.use(express.json());

registerNavaiExpressRoutes(app, {
  backendOptions: {
    openaiApiKey: process.env.OPENAI_API_KEY,
    defaultModel: "gpt-realtime",
    defaultVoice: "marin",
    clientSecretTtlSeconds: 600
  }
});

app.listen(3000);
```

## Operational Guidance

Production recommendations:

- keep `OPENAI_API_KEY` only on server.
- keep `ELEVENLABS_API_KEY` only on server.
- keep `NAVAI_ALLOW_FRONTEND_API_KEY=false` in production.
- whitelist CORS origins at app layer.
- monitor and surface `warnings` from both runtime and registry.
- restart backend when function files are changed if you need updated registry.

Common errors:

- `Missing openaiApiKey in NavaiVoiceBackendOptions.`
- `Passing apiKey from request is disabled. Set allowApiKeyFromRequest=true to enable it.`
- `clientSecretTtlSeconds must be between 10 and 7200.`

## Related Docs

- Package index: `README.md`
- Spanish version: `README.es.md`
- Frontend package: `../voice-frontend/README.md`
- Mobile package: `../voice-mobile/README.md`
- Playground API: `../../apps/playground-api/README.md`
- Playground Web: `../../apps/playground-web/README.md`
- Playground Mobile: `../../apps/playground-mobile/README.md`
