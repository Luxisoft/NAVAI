# @navai/voice-backend

<p>
  <a href="./README.es.md"><img alt="Idioma Espanol" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="Language English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

Paquete backend para aplicaciones de voz con Navai.

Este paquete resuelve tres responsabilidades backend:

1. Generar `client_secret` efimero y seguro para OpenAI Realtime.
2. Hacer proxy opcional de sintesis de voz con ElevenLabs para salida hibrida.
3. Descubrir, validar, exponer y ejecutar tools backend desde tu codigo.

## Instalacion

```bash
npm install @navai/voice-backend
```

`express` es dependencia peer.

## Arquitectura General

La arquitectura runtime tiene tres capas:

1. `src/index.ts`
Capa de entrada. Expone API publica, helpers de client secret y registro de rutas Express.

2. `src/runtime.ts`
Capa de descubrimiento. Resuelve `NAVAI_FUNCTIONS_FOLDERS` + `NAVAI_AGENTS_FOLDERS`, escanea archivos, aplica reglas de match de rutas y construye module loaders.

3. `src/functions.ts`
Capa de ejecucion. Importa modulos seleccionados, transforma exports a tools normalizadas y las ejecuta de forma controlada.

Flujo de extremo a extremo:

1. Frontend/mobile llama `POST /navai/realtime/client-secret`.
2. Backend valida opciones y politica de API key.
3. Backend llama OpenAI `POST https://api.openai.com/v1/realtime/client_secrets`.
4. Cuando `speech.provider=elevenlabs`, frontend/mobile llama despues `POST /navai/speech/synthesize` con el texto final del asistente.
5. Frontend/mobile llama `GET /navai/functions` para descubrir tools.
6. El agente llama `POST /navai/functions/execute` con `function_name` y `payload`.
7. Backend ejecuta solo nombres permitidos del registry cargado.

Nota sobre estado de voz del agente:

- El backend no detecta directamente cuando el asistente empieza/termina de hablar, porque la sesion Realtime de audio vive en cliente.
- Ese estado se expone en `@navai/voice-frontend`, `@navai/voice-mobile` y en el widget de WordPress via eventos frontend.

## API Publica

Helpers de client secret:

- `getNavaiVoiceBackendOptionsFromEnv(env?)`
- `createRealtimeClientSecret(options, request?)`
- `createExpressClientSecretHandler(options)`
- `synthesizeSpeech(options, request?)`

Integracion Express:

- `registerNavaiExpressRoutes(app, options?)`

Helpers runtime dinamico:

- `resolveNavaiBackendRuntimeConfig(options?)`
- `loadNavaiFunctions(functionModuleLoaders)`

Tipos exportados importantes:

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

## Comportamiento Detallado de Rutas

`registerNavaiExpressRoutes` registra por defecto:

- `POST /navai/realtime/client-secret`
- `POST /navai/speech/synthesize`
- `GET /navai/functions`
- `POST /navai/functions/execute`

Puedes cambiar paths con:

- `clientSecretPath`
- `speechSynthesizePath`
- `functionsListPath`
- `functionsExecutePath`

`includeFunctionsRoutes` controla si se montan rutas `/navai/functions*`.

Detalle importante:

- runtime de tools se carga de forma lazy una sola vez y queda cacheado en memoria.
- la primera llamada a listar/ejecutar construye el registry.
- luego no hay recarga automatica de archivos hasta reiniciar proceso.

## Pipeline de Client Secret

Comportamiento de `createRealtimeClientSecret`:

1. Valida opciones.
- `clientSecretTtlSeconds` debe estar entre `10` y `7200`.
- si falta key backend y no se permite key desde request, lanza error.

2. Resuelve API key con prioridad estricta.
- `openaiApiKey` backend siempre gana.
- `apiKey` del request solo es fallback cuando backend no tiene key.

3. Construye payload de sesion.
- `model` default: `gpt-realtime`
- `voice` default: `marin`
- `instructions` incluye base mas lineas opcionales de idioma/acento/tono.
- cuando `NAVAI_TTS_PROVIDER=elevenlabs`, el backend solicita salida Realtime solo en texto con `output_modalities: ["text"]`.

4. Llama endpoint de client secret de OpenAI y retorna:
- `value`
- `expires_at`
- `speech.provider`

Body aceptado por la ruta:

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

Respuesta:

```json
{
  "value": "ek_...",
  "expires_at": 1730000000,
  "speech": {
    "provider": "elevenlabs"
  }
}
```

Body de sintesis de voz:

```json
{
  "text": "Hola, soy NAVAI.",
  "voiceId": "voice_id_opcional",
  "modelId": "model_id_opcional"
}
```

Respuesta de sintesis:

```json
{
  "provider": "elevenlabs",
  "mimeType": "audio/mpeg",
  "audioBase64": "SUQz..."
}
```

## Carga Dinamica de Funciones Interna

`resolveNavaiBackendRuntimeConfig` lee:

- primero opciones explicitas.
- luego env `NAVAI_FUNCTIONS_FOLDERS`.
- luego fallback `src/ai/functions-modules`.

Formatos soportados en `NAVAI_FUNCTIONS_FOLDERS`:

- carpeta: `src/ai/functions-modules`
- carpeta recursiva: `src/ai/functions-modules/...`
- wildcard: `src/features/*/voice-functions`
- archivo explicito: `src/ai/functions-modules/secret.ts`
- lista CSV: `src/ai/functions-modules,...`

Comportamiento del scanner:

- escanea recursivamente desde `baseDir`.
- incluye extensiones de `includeExtensions` (default `ts/js/mjs/cjs/mts/cts`).
- excluye patrones de `exclude` (por defecto ignora `node_modules`, `dist`, rutas ocultas).
- ignora `*.d.ts`.

Comportamiento fallback:

- si rutas configuradas no matchean nada, emite warning.
- el loader cae a `src/ai/functions-modules`.

## Reglas de Mapeo Export a Tool

`loadNavaiFunctions` transforma estos shapes:

1. Funcion exportada.
- crea una tool.

2. Clase exportada.
- crea una tool por metodo de instancia callable.
- args constructor desde `payload.constructorArgs`.
- args metodo desde `payload.methodArgs`.

3. Objeto exportado.
- crea una tool por miembro callable.

Normalizacion de nombres:

- convierte a snake_case en minusculas.
- elimina caracteres no seguros.
- en colision agrega sufijo (`_2`, `_3`, ...).
- emite warning cuando renombra.

Resolucion de argumentos al invocar:

- si existe `payload.args`, se usa como lista de argumentos.
- si no, y existe `payload.value`, ese es primer argumento.
- si no, y payload tiene claves, payload completo es primer argumento.
- si la aridad espera un argumento mas, agrega contexto al final.

En `/navai/functions/execute`, contexto incluye `{ req }`.

## Contratos HTTP de Tools

Respuesta `GET /navai/functions`:

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

Body `POST /navai/functions/execute`:

```json
{
  "function_name": "secret_password",
  "payload": {
    "args": ["abc"]
  }
}
```

Respuesta de exito:

```json
{
  "ok": true,
  "function_name": "secret_password",
  "source": "src/ai/functions-modules/security.ts#default",
  "result": "..."
}
```

Respuesta por funcion desconocida:

```json
{
  "error": "Unknown or disallowed function.",
  "available_functions": ["..."]
}
```

## Configuracion y Reglas de Entorno

Claves env principales:

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
- `NAVAI_AGENTS_FOLDERS`
- `NAVAI_FUNCTIONS_BASE_DIR`

Politica API key desde env:

- si existe `OPENAI_API_KEY`, keys desde request quedan bloqueadas salvo `NAVAI_ALLOW_FRONTEND_API_KEY=true`.
- si falta `OPENAI_API_KEY`, se permite key desde request como fallback.

## Ejemplo Minimo de Integracion

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

## Guia Operativa

Recomendaciones para produccion:

- mantener `OPENAI_API_KEY` solo en servidor.
- mantener `ELEVENLABS_API_KEY` solo en servidor.
- mantener `NAVAI_ALLOW_FRONTEND_API_KEY=false` en produccion.
- restringir CORS en tu capa app.
- monitorear y exponer `warnings` de runtime y registry.
- reiniciar backend si cambias archivos de funciones y necesitas registry actualizado.

Errores comunes:

- `Missing openaiApiKey in NavaiVoiceBackendOptions.`
- `Passing apiKey from request is disabled. Set allowApiKeyFromRequest=true to enable it.`
- `clientSecretTtlSeconds must be between 10 and 7200.`

## Documentacion Relacionada

- Index del paquete: `README.md`
- Version EN: `README.en.md`
- Paquete frontend: `../voice-frontend/README.md`
- Paquete mobile: `../voice-mobile/README.md`
- Playground API: `../../apps/playground-api/README.md`
- Playground Web: `../../apps/playground-web/README.md`
- Playground Mobile: `../../apps/playground-mobile/README.md`
