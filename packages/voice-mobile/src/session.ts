import {
  createNavaiMobileBackendClient,
  type BackendFunctionsResult,
  type CreateNavaiMobileBackendClientOptions,
  type NavaiBackendSpeechConfig,
  type CreateRealtimeClientSecretInput,
  type ExecuteNavaiBackendFunctionInput,
  type NavaiBackendFunctionDefinition,
  type NavaiMobileBackendClient
} from "./backend";
import type { NavaiRealtimeTransport, NavaiRealtimeTransportState } from "./transport";

export type NavaiMobileVoiceSessionState = "idle" | "connecting" | "connected" | "error";

export type StartNavaiMobileVoiceSessionInput = CreateRealtimeClientSecretInput & {
  preloadBackendFunctions?: boolean;
};

export type StartNavaiMobileVoiceSessionResult = {
  clientSecret: string;
  speech: NavaiBackendSpeechConfig;
  backendFunctions: NavaiBackendFunctionDefinition[];
  warnings: string[];
};

export type NavaiMobileVoiceSessionSnapshot = {
  state: NavaiMobileVoiceSessionState;
  transportState: NavaiRealtimeTransportState | "unknown";
  backendFunctions: NavaiBackendFunctionDefinition[];
  warnings: string[];
};

export type CreateNavaiMobileVoiceSessionOptions = {
  transport: NavaiRealtimeTransport;
  backendClient?: NavaiMobileBackendClient;
  backendClientOptions?: CreateNavaiMobileBackendClientOptions;
  onRealtimeEvent?: (event: unknown) => void;
  onRealtimeError?: (error: unknown) => void;
};

export type NavaiMobileVoiceSession = {
  start: (input?: StartNavaiMobileVoiceSessionInput) => Promise<StartNavaiMobileVoiceSessionResult>;
  stop: () => Promise<void>;
  listBackendFunctions: (forceReload?: boolean) => Promise<BackendFunctionsResult>;
  executeBackendFunction: (input: ExecuteNavaiBackendFunctionInput) => Promise<unknown>;
  sendRealtimeEvent: (event: unknown) => Promise<void>;
  getSnapshot: () => NavaiMobileVoiceSessionSnapshot;
};

export function createNavaiMobileVoiceSession(options: CreateNavaiMobileVoiceSessionOptions): NavaiMobileVoiceSession {
  const backendClient = options.backendClient ?? createNavaiMobileBackendClient(options.backendClientOptions);

  let state: NavaiMobileVoiceSessionState = "idle";
  let cachedFunctions: NavaiBackendFunctionDefinition[] = [];
  let warnings: string[] = [];

  async function listBackendFunctions(forceReload = false): Promise<BackendFunctionsResult> {
    if (!forceReload && cachedFunctions.length > 0) {
      return { functions: cachedFunctions, warnings };
    }

    const response = await backendClient.listFunctions();
    cachedFunctions = response.functions;
    warnings = response.warnings;
    return response;
  }

  async function start(input: StartNavaiMobileVoiceSessionInput = {}): Promise<StartNavaiMobileVoiceSessionResult> {
    if (state === "connecting" || state === "connected") {
      throw new Error(`Mobile voice session is already ${state}.`);
    }

    state = "connecting";

    try {
      const preloadFunctions = input.preloadBackendFunctions ?? true;
      const backendFunctionsResult = preloadFunctions
        ? await listBackendFunctions(true)
        : { functions: cachedFunctions, warnings };

      const secret = await backendClient.createClientSecret(input);

      await options.transport.connect({
        clientSecret: secret.value,
        model: input.model,
        onEvent: options.onRealtimeEvent,
        onError: options.onRealtimeError
      });

      state = "connected";
      return {
        clientSecret: secret.value,
        speech: secret.speech,
        backendFunctions: backendFunctionsResult.functions,
        warnings: backendFunctionsResult.warnings
      };
    } catch (error) {
      state = "error";
      throw error;
    }
  }

  async function stop(): Promise<void> {
    try {
      await options.transport.disconnect();
    } finally {
      state = "idle";
    }
  }

  async function executeBackendFunction(input: ExecuteNavaiBackendFunctionInput): Promise<unknown> {
    return backendClient.executeFunction(input);
  }

  async function sendRealtimeEvent(event: unknown): Promise<void> {
    if (!options.transport.sendEvent) {
      throw new Error("Realtime transport does not implement sendEvent.");
    }

    await options.transport.sendEvent(event);
  }

  function getSnapshot(): NavaiMobileVoiceSessionSnapshot {
    const transportState = options.transport.getState ? options.transport.getState() : "unknown";
    return {
      state,
      transportState,
      backendFunctions: cachedFunctions,
      warnings
    };
  }

  return {
    start,
    stop,
    listBackendFunctions,
    executeBackendFunction,
    sendRealtimeEvent,
    getSnapshot
  };
}
