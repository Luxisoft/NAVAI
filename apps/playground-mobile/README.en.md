# @navai/playground-mobile

<p align="center">
  <a href="./README.es.md"><img alt="Spanish" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.en.md"><img alt="English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="../playground-api/README.es.md"><img alt="Playground API ES" src="https://img.shields.io/badge/Playground%20API-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-api/README.en.md"><img alt="Playground API EN" src="https://img.shields.io/badge/Playground%20API-EN-1D9A6C?style=for-the-badge"></a>
  <a href="../playground-web/README.es.md"><img alt="Playground Web ES" src="https://img.shields.io/badge/Playground%20Web-ES-0A66C2?style=for-the-badge"></a>
  <a href="../playground-web/README.en.md"><img alt="Playground Web EN" src="https://img.shields.io/badge/Playground%20Web-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="../../packages/voice-backend/README.md"><img alt="Voice Backend Docs" src="https://img.shields.io/badge/Voice%20Backend-Docs-ff9023?style=for-the-badge"></a>
  <a href="../../packages/voice-frontend/README.md"><img alt="Voice Frontend Docs" src="https://img.shields.io/badge/Voice%20Frontend-Docs-146EF5?style=for-the-badge"></a>
  <a href="../../packages/voice-mobile/README.md"><img alt="Voice Mobile Docs" src="https://img.shields.io/badge/Voice%20Mobile-Docs-0B8F6A?style=for-the-badge"></a>
</p>

React Native (Expo) playground to test `@navai/voice-mobile` + NAVAI backend with:

- screen navigation
- local tools loaded dynamically from folders
- backend tools discovered by API
- `.env` configuration (no VITE)
- `VoiceNavigator` using `useMobileVoiceAgent` from `@navai/voice-mobile`
- UI that reacts to assistant voice state (`idle`/`speaking`)
- local Expo playback when backend uses ElevenLabs in hybrid mode

## Requirements

- Node.js 20+
- Android Studio + Android SDK
- JDK 23 (Java)
- Android device with USB debugging (or emulator)

## Environment variables

File: `apps/playground-mobile/.env`

```env
NAVAI_API_URL=http://<YOUR_LAN_IP>:3000
NAVAI_FUNCTIONS_FOLDERS=src/ai/functions-modules
NAVAI_ROUTES_FILE=src/ai/routes.ts
```

Notes:

- `NAVAI_FUNCTIONS_FOLDERS`: local mobile tool folder(s).
- `NAVAI_ROUTES_FILE`: routes file the agent can navigate.
- Variables are exposed at runtime via `app.config.js`.
- `generate:ai-modules` uses official CLI from `@navai/voice-mobile` (no duplicate local script).
- `NAVAI_TTS_PROVIDER` and `ELEVENLABS_*` live in `@navai/playground-api`, not in this playground.

## Expected structure

- Routes: `apps/playground-mobile/src/ai/routes.ts`
- Local functions: `apps/playground-mobile/src/ai/functions-modules/**/*.ts`
- Auto-generated module registry: `apps/playground-mobile/src/ai/generated-module-loaders.ts`

Manual command to regenerate registry:

```bash
npm run generate:ai-modules --workspace @navai/playground-mobile
```

For dynamic screen routes, define module paths in `src/ai/routes.ts` (example):

```ts
{
  name: "home",
  path: "/",
  description: "Main screen",
  modulePath: "src/pages/HomeScreen.tsx",
  moduleExport: "HomeScreen"
}
```

## Quick start

1. Install dependencies from root:

```bash
npm install
```

2. Run API in another terminal:

```bash
npm run dev --workspace @navai/playground-api
```

3. Run mobile playground and select your phone (USB debugging enabled):

```bash
npm run android --workspace @navai/playground-mobile -- --device
```

## Agent voice state

`useMobileVoiceAgent` exposes:

- `agentVoiceState`: `idle | speaking`
- `isAgentSpeaking`: `boolean`

The playground `VoiceNavigator` uses these fields to update text, button label, and colors when the agent starts/stops speaking.

If backend `playground-api` uses `NAVAI_TTS_PROVIDER=elevenlabs`, the realtime session still runs on OpenAI for microphone/tools, but final assistant speech is synthesized locally through the playground Expo `speechPlayer`.

## Expo Go vs Development Build

`react-native-webrtc` requires native modules. For realtime voice use Development Build.

1. Connect phone via USB and enable USB debugging.
2. Build and install development client:

```bash
npm run android --workspace @navai/playground-mobile -- --device
```

3. Start Metro for Development Build:

```bash
npm run dev --workspace @navai/playground-mobile -- --dev-client
```

4. Open the installed app (not Expo Go).

## Android configuration (Windows + Git Bash)

```bash
export JAVA_HOME="/c/Program Files/Java/jdk-23"
export ANDROID_HOME="/c/Users/<YOUR_USER>/AppData/Local/Android/Sdk"
export PATH="$JAVA_HOME/bin:$ANDROID_HOME/platform-tools:$ANDROID_HOME/emulator:$PATH"
```

Validation:

```bash
java -version
adb devices
```

If Gradle cannot find SDK, create `apps/playground-mobile/android/local.properties`:

```properties
sdk.dir=C:/Users/<YOUR_USER>/AppData/Local/Android/Sdk
```

## Network notes

- Android emulator: `http://10.0.2.2:3000`
- iOS simulator: `http://localhost:3000`
- Physical device: your PC LAN IP, e.g. `http://<YOUR_LAN_IP>:3000`
- `Cannot GET /` on root is expected; test:
- `http://<IP>:3000/health`
- `http://<IP>:3000/navai/functions`

## Troubleshooting

Common issues and fixes: `apps/playground-mobile/TROUBLESHOOTING.md`.
