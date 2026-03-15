# @navai/voice-mobile

<p>
  <a href="./README.es.md"><img alt="Idioma Espanol" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="Language English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

Paquete mobile para ejecutar agentes de voz Navai en aplicaciones React Native.

Entrega un stack completo para:

1. Obtener `client_secret` desde backend.
2. Negociacion WebRTC para Realtime.
3. Tools mobile basadas en rutas y funciones permitidas.
4. Carga dinamica de funciones locales.
5. Parseo de tool calls realtime y emision de eventos de resultado.
6. Ciclo de vida React para microfono, transporte y sesion.
7. Reproduccion local opcional para salida hibrida con ElevenLabs.

## Instalacion

```bash
npm install @navai/voice-mobile
npm install react react-native react-native-webrtc
```

`react-native-webrtc` es dependencia peer y debe existir en la app consumidora.

## Arquitectura General

El paquete esta organizado en capas:

1. Capa de runtime/config
- `src/runtime.ts`
- resuelve env, URL de API, archivo de rutas, filtros de funciones, agentes y override de modelo.

2. Capa de funciones
- `src/functions.ts`
- carga modulos locales y convierte exports en definiciones de funcion ejecutables.

3. Capa runtime del agente
- `src/agent.ts`
- construye instrucciones mobile y esquemas de tools.
- ejecuta `navigate_to` y `execute_app_function`.
- parsea tool calls desde eventos Realtime.
- construye eventos de respuesta para `function_call_output`.

4. Capa puente backend
- `src/backend.ts`
- cliente API para rutas Navai backend.

5. Capa de orquestacion de sesion
- `src/session.ts`
- coordina backend client + transporte.
- maneja inicio/parada, precarga de funciones y forwarding de eventos.

6. Capa de transporte
- `src/transport.ts`
- contrato de interfaz para transportes custom.
- `src/react-native-webrtc.ts` implementacion WebRTC para React Native.

7. Capa de integracion React
- `src/useMobileVoiceAgent.ts`
- hook que combina runtime, funciones locales, tools backend, permisos y estado de sesion.

## Flujo End-to-End

Flujo tipico con hook:

1. La app resuelve runtime config con module loaders generados.
2. El hook carga dinamicamente `react-native-webrtc`.
3. El hook carga el registry de funciones locales desde module loaders.
4. En `start()`:
- valida el estado del runtime.
- solicita permiso de microfono en Android cuando aplica.
- crea backend client y transporte WebRTC.
- inicia sesion de voz mobile (client secret + connect del transporte).
- construye runtime del agente mobile (instrucciones + tools).
- envia `session.update` con tools e instrucciones.
- cuando `speech.provider=elevenlabs`, la sesion se configura para recibir salida en texto y el hook puede sintetizar/reproducir audio localmente.
5. Durante la conversacion:
- se parsean eventos Realtime buscando tool calls.
- se emiten resultados via `conversation.item.create` y `response.create`.
6. En `stop()`:
- desconecta transporte.
- limpia referencias locales y mapas de tool calls pendientes.

## API Publica

Exports principales:

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

Tipos importantes:

- `NavaiRoute`
- `NavaiFunctionDefinition`
- `NavaiRealtimeTransport`
- `NavaiMobileVoiceSession`
- `NavaiBackendSpeechConfig`
- `NavaiMobileSpeechPlayer`
- `ResolveNavaiMobileApplicationRuntimeConfigResult`
- `UseMobileVoiceAgentTransportOptions`

## Diseno del Runtime de Tools

La superficie de tools mobile es estable:

- `navigate_to`
- `execute_app_function`

Comportamiento de ejecucion:

1. `navigate_to`
- valida `target`.
- resuelve la ruta con el matcher de rutas.
- ejecuta `navigate(path)`.

2. `execute_app_function`
- valida `function_name`.
- intenta funcion local primero.
- si no existe local, hace fallback a backend.

Fallback de compatibilidad:

- si el modelo llama directamente una funcion como nombre de tool, se enruta como `execute_app_function`.

## Manejo de Eventos Realtime de Tools

`extractNavaiRealtimeToolCalls` entiende varias familias de eventos:

- `response.function_call_arguments.done`
- `response.output_item.done`
- `response.output_item.added`
- `conversation.item.created`
- `conversation.item.added`
- `conversation.item.done`
- `conversation.item.retrieved`
- `response.done`

Los tool calls parciales se ignoran hasta tener estado completado.

`buildNavaiRealtimeToolResultEvents` emite dos eventos:

1. `conversation.item.create` con `function_call_output`.
2. `response.create` para reanudar generacion del modelo.

## Resolucion de Runtime y Entorno

Prioridad de `resolveNavaiMobileRuntimeConfig`:

1. Opciones explicitas.
2. Valores del objeto env.
3. Defaults.

Claves:

- `NAVAI_FUNCTIONS_FOLDERS`
- `NAVAI_AGENTS_FOLDERS`
- `NAVAI_ROUTES_FILE`
- `NAVAI_REALTIME_MODEL`

Defaults:

- archivo de rutas: `src/ai/routes.ts`
- carpeta de funciones: `src/ai/functions-modules`

Formatos de matcher:

- carpeta
- carpeta recursiva (`/...`)
- wildcard (`*`)
- archivo explicito
- lista CSV

Comportamiento fallback:

- si rutas configuradas no matchean archivos, emite warning.
- hace fallback a carpeta default.

`resolveNavaiMobileApplicationRuntimeConfig` tambien resuelve:

- `apiBaseUrl` con prioridad:
1) `apiBaseUrl` explicita
2) `env.NAVAI_API_URL`
3) `defaultApiBaseUrl` explicita
4) default `http://localhost:3000`
- warning cuando el map de module loaders generados esta vacio.

`resolveNavaiMobileEnv` permite combinar varias fuentes env (por ejemplo Expo `extra`, `process.env`, objeto custom).

## Contrato del Backend Client

`createNavaiMobileBackendClient` llama:

- `POST /navai/realtime/client-secret`
- `POST /navai/speech/synthesize`
- `GET /navai/functions`
- `POST /navai/functions/execute`

Prioridad de base URL:

1. Opcion `apiBaseUrl`.
2. `env.NAVAI_API_URL`.
3. fallback `http://localhost:3000`.

`listFunctions` retorna warnings en vez de lanzar en muchos errores de parseo/red.

`createClientSecret` y `executeFunction` lanzan error cuando hay fallos de request o respuestas invalidas.

`createClientSecret()` retorna `{ value, expires_at, speech }`, donde `speech.provider` puede ser `openai` o `elevenlabs`.

## Detalle del Orquestador de Sesion

Responsabilidades de `createNavaiMobileVoiceSession`:

1. Cache de listado de funciones.
2. Transiciones de estado (`idle`, `connecting`, `connected`, `error`).
3. Flujo de inicio:
- precarga opcional de funciones backend.
- solicitud de client secret.
- `connect` del transporte con `clientSecret` y `model` opcional.
4. Flujo de parada:
- desconexion de transporte.
5. Helper para enviar eventos realtime (requiere que el transporte implemente `sendEvent`).

## Detalle del Transporte React Native WebRTC

Comportamiento por defecto de `createReactNativeWebRtcTransport`:

- endpoint realtime: `https://api.openai.com/v1/realtime/calls`
- modelo default: `gpt-realtime`
- crea `RTCPeerConnection`
- abre data channel `oai-events`
- captura microfono con `mediaDevices.getUserMedia`
- negocia SDP con OpenAI
- espera apertura del data channel antes de resolver `connect`

Comportamiento de resiliencia:

- mantiene estado del transporte (`idle`, `connecting`, `connected`, `error`, `closed`)
- propaga errores de conexion/data channel via callbacks
- limpia tracks, canal y peer connection al desconectar
- soporta volumen remoto configurable usando `_setVolume` cuando existe

## Internos del Hook React

`useMobileVoiceAgent` agrega comportamiento de app:

- solicitud de permiso de microfono en Android.
- `require("react-native-webrtc")` dinamico.
- cola de tool calls pendientes durante inicializacion de runtime/sesion.
- deduplicacion de tool call ids ya procesados.
- envio automatico de `session.update` despues de iniciar sesion.
- `transportOptions` opcional para reenviar `rtcConfiguration`, `audioConstraints` y `remoteAudioTrackVolume`.
- `speechPlayer` opcional para reproduccion local cuando el backend usa TTS hibrido con ElevenLabs.

Estados del hook:

- `idle`
- `connecting`
- `connected`
- `error`

Estado de voz del agente expuesto por el hook:

- `agentVoiceState`: `idle | speaking`
- `isAgentSpeaking`: `boolean`

`agentVoiceState` se infiere desde eventos realtime de audio (`response.output_audio.delta`, `response.output_audio.done`, `output_audio_buffer.started`, `output_audio_buffer.stopped`, `response.done`).

## Modo de voz hibrido

Cuando el backend devuelve `speech.provider: "elevenlabs"`:

- `useMobileVoiceAgent` actualiza la sesion Realtime para pedir `output_modalities: ["text"]`.
- el texto final del asistente se envia a `backendClient.synthesizeSpeech(...)`.
- el audio sintetizado se reproduce mediante el `speechPlayer` provisto por la app.
- si no se pasa `speechPlayer`, el hook registra un warning y omite la reproduccion local.

## CLI de Generacion de Loaders

Este paquete incluye:

- `navai-generate-mobile-loaders`

Comportamiento por defecto:

1. Lee `NAVAI_FUNCTIONS_FOLDERS` y `NAVAI_ROUTES_FILE` desde env del proceso o `.env`.
2. Escanea `src/` para archivos de codigo.
3. Selecciona solo modulos que matchean las rutas de funciones configuradas.
4. Incluye el modulo de rutas.
5. Incluye archivos referenciados en el archivo de rutas mediante literales `src/...` (por ejemplo pantallas).
6. Escribe `src/ai/generated-module-loaders.ts`.

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

El `postinstall` puede agregar scripts faltantes:

- `generate:ai-modules` -> `navai-generate-mobile-loaders`
- `predev` -> `npm run generate:ai-modules`
- `preandroid` -> `npm run generate:ai-modules`
- `preios` -> `npm run generate:ai-modules`
- `pretypecheck` -> `npm run generate:ai-modules`

Reglas:

- solo agrega scripts faltantes.
- nunca sobreescribe scripts existentes.

Desactivar auto setup:

- `NAVAI_SKIP_AUTO_SETUP=1`
- o `NAVAI_SKIP_MOBILE_AUTO_SETUP=1`

Ejecutor manual:

```bash
npx navai-setup-voice-mobile
```

## Ejemplos de Integracion

Integracion de bajo nivel:

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

Integracion con hook:

```ts
import { useMobileVoiceAgent } from "@navai/voice-mobile";

const voice = useMobileVoiceAgent({
  runtime,
  runtimeLoading,
  runtimeError,
  navigate: (path) => navigation.navigate(path as never)
});
```

React Native CLI Android (configuracion opt-in del transporte):

En Expo deje `transportOptions` sin definir si el comportamiento actual ya funciona en su app. En React Native CLI bare sobre Android puede activar una configuracion explicita del transporte WebRTC sin cambiar el comportamiento de Expo.

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

## Rutas Backend Esperadas

- `POST /navai/realtime/client-secret`
- `POST /navai/speech/synthesize`
- `GET /navai/functions`
- `POST /navai/functions/execute`

Estas rutas las puede proveer `registerNavaiExpressRoutes` desde `@navai/voice-backend`.

## Documentacion Relacionada

- Version en espanol: `README.es.md`
- Version en ingles: `README.en.md`
- Paquete backend: `../voice-backend/README.md`
- Paquete frontend: `../voice-frontend/README.md`
- Playground Mobile: `../../apps/playground-mobile/README.md`
- Playground API: `../../apps/playground-api/README.md`
