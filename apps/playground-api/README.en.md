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

Sample Express backend for:

- creating Realtime `client_secret`
- exposing dynamic backend functions for tools

## Quick start

1. Install dependencies from repo root:

```bash
npm install
```

2. Create `.env`:

```powershell
Copy-Item .env.example .env
```

3. Set minimal values in `.env`:

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

4. Run API:

```bash
npm run dev --workspace @navai/playground-api
```

Shortcut: from root, `npm run dev` starts API + Web.

## Endpoints

- `GET /health`
  - response: `{ "ok": true }`
- `POST /navai/realtime/client-secret`
  - response: `{ "value": "ek_...", "expires_at": 1730000000 }`
- `GET /navai/functions`
  - response: `{ "items": [...], "warnings": [...] }`
- `POST /navai/functions/execute`
  - executes one backend function by name.

### Optional body for `POST /navai/realtime/client-secret`

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

Notes:

- If backend has `OPENAI_API_KEY`, that key always wins.
- Request `apiKey` is only used as fallback when backend key is missing.
- `language`, `voiceAccent`, and `voiceTone` are appended to session instructions.

### Body for `POST /navai/functions/execute`

```json
{
  "function_name": "secret_password",
  "payload": { "args": ["abc"] }
}
```

## Environment variables

- `OPENAI_API_KEY`: server key.
- `OPENAI_REALTIME_MODEL`: default `gpt-realtime`.
- `OPENAI_REALTIME_VOICE`: default `marin`.
- `OPENAI_REALTIME_INSTRUCTIONS`: base instructions.
- `OPENAI_REALTIME_LANGUAGE`: output language (injected into instructions).
- `OPENAI_REALTIME_VOICE_ACCENT`: voice accent (injected into instructions).
- `OPENAI_REALTIME_VOICE_TONE`: voice tone (injected into instructions).
- `OPENAI_REALTIME_CLIENT_SECRET_TTL`: seconds (`10-7200`).
- `NAVAI_TTS_PROVIDER`: `openai|elevenlabs`. Use `elevenlabs` to enable the hybrid mode.
- `ELEVENLABS_API_KEY`: ElevenLabs API key for synthesis.
- `ELEVENLABS_BASE_URL`: default `https://api.elevenlabs.io`.
- `ELEVENLABS_VOICE_ID`: required voice when `NAVAI_TTS_PROVIDER=elevenlabs`.
- `ELEVENLABS_MODEL_ID`: optional TTS model.
- `ELEVENLABS_OUTPUT_FORMAT`: default `mp3_44100_128`.
- `ELEVENLABS_OPTIMIZE_STREAMING_LATENCY`: integer `0-4`.
- `ELEVENLABS_STABILITY`: float `0-1`.
- `ELEVENLABS_SIMILARITY_BOOST`: float `0-1`.
- `ELEVENLABS_STYLE`: float `0-1`.
- `ELEVENLABS_USE_SPEAKER_BOOST`: `true|false`.
- `NAVAI_FUNCTIONS_FOLDERS`: backend function paths (CSV, `...`, `*`).
- `NAVAI_FUNCTIONS_BASE_DIR`: optional base dir for function path resolution.
- `NAVAI_CORS_ORIGIN`: allowed CORS origins (CSV).
- `NAVAI_ALLOW_FRONTEND_API_KEY`: `true|false`.
- `PORT`: HTTP port.

## Relevant structure

- `src/server.ts`: Express setup, CORS and errors.
- `src/ai/**`: backend functions loaded as tools (based on `NAVAI_FUNCTIONS_FOLDERS`).
