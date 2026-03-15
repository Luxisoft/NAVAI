# @navai/voice-frontend

<p>
  <a href="./README.es.md"><img alt="Idioma Espanol" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="Language English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

Frontend package to build Navai voice agents in web applications.

It removes repeated boilerplate for:

1. Realtime client secret requests.
2. Route-aware navigation tools.
3. Dynamic local function loading.
4. Optional backend function bridging.
5. React hook lifecycle for connect/disconnect.

## Installation

```bash
npm install @navai/voice-frontend @openai/agents zod
npm install react
```

## Package Architecture

This package is intentionally split by concern:

1. `src/backend.ts`
HTTP client for backend routes:
- `POST /navai/realtime/client-secret`
- `POST /navai/speech/synthesize`
- `GET /navai/functions`
- `POST /navai/functions/execute`

2. `src/runtime.ts`
Runtime resolver for:
- route module selection
- function module filtering by `NAVAI_FUNCTIONS_FOLDERS`
- agent discovery by `NAVAI_AGENTS_FOLDERS`
- optional model override

3. `src/functions.ts`
Local function loader:
- imports modules from generated loaders
- converts exports into normalized callable tool definitions

4. `src/agent.ts`
Agent builder:
- creates primary `RealtimeAgent`
- injects built-in tools (`navigate_to`, `execute_app_function`)
- optionally adds direct alias tools for each allowed function
- creates specialist realtime agents and wires them as `handoffs`

5. `src/useWebVoiceAgent.ts`
React lifecycle wrapper:
- builds runtime config
- requests client secret
- discovers backend functions
- builds agent
- opens/closes `RealtimeSession`

6. `src/routes.ts`
Route matching helpers for natural language to path resolution.

## End-to-End Runtime Flow

Hook-driven runtime flow (`useWebVoiceAgent`):

1. Resolve runtime config from `moduleLoaders` + `defaultRoutes` + env/options.
2. Create backend client with `apiBaseUrl` or `NAVAI_API_URL`.
3. On `start()`:
- request client secret.
- read `speech.provider` from backend response.
- fetch backend function list.
- build Navai agent with local + backend functions.
- connect `RealtimeSession`.
4. On `stop()`:
- close session and reset state.

State machine exposed by hook:

- `idle`
- `connecting`
- `connected`
- `error`

Agent voice state exposed by the hook:

- `agentVoiceState`: `idle | speaking`
- `isAgentSpeaking`: `boolean`

`agentVoiceState` is driven by realtime events `audio_start`, `audio_stopped`, and `audio_interrupted`.

## Public API

Main exports:

- `buildNavaiAgent(...)`
- `createNavaiBackendClient(...)`
- `resolveNavaiFrontendRuntimeConfig(...)`
- `loadNavaiFunctions(...)`
- `useWebVoiceAgent(...)`
- `resolveNavaiRoute(...)`
- `getNavaiRoutePromptLines(...)`

Useful types:

- `NavaiRoute`
- `NavaiFunctionDefinition`
- `NavaiFunctionsRegistry`
- `NavaiBackendFunctionDefinition`
- `NavaiBackendSpeechConfig`
- `UseWebVoiceAgentOptions`

## Hybrid Speech Mode

When backend returns `speech.provider: "elevenlabs"`:

- `useWebVoiceAgent` updates the Realtime session to use `output_modalities: ["text"]`.
- assistant final text is sent to `backendClient.synthesizeSpeech(...)`.
- playback happens locally in the browser with the synthesized ElevenLabs audio.

## Tool Model and Behavior

`buildNavaiAgent` always registers:

- `navigate_to`
- `execute_app_function`

When runtime agents are available:

- the first configured or explicit primary agent becomes the main realtime agent.
- other configured agents become specialist `RealtimeAgent` instances.
- the main agent delegates with `handoffs`, following the OpenAI Agents SDK multi-agent model.

Optional direct alias tools:

- for each allowed function name, a direct tool can be created.
- reserved names are never used as direct tools (`navigate_to`, `execute_app_function`).
- invalid tool ids are skipped (kept accessible via `execute_app_function`).

Execution precedence:

1. Try frontend/local function first.
2. If missing, try backend function.
3. If both exist with same name, frontend wins and backend is ignored with warning.

## Dynamic Function Loading Internals

`loadNavaiFunctions` supports module export shapes:

1. Exported function.
2. Exported class (instance methods become functions).
3. Exported object (callable members become functions).

Name normalization rules:

- snake_case lowercase.
- invalid chars removed.
- collisions are renamed with suffixes (`_2`, `_3`, ...).

Argument mapping rules:

- `payload.args` or `payload.arguments` as direct args.
- else `payload.value` as first arg.
- else full payload as first arg.
- context appended when arity indicates one more argument.

For class methods:

- constructor args: `payload.constructorArgs`.
- method args: `payload.methodArgs`.

## Runtime Resolution and Env Precedence

`resolveNavaiFrontendRuntimeConfig` input priority:

1. Explicit function args.
2. Env object keys.
3. Package defaults.

Keys used:

- `NAVAI_ROUTES_FILE`
- `NAVAI_FUNCTIONS_FOLDERS`
- `NAVAI_AGENTS_FOLDERS`
- `NAVAI_REALTIME_MODEL`

Defaults:

- routes file: `src/ai/routes.ts`
- functions folder: `src/ai/functions-modules`

Multi-agent layout:

- agent root: `src/ai`
- agents env: `NAVAI_AGENTS_FOLDERS=main,support,sales,food`
- per-agent files live in `src/ai/<agent>/...`
- optional per-agent config file: `src/ai/<agent>/agent.config.ts`
- only the first level under `src/ai/` defines the agent key
- deeper folders are optional organization for that same agent

Multi-agent layout:

- agent root: `src/ai`
- agents env: `NAVAI_AGENTS_FOLDERS=main,support,sales`
- per-agent files live in `src/ai/<agent>/...`
- optional per-agent config file: `src/ai/<agent>/agent.config.ts`

Example:

```text
src/ai/
  main/
    agent.config.ts
    session/logout.fn.ts
    support/open-help.fn.ts
  support/
    agent.config.ts
    system/ai-service.ts
  sales/
    agent.config.ts
    utils/math.ts
  food/
    agent.config.ts
    comida_rapida/hamburguesa.ts
```

Notes:

- `src/ai/main/session/logout.fn.ts` belongs to the `main` agent.
- `src/ai/main/support/open-help.fn.ts` also belongs to the `main` agent.
- folders like `session`, `support`, `help`, or `utils` inside an agent are optional.

Path matcher formats:

- folder: `src/ai/functions-modules`
- recursive: `src/ai/functions-modules/...`
- wildcard: `src/features/*/voice-functions`
- explicit file: `src/ai/functions-modules/secret.ts`
- CSV list: `a,b,c`

Fallback behavior:

- if configured folders match no modules, warning is emitted.
- resolver falls back to default functions folder.

When `NAVAI_AGENTS_FOLDERS` is present and `NAVAI_FUNCTIONS_FOLDERS` points to a folder root such as `src/ai`, the resolver only includes modules inside `src/ai/<agent>/...` for the configured agents.

For browser realtime multi-agent orchestration, `buildNavaiAgent` currently wires specialist agents with `handoffs` inside the shared `RealtimeSession`.

## Backend Client Behavior

`createNavaiBackendClient` base URL priority:

1. `apiBaseUrl` option.
2. `env.NAVAI_API_URL`.
3. fallback `http://localhost:3000`.

Methods:

- `createClientSecret(input?)`
- `synthesizeSpeech({ text, ... })`
- `listFunctions()`
- `executeFunction({ functionName, payload })`

Error handling:

- network/HTTP failures throw for create/execute.
- function listing returns warnings and empty list on failures.
- `createClientSecret()` returns `{ value, expires_at, speech }`, where `speech.provider` is `openai` or `elevenlabs`.

## Generated Module Loader CLI

This package ships:

- `navai-generate-web-loaders`

Default command behavior:

1. Reads `.env` and process env.
2. Resolves `NAVAI_FUNCTIONS_FOLDERS` and `NAVAI_ROUTES_FILE`.
3. Selects modules only from configured function paths.
4. Optionally includes configured route module if it differs from default route module.
5. Writes `src/ai/generated-module-loaders.ts`.

Manual usage:

```bash
navai-generate-web-loaders
```

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

Postinstall script can auto-add missing scripts in consumer app:

- `generate:module-loaders` -> `navai-generate-web-loaders`
- `predev` -> `npm run generate:module-loaders`
- `prebuild` -> `npm run generate:module-loaders`
- `pretypecheck` -> `npm run generate:module-loaders`
- `prelint` -> `npm run generate:module-loaders`

Rules:

- only missing scripts are added.
- existing scripts are never overwritten.

Disable auto setup:

- `NAVAI_SKIP_AUTO_SETUP=1`
- or `NAVAI_SKIP_FRONTEND_AUTO_SETUP=1`

Manual setup runner:

```bash
npx navai-setup-voice-frontend
```

## Integration Examples

Imperative integration:

```ts
import { RealtimeSession } from "@openai/agents/realtime";
import { buildNavaiAgent, createNavaiBackendClient } from "@navai/voice-frontend";
import { NAVAI_ROUTE_ITEMS } from "./ai/routes";
import { NAVAI_WEB_MODULE_LOADERS } from "./ai/generated-module-loaders";

const backend = createNavaiBackendClient({ apiBaseUrl: "http://localhost:3000" });
const secret = await backend.createClientSecret();
const backendList = await backend.listFunctions();

const { agent, warnings } = await buildNavaiAgent({
  navigate: (path) => router.navigate(path),
  routes: NAVAI_ROUTE_ITEMS,
  functionModuleLoaders: NAVAI_WEB_MODULE_LOADERS,
  backendFunctions: backendList.functions,
  executeBackendFunction: backend.executeFunction
});

warnings.forEach((w) => console.warn(w));

const session = new RealtimeSession(agent);
await session.connect({ apiKey: secret.value });
```

React hook integration:

```ts
import { useWebVoiceAgent } from "@navai/voice-frontend";
import { NAVAI_WEB_MODULE_LOADERS } from "./ai/generated-module-loaders";
import { NAVAI_ROUTE_ITEMS } from "./ai/routes";

const voice = useWebVoiceAgent({
  navigate: (path) => router.navigate(path),
  moduleLoaders: NAVAI_WEB_MODULE_LOADERS,
  defaultRoutes: NAVAI_ROUTE_ITEMS,
  env: import.meta.env as Record<string, string | undefined>
});
```

## Operational Notes

- warnings are emitted with `console.warn` from runtime, backend list, and agent builder.
- unknown function execution returns structured `ok: false` payload.
- if route module fails to load or has invalid shape, resolver falls back to default routes.

## Related Docs

- Spanish version: `README.es.md`
- English version: `README.en.md`
- Backend package: `../voice-backend/README.md`
- Mobile package: `../voice-mobile/README.md`
- Playground Web: `../../apps/playground-web/README.md`
- Playground API: `../../apps/playground-api/README.md`
