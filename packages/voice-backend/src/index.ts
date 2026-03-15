import path from "node:path";

import type { Express, NextFunction, Request, Response } from "express";
import { loadNavaiFunctions } from "./functions";
import type { NavaiFunctionsRegistry } from "./functions";
import { resolveNavaiBackendRuntimeConfig } from "./runtime";
export { loadNavaiFunctions } from "./functions";
export type {
  NavaiFunctionContext,
  NavaiFunctionDefinition,
  NavaiFunctionModuleLoaders,
  NavaiFunctionPayload,
  NavaiFunctionsRegistry
} from "./functions";
export { resolveNavaiBackendRuntimeConfig } from "./runtime";
export type {
  ResolveNavaiBackendRuntimeConfigOptions,
  ResolveNavaiBackendRuntimeConfigResult
} from "./runtime";

const OPENAI_CLIENT_SECRETS_URL = "https://api.openai.com/v1/realtime/client_secrets";
const MIN_TTL_SECONDS = 10;
const MAX_TTL_SECONDS = 7200;
const DEFAULT_CLIENT_SECRET_PATH = "/navai/realtime/client-secret";
const DEFAULT_FUNCTIONS_LIST_PATH = "/navai/functions";
const DEFAULT_FUNCTIONS_EXECUTE_PATH = "/navai/functions/execute";
const DEFAULT_SPEECH_SYNTHESIZE_PATH = "/navai/speech/synthesize";
const DEFAULT_ELEVENLABS_BASE_URL = "https://api.elevenlabs.io";
const DEFAULT_ELEVENLABS_OUTPUT_FORMAT = "mp3_44100_128";

type NavaiBackendEnv = Record<string, string | undefined>;

export type NavaiSpeechProvider = "openai" | "elevenlabs";

export type NavaiSpeechVoiceSettings = {
  stability?: number;
  similarityBoost?: number;
  style?: number;
  useSpeakerBoost?: boolean;
};

export type NavaiResolvedSpeechConfig = {
  provider: NavaiSpeechProvider;
};

export type NavaiVoiceBackendOptions = {
  openaiApiKey?: string;
  defaultModel?: string;
  defaultVoice?: string;
  defaultInstructions?: string;
  defaultLanguage?: string;
  defaultVoiceAccent?: string;
  defaultVoiceTone?: string;
  clientSecretTtlSeconds?: number;
  allowApiKeyFromRequest?: boolean;
  ttsProvider?: NavaiSpeechProvider;
  elevenLabsApiKey?: string;
  elevenLabsBaseUrl?: string;
  elevenLabsVoiceId?: string;
  elevenLabsModelId?: string;
  elevenLabsOutputFormat?: string;
  elevenLabsOptimizeStreamingLatency?: number;
  elevenLabsVoiceSettings?: NavaiSpeechVoiceSettings;
};

export type CreateClientSecretRequest = {
  model?: string;
  voice?: string;
  instructions?: string;
  language?: string;
  voiceAccent?: string;
  voiceTone?: string;
  apiKey?: string;
};

export type OpenAIRealtimeClientSecretResponse = {
  value: string;
  expires_at: number;
  session?: unknown;
  speech: NavaiResolvedSpeechConfig;
};

export type SynthesizeSpeechRequest = {
  text?: string;
  voiceId?: string;
  modelId?: string;
  outputFormat?: string;
  optimizeStreamingLatency?: number;
  voiceSettings?: NavaiSpeechVoiceSettings;
};

export type SynthesizeSpeechResponse = {
  provider: "elevenlabs";
  mimeType: string;
  audioBase64: string;
};

export type RegisterNavaiExpressRoutesOptions = {
  env?: NavaiBackendEnv;
  backendOptions?: NavaiVoiceBackendOptions;
  includeFunctionsRoutes?: boolean;
  clientSecretPath?: string;
  functionsListPath?: string;
  functionsExecutePath?: string;
  functionsBaseDir?: string;
  functionsFolders?: string;
  agentsFolders?: string;
  includeExtensions?: string[];
  exclude?: string[];
  speechSynthesizePath?: string;
};

function validateOptions(opts: NavaiVoiceBackendOptions): void {
  const hasBackendApiKey = Boolean(opts.openaiApiKey?.trim());
  if (!hasBackendApiKey && !opts.allowApiKeyFromRequest) {
    throw new Error("Missing openaiApiKey in NavaiVoiceBackendOptions.");
  }

  const ttl = opts.clientSecretTtlSeconds ?? 600;
  if (ttl < MIN_TTL_SECONDS || ttl > MAX_TTL_SECONDS) {
    throw new Error(
      `clientSecretTtlSeconds must be between ${MIN_TTL_SECONDS} and ${MAX_TTL_SECONDS}. Received: ${ttl}`
    );
  }

  if (resolveSpeechConfig(opts).provider === "elevenlabs") {
    if (!readOptional(opts.elevenLabsApiKey)) {
      throw new Error("Missing elevenLabsApiKey in NavaiVoiceBackendOptions.");
    }

    if (!readOptional(opts.elevenLabsVoiceId)) {
      throw new Error("Missing elevenLabsVoiceId in NavaiVoiceBackendOptions.");
    }
  }
}

function resolveApiKey(opts: NavaiVoiceBackendOptions, req?: CreateClientSecretRequest): string {
  // Server key always wins when configured; request key is only a fallback.
  const backendApiKey = opts.openaiApiKey?.trim();
  if (backendApiKey) {
    return backendApiKey;
  }

  const requestApiKey = req?.apiKey?.trim();
  if (requestApiKey) {
    if (!opts.allowApiKeyFromRequest) {
      throw new Error(
        "Passing apiKey from request is disabled. Set allowApiKeyFromRequest=true to enable it."
      );
    }
    return requestApiKey;
  }

  throw new Error("Missing API key. Configure openaiApiKey or send apiKey in request.");
}

function readOptional(value: string | undefined): string | undefined {
  const trimmed = value?.trim();
  return trimmed ? trimmed : undefined;
}

function readOptionalNumber(value: string | undefined): number | undefined {
  const normalized = readOptional(value);
  if (!normalized) {
    return undefined;
  }

  const parsed = Number(normalized);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function readOptionalBoolean(value: string | undefined): boolean | undefined {
  const normalized = readOptional(value)?.toLowerCase();
  if (!normalized) {
    return undefined;
  }

  if (normalized === "true" || normalized === "1" || normalized === "yes") {
    return true;
  }

  if (normalized === "false" || normalized === "0" || normalized === "no") {
    return false;
  }

  return undefined;
}

function sanitizeVoiceSettings(
  value: NavaiSpeechVoiceSettings | undefined
): NavaiSpeechVoiceSettings | undefined {
  if (!value) {
    return undefined;
  }

  const next: NavaiSpeechVoiceSettings = {};
  if (typeof value.stability === "number" && Number.isFinite(value.stability)) {
    next.stability = value.stability;
  }
  if (typeof value.similarityBoost === "number" && Number.isFinite(value.similarityBoost)) {
    next.similarityBoost = value.similarityBoost;
  }
  if (typeof value.style === "number" && Number.isFinite(value.style)) {
    next.style = value.style;
  }
  if (typeof value.useSpeakerBoost === "boolean") {
    next.useSpeakerBoost = value.useSpeakerBoost;
  }

  return Object.keys(next).length > 0 ? next : undefined;
}

function normalizeSpeechProvider(value: string | undefined): NavaiSpeechProvider {
  return readOptional(value)?.toLowerCase() === "elevenlabs" ? "elevenlabs" : "openai";
}

function resolveSpeechConfig(opts: NavaiVoiceBackendOptions): NavaiResolvedSpeechConfig {
  return {
    provider: normalizeSpeechProvider(opts.ttsProvider)
  };
}

function resolveElevenLabsBaseUrl(opts: NavaiVoiceBackendOptions): string {
  return readOptional(opts.elevenLabsBaseUrl) ?? DEFAULT_ELEVENLABS_BASE_URL;
}

function resolveMimeType(outputFormat: string | undefined, responseContentType: string | null): string {
  const contentType = readOptional(responseContentType ?? undefined);
  if (contentType) {
    return contentType;
  }

  const normalized = readOptional(outputFormat)?.toLowerCase() ?? "";
  if (normalized.startsWith("mp3")) {
    return "audio/mpeg";
  }
  if (normalized.startsWith("pcm")) {
    return "audio/pcm";
  }
  if (normalized.startsWith("wav")) {
    return "audio/wav";
  }
  if (normalized.startsWith("ulaw")) {
    return "audio/basic";
  }

  return "audio/mpeg";
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === "object");
}

export function getNavaiVoiceBackendOptionsFromEnv(
  env: NavaiBackendEnv = process.env as NavaiBackendEnv
): NavaiVoiceBackendOptions {
  const hasBackendApiKey = Boolean(env.OPENAI_API_KEY?.trim());
  const allowFrontendApiKeyFromEnv = (env.NAVAI_ALLOW_FRONTEND_API_KEY ?? "false").toLowerCase() === "true";
  const allowFrontendApiKey = allowFrontendApiKeyFromEnv || !hasBackendApiKey;

  return {
    openaiApiKey: env.OPENAI_API_KEY,
    defaultModel: env.OPENAI_REALTIME_MODEL,
    defaultVoice: env.OPENAI_REALTIME_VOICE,
    defaultInstructions: env.OPENAI_REALTIME_INSTRUCTIONS,
    defaultLanguage: env.OPENAI_REALTIME_LANGUAGE,
    defaultVoiceAccent: env.OPENAI_REALTIME_VOICE_ACCENT,
    defaultVoiceTone: env.OPENAI_REALTIME_VOICE_TONE,
    clientSecretTtlSeconds: Number(env.OPENAI_REALTIME_CLIENT_SECRET_TTL ?? "600"),
    allowApiKeyFromRequest: allowFrontendApiKey,
    ttsProvider: normalizeSpeechProvider(env.NAVAI_TTS_PROVIDER),
    elevenLabsApiKey: env.ELEVENLABS_API_KEY,
    elevenLabsBaseUrl: env.ELEVENLABS_BASE_URL,
    elevenLabsVoiceId: env.ELEVENLABS_VOICE_ID,
    elevenLabsModelId: env.ELEVENLABS_MODEL_ID,
    elevenLabsOutputFormat: env.ELEVENLABS_OUTPUT_FORMAT,
    elevenLabsOptimizeStreamingLatency: readOptionalNumber(env.ELEVENLABS_OPTIMIZE_STREAMING_LATENCY),
    elevenLabsVoiceSettings: sanitizeVoiceSettings({
      stability: readOptionalNumber(env.ELEVENLABS_STABILITY),
      similarityBoost: readOptionalNumber(env.ELEVENLABS_SIMILARITY_BOOST),
      style: readOptionalNumber(env.ELEVENLABS_STYLE),
      useSpeakerBoost: readOptionalBoolean(env.ELEVENLABS_USE_SPEAKER_BOOST)
    })
  };
}

function buildSessionInstructions(input: {
  baseInstructions: string;
  language?: string;
  voiceAccent?: string;
  voiceTone?: string;
}): string {
  const lines = [input.baseInstructions.trim()];
  const language = readOptional(input.language);
  const voiceAccent = readOptional(input.voiceAccent);
  const voiceTone = readOptional(input.voiceTone);

  if (language) {
    lines.push(`Always reply in ${language}.`);
  }

  if (voiceAccent) {
    lines.push(`Use a ${voiceAccent} accent while speaking.`);
  }

  if (voiceTone) {
    lines.push(`Use a ${voiceTone} tone while speaking.`);
  }

  return lines.join("\n");
}

export async function createRealtimeClientSecret(
  opts: NavaiVoiceBackendOptions,
  req?: CreateClientSecretRequest
): Promise<OpenAIRealtimeClientSecretResponse> {
  validateOptions(opts);
  const apiKey = resolveApiKey(opts, req);
  const speech = resolveSpeechConfig(opts);

  const model = req?.model ?? opts.defaultModel ?? "gpt-realtime";
  const voice = req?.voice ?? opts.defaultVoice ?? "marin";
  const baseInstructions = req?.instructions ?? opts.defaultInstructions ?? "You are a helpful assistant.";
  const instructions = buildSessionInstructions({
    baseInstructions,
    language: req?.language ?? opts.defaultLanguage,
    voiceAccent: req?.voiceAccent ?? opts.defaultVoiceAccent,
    voiceTone: req?.voiceTone ?? opts.defaultVoiceTone
  });
  const ttl = opts.clientSecretTtlSeconds ?? 600;

  const body = {
    expires_after: { anchor: "created_at", seconds: ttl },
    session:
      speech.provider === "elevenlabs"
        ? {
            type: "realtime",
            model,
            instructions,
            output_modalities: ["text"]
          }
        : {
            type: "realtime",
            model,
            instructions,
            audio: {
              output: { voice }
            }
          }
  };

  const response = await fetch(OPENAI_CLIENT_SECRETS_URL, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey}`,
      "Content-Type": "application/json"
    },
    body: JSON.stringify(body)
  });

  if (!response.ok) {
    const message = await response.text();
    throw new Error(`OpenAI client_secrets failed (${response.status}): ${message}`);
  }

  const payload = (await response.json()) as Omit<OpenAIRealtimeClientSecretResponse, "speech">;
  return {
    ...payload,
    speech
  };
}

export async function synthesizeSpeech(
  opts: NavaiVoiceBackendOptions,
  req: SynthesizeSpeechRequest
): Promise<SynthesizeSpeechResponse> {
  validateOptions(opts);

  if (resolveSpeechConfig(opts).provider !== "elevenlabs") {
    throw new Error('Speech synthesis is only available when NAVAI_TTS_PROVIDER is set to "elevenlabs".');
  }

  const text = readOptional(req.text);
  if (!text) {
    throw new Error("text is required.");
  }

  const apiKey = readOptional(opts.elevenLabsApiKey);
  const voiceId = readOptional(req.voiceId) ?? readOptional(opts.elevenLabsVoiceId);
  if (!apiKey || !voiceId) {
    throw new Error("ElevenLabs API key and voice ID are required.");
  }

  const modelId = readOptional(req.modelId) ?? readOptional(opts.elevenLabsModelId);
  const outputFormat =
    readOptional(req.outputFormat) ?? readOptional(opts.elevenLabsOutputFormat) ?? DEFAULT_ELEVENLABS_OUTPUT_FORMAT;
  const optimizeStreamingLatency =
    typeof req.optimizeStreamingLatency === "number" && Number.isFinite(req.optimizeStreamingLatency)
      ? req.optimizeStreamingLatency
      : opts.elevenLabsOptimizeStreamingLatency;
  const voiceSettings =
    sanitizeVoiceSettings(req.voiceSettings) ?? sanitizeVoiceSettings(opts.elevenLabsVoiceSettings);

  const url = new URL(`/v1/text-to-speech/${encodeURIComponent(voiceId)}`, resolveElevenLabsBaseUrl(opts));
  url.searchParams.set("output_format", outputFormat);
  if (typeof optimizeStreamingLatency === "number" && Number.isFinite(optimizeStreamingLatency)) {
    url.searchParams.set("optimize_streaming_latency", String(Math.round(optimizeStreamingLatency)));
  }

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "xi-api-key": apiKey,
      "Content-Type": "application/json",
      Accept: resolveMimeType(outputFormat, null)
    },
    body: JSON.stringify({
      text,
      ...(modelId ? { model_id: modelId } : {}),
      ...(voiceSettings ? { voice_settings: {
        ...(voiceSettings.stability !== undefined ? { stability: voiceSettings.stability } : {}),
        ...(voiceSettings.similarityBoost !== undefined
          ? { similarity_boost: voiceSettings.similarityBoost }
          : {}),
        ...(voiceSettings.style !== undefined ? { style: voiceSettings.style } : {}),
        ...(voiceSettings.useSpeakerBoost !== undefined
          ? { use_speaker_boost: voiceSettings.useSpeakerBoost }
          : {})
      } } : {})
    })
  });

  if (!response.ok) {
    const message = await response.text();
    throw new Error(`ElevenLabs synthesize failed (${response.status}): ${message}`);
  }

  const audioBuffer = Buffer.from(await response.arrayBuffer());
  return {
    provider: "elevenlabs",
    mimeType: resolveMimeType(outputFormat, response.headers.get("content-type")),
    audioBase64: audioBuffer.toString("base64")
  };
}

export function createExpressClientSecretHandler(opts: NavaiVoiceBackendOptions) {
  validateOptions(opts);

  return async (req: Request, res: Response, next: NextFunction) => {
    try {
      const input = req.body as CreateClientSecretRequest | undefined;
      const data = await createRealtimeClientSecret(opts, input);
      res.json({ value: data.value, expires_at: data.expires_at, speech: data.speech });
    } catch (error) {
      next(error);
    }
  };
}

export function createExpressSpeechSynthesizeHandler(opts: NavaiVoiceBackendOptions) {
  validateOptions(opts);

  return async (req: Request, res: Response, next: NextFunction) => {
    try {
      const input = (req.body ?? {}) as SynthesizeSpeechRequest;
      const data = await synthesizeSpeech(opts, input);
      res.json(data);
    } catch (error) {
      next(error);
    }
  };
}

function resolveFunctionsBaseDir(env: NavaiBackendEnv, input?: string): string {
  const configured = readOptional(input) ?? readOptional(env.NAVAI_FUNCTIONS_BASE_DIR);
  return configured ? path.resolve(configured) : process.cwd();
}

type BackendFunctionsRuntime = {
  registry: NavaiFunctionsRegistry;
  warnings: string[];
};

async function loadBackendFunctionsRuntime(input: {
  env: NavaiBackendEnv;
  functionsBaseDir?: string;
  functionsFolders?: string;
  agentsFolders?: string;
  includeExtensions?: string[];
  exclude?: string[];
}): Promise<BackendFunctionsRuntime> {
  const runtimeConfig = await resolveNavaiBackendRuntimeConfig({
    env: input.env,
    baseDir: resolveFunctionsBaseDir(input.env, input.functionsBaseDir),
    functionsFolders: input.functionsFolders,
    agentsFolders: input.agentsFolders,
    includeExtensions: input.includeExtensions,
    exclude: input.exclude
  });

  const registry = await loadNavaiFunctions(runtimeConfig.functionModuleLoaders);
  return {
    registry,
    warnings: [...runtimeConfig.warnings, ...registry.warnings]
  };
}

export function registerNavaiExpressRoutes(app: Express, options: RegisterNavaiExpressRoutesOptions = {}): void {
  const env = options.env ?? (process.env as NavaiBackendEnv);
  const backendOptions = options.backendOptions ?? getNavaiVoiceBackendOptionsFromEnv(env);
  const includeFunctionsRoutes = options.includeFunctionsRoutes ?? true;
  const clientSecretPath = options.clientSecretPath ?? DEFAULT_CLIENT_SECRET_PATH;
  const functionsListPath = options.functionsListPath ?? DEFAULT_FUNCTIONS_LIST_PATH;
  const functionsExecutePath = options.functionsExecutePath ?? DEFAULT_FUNCTIONS_EXECUTE_PATH;
  const speechSynthesizePath = options.speechSynthesizePath ?? DEFAULT_SPEECH_SYNTHESIZE_PATH;

  app.post(clientSecretPath, createExpressClientSecretHandler(backendOptions));
  app.post(speechSynthesizePath, createExpressSpeechSynthesizeHandler(backendOptions));

  if (!includeFunctionsRoutes) {
    return;
  }

  let runtimePromise: Promise<BackendFunctionsRuntime> | null = null;
  const getRuntime = async (): Promise<BackendFunctionsRuntime> => {
    if (!runtimePromise) {
      runtimePromise = loadBackendFunctionsRuntime({
        env,
        functionsBaseDir: options.functionsBaseDir,
        functionsFolders: options.functionsFolders,
        agentsFolders: options.agentsFolders,
        includeExtensions: options.includeExtensions,
        exclude: options.exclude
      });
    }

    return runtimePromise;
  };

  app.get(functionsListPath, async (_req, res, next) => {
    try {
      const runtime = await getRuntime();
      res.json({
        items: runtime.registry.ordered.map((item) => ({
          name: item.name,
          description: item.description,
          source: item.source
        })),
        warnings: runtime.warnings
      });
    } catch (error) {
      next(error);
    }
  });

  app.post(functionsExecutePath, async (req: Request, res: Response, next: NextFunction) => {
    try {
      const runtime = await getRuntime();
      const input = req.body as { function_name?: unknown; payload?: unknown } | undefined;
      const functionName = typeof input?.function_name === "string" ? input.function_name.trim().toLowerCase() : "";

      if (!functionName) {
        res.status(400).json({ error: "function_name is required." });
        return;
      }

      const definition = runtime.registry.byName.get(functionName);
      if (!definition) {
        res.status(404).json({
          error: "Unknown or disallowed function.",
          available_functions: runtime.registry.ordered.map((item) => item.name)
        });
        return;
      }

      const payload = isObjectRecord(input?.payload) ? input.payload : {};
      const result = await definition.run(payload, { req });

      res.json({
        ok: true,
        function_name: definition.name,
        source: definition.source,
        result
      });
    } catch (error) {
      next(error);
    }
  });
}
