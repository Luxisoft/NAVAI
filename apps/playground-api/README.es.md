# @navai/playground-api

<p align="center">
  <a href="./README.es.md"><img alt="Spanish" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="../playground-web/README.es.md"><img alt="Playground Web ES" src="https://img.shields.io/badge/Playground%20Web-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-web/README.en.md"><img alt="Playground Web EN" src="https://img.shields.io/badge/Playground%20Web-EN-1D9A6C?style=for-the-badge"></a>
  <a href="../playground-mobile/README.es.md"><img alt="Playground Mobile ES" src="https://img.shields.io/badge/Playground%20Mobile-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-mobile/README.en.md"><img alt="Playground Mobile EN" src="https://img.shields.io/badge/Playground%20Mobile-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="../../packages/voice-backend/README.md"><img alt="Voice Backend Docs" src="https://img.shields.io/badge/Voice%20Backend-Docs-ff9023?style=for-the-badge"></a>
  <a href="../../packages/voice-frontend/README.md"><img alt="Voice Frontend Docs" src="https://img.shields.io/badge/Voice%20Frontend-Docs-146EF5?style=for-the-badge"></a>
  <a href="../../packages/voice-mobile/README.md"><img alt="Voice Mobile Docs" src="https://img.shields.io/badge/Voice%20Mobile-Docs-0B8F6A?style=for-the-badge"></a>
</p>

Backend Express de ejemplo para:

- crear `client_secret` de Realtime
- exponer funciones backend dinamicas para tools

## Inicio rapido

1. Instala dependencias desde la raiz:

```bash
npm install
```

2. Crea `.env`:

```powershell
Copy-Item .env.example .env
```

3. Configura valores minimos en `.env`:

```env
OPENAI_API_KEY=sk-...
OPENAI_REALTIME_MODEL=gpt-realtime
NAVAI_TTS_PROVIDER=openai
ELEVENLABS_API_KEY=el-...
ELEVENLABS_VOICE_ID=voice_...
NAVAI_FUNCTIONS_FOLDERS=src/ai/...
NAVAI_CORS_ORIGIN=http://localhost:5173,http://localhost:5174
PORT=3000
```

4. Ejecuta la API:

```bash
npm run dev --workspace @navai/playground-api
```

Atajo: desde la raiz, `npm run dev` levanta API + Web.

## Endpoints

- `GET /health`
  - respuesta: `{ "ok": true }`
- `POST /navai/realtime/client-secret`
  - respuesta: `{ "value": "ek_...", "expires_at": 1730000000 }`
- `GET /navai/functions`
  - respuesta: `{ "items": [...], "warnings": [...] }`
- `POST /navai/functions/execute`
  - ejecuta una funcion backend por nombre.

### Body opcional de `POST /navai/realtime/client-secret`

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

Notas:

- Si backend tiene `OPENAI_API_KEY`, esa key gana siempre.
- `apiKey` en request solo se usa como fallback cuando backend no tiene key.
- `language`, `voiceAccent` y `voiceTone` se agregan a las instrucciones de sesion.

### Body de `POST /navai/functions/execute`

```json
{
  "function_name": "secret_password",
  "payload": { "args": ["abc"] }
}
```

## Variables de entorno

- `OPENAI_API_KEY`: key de servidor.
- `OPENAI_REALTIME_MODEL`: default `gpt-realtime`.
- `OPENAI_REALTIME_VOICE`: default `marin`.
- `OPENAI_REALTIME_INSTRUCTIONS`: instrucciones base.
- `OPENAI_REALTIME_LANGUAGE`: idioma de salida (se inyecta en instrucciones).
- `OPENAI_REALTIME_VOICE_ACCENT`: acento de voz (se inyecta en instrucciones).
- `OPENAI_REALTIME_VOICE_TONE`: tono de voz (se inyecta en instrucciones).
- `OPENAI_REALTIME_CLIENT_SECRET_TTL`: segundos (`10-7200`).
- `NAVAI_TTS_PROVIDER`: `openai|elevenlabs`. Usa `elevenlabs` para activar el modo hibrido.
- `ELEVENLABS_API_KEY`: key de ElevenLabs para sintesis.
- `ELEVENLABS_BASE_URL`: default `https://api.elevenlabs.io`.
- `ELEVENLABS_VOICE_ID`: voz obligatoria cuando `NAVAI_TTS_PROVIDER=elevenlabs`.
- `ELEVENLABS_MODEL_ID`: modelo TTS opcional.
- `ELEVENLABS_OUTPUT_FORMAT`: default `mp3_44100_128`.
- `ELEVENLABS_OPTIMIZE_STREAMING_LATENCY`: entero `0-4`.
- `ELEVENLABS_STABILITY`: float `0-1`.
- `ELEVENLABS_SIMILARITY_BOOST`: float `0-1`.
- `ELEVENLABS_STYLE`: float `0-1`.
- `ELEVENLABS_USE_SPEAKER_BOOST`: `true|false`.
- `NAVAI_FUNCTIONS_FOLDERS`: rutas para auto-cargar funciones backend (CSV, `...`, `*`).
- `NAVAI_FUNCTIONS_BASE_DIR`: base dir opcional para resolver rutas de funciones.
- `NAVAI_CORS_ORIGIN`: origenes CORS permitidos (CSV).
- `NAVAI_ALLOW_FRONTEND_API_KEY`: `true|false`.
- `PORT`: puerto HTTP.

## Estructura relevante

- `src/server.ts`: setup Express, CORS y errores.
- `src/ai/**`: funciones backend que se cargan como tools (segun `NAVAI_FUNCTIONS_FOLDERS`).
