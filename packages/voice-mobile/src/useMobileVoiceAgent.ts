import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { PermissionsAndroid, Platform } from "react-native";

import {
  buildNavaiRealtimeToolResultEvents,
  createNavaiMobileAgentRuntime,
  extractNavaiRealtimeToolCalls,
  type NavaiMobileAgentRuntime,
  type NavaiRealtimeToolCall
} from "./agent";
import {
  createNavaiMobileBackendClient,
  type NavaiSpeechProvider,
  type SynthesizeSpeechResult
} from "./backend";
import { loadNavaiFunctions, type NavaiFunctionsRegistry } from "./functions";
import {
  createReactNativeWebRtcTransport,
  type CreateReactNativeWebRtcTransportOptions
} from "./react-native-webrtc";
import type { ResolveNavaiMobileApplicationRuntimeConfigResult } from "./runtime";
import {
  createNavaiMobileVoiceSession,
  type NavaiMobileVoiceSession
} from "./session";

type SessionStatus = "idle" | "connecting" | "connected" | "error";
type AgentVoiceState = "idle" | "speaking";

type WebRtcRuntime = {
  mediaDevices: unknown;
  RTCPeerConnection: unknown;
};

type PendingToolCall = NavaiRealtimeToolCall & { name: string };
type AssistantTextPayload = { key: string; text: string };

export type NavaiMobileSpeechPlayer = {
  play: (input: SynthesizeSpeechResult) => Promise<void>;
  stop?: () => Promise<void> | void;
};

export type UseMobileVoiceAgentTransportOptions = Pick<
  CreateReactNativeWebRtcTransportOptions,
  "rtcConfiguration" | "audioConstraints" | "remoteAudioTrackVolume"
>;

export type UseMobileVoiceAgentOptions = {
  runtime: ResolveNavaiMobileApplicationRuntimeConfigResult | null;
  runtimeLoading: boolean;
  runtimeError: string | null;
  navigate: (path: string) => void;
  transportOptions?: UseMobileVoiceAgentTransportOptions;
  speechPlayer?: NavaiMobileSpeechPlayer;
};

export type UseMobileVoiceAgentResult = {
  status: SessionStatus;
  agentVoiceState: AgentVoiceState;
  error: string | null;
  isConnecting: boolean;
  isConnected: boolean;
  isAgentSpeaking: boolean;
  start: () => Promise<void>;
  stop: () => Promise<void>;
};

const REMOTE_AUDIO_TRACK_VOLUME = 10;

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === "object");
}

function readString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

function readToolCallDescriptors(event: unknown): Array<{ callId: string; name: string }> {
  if (!isRecord(event)) {
    return [];
  }

  const items: Record<string, unknown>[] = [];
  if (isRecord(event.item)) {
    items.push(event.item);
  }

  if (isRecord(event.response) && Array.isArray(event.response.output)) {
    for (const outputItem of event.response.output) {
      if (isRecord(outputItem)) {
        items.push(outputItem);
      }
    }
  }

  const descriptors: Array<{ callId: string; name: string }> = [];
  for (const item of items) {
    if (item.type !== "function_call") {
      continue;
    }

    const callId = readString(item.call_id) ?? readString(item.id);
    const name = readString(item.name)?.trim().toLowerCase();
    if (!callId || !name) {
      continue;
    }

    descriptors.push({ callId, name });
  }

  return descriptors;
}

function formatError(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }

  return String(error);
}

async function ensureMicrophonePermission(): Promise<void> {
  if (Platform.OS !== "android") {
    return;
  }

  const hasPermission = await PermissionsAndroid.check(PermissionsAndroid.PERMISSIONS.RECORD_AUDIO);
  if (hasPermission) {
    return;
  }

  const result = await PermissionsAndroid.request(PermissionsAndroid.PERMISSIONS.RECORD_AUDIO, {
    title: "Microphone permission",
    message: "Navai needs microphone access to start voice.",
    buttonPositive: "Allow",
    buttonNegative: "Cancel"
  });

  if (result === PermissionsAndroid.RESULTS.NEVER_ASK_AGAIN) {
    throw new Error("Microphone permission blocked. Enable it in Android settings and retry.");
  }

  if (result !== PermissionsAndroid.RESULTS.GRANTED) {
    throw new Error("Microphone permission denied. Please allow it and try again.");
  }
}

function loadWebRtcRuntime(): { runtime: WebRtcRuntime | null; error: string | null } {
  try {
    const webrtc = require("react-native-webrtc") as {
      mediaDevices?: unknown;
      RTCPeerConnection?: unknown;
    };

    if (!webrtc.mediaDevices || !webrtc.RTCPeerConnection) {
      return {
        runtime: null,
        error: "react-native-webrtc loaded without required globals."
      };
    }

    return {
      runtime: {
        mediaDevices: webrtc.mediaDevices,
        RTCPeerConnection: webrtc.RTCPeerConnection
      },
      error: null
    };
  } catch (error) {
    return {
      runtime: null,
      error: formatError(error)
    };
  }
}

function emitWarnings(warnings: string[]): void {
  for (const warning of warnings) {
    if (warning.trim().length > 0) {
      console.warn(warning);
    }
  }
}

function readRealtimeEventType(event: unknown): string {
  if (!isRecord(event) || typeof event.type !== "string") {
    return "";
  }

  return event.type.trim().toLowerCase();
}

function readAssistantTextFromResponseOutput(items: unknown[]): string {
  const parts: string[] = [];

  for (const item of items) {
    if (!isRecord(item) || item.type !== "message" || item.role !== "assistant") {
      continue;
    }

    const content = Array.isArray(item.content) ? item.content : [];
    for (const chunk of content) {
      if (!isRecord(chunk)) {
        continue;
      }

      const text =
        chunk.type === "output_text"
          ? typeof chunk.text === "string"
            ? chunk.text
            : ""
          : chunk.type === "output_audio"
            ? typeof chunk.transcript === "string"
              ? chunk.transcript
              : ""
            : "";
      const normalized = text.trim();
      if (normalized) {
        parts.push(normalized);
      }
    }
  }

  return parts.join("\n").trim();
}

function extractAssistantTextFromRealtimeEvent(event: unknown): AssistantTextPayload | null {
  if (!isRecord(event)) {
    return null;
  }

  const eventType = readRealtimeEventType(event);
  if (
    eventType === "response.output_text.done" ||
    eventType === "response.text.done" ||
    eventType === "response.audio_transcript.done"
  ) {
    const text =
      typeof event.text === "string"
        ? event.text.trim()
        : typeof event.transcript === "string"
          ? event.transcript.trim()
          : "";
    if (!text) {
      return null;
    }

    const key = [
      eventType,
      typeof event.response_id === "string" ? event.response_id : "",
      typeof event.item_id === "string" ? event.item_id : ""
    ]
      .filter(Boolean)
      .join(":");

    return { key: key || `${eventType}:${text}`, text };
  }

  if (eventType === "response.done" && isRecord(event.response) && Array.isArray(event.response.output)) {
    const text = readAssistantTextFromResponseOutput(event.response.output);
    if (!text) {
      return null;
    }

    const responseId = typeof event.response.id === "string" ? event.response.id : "";
    return { key: responseId ? `response.done:${responseId}` : `response.done:${text}`, text };
  }

  return null;
}

function resolveAgentVoiceStateFromRealtimeEvent(
  event: unknown,
  speechProvider: NavaiSpeechProvider
): AgentVoiceState | null {
  const eventType = readRealtimeEventType(event);
  if (!eventType) {
    return null;
  }

  if (speechProvider === "elevenlabs") {
    if (
      eventType === "response.canceled" ||
      eventType === "response.cancelled" ||
      eventType === "conversation.interrupted" ||
      eventType === "error"
    ) {
      return "idle";
    }

    return null;
  }

  if (
    eventType === "response.output_audio.delta" ||
    eventType === "response.audio.delta" ||
    eventType === "output_audio_buffer.started"
  ) {
    return "speaking";
  }

  if (
    eventType === "response.output_audio.done" ||
    eventType === "response.audio.done" ||
    eventType === "response.done" ||
    eventType === "response.canceled" ||
    eventType === "response.cancelled" ||
    eventType === "conversation.interrupted" ||
    eventType === "output_audio_buffer.stopped" ||
    eventType === "output_audio_buffer.cleared" ||
    eventType === "error"
  ) {
    return "idle";
  }

  return null;
}

export function useMobileVoiceAgent(options: UseMobileVoiceAgentOptions): UseMobileVoiceAgentResult {
  const [status, setStatus] = useState<SessionStatus>("idle");
  const [agentVoiceState, setAgentVoiceState] = useState<AgentVoiceState>("idle");
  const [error, setError] = useState<string | null>(null);
  const [functionsReady, setFunctionsReady] = useState(false);

  const webRtc = useMemo(loadWebRtcRuntime, []);
  const backendClient = useMemo(
    () =>
      options.runtime
        ? createNavaiMobileBackendClient({
            apiBaseUrl: options.runtime.apiBaseUrl,
            env: options.runtime.env
          })
        : null,
    [options.runtime]
  );
  const sessionRef = useRef<NavaiMobileVoiceSession | null>(null);
  const agentRuntimeRef = useRef<NavaiMobileAgentRuntime | null>(null);
  const frontendRegistryRef = useRef<NavaiFunctionsRegistry | null>(null);
  const handledToolCallIdsRef = useRef<Set<string>>(new Set());
  const toolCallNamesByIdRef = useRef<Map<string, string>>(new Map());
  const pendingToolCallsRef = useRef<Map<string, PendingToolCall>>(new Map());
  const speechProviderRef = useRef<NavaiSpeechProvider>("openai");
  const spokenAssistantKeysRef = useRef<Set<string>>(new Set());
  const speechPlaybackGenerationRef = useRef(0);

  const setAgentVoiceStateIfChanged = useCallback((next: AgentVoiceState) => {
    setAgentVoiceState((current) => (current === next ? current : next));
  }, []);

  const resetSessionState = useCallback(() => {
    sessionRef.current = null;
    agentRuntimeRef.current = null;
    handledToolCallIdsRef.current.clear();
    toolCallNamesByIdRef.current.clear();
    pendingToolCallsRef.current.clear();
    spokenAssistantKeysRef.current.clear();
    speechProviderRef.current = "openai";
    speechPlaybackGenerationRef.current += 1;
  }, []);

  const stopSpeechPlayback = useCallback(
    (input?: { invalidate?: boolean; resetState?: boolean }) => {
      if (input?.invalidate) {
        speechPlaybackGenerationRef.current += 1;
      }

      if (typeof input?.resetState === "undefined" || input.resetState) {
        setAgentVoiceStateIfChanged("idle");
      }

      void Promise.resolve(options.speechPlayer?.stop?.());
    },
    [options.speechPlayer, setAgentVoiceStateIfChanged]
  );

  const playAssistantSpeech = useCallback(
    async (text: string): Promise<void> => {
      if (speechProviderRef.current !== "elevenlabs") {
        return;
      }

      const normalized = text.trim();
      if (!normalized) {
        return;
      }

      if (!backendClient) {
        setError("Speech backend client is not available.");
        setAgentVoiceStateIfChanged("idle");
        return;
      }

      if (!options.speechPlayer) {
        console.warn("[navai] ElevenLabs speech is enabled but no mobile speechPlayer was provided.");
        setAgentVoiceStateIfChanged("idle");
        return;
      }

      const generation = speechPlaybackGenerationRef.current + 1;
      speechPlaybackGenerationRef.current = generation;
      await Promise.resolve(options.speechPlayer.stop?.());
      setAgentVoiceStateIfChanged("speaking");

      try {
        const synthesized = await backendClient.synthesizeSpeech({ text: normalized });
        if (speechProviderRef.current !== "elevenlabs" || speechPlaybackGenerationRef.current !== generation) {
          return;
        }

        await options.speechPlayer.play(synthesized);
      } catch (speechError) {
        if (speechPlaybackGenerationRef.current === generation) {
          setError(formatError(speechError));
        }
      } finally {
        if (speechPlaybackGenerationRef.current === generation) {
          setAgentVoiceStateIfChanged("idle");
        }
      }
    },
    [backendClient, options.speechPlayer, setAgentVoiceStateIfChanged]
  );

  const handleRealtimeToolCall = useCallback(async (call: PendingToolCall): Promise<void> => {
    if (handledToolCallIdsRef.current.has(call.callId)) {
      return;
    }

    const session = sessionRef.current;
    const agentRuntime = agentRuntimeRef.current;
    if (!session || !agentRuntime) {
      pendingToolCallsRef.current.set(call.callId, call);
      return;
    }

    handledToolCallIdsRef.current.add(call.callId);

    try {
      const result = await agentRuntime.executeToolCall({
        name: call.name,
        payload: call.payload
      });
      const events = buildNavaiRealtimeToolResultEvents({
        callId: call.callId,
        output: result
      });

      for (const event of events) {
        await session.sendRealtimeEvent(event);
      }
    } catch (toolError) {
      const events = buildNavaiRealtimeToolResultEvents({
        callId: call.callId,
        output: {
          ok: false,
          error: "Tool execution failed.",
          details: formatError(toolError)
        }
      });

      for (const event of events) {
        await session.sendRealtimeEvent(event);
      }
    }
  }, []);

  const handleRealtimeEvent = useCallback(
    (event: unknown) => {
      const eventType = readRealtimeEventType(event);
      if (
        speechProviderRef.current === "elevenlabs" &&
        (eventType === "input_audio_buffer.speech_started" ||
          eventType === "conversation.item.input_audio_transcription.started")
      ) {
        stopSpeechPlayback({ invalidate: true, resetState: true });
      }

      const nextVoiceState = resolveAgentVoiceStateFromRealtimeEvent(event, speechProviderRef.current);
      if (nextVoiceState) {
        setAgentVoiceStateIfChanged(nextVoiceState);
      }

      if (speechProviderRef.current === "elevenlabs") {
        const assistantText = extractAssistantTextFromRealtimeEvent(event);
        if (assistantText && !spokenAssistantKeysRef.current.has(assistantText.key)) {
          spokenAssistantKeysRef.current.add(assistantText.key);
          void playAssistantSpeech(assistantText.text);
        }
      }

      for (const descriptor of readToolCallDescriptors(event)) {
        toolCallNamesByIdRef.current.set(descriptor.callId, descriptor.name);
      }

      for (const toolCall of extractNavaiRealtimeToolCalls(event)) {
        const resolvedName = toolCall.name ?? toolCallNamesByIdRef.current.get(toolCall.callId);
        if (!resolvedName) {
          continue;
        }

        void handleRealtimeToolCall({
          ...toolCall,
          name: resolvedName
        });
      }
    },
    [handleRealtimeToolCall, playAssistantSpeech, setAgentVoiceStateIfChanged, stopSpeechPlayback]
  );

  useEffect(() => {
    if (!options.runtime) {
      frontendRegistryRef.current = null;
      setFunctionsReady(false);
      return;
    }

    const runtime = options.runtime;
    let cancelled = false;
    setFunctionsReady(false);

    void loadNavaiFunctions(runtime.functionModuleLoaders)
      .then((registry) => {
        if (cancelled) {
          return;
        }

        frontendRegistryRef.current = registry;
        setFunctionsReady(true);
        emitWarnings([...runtime.warnings, ...registry.warnings]);
      })
      .catch((nextError) => {
        if (cancelled) {
          return;
        }

        frontendRegistryRef.current = null;
        setFunctionsReady(false);
        setError(formatError(nextError));
      });

    return () => {
      cancelled = true;
    };
  }, [options.runtime]);

  useEffect(() => {
    return () => {
      void sessionRef.current?.stop();
      stopSpeechPlayback({ invalidate: true, resetState: true });
      resetSessionState();
    };
  }, [resetSessionState, stopSpeechPlayback]);

  const start = useCallback(async (): Promise<void> => {
    if (status === "connecting" || status === "connected") {
      return;
    }

    setError(null);
    setAgentVoiceStateIfChanged("idle");

    if (webRtc.error || !webRtc.runtime) {
      setError(webRtc.error ?? "WebRTC native module is not available.");
      setStatus("error");
      return;
    }

    if (options.runtimeLoading) {
      setError("Runtime is still loading.");
      setStatus("error");
      return;
    }

    if (options.runtimeError) {
      setError(options.runtimeError);
      setStatus("error");
      return;
    }

    if (!options.runtime) {
      setError("Runtime configuration is not available.");
      setStatus("error");
      return;
    }

    const runtime = options.runtime;
    const frontendRegistry = frontendRegistryRef.current;
    if (!frontendRegistry || !functionsReady) {
      setError("Functions are still loading. Try again in a moment.");
      setStatus("error");
      return;
    }

    if (!backendClient) {
      setError("Backend client is not available.");
      setStatus("error");
      return;
    }

    setStatus("connecting");
    handledToolCallIdsRef.current.clear();
    toolCallNamesByIdRef.current.clear();
    pendingToolCallsRef.current.clear();
    spokenAssistantKeysRef.current.clear();
    speechProviderRef.current = "openai";
    stopSpeechPlayback({ invalidate: true, resetState: true });

    try {
      await ensureMicrophonePermission();

      const session = createNavaiMobileVoiceSession({
        backendClient,
        transport: createReactNativeWebRtcTransport({
          globals: {
            mediaDevices: webRtc.runtime.mediaDevices,
            RTCPeerConnection: webRtc.runtime.RTCPeerConnection
          } as never,
          model: runtime.modelOverride,
          rtcConfiguration: options.transportOptions?.rtcConfiguration,
          audioConstraints: options.transportOptions?.audioConstraints,
          remoteAudioTrackVolume:
            options.transportOptions?.remoteAudioTrackVolume ?? REMOTE_AUDIO_TRACK_VOLUME
        }),
        onRealtimeEvent: handleRealtimeEvent,
        onRealtimeError: (nextError) => {
          setError(formatError(nextError));
          setStatus("error");
          stopSpeechPlayback({ invalidate: true, resetState: true });
          setAgentVoiceStateIfChanged("idle");
        }
      });
      sessionRef.current = session;

      const response = await session.start({
        model: runtime.modelOverride || undefined
      });
      speechProviderRef.current = response.speech.provider;
      spokenAssistantKeysRef.current.clear();

      const agentRuntime = createNavaiMobileAgentRuntime({
        navigate: options.navigate,
        routes: runtime.routes,
        functionsRegistry: frontendRegistry,
        backendFunctions: response.backendFunctions,
        executeBackendFunction: session.executeBackendFunction,
        agentName: runtime.agents.find((agent) => agent.key === runtime.primaryAgentKey)?.name,
        baseInstructions: runtime.agents.find((agent) => agent.key === runtime.primaryAgentKey)?.instructions
      });
      agentRuntimeRef.current = agentRuntime;

      emitWarnings([...response.warnings, ...agentRuntime.warnings]);

      const pendingCalls = [...pendingToolCallsRef.current.values()];
      pendingToolCallsRef.current.clear();
      for (const queuedCall of pendingCalls) {
        void handleRealtimeToolCall(queuedCall);
      }

      await session.sendRealtimeEvent({
        type: "session.update",
        session: {
          type: "realtime",
          instructions: agentRuntime.session.instructions,
          tools: agentRuntime.session.tools,
          tool_choice: "auto",
          ...(response.speech.provider === "elevenlabs" ? { output_modalities: ["text"] } : {})
        }
      });

      setStatus("connected");
    } catch (nextError) {
      setError(formatError(nextError));
      setStatus("error");
      stopSpeechPlayback({ invalidate: true, resetState: true });
      setAgentVoiceStateIfChanged("idle");

      try {
        await sessionRef.current?.stop();
      } catch {
        // ignore close errors during bootstrap
      }

      resetSessionState();
    }
  }, [
    functionsReady,
    backendClient,
    handleRealtimeEvent,
    handleRealtimeToolCall,
    options.navigate,
    options.runtime,
    options.runtimeError,
    options.runtimeLoading,
    options.speechPlayer,
    options.transportOptions,
    resetSessionState,
    setAgentVoiceStateIfChanged,
    status,
    stopSpeechPlayback,
    webRtc.error,
    webRtc.runtime
  ]);

  const stop = useCallback(async (): Promise<void> => {
    try {
      await sessionRef.current?.stop();
      setStatus("idle");
      stopSpeechPlayback({ invalidate: true, resetState: true });
      setAgentVoiceStateIfChanged("idle");
    } catch (nextError) {
      setError(formatError(nextError));
      setStatus("error");
      stopSpeechPlayback({ invalidate: true, resetState: true });
      setAgentVoiceStateIfChanged("idle");
    } finally {
      resetSessionState();
    }
  }, [resetSessionState, setAgentVoiceStateIfChanged, stopSpeechPlayback]);

  return {
    status,
    agentVoiceState,
    error,
    isConnecting: status === "connecting",
    isConnected: status === "connected",
    isAgentSpeaking: agentVoiceState === "speaking",
    start,
    stop
  };
}
