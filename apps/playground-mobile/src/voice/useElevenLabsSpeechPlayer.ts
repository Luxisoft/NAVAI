import {
  setAudioModeAsync,
  useAudioPlayer,
  useAudioPlayerStatus,
  type AudioPlayer
} from "expo-audio";
import {
  EncodingType,
  cacheDirectory,
  deleteAsync,
  writeAsStringAsync
} from "expo-file-system/legacy";
import { useEffect, useMemo, useRef } from "react";
import type { NavaiMobileSpeechPlayer, SynthesizeSpeechResult } from "@navai/voice-mobile";

const DEFAULT_AUDIO_MIME_TYPE = "audio/mpeg";

function normalizeMimeType(value: unknown): string {
  return typeof value === "string" && value.trim() !== "" ? value.trim().toLowerCase() : DEFAULT_AUDIO_MIME_TYPE;
}

function extensionFromMimeType(mimeType: string): string {
  switch (mimeType) {
    case "audio/mpeg":
    case "audio/mp3":
      return ".mp3";
    case "audio/wav":
    case "audio/x-wav":
      return ".wav";
    case "audio/ogg":
      return ".ogg";
    case "audio/aac":
      return ".aac";
    case "audio/flac":
      return ".flac";
    default:
      return ".mp3";
  }
}

function createPlaybackFileUri(mimeType: string): string {
  if (!cacheDirectory) {
    throw new Error("Expo cache directory is not available for speech playback.");
  }

  const suffix = Math.random().toString(36).slice(2, 10);
  return `${cacheDirectory}navai-tts-${Date.now()}-${suffix}${extensionFromMimeType(mimeType)}`;
}

async function deleteFileIfPresent(fileUri: string | null): Promise<void> {
  if (!fileUri) {
    return;
  }

  try {
    await deleteAsync(fileUri, { idempotent: true });
  } catch {
    // Ignore cache cleanup failures.
  }
}

async function stopPlayer(player: AudioPlayer): Promise<void> {
  try {
    player.pause();
  } catch {
    // Ignore pause failures during cleanup.
  }

  try {
    await player.seekTo(0);
  } catch {
    // Ignore seek failures during cleanup.
  }

  try {
    player.replace(null);
  } catch {
    // Ignore unload failures during cleanup.
  }
}

type PendingPlayback = {
  generation: number;
  resolve: () => void;
};

export function useElevenLabsSpeechPlayer(): NavaiMobileSpeechPlayer {
  const player = useAudioPlayer(null, {
    keepAudioSessionActive: true,
    updateInterval: 100
  });
  const playerStatus = useAudioPlayerStatus(player);
  const activeFileUriRef = useRef<string | null>(null);
  const playbackGenerationRef = useRef(0);
  const pendingPlaybackRef = useRef<PendingPlayback | null>(null);

  useEffect(() => {
    const pending = pendingPlaybackRef.current;
    if (!pending) {
      return;
    }

    if (playbackGenerationRef.current !== pending.generation) {
      pendingPlaybackRef.current = null;
      pending.resolve();
      return;
    }

    const reachedEnd =
      playerStatus.isLoaded &&
      !playerStatus.playing &&
      playerStatus.duration > 0 &&
      playerStatus.currentTime >= playerStatus.duration;

    if (playerStatus.didJustFinish || reachedEnd) {
      pendingPlaybackRef.current = null;
      pending.resolve();
    }
  }, [
    playerStatus.currentTime,
    playerStatus.didJustFinish,
    playerStatus.duration,
    playerStatus.isLoaded,
    playerStatus.playing
  ]);

  useEffect(() => {
    return () => {
      playbackGenerationRef.current += 1;
      if (pendingPlaybackRef.current) {
        pendingPlaybackRef.current.resolve();
        pendingPlaybackRef.current = null;
      }
      void stopPlayer(player);
      void deleteFileIfPresent(activeFileUriRef.current);
      activeFileUriRef.current = null;
    };
  }, [player]);

  return useMemo<NavaiMobileSpeechPlayer>(
    () => ({
      play: async (input: SynthesizeSpeechResult): Promise<void> => {
        const mimeType = normalizeMimeType(input.mimeType);
        const generation = playbackGenerationRef.current + 1;
        playbackGenerationRef.current = generation;

        await setAudioModeAsync({
          interruptionMode: "duckOthers",
          playsInSilentMode: true
        });

        if (pendingPlaybackRef.current) {
          pendingPlaybackRef.current.resolve();
          pendingPlaybackRef.current = null;
        }
        await stopPlayer(player);
        await deleteFileIfPresent(activeFileUriRef.current);
        activeFileUriRef.current = null;

        const fileUri = createPlaybackFileUri(mimeType);
        activeFileUriRef.current = fileUri;

        try {
          await writeAsStringAsync(fileUri, input.audioBase64, {
            encoding: EncodingType.Base64
          });

          player.loop = false;
          player.muted = false;
          player.volume = 1;
          player.replace({ uri: fileUri });
          const playbackComplete = new Promise<void>((resolve) => {
            pendingPlaybackRef.current = {
              generation,
              resolve
            };
          });
          player.play();

          await playbackComplete;
        } finally {
          const pendingPlayback = pendingPlaybackRef.current as PendingPlayback | null;
          if (pendingPlayback !== null && pendingPlayback.generation === generation) {
            pendingPlayback.resolve();
            pendingPlaybackRef.current = null;
          }

          if (activeFileUriRef.current === fileUri) {
            activeFileUriRef.current = null;
          }

          await stopPlayer(player);
          await deleteFileIfPresent(fileUri);
        }
      },
      stop: async (): Promise<void> => {
        playbackGenerationRef.current += 1;
        if (pendingPlaybackRef.current) {
          pendingPlaybackRef.current.resolve();
          pendingPlaybackRef.current = null;
        }
        await stopPlayer(player);
        await deleteFileIfPresent(activeFileUriRef.current);
        activeFileUriRef.current = null;
      }
    }),
    [player]
  );
}
