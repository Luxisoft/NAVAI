# @navai/voice-mobile

<p>
  <a href="./README.es.md"><img alt="Idioma Espanol" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="Language English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

Mobile package to run Navai voice agents in React Native applications.

It provides a complete mobile stack for:

1. Backend `client_secret` retrieval.
2. WebRTC transport negotiation for Realtime.
3. Route-aware and function-aware mobile tools.
4. Local dynamic function loading.
5. Realtime tool call parsing and result event emission.
6. React hook lifecycle for microphone, transport, and session.
7. Optional local speech playback for hybrid ElevenLabs output.

## Installation

```bash
npm install @navai/voice-mobile
npm install react react-native react-native-webrtc
```

`react-native-webrtc` is a peer dependency and must exist in the consuming app.

## Architecture Overview

This package is organized in layers:

1. Runtime/config layer
- `src/runtime.ts`
- resolves env values, API URL, routes file, function folder filters, and model override.

2. Function layer
- `src/functions.ts`
- loads local modules and converts exports into callable function definitions.

3. Agent runtime layer
- `src/agent.ts`
- builds mobile instructions and tool schemas.
- executes `navigate_to` and `execute_app_function`.
- parses tool calls from Realtime events.
- creates response events for function_call outputs.

4. Backend bridge layer
- `src/backend.ts`
- API client for Navai backend routes.

5. Session orchestration layer
- `src/session.ts`
- coordinates backend client + transport.
- handles start/stop, function preloading, event forwarding.

6. Transport layer
- `src/transport.ts`
- interface contract for custom transports.
- `src/react-native-webrtc.ts` implementation for React Native WebRTC.

7. React integration layer
- `src/useMobileVoiceAgent.ts`
- hook that combines runtime, local functions, backend tools, permissions, and session state.

## End-to-End Flow

Typical hook-driven flow:

1. App resolves runtime config with generated module loaders.
2. Hook dynamically loads `react-native-webrtc`.
3. Hook loads local function registry from module loaders.
4. On `start()`:
- validates runtime state.
- requests Android microphone permission when needed.
- creates backend client and WebRTC transport.
- starts mobile voice session (client secret + transport connect).
- builds mobile agent runtime (instructions + tool schemas).
- sends `session.update` event with tools and instructions.
- when `speech.provider=elevenlabs`, the session is configured to receive text output and the hook can synthesize/play audio locally.
5. During conversation:
- incoming Realtime events are parsed for tool calls.
- tool call outputs are emitted back via `conversation.item.create` and `response.create`.
6. On `stop()`:
- transport disconnects.
- local refs and pending tool maps are cleared.

## Public API

Main exports:

- `resolveNavaiMobileEnv(...)`
- `resolveNavaiMobileRuntimeConfig(...)`
- `resolveNavaiMobileApplicationRuntimeConfig(...)`
- `loadNavaiFunctions(...)`
- `createNavaiMobileAgentRuntime(...)`
- `extractNavaiRealtimeToolCalls(...)`
- `buildNavaiRealtimeToolResultEvents(...)`
- `createNavaiMobileBackendClient(...)`
- `createNavaiMobileVoiceSession(...)`
- `createReactNativeWebRtcTransport(...)`
- `useMobileVoiceAgent(...)`

Important types:

- `NavaiRoute`
- `NavaiFunctionDefinition`
- `NavaiRealtimeTransport`
- `NavaiMobileVoiceSession`
- `NavaiBackendSpeechConfig`
- `NavaiMobileSpeechPlayer`
- `ResolveNavaiMobileApplicationRuntimeConfigResult`
- `UseMobileVoiceAgentTransportOptions`

## Tool Runtime Design

Mobile tool surface is intentionally stable:

- `navigate_to`
- `execute_app_function`

Execution behavior:

1. `navigate_to`
- validates `target`.
- resolves route using route matcher.
- calls `navigate(path)`.

2. `execute_app_function`
- validates `function_name`.
- tries local function first.
- falls back to backend function if local not found.

Graceful compatibility fallback:

- if model calls a function name directly as tool, runtime routes it as `execute_app_function`.

## Realtime Tool Event Handling

`extractNavaiRealtimeToolCalls` understands multiple event families:

- `response.function_call_arguments.done`
- `response.output_item.done`
- `response.output_item.added`
- `conversation.item.created`
- `conversation.item.added`
- `conversation.item.done`
- `conversation.item.retrieved`
- `response.done`

Partial tool calls are ignored until completed status is available.

`buildNavaiRealtimeToolResultEvents` emits two events:

1. `conversation.item.create` with `function_call_output`.
2. `response.create` to resume model generation.

## Runtime Config and Env Resolution

`resolveNavaiMobileRuntimeConfig` priority:

1. Explicit options.
2. Env object values.
3. Defaults.

Keys:

- `NAVAI_FUNCTIONS_FOLDERS`
- `NAVAI_ROUTES_FILE`
- `NAVAI_REALTIME_MODEL`

Defaults:

- routes file: `src/ai/routes.ts`
- functions folder: `src/ai/functions-modules`

Matcher formats:

- folder
- recursive folder (`/...`)
- wildcard (`*`)
- explicit file
- CSV list

Fallback behavior:

- configured folders with no matches emit warning.
- resolver falls back to default folder.

`resolveNavaiMobileApplicationRuntimeConfig` also resolves:

- `apiBaseUrl` from:
  1) explicit `apiBaseUrl`
  2) `env.NAVAI_API_URL`
  3) explicit `defaultApiBaseUrl`
  4) default `http://localhost:3000`
- warning when generated module loader map is empty.

`resolveNavaiMobileEnv` lets you merge multiple env-like sources (for example Expo `extra`, `process.env`, custom config object).

## Backend Client Contract

`createNavaiMobileBackendClient` calls:

- `POST /navai/realtime/client-secret`
- `POST /navai/speech/synthesize`
- `GET /navai/functions`
- `POST /navai/functions/execute`

Base URL priority:

1. `apiBaseUrl` option.
2. `env.NAVAI_API_URL`.
3. fallback `http://localhost:3000`.

`listFunctions` returns warnings instead of throwing on most parse/network failures.

`createClientSecret` and `executeFunction` throw on request failures or invalid responses.

`createClientSecret()` returns `{ value, expires_at, speech }`, where `speech.provider` is `openai` or `elevenlabs`.

## Session Orchestrator Details

`createNavaiMobileVoiceSession` responsibilities:

1. Function list cache.
2. Session state transitions (`idle`, `connecting`, `connected`, `error`).
3. Start flow:
- optional backend function preload.
- client secret request.
- transport connect with `clientSecret` and optional `model`.
4. Stop flow:
- transport disconnect.
5. Realtime event send helper (requires transport `sendEvent` implementation).

## React Native WebRTC Transport Details

`createReactNativeWebRtcTransport` default behavior:

- realtime endpoint: `https://api.openai.com/v1/realtime/calls`
- model default: `gpt-realtime`
- creates `RTCPeerConnection`
- opens data channel `oai-events`
- captures microphone via `mediaDevices.getUserMedia`
- negotiates SDP with OpenAI
- waits for data channel open before resolving connect

Resilience behavior:

- tracks transport state (`idle`, `connecting`, `connected`, `error`, `closed`)
- propagates connection/data channel errors via callbacks
- cleans tracks, channel, and connection on disconnect
- supports configurable remote audio track volume via private `_setVolume` when available

## React Hook Internals

`useMobileVoiceAgent` adds app-level behavior:

- Android microphone permission request.
- dynamic `require("react-native-webrtc")`.
- pending tool call queue while runtime/session is initializing.
- dedup of handled tool call ids.
- automatic `session.update` after session starts.
- optional `transportOptions` passthrough for `rtcConfiguration`, `audioConstraints`, and `remoteAudioTrackVolume`.
- optional `speechPlayer` for local playback when backend uses ElevenLabs hybrid TTS.

Hook states:

- `idle`
- `connecting`
- `connected`
- `error`

Agent voice state exposed by the hook:

- `agentVoiceState`: `idle | speaking`
- `isAgentSpeaking`: `boolean`

`agentVoiceState` is inferred from realtime audio events (`response.output_audio.delta`, `response.output_audio.done`, `output_audio_buffer.started`, `output_audio_buffer.stopped`, `response.done`).

## Hybrid Speech Mode

When backend returns `speech.provider: "elevenlabs"`:

- `useMobileVoiceAgent` updates the Realtime session to request `output_modalities: ["text"]`.
- assistant final text is sent to `backendClient.synthesizeSpeech(...)`.
- the synthesized audio is played through the app-provided `speechPlayer`.
- if no `speechPlayer` is provided, the hook logs a warning and skips local playback.

## Generated Loader CLI

This package ships:

- `navai-generate-mobile-loaders`

Default behavior:

1. Read `NAVAI_FUNCTIONS_FOLDERS` and `NAVAI_ROUTES_FILE` from process env or `.env`.
2. Scan `src/` for source files.
3. Select only modules matching configured function folders.
4. Include route module.
5. Include files referenced by route module string literals like `src/...` (for screen modules).
6. Write `src/ai/generated-module-loaders.ts`.

Useful flags:

- `--project-root <path>`
- `--src-root <path>`
- `--output-file <path>`
- `--env-file <path>`
- `--default-functions-folder <path>`
- `--default-routes-file <path>`
- `--type-import <module>`
- `--export-name <identifier>`

## Auto Setup on npm Install

Postinstall can auto-add missing scripts:

- `generate:ai-modules` -> `navai-generate-mobile-loaders`
- `predev` -> `npm run generate:ai-modules`
- `preandroid` -> `npm run generate:ai-modules`
- `preios` -> `npm run generate:ai-modules`
- `pretypecheck` -> `npm run generate:ai-modules`

Rules:

- only missing scripts are added.
- existing scripts are never overwritten.

Disable auto setup:

- `NAVAI_SKIP_AUTO_SETUP=1`
- or `NAVAI_SKIP_MOBILE_AUTO_SETUP=1`

Manual setup runner:

```bash
npx navai-setup-voice-mobile
```

## Integration Examples

Low-level integration:

```ts
import { mediaDevices, RTCPeerConnection } from "react-native-webrtc";
import {
  createNavaiMobileBackendClient,
  createNavaiMobileVoiceSession,
  createReactNativeWebRtcTransport
} from "@navai/voice-mobile";

const backend = createNavaiMobileBackendClient({
  apiBaseUrl: "http://localhost:3000"
});

const transport = createReactNativeWebRtcTransport({
  globals: { mediaDevices, RTCPeerConnection }
});

const session = createNavaiMobileVoiceSession({
  backendClient: backend,
  transport,
  onRealtimeEvent: (event) => console.log(event),
  onRealtimeError: (error) => console.error(error)
});

await session.start();
```

Hook integration:

```ts
import { useMobileVoiceAgent } from "@navai/voice-mobile";

const voice = useMobileVoiceAgent({
  runtime,
  runtimeLoading,
  runtimeError,
  navigate: (path) => navigation.navigate(path as never)
});
```

React Native CLI Android (opt-in transport config):

Leave `transportOptions` undefined in Expo if the current defaults already work for your app. In bare React Native CLI on Android, you can opt in to an explicit WebRTC transport configuration without changing Expo behavior.

```ts
import { Platform } from "react-native";
import {
  useMobileVoiceAgent,
  type UseMobileVoiceAgentTransportOptions
} from "@navai/voice-mobile";

const androidBareTransportOptions: UseMobileVoiceAgentTransportOptions | undefined =
  Platform.OS === "android"
    ? {
        rtcConfiguration: {
          iceServers: [{ urls: ["stun:stun.l.google.com:19302"] }]
        },
        audioConstraints: {
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true
          },
          video: false
        },
        remoteAudioTrackVolume: 10
      }
    : undefined;

const voice = useMobileVoiceAgent({
  runtime,
  runtimeLoading,
  runtimeError,
  navigate: (path) => navigation.navigate(path as never),
  transportOptions: androidBareTransportOptions
});
```

## Expected Backend Routes

- `POST /navai/realtime/client-secret`
- `POST /navai/speech/synthesize`
- `GET /navai/functions`
- `POST /navai/functions/execute`

These can be provided by `registerNavaiExpressRoutes` from `@navai/voice-backend`.

## Related Docs

- Spanish version: `README.es.md`
- English version: `README.en.md`
- Backend package: `../voice-backend/README.md`
- Frontend package: `../voice-frontend/README.md`
- Playground Mobile: `../../apps/playground-mobile/README.md`
- Playground API: `../../apps/playground-api/README.md`
