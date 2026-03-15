import { RealtimeSession } from "@openai/agents/realtime";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { buildNavaiAgent } from "./agent";
import { createNavaiBackendClient, type NavaiSpeechProvider, type SynthesizeSpeechOutput } from "./backend";
import type { NavaiFunctionModuleLoaders } from "./functions";
import type { NavaiRoute } from "./routes";
import { resolveNavaiFrontendRuntimeConfig } from "./runtime";

type VoiceStatus = "idle" | "connecting" | "connected" | "error";
type AgentVoiceState = "idle" | "speaking";

type NavaiFrontendEnv = Record<string, string | undefined>;
const DEBUG_PREFIX = "[navai debug]";

type ActivePlayback = {
  audio: HTMLAudioElement;
  url: string;
};

export type UseWebVoiceAgentOptions = {
  navigate: (path: string) => void;
  moduleLoaders: NavaiFunctionModuleLoaders;
  defaultRoutes: NavaiRoute[];
  env?: NavaiFrontendEnv;
  apiBaseUrl?: string;
  routesFile?: string;
  functionsFolders?: string;
  agentsFolders?: string;
  modelOverride?: string;
  defaultRoutesFile?: string;
  defaultFunctionsFolder?: string;
};

export type UseWebVoiceAgentResult = {
  status: VoiceStatus;
  agentVoiceState: AgentVoiceState;
  error: string | null;
  isConnecting: boolean;
  isConnected: boolean;
  isAgentSpeaking: boolean;
  start: () => Promise<void>;
  stop: () => void;
};

function formatError(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }

  return String(error);
}

function emitWarnings(warnings: string[]): void {
  for (const warning of warnings) {
    if (warning.trim().length > 0) {
      console.warn(warning);
    }
  }
}

function debugLog(message: string, details?: unknown): void {
  if (details === undefined) {
    console.log(`${DEBUG_PREFIX} ${message}`);
    return;
  }

  console.log(`${DEBUG_PREFIX} ${message}`, details);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === "object");
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

function extractAssistantTextFromRealtimeEvent(event: unknown): { key: string; text: string } | null {
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

function audioUrlFromSynthesis(result: SynthesizeSpeechOutput): string {
  const binary = atob(result.audioBase64);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index);
  }

  return URL.createObjectURL(new Blob([bytes], { type: result.mimeType }));
}

export function useWebVoiceAgent(options: UseWebVoiceAgentOptions): UseWebVoiceAgentResult {
  const sessionRef = useRef<RealtimeSession | null>(null);
  const attachedRealtimeSessionRef = useRef<RealtimeSession | null>(null);
  const speechProviderRef = useRef<NavaiSpeechProvider>("openai");
  const spokenAssistantKeysRef = useRef<Set<string>>(new Set());
  const playbackGenerationRef = useRef(0);
  const activePlaybackRef = useRef<ActivePlayback | null>(null);
  const runtimeConfigPromise = useMemo(
    () =>
      resolveNavaiFrontendRuntimeConfig({
        moduleLoaders: options.moduleLoaders,
        defaultRoutes: options.defaultRoutes,
        env: options.env,
        routesFile: options.routesFile,
        functionsFolders: options.functionsFolders,
        agentsFolders: options.agentsFolders,
        modelOverride: options.modelOverride,
        defaultRoutesFile: options.defaultRoutesFile,
        defaultFunctionsFolder: options.defaultFunctionsFolder
      }),
    [
      options.defaultFunctionsFolder,
      options.defaultRoutes,
      options.defaultRoutesFile,
      options.agentsFolders,
      options.env,
      options.functionsFolders,
      options.modelOverride,
      options.moduleLoaders,
      options.routesFile
    ]
  );
  const backendClient = useMemo(
    () =>
      createNavaiBackendClient({
        ...(options.apiBaseUrl ? { apiBaseUrl: options.apiBaseUrl } : {}),
        env: options.env
      }),
    [options.apiBaseUrl, options.env]
  );

  const [status, setStatus] = useState<VoiceStatus>("idle");
  const [agentVoiceState, setAgentVoiceState] = useState<AgentVoiceState>("idle");
  const [error, setError] = useState<string | null>(null);

  const setAgentVoiceStateIfChanged = useCallback((next: AgentVoiceState) => {
    setAgentVoiceState((current) => (current === next ? current : next));
  }, []);

  const clearPlayback = useCallback(
    (options?: { invalidate?: boolean; resetState?: boolean }) => {
      if (options?.invalidate) {
        playbackGenerationRef.current += 1;
      }

      const active = activePlaybackRef.current;
      if (active) {
        try {
          active.audio.pause();
          active.audio.currentTime = 0;
        } catch {
          // ignore browser playback cleanup failures
        }
        URL.revokeObjectURL(active.url);
      }

      activePlaybackRef.current = null;
      if (options?.resetState !== false) {
        setAgentVoiceStateIfChanged("idle");
      }
    },
    [setAgentVoiceStateIfChanged]
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

      clearPlayback({ resetState: false });
      const generation = playbackGenerationRef.current + 1;
      playbackGenerationRef.current = generation;
      setAgentVoiceStateIfChanged("speaking");

      try {
        const synthesized = await backendClient.synthesizeSpeech({ text: normalized });
        if (speechProviderRef.current !== "elevenlabs" || playbackGenerationRef.current !== generation) {
          return;
        }

        const audio = new Audio();
        const url = audioUrlFromSynthesis(synthesized);
        audio.src = url;
        audio.autoplay = false;
        activePlaybackRef.current = { audio, url };

        const finish = (): void => {
          if (activePlaybackRef.current?.audio === audio) {
            clearPlayback({ resetState: true });
          } else {
            URL.revokeObjectURL(url);
          }
        };

        audio.addEventListener("ended", finish, { once: true });
        audio.addEventListener("error", finish, { once: true });
        await audio.play();
      } catch (playbackError) {
        debugLog("assistant speech playback failed", playbackError);
        if (playbackGenerationRef.current === generation) {
          clearPlayback({ resetState: true });
        }
      }
    },
    [backendClient, clearPlayback, setAgentVoiceStateIfChanged]
  );

  const handleSessionAudioStart = useCallback((): void => {
    setAgentVoiceStateIfChanged("speaking");
  }, [setAgentVoiceStateIfChanged]);

  const handleSessionAudioStopped = useCallback((): void => {
    setAgentVoiceStateIfChanged("idle");
  }, [setAgentVoiceStateIfChanged]);

  const handleSessionAudioInterrupted = useCallback((): void => {
    clearPlayback({ invalidate: true, resetState: true });
    setAgentVoiceStateIfChanged("idle");
  }, [clearPlayback, setAgentVoiceStateIfChanged]);

  const handleSessionError = useCallback((): void => {
    clearPlayback({ invalidate: true, resetState: true });
    setAgentVoiceStateIfChanged("idle");
  }, [clearPlayback, setAgentVoiceStateIfChanged]);

  const handleTransportEvent = useCallback(
    (event: unknown): void => {
      const eventType = readRealtimeEventType(event);
      if (!eventType) {
        return;
      }

      if (
        eventType === "input_audio_buffer.speech_started" ||
        eventType === "conversation.item.input_audio_transcription.started"
      ) {
        clearPlayback({ invalidate: true, resetState: true });
        return;
      }

      if (speechProviderRef.current !== "elevenlabs") {
        return;
      }

      const assistantText = extractAssistantTextFromRealtimeEvent(event);
      if (!assistantText || spokenAssistantKeysRef.current.has(assistantText.key)) {
        return;
      }

      spokenAssistantKeysRef.current.add(assistantText.key);
      void playAssistantSpeech(assistantText.text);
    },
    [clearPlayback, playAssistantSpeech]
  );

  const detachSessionAudioListeners = useCallback(() => {
    const attachedSession = attachedRealtimeSessionRef.current;
    if (!attachedSession) {
      return;
    }

    attachedSession.off("audio_start", handleSessionAudioStart);
    attachedSession.off("audio_stopped", handleSessionAudioStopped);
    attachedSession.off("audio_interrupted", handleSessionAudioInterrupted);
    attachedSession.off("transport_event", handleTransportEvent);
    attachedSession.off("error", handleSessionError);
    attachedRealtimeSessionRef.current = null;
  }, [
    handleSessionAudioInterrupted,
    handleSessionAudioStart,
    handleSessionAudioStopped,
    handleSessionError,
    handleTransportEvent
  ]);

  const attachSessionAudioListeners = useCallback(
    (session: RealtimeSession) => {
      detachSessionAudioListeners();
      session.on("agent_start", (_context, agent, turnInput) => {
        debugLog("session agent_start", {
          agent: agent.name,
          turnInputCount: Array.isArray(turnInput) ? turnInput.length : 0
        });
      });
      session.on("agent_end", (_context, agent, output) => {
        debugLog("session agent_end", {
          agent: agent.name,
          output
        });
      });
      session.on("agent_handoff", (_context, fromAgent, toAgent) => {
        debugLog("session agent_handoff", {
          from: fromAgent.name,
          to: toAgent.name
        });
      });
      session.on("agent_tool_start", (_context, agent, tool, details) => {
        debugLog("session agent_tool_start", {
          agent: agent.name,
          tool: tool.name,
          toolCall: details.toolCall
        });
      });
      session.on("agent_tool_end", (_context, agent, tool, result, details) => {
        debugLog("session agent_tool_end", {
          agent: agent.name,
          tool: tool.name,
          result,
          toolCall: details.toolCall
        });
      });
      session.on("history_added", (item) => {
        debugLog("session history_added", item);
      });
      session.on("transport_event", handleTransportEvent);
      session.on("error", (sessionError) => {
        debugLog("session error", sessionError);
      });
      session.on("audio_start", handleSessionAudioStart);
      session.on("audio_stopped", handleSessionAudioStopped);
      session.on("audio_interrupted", handleSessionAudioInterrupted);
      session.on("error", handleSessionError);
      attachedRealtimeSessionRef.current = session;
    },
    [
      detachSessionAudioListeners,
      handleSessionAudioInterrupted,
      handleSessionAudioStart,
      handleSessionAudioStopped,
      handleSessionError,
      handleTransportEvent
    ]
  );

  const stop = useCallback(() => {
    detachSessionAudioListeners();
    clearPlayback({ invalidate: true, resetState: true });
    try {
      sessionRef.current?.close();
    } finally {
      sessionRef.current = null;
      spokenAssistantKeysRef.current.clear();
      speechProviderRef.current = "openai";
      setStatus("idle");
      setAgentVoiceStateIfChanged("idle");
    }
  }, [clearPlayback, detachSessionAudioListeners, setAgentVoiceStateIfChanged]);

  useEffect(() => {
    return () => {
      stop();
    };
  }, [stop]);

  const start = useCallback(async (): Promise<void> => {
    if (status === "connecting" || status === "connected") {
      return;
    }

    setError(null);
    setStatus("connecting");
    setAgentVoiceStateIfChanged("idle");

    try {
      const runtimeConfig = await runtimeConfigPromise;
      debugLog("resolved runtime config", {
        routes: runtimeConfig.routes.map((route) => route.path),
        functionModules: Object.keys(runtimeConfig.functionModuleLoaders),
        agents: runtimeConfig.agents.map((agent) => ({
          key: agent.key,
          name: agent.name,
          isPrimary: agent.isPrimary,
          functionModules: Object.keys(agent.functionModuleLoaders)
        })),
        warnings: runtimeConfig.warnings
      });
      const requestPayload = runtimeConfig.modelOverride ? { model: runtimeConfig.modelOverride } : {};
      const secretPayload = await backendClient.createClientSecret(requestPayload);
      speechProviderRef.current = secretPayload.speech.provider;
      spokenAssistantKeysRef.current.clear();
      clearPlayback({ invalidate: true, resetState: true });
      const backendFunctionsResult = await backendClient.listFunctions();

      const { agent, warnings } = await buildNavaiAgent({
        navigate: options.navigate,
        routes: runtimeConfig.routes,
        functionModuleLoaders: runtimeConfig.functionModuleLoaders,
        agents: runtimeConfig.agents,
        primaryAgentKey: runtimeConfig.primaryAgentKey,
        backendFunctions: backendFunctionsResult.functions,
        executeBackendFunction: backendClient.executeFunction
      });
      emitWarnings([...runtimeConfig.warnings, ...backendFunctionsResult.warnings, ...warnings]);

      const session =
        secretPayload.speech.provider === "elevenlabs"
          ? new RealtimeSession(agent, {
              config: {
                outputModalities: ["text"]
              }
            })
          : new RealtimeSession(agent);
      attachSessionAudioListeners(session);

      if (runtimeConfig.modelOverride) {
        await session.connect({ apiKey: secretPayload.value, model: runtimeConfig.modelOverride });
      } else {
        await session.connect({ apiKey: secretPayload.value });
      }

      sessionRef.current = session;
      setStatus("connected");
    } catch (startError) {
      const message = formatError(startError);
      debugLog("session start failed", { message, error: startError });
      setError(message);
      setStatus("error");
      setAgentVoiceStateIfChanged("idle");
      detachSessionAudioListeners();
      clearPlayback({ invalidate: true, resetState: true });
      spokenAssistantKeysRef.current.clear();
      speechProviderRef.current = "openai";

      try {
        sessionRef.current?.close();
      } catch {
        // ignore close errors during bootstrap
      }
      sessionRef.current = null;
    }
  }, [
    attachSessionAudioListeners,
    backendClient,
    clearPlayback,
    detachSessionAudioListeners,
    options.navigate,
    runtimeConfigPromise,
    setAgentVoiceStateIfChanged,
    status
  ]);

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
