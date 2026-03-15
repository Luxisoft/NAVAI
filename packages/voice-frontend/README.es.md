# @navai/voice-frontend

<p>
  <a href="./README.es.md"><img alt="Idioma Espanol" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="Language English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

Paquete frontend para construir agentes de voz Navai en aplicaciones web.

El objetivo es evitar boilerplate repetido para:

1. Solicitud de `client_secret` realtime.
2. Tools de navegacion basadas en rutas permitidas.
3. Carga dinamica de funciones locales.
4. Puente opcional con funciones backend.
5. Ciclo de vida React para conectar/desconectar sesion.

## Instalacion

```bash
npm install @navai/voice-frontend @openai/agents zod
npm install react
```

## Arquitectura del Paquete

El paquete esta separado por responsabilidades:

1. `src/backend.ts`
Cliente HTTP para rutas backend:
- `POST /navai/realtime/client-secret`
- `POST /navai/speech/synthesize`
- `GET /navai/functions`
- `POST /navai/functions/execute`

2. `src/runtime.ts`
Resolver de runtime para:
- seleccion de modulo de rutas
- filtrado de modulos de funciones por `NAVAI_FUNCTIONS_FOLDERS`
- descubrimiento de agentes por `NAVAI_AGENTS_FOLDERS`
- override opcional de modelo

3. `src/functions.ts`
Loader de funciones locales:
- importa modulos desde loaders generados
- transforma exports en definiciones de tools normalizadas y ejecutables

4. `src/agent.ts`
Builder del agente:
- crea el `RealtimeAgent` principal
- inyecta tools base (`navigate_to`, `execute_app_function`)
- agrega aliases directos por funcion permitida cuando aplica
- crea agentes especialistas y los conecta con `handoffs`

5. `src/useWebVoiceAgent.ts`
Wrapper de ciclo de vida React:
- construye runtime config
- solicita client secret
- descubre funciones backend
- construye el agente
- abre/cierra `RealtimeSession`

6. `src/routes.ts`
Helpers para resolver texto natural hacia rutas permitidas.

## Flujo Runtime de Punta a Punta

Flujo del hook (`useWebVoiceAgent`):

1. Resuelve runtime config desde `moduleLoaders` + `defaultRoutes` + env/opciones.
2. Crea backend client con `apiBaseUrl` o `NAVAI_API_URL`.
3. En `start()`:
- solicita client secret.
- lee `speech.provider` desde la respuesta backend.
- solicita listado de funciones backend.
- construye agente Navai con funciones locales + backend.
- conecta `RealtimeSession`.
4. En `stop()`:
- cierra sesion y resetea estado.

Maquina de estados expuesta por el hook:

- `idle`
- `connecting`
- `connected`
- `error`

Estado de voz del agente expuesto por el hook:

- `agentVoiceState`: `idle | speaking`
- `isAgentSpeaking`: `boolean`

`agentVoiceState` se actualiza con los eventos realtime `audio_start`, `audio_stopped` y `audio_interrupted`.

## API Publica

Exports principales:

- `buildNavaiAgent(...)`
- `createNavaiBackendClient(...)`
- `resolveNavaiFrontendRuntimeConfig(...)`
- `loadNavaiFunctions(...)`
- `useWebVoiceAgent(...)`
- `resolveNavaiRoute(...)`
- `getNavaiRoutePromptLines(...)`

Tipos utiles:

- `NavaiRoute`
- `NavaiFunctionDefinition`
- `NavaiFunctionsRegistry`
- `NavaiBackendFunctionDefinition`
- `NavaiBackendSpeechConfig`
- `UseWebVoiceAgentOptions`

## Modo de voz hibrido

Cuando el backend devuelve `speech.provider: "elevenlabs"`:

- `useWebVoiceAgent` actualiza la sesion Realtime con `output_modalities: ["text"]`.
- el texto final del asistente se envia a `backendClient.synthesizeSpeech(...)`.
- la reproduccion ocurre localmente en el navegador con el audio sintetizado por ElevenLabs.

## Modelo de Tools y Comportamiento

`buildNavaiAgent` siempre registra:

- `navigate_to`
- `execute_app_function`

Aliases directos opcionales:

- para cada funcion permitida se puede crear una tool directa.
- nombres reservados nunca se exponen como tool directa (`navigate_to`, `execute_app_function`).
- ids invalidos de tool se omiten (la funcion sigue accesible via `execute_app_function`).

Precedencia de ejecucion:

1. Intenta funcion local/frontend.
2. Si no existe, intenta funcion backend.
3. Si ambas tienen el mismo nombre, gana frontend y backend se ignora con warning.

## Internos de Carga Dinamica de Funciones

`loadNavaiFunctions` soporta estos formatos de export:

1. Funcion exportada.
2. Clase exportada (metodos de instancia se vuelven funciones).
3. Objeto exportado (miembros callables se vuelven funciones).

Reglas de normalizacion de nombre:

- snake_case en minusculas.
- elimina caracteres invalidos.
- colisiones se renombran con sufijos (`_2`, `_3`, ...).

Reglas de mapeo de argumentos:

- usa `payload.args` o `payload.arguments` como argumentos directos.
- si no, usa `payload.value` como primer argumento.
- si no, usa payload completo como primer argumento.
- agrega contexto cuando la aridad indica un argumento adicional.

Para metodos de clase:

- args de constructor: `payload.constructorArgs`.
- args del metodo: `payload.methodArgs`.

## Resolucion Runtime y Precedencia de Env

Prioridad de entrada en `resolveNavaiFrontendRuntimeConfig`:

1. Argumentos explicitos de funcion.
2. Claves del objeto env.
3. Defaults del paquete.

Claves usadas:

- `NAVAI_ROUTES_FILE`
- `NAVAI_FUNCTIONS_FOLDERS`
- `NAVAI_AGENTS_FOLDERS`
- `NAVAI_REALTIME_MODEL`

Defaults:

- archivo de rutas: `src/ai/routes.ts`
- carpeta de funciones: `src/ai/functions-modules`

Estructura multiagente:

- raiz de agentes: `src/ai`
- env de agentes: `NAVAI_AGENTS_FOLDERS=main,support,sales,food`
- los archivos por agente viven en `src/ai/<agent>/...`
- archivo opcional por agente: `src/ai/<agent>/agent.config.ts`
- solo el primer nivel debajo de `src/ai/` define la clave del agente
- el resto de carpetas son solo organizacion interna de ese agente

Ejemplo:

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

Notas:

- `src/ai/main/session/logout.fn.ts` pertenece al agente `main`.
- `src/ai/main/support/open-help.fn.ts` tambien pertenece al agente `main`.
- carpetas como `session`, `support`, `help` o `utils` dentro de un agente no son obligatorias.

Formatos aceptados en matcher de rutas:

- carpeta: `src/ai/functions-modules`
- recursivo: `src/ai/functions-modules/...`
- wildcard: `src/features/*/voice-functions`
- archivo explicito: `src/ai/functions-modules/secret.ts`
- CSV: `a,b,c`

Comportamiento fallback:

- si `NAVAI_FUNCTIONS_FOLDERS` no matchea modulos, emite warning.
- hace fallback a carpeta de funciones por defecto.

Cuando `NAVAI_AGENTS_FOLDERS` esta presente y `NAVAI_FUNCTIONS_FOLDERS` apunta a una raiz como `src/ai`, el resolver solo incluye modulos dentro de `src/ai/<agent>/...` para los agentes configurados.

En realtime web, `buildNavaiAgent` conecta actualmente los agentes especialistas usando `handoffs` dentro de la misma `RealtimeSession`.

## Comportamiento del Backend Client

Prioridad de base URL en `createNavaiBackendClient`:

1. Opcion `apiBaseUrl`.
2. `env.NAVAI_API_URL`.
3. Fallback `http://localhost:3000`.

Metodos:

- `createClientSecret(input?)`
- `synthesizeSpeech({ text, ... })`
- `listFunctions()`
- `executeFunction({ functionName, payload })`

Manejo de errores:

- fallos de red/HTTP lanzan error en create/execute.
- el listado de funciones retorna warnings + lista vacia en fallos.
- `createClientSecret()` retorna `{ value, expires_at, speech }`, donde `speech.provider` puede ser `openai` o `elevenlabs`.

## CLI Generador de Module Loaders

Este paquete incluye:

- `navai-generate-web-loaders`

Comportamiento por defecto:

1. Lee `.env` y env del proceso.
2. Resuelve `NAVAI_FUNCTIONS_FOLDERS` y `NAVAI_ROUTES_FILE`.
3. Selecciona modulos solo en rutas de funciones configuradas.
4. Incluye modulo de rutas configurado cuando difiere del modulo por defecto.
5. Escribe `src/ai/generated-module-loaders.ts`.

Uso manual:

```bash
navai-generate-web-loaders
```

Flags utiles:

- `--project-root <path>`
- `--src-root <path>`
- `--output-file <path>`
- `--env-file <path>`
- `--default-functions-folder <path>`
- `--default-routes-file <path>`
- `--type-import <module>`
- `--export-name <identifier>`

## Auto Configuracion al Instalar desde npm

El `postinstall` puede agregar scripts faltantes en el consumidor:

- `generate:module-loaders` -> `navai-generate-web-loaders`
- `predev` -> `npm run generate:module-loaders`
- `prebuild` -> `npm run generate:module-loaders`
- `pretypecheck` -> `npm run generate:module-loaders`
- `prelint` -> `npm run generate:module-loaders`

Reglas:

- solo agrega scripts faltantes.
- nunca sobreescribe scripts existentes.

Desactivar auto setup:

- `NAVAI_SKIP_AUTO_SETUP=1`
- o `NAVAI_SKIP_FRONTEND_AUTO_SETUP=1`

Ejecutor manual de setup:

```bash
npx navai-setup-voice-frontend
```

## Ejemplos de Integracion

Integracion imperativa:

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

Integracion con hook React:

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

## Notas Operativas

- los warnings se emiten con `console.warn` desde runtime, backend list y agent builder.
- una funcion desconocida retorna payload estructurado `ok: false`.
- si el modulo de rutas falla o tiene shape invalido, el resolver hace fallback a rutas por defecto.

## Documentacion Relacionada

- Version en espanol: `README.es.md`
- Version en ingles: `README.en.md`
- Paquete backend: `../voice-backend/README.md`
- Paquete mobile: `../voice-mobile/README.md`
- Playground Web: `../../apps/playground-web/README.md`
- Playground API: `../../apps/playground-api/README.md`
