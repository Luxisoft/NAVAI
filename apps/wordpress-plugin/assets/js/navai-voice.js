(function () {
  "use strict";

  var runtime = window.NAVAI_VOICE_RUNTIME || {};
  var RESERVED_TOOL_NAMES = runtime.RESERVED_TOOL_NAMES || {};
  var DEFAULT_WEBRTC_URL = runtime.DEFAULT_WEBRTC_URL || "https://api.openai.com/v1/realtime/calls";
  var DEFAULT_MODEL = runtime.DEFAULT_MODEL || "gpt-realtime";
  var MAX_LOG_LINES = typeof runtime.MAX_LOG_LINES === "number" ? runtime.MAX_LOG_LINES : 120;
  var GLOBAL_ACTIVE_STORAGE_KEY = runtime.GLOBAL_ACTIVE_STORAGE_KEY || "navai_voice_global_active";
  var GLOBAL_SESSION_KEY_STORAGE_KEY = runtime.GLOBAL_SESSION_KEY_STORAGE_KEY || "navai_voice_session_key";
  var isRecord = runtime.isRecord;
  var asTrimmedString = runtime.asTrimmedString;
  var parseJsonSafe = runtime.parseJsonSafe;
  var getSafeStorage = runtime.getSafeStorage;
  var safeJsonStringify = runtime.safeJsonStringify;
  var sanitizeSessionKey = runtime.sanitizeSessionKey;
  var generateSessionKey = runtime.generateSessionKey;
  var normalizeMatchValue = runtime.normalizeMatchValue;
  var getGlobalConfig = runtime.getGlobalConfig;
  var getMessage = runtime.getMessage;
  var normalizeRoutes = runtime.normalizeRoutes;
  var normalizeRoadmapPhases = runtime.normalizeRoadmapPhases;
  var resolveAllowedRoute = runtime.resolveAllowedRoute;
  var buildToolDefinitions = runtime.buildToolDefinitions;
  var buildAssistantInstructions = runtime.buildAssistantInstructions;
  var readErrorMessage = runtime.readErrorMessage;
  var normalizeSpeechProvider =
    runtime.normalizeSpeechProvider ||
    function (value) {
      return asTrimmedString(value).toLowerCase() === "elevenlabs" ? "elevenlabs" : "openai";
    };
  var requestClientSecret = runtime.requestClientSecret;
  var requestSpeechSynthesis = runtime.requestSpeechSynthesis;
  var requestBackendFunctions = runtime.requestBackendFunctions;
  var requestRoutes = runtime.requestRoutes;
  var executeBackendFunction = runtime.executeBackendFunction;
  var sendSessionMessages = runtime.sendSessionMessages;
  var INTERRUPTED_STATE_RESET_MS = 900;
  var PTT_HOLD_DELAY_MS = 180;

  function toConfigBool(value, fallback) {
    if (typeof value === "boolean") {
      return value;
    }
    if (typeof value === "string") {
      var normalized = value.trim().toLowerCase();
      if (normalized === "1" || normalized === "true" || normalized === "yes" || normalized === "on") {
        return true;
      }
      if (normalized === "0" || normalized === "false" || normalized === "no" || normalized === "off") {
        return false;
      }
    }
    return !!fallback;
  }

  function clampNumber(value, fallback, min, max) {
    var number = typeof value === "number" ? value : Number(value);
    if (!isFinite(number)) {
      number = fallback;
    }
    if (typeof min === "number" && number < min) {
      number = min;
    }
    if (typeof max === "number" && number > max) {
      number = max;
    }
    return number;
  }

  function normalizeVoiceInputMode(value) {
    var mode = asTrimmedString(value).toLowerCase();
    return mode === "ptt" ? "ptt" : "vad";
  }

  function normalizeTurnDetectionMode(value) {
    var mode = asTrimmedString(value).toLowerCase();
    if (mode === "semantic_vad") {
      return "semantic_vad";
    }
    if (mode === "none" || mode === "disabled") {
      return "none";
    }
    return "server_vad";
  }

  function resolveAssistantVoiceStateFromRealtimeEvent(type, speechProvider) {
    var normalizedType = asTrimmedString(type).toLowerCase();
    if (!normalizedType) {
      return null;
    }

    if (normalizeSpeechProvider(speechProvider) === "elevenlabs") {
      if (
        normalizedType === "response.canceled" ||
        normalizedType === "response.cancelled" ||
        normalizedType === "conversation.interrupted" ||
        normalizedType === "error"
      ) {
        return "idle";
      }

      return null;
    }

    if (
      normalizedType === "response.output_audio.delta" ||
      normalizedType === "response.audio.delta" ||
      normalizedType === "output_audio_buffer.started"
    ) {
      return "speaking";
    }

    if (
      normalizedType === "response.canceled" ||
      normalizedType === "response.cancelled" ||
      normalizedType === "conversation.interrupted" ||
      normalizedType === "output_audio_buffer.stopped" ||
      normalizedType === "output_audio_buffer.cleared" ||
      normalizedType === "error"
    ) {
      return "idle";
    }

    return null;
  }

  function readAssistantTextFromResponseOutput(items) {
    if (!Array.isArray(items)) {
      return "";
    }

    var parts = [];
    for (var i = 0; i < items.length; i += 1) {
      var item = items[i];
      if (!isRecord(item) || item.type !== "message" || item.role !== "assistant" || !Array.isArray(item.content)) {
        continue;
      }

      for (var j = 0; j < item.content.length; j += 1) {
        var chunk = item.content[j];
        if (!isRecord(chunk)) {
          continue;
        }

        var text =
          chunk.type === "output_text"
            ? asTrimmedString(chunk.text)
            : chunk.type === "output_audio"
              ? asTrimmedString(chunk.transcript)
              : "";
        if (text) {
          parts.push(text);
        }
      }
    }

    return parts.join("\n").trim();
  }

  function extractAssistantTextFromRealtimeEvent(event) {
    if (!isRecord(event)) {
      return null;
    }

    var eventType = asTrimmedString(event.type).toLowerCase();
    if (
      eventType === "response.output_text.done" ||
      eventType === "response.text.done" ||
      eventType === "response.audio_transcript.done"
    ) {
      var directText = asTrimmedString(event.text || event.transcript || "");
      if (!directText) {
        return null;
      }

      var directKey = [eventType, asTrimmedString(event.response_id), asTrimmedString(event.item_id)]
        .filter(function (value) {
          return !!value;
        })
        .join(":");

      return {
        key: directKey || eventType + ":" + directText,
        text: directText
      };
    }

    if (eventType === "response.done" && isRecord(event.response) && Array.isArray(event.response.output)) {
      var text = readAssistantTextFromResponseOutput(event.response.output);
      if (!text) {
        return null;
      }

      var responseId = asTrimmedString(event.response.id);
      return {
        key: responseId ? "response.done:" + responseId : "response.done:" + text,
        text: text
      };
    }

    return null;
  }

  function audioUrlFromSynthesis(result) {
    if (!isRecord(result)) {
      return "";
    }

    var audioBase64 = asTrimmedString(result.audioBase64);
    if (!audioBase64 || typeof window.atob !== "function" || typeof Blob === "undefined" || !window.URL) {
      return "";
    }

    var mimeType = asTrimmedString(result.mimeType) || "audio/mpeg";
    var raw = window.atob(audioBase64);
    var bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i += 1) {
      bytes[i] = raw.charCodeAt(i);
    }

    return window.URL.createObjectURL(
      new Blob([bytes], {
        type: mimeType
      })
    );
  }

  function extractRealtimeResponsePayload(event) {
    if (!isRecord(event)) {
      return {};
    }

    if (isRecord(event.response)) {
      return event.response;
    }

    if (isRecord(event.data) && isRecord(event.data.response)) {
      return event.data.response;
    }

    return {};
  }

  function extractRealtimeUsagePayload(event) {
    if (!isRecord(event)) {
      return null;
    }

    var responsePayload = extractRealtimeResponsePayload(event);

    if (isRecord(event.usage)) {
      return event.usage;
    }

    if (isRecord(responsePayload.usage)) {
      return responsePayload.usage;
    }

    if (isRecord(event.response_usage)) {
      return event.response_usage;
    }

    if (isRecord(responsePayload.response_usage)) {
      return responsePayload.response_usage;
    }

    return null;
  }

  function extractRealtimeModelFromEvent(event, fallbackModel) {
    if (!isRecord(event)) {
      return asTrimmedString(fallbackModel || "");
    }

    var responsePayload = extractRealtimeResponsePayload(event);
    return asTrimmedString(responsePayload.model || event.model || fallbackModel || "");
  }

  function buildRealtimeUsageTrackingContent(event, fallbackModel) {
    if (!isRecord(event)) {
      return null;
    }

    var type = asTrimmedString(event.type);
    if (!type) {
      return null;
    }

    var responsePayload = extractRealtimeResponsePayload(event);
    var usagePayload = extractRealtimeUsagePayload(event);
    if (!isRecord(usagePayload) && type !== "response.done") {
      return null;
    }

    return {
      type: type,
      response_id: asTrimmedString(responsePayload.id || event.response_id || ""),
      status: asTrimmedString(responsePayload.status || event.status || ""),
      model: extractRealtimeModelFromEvent(event, fallbackModel),
      usage: isRecord(usagePayload) ? usagePayload : null,
      output_item_count: Array.isArray(responsePayload.output)
        ? responsePayload.output.length
        : Array.isArray(event.output)
          ? event.output.length
          : 0
    };
  }

  function getOrbRuntime() {
    if (!window.NAVAI_VOICE_ORB || typeof window.NAVAI_VOICE_ORB.create !== "function") {
      return null;
    }
    return window.NAVAI_VOICE_ORB;
  }

  function NavaiVoiceWidget(container) {
    this.container = container;
    this.orbSurfaceEl = container.querySelector(".navai-voice-orb-surface");
    this.button = container.querySelector(".navai-voice-toggle");
    this.buttonLabelEl = this.button ? this.button.querySelector(".navai-voice-toggle-text") : null;
    this.buttonIconEl = this.button ? this.button.querySelector(".navai-voice-toggle-icon") : null;
    this.textForm = container.querySelector(".navai-voice-text-form");
    this.textInput = container.querySelector(".navai-voice-text-input");
    this.textSendButton = container.querySelector(".navai-voice-text-send");
    this.statusEl = container.querySelector(".navai-voice-status");
    this.logEl = container.querySelector(".navai-voice-log");
    this.logCopyButton = container.querySelector(".navai-voice-log-copy");
    this.logFilterInputs = Array.prototype.slice.call(container.querySelectorAll("[data-navai-log-filter]"));
    this.globalConfig = getGlobalConfig();

    this.routes = normalizeRoutes(this.globalConfig.routes || []);
    this.roadmapPhases = normalizeRoadmapPhases(this.globalConfig.roadmapPhases || []);
    this.backendFunctions = [];
    this.directAliases = [];
    this.handledCalls = {};
    this.logEntries = [];
    this.logFilterState = this.createDefaultLogFilterState();

    this.state = "idle";
    this.activityState = "idle";
    this.lastPublishedAgentStateKey = "";
    this.lastPublishedAssistantVoiceState = "idle";
    this.localStream = null;
    this.peerConnection = null;
    this.eventsChannel = null;
    this.remoteAudioEl = null;
    this.ttsAudioEl = null;
    this.ttsAudioUrl = "";
    this.ttsPlaybackGeneration = 0;
    this.connectionMode = "voice";
    this.pendingTextMessages = [];
    this.interruptedResetTimer = 0;
    this.pttPressed = false;
    this.pttHoldTimer = 0;
    this.suppressToggleClickUntil = 0;
    this.assistantVoiceState = "idle";
    this.orbInstance = null;

    this.startLabel = asTrimmedString(container.dataset.startLabel) || "Start Voice";
    this.stopLabel = asTrimmedString(container.dataset.stopLabel) || "Stop Voice";
    this.debugEnabled = container.dataset.debug === "1";
    this.widgetMode = asTrimmedString(container.dataset.widgetMode) || "shortcode";
    this.isFloating = container.dataset.floating === "1";
    this.persistActive = container.dataset.persistActive === "1";
    this.buttonSide = asTrimmedString(container.dataset.buttonSide) === "right" ? "right" : "left";
    this.voiceInputMode = normalizeVoiceInputMode(
      container.dataset.voiceInputMode ||
        (isRecord(this.globalConfig.realtime) ? this.globalConfig.realtime.voiceInputMode : "")
    );
    this.textInputEnabled = container.dataset.textEnabled === "1";
    this.textPlaceholder =
      asTrimmedString(container.dataset.textPlaceholder) ||
      (isRecord(this.globalConfig.realtime) ? asTrimmedString(this.globalConfig.realtime.textPlaceholder) : "") ||
      "Write a message...";
    var widgetConfig = isRecord(this.globalConfig.widget) ? this.globalConfig.widget : {};
    this.autoInitializeOnLoad = toConfigBool(container.dataset.autoInitialize, widgetConfig.autoInitializeOnLoad);
    this.assistantStopToolEnabled = toConfigBool(
      container.dataset.assistantStopToolEnabled,
      widgetConfig.allowAssistantStopTool
    );
    this.navigationInProgress = false;
    this.storage = getSafeStorage();
    this.sessionKey = "";
    this.sessionMessageQueue = [];
    this.sessionFlushTimer = 0;
    this.sessionFlushInFlight = false;
    this.realtimeSettings = this.resolveRealtimeSettings();
    this.speechProvider = normalizeSpeechProvider(isRecord(this.globalConfig.speech) ? this.globalConfig.speech.provider : "");
    this.spokenAssistantKeys = Object.create(null);

    this.modelOverride = asTrimmedString(container.dataset.model);
    this.voiceOverride = asTrimmedString(container.dataset.voice);
    this.instructionsOverride = asTrimmedString(container.dataset.instructions);
    this.languageOverride = asTrimmedString(container.dataset.language);
    this.voiceAccentOverride = asTrimmedString(container.dataset.voiceAccent);
    this.voiceToneOverride = asTrimmedString(container.dataset.voiceTone);
    this.sessionModel = "";

    if (this.button) {
      if (this.buttonLabelEl) {
        this.buttonLabelEl.textContent = this.startLabel;
      } else {
        this.button.textContent = this.startLabel;
      }
      this.button.addEventListener("click", this.handleToggle.bind(this));
      this.bindPushToTalkEvents();
    }

    if (this.textInput) {
      this.textInput.placeholder = this.textPlaceholder;
    }

    if (this.textForm) {
      this.textForm.addEventListener("submit", this.handleTextSubmit.bind(this));
    }

    if (this.persistActive) {
      var widget = this;
      window.addEventListener("beforeunload", function () {
        widget.flushSessionMessages(true);
        if (widget.state === "connected" || widget.state === "connecting" || widget.navigationInProgress) {
          widget.storeActivePreference(true);
        }
      });
    }

    this.sessionKey = this.loadSessionKey();

    this.syncOrbRuntime();
    this.applyStateClass();
    this.refreshButton();
    this.updatePttButton();
    this.updateTextControlsDisabledState(false);
    this.updateStatusIndicator();
    this.bindLogControls();
    this.renderLog();

    if (this.autoInitializeOnLoad) {
      this.appendLog("Auto-starting NAVAI voice widget on page load.", "info");
      var autoStartWidget = this;
      window.setTimeout(function () {
        autoStartWidget.start();
      }, 350);
    } else if (this.persistActive && this.shouldAutoResume()) {
      this.appendLog("Auto-resuming global voice widget from previous page.", "info");
      var resumeWidget = this;
      window.setTimeout(function () {
        resumeWidget.start();
      }, 350);
    }
  }

  NavaiVoiceWidget.prototype.handleToggle = function (event) {
    if (this.consumeToggleClickSuppression()) {
      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }
      return;
    }

    if (this.isPushToTalkArmed()) {
      return;
    }

    if (this.state === "connecting") {
      return;
    }

    if (this.state === "connected") {
      this.stop();
      return;
    }

    this.start();
  };

  NavaiVoiceWidget.prototype.queueToggleClickSuppression = function () {
    this.suppressToggleClickUntil = Date.now() + 400;
  };

  NavaiVoiceWidget.prototype.consumeToggleClickSuppression = function () {
    if (!this.suppressToggleClickUntil) {
      return false;
    }

    if (Date.now() > this.suppressToggleClickUntil) {
      this.suppressToggleClickUntil = 0;
      return false;
    }

    this.suppressToggleClickUntil = 0;
    return true;
  };

  NavaiVoiceWidget.prototype.setStatus = function (message) {
    if (this.statusEl) {
      this.statusEl.textContent = message;
    }
  };

  NavaiVoiceWidget.prototype.isAssistantSpeakingActive = function () {
    return this.state === "connected" && this.assistantVoiceState === "speaking";
  };

  NavaiVoiceWidget.prototype.setAssistantVoiceState = function (state) {
    var nextState = asTrimmedString(state).toLowerCase() === "speaking" ? "speaking" : "idle";
    if (this.assistantVoiceState === nextState) {
      return;
    }

    this.assistantVoiceState = nextState;
    if (this.state !== "connected") {
      this.syncOrbRuntime();
      this.publishAgentState("assistant_voice_state_changed");
      return;
    }

    if (nextState === "speaking") {
      this.setActivityState("speaking");
      return;
    }

    if (this.activityState === "speaking") {
      this.setActivityState(this.pttPressed ? "listening" : "idle");
      return;
    }

    this.updateStatusIndicator();
    this.syncOrbRuntime();
    this.publishAgentState("assistant_voice_state_changed");
  };

  NavaiVoiceWidget.prototype.resolveOrbRuntimeOptions = function () {
    var isAssistantSpeaking = this.isAssistantSpeakingActive();
    return {
      hoverIntensity: isAssistantSpeaking ? 0.66 : 0.08,
      rotateOnHover: true,
      forceHoverState: isAssistantSpeaking,
      enablePointerHover: false,
      backgroundColor: "#060914",
      animate: this.state !== "error"
    };
  };

  NavaiVoiceWidget.prototype.syncOrbRuntime = function () {
    if (!this.orbSurfaceEl) {
      return;
    }

    var shouldHighlight = this.state === "connecting" || this.state === "connected";
    this.orbSurfaceEl.classList.toggle("is-highlighted", shouldHighlight);
    var runtime = getOrbRuntime();
    if (!runtime) {
      return;
    }

    if (!this.orbInstance) {
      this.orbInstance = runtime.create(this.orbSurfaceEl, this.resolveOrbRuntimeOptions());
      return;
    }

    runtime.update(this.orbInstance, this.resolveOrbRuntimeOptions());
  };

  NavaiVoiceWidget.prototype.getAssistantVoiceState = function () {
    return this.state === "connected" ? this.assistantVoiceState : "idle";
  };

  NavaiVoiceWidget.prototype.emitWidgetStateEvent = function (eventName, detail) {
    if (!eventName || typeof window.CustomEvent !== "function") {
      return;
    }

    var payload = isRecord(detail) ? detail : {};

    try {
      if (this.container && typeof this.container.dispatchEvent === "function") {
        this.container.dispatchEvent(new window.CustomEvent(eventName, { detail: payload }));
      }
    } catch (_error) {
      // noop
    }

    try {
      if (typeof window.dispatchEvent === "function") {
        window.dispatchEvent(new window.CustomEvent(eventName, { detail: payload }));
      }
    } catch (_error2) {
      // noop
    }
  };

  NavaiVoiceWidget.prototype.publishAgentState = function (reason) {
    var assistantVoiceState = this.getAssistantVoiceState();
    var payload = {
      reason: asTrimmedString(reason) || "state_update",
      connection_state: this.state,
      activity_state: this.activityState,
      assistant_voice_state: assistantVoiceState,
      is_assistant_speaking: assistantVoiceState === "speaking",
      connection_mode: this.connectionMode,
      widget_mode: this.widgetMode
    };
    var nextStateKey = [
      payload.connection_state,
      payload.activity_state,
      payload.assistant_voice_state,
      payload.connection_mode
    ].join("|");
    if (this.lastPublishedAgentStateKey === nextStateKey) {
      return;
    }

    this.lastPublishedAgentStateKey = nextStateKey;

    if (this.container && this.container.dataset) {
      this.container.dataset.navaiConnectionState = payload.connection_state;
      this.container.dataset.navaiActivityState = payload.activity_state;
      this.container.dataset.navaiAssistantVoiceState = payload.assistant_voice_state;
      this.container.dataset.navaiAssistantSpeaking = payload.is_assistant_speaking ? "1" : "0";
    }

    this.emitWidgetStateEvent("navai:agent-state", payload);

    var previousAssistantVoiceState = this.lastPublishedAssistantVoiceState || "idle";
    if (payload.assistant_voice_state === "speaking" && previousAssistantVoiceState !== "speaking") {
      this.emitWidgetStateEvent("navai:agent-speaking-start", payload);
    } else if (payload.assistant_voice_state !== "speaking" && previousAssistantVoiceState === "speaking") {
      this.emitWidgetStateEvent("navai:agent-speaking-stop", payload);
    }

    this.lastPublishedAssistantVoiceState = payload.assistant_voice_state;
  };

  NavaiVoiceWidget.prototype.resolveRealtimeSettings = function () {
    var config = isRecord(this.globalConfig.realtime) ? this.globalConfig.realtime : {};
    var vadConfig = isRecord(config.vad) ? config.vad : {};

    return {
      turnDetectionMode: normalizeTurnDetectionMode(config.turnDetectionMode),
      interruptResponse: toConfigBool(config.interruptResponse, true),
      vadThreshold: clampNumber(vadConfig.threshold, 0.5, 0.1, 0.99),
      vadSilenceDurationMs: Math.round(clampNumber(vadConfig.silenceDurationMs, 800, 100, 5000)),
      vadPrefixPaddingMs: Math.round(clampNumber(vadConfig.prefixPaddingMs, 300, 0, 2000))
    };
  };

  NavaiVoiceWidget.prototype.isPushToTalkMode = function () {
    return this.voiceInputMode === "ptt";
  };

  NavaiVoiceWidget.prototype.isPushToTalkArmed = function () {
    return this.isPushToTalkMode() && this.state === "connected" && this.connectionMode !== "text";
  };

  NavaiVoiceWidget.prototype.getEffectiveTurnDetectionMode = function () {
    if (this.isPushToTalkMode()) {
      return "none";
    }
    return normalizeTurnDetectionMode(this.realtimeSettings.turnDetectionMode);
  };

  NavaiVoiceWidget.prototype.buildTurnDetectionConfig = function () {
    var mode = this.getEffectiveTurnDetectionMode();
    if (mode === "none") {
      return null;
    }

    var config = {
      type: mode,
      interrupt_response: !!this.realtimeSettings.interruptResponse
    };

    if (mode === "server_vad") {
      config.threshold = this.realtimeSettings.vadThreshold;
      config.silence_duration_ms = this.realtimeSettings.vadSilenceDurationMs;
      config.prefix_padding_ms = this.realtimeSettings.vadPrefixPaddingMs;
    }

    return config;
  };

  NavaiVoiceWidget.prototype.clearInterruptedStateTimer = function () {
    if (this.interruptedResetTimer) {
      window.clearTimeout(this.interruptedResetTimer);
      this.interruptedResetTimer = 0;
    }
  };

  NavaiVoiceWidget.prototype.clearPendingPttHoldTimer = function () {
    if (this.pttHoldTimer) {
      window.clearTimeout(this.pttHoldTimer);
      this.pttHoldTimer = 0;
    }
  };

  NavaiVoiceWidget.prototype.setActivityState = function (activity, options) {
    var next = asTrimmedString(activity).toLowerCase();
    if (!next || ["idle", "listening", "speaking", "interrupted"].indexOf(next) === -1) {
      next = "idle";
    }

    var opts = isRecord(options) ? options : {};
    this.clearInterruptedStateTimer();
    this.activityState = next;
    this.applyStateClass();
    this.updatePttButton();
    this.updateStatusIndicator();

    if (next === "interrupted") {
      var widget = this;
      this.interruptedResetTimer = window.setTimeout(function () {
        widget.interruptedResetTimer = 0;
        if (widget.state === "connected") {
          widget.activityState = widget.pttPressed ? "listening" : "idle";
          widget.applyStateClass();
          widget.updatePttButton();
          widget.updateStatusIndicator();
        }
      }, typeof opts.resetAfterMs === "number" ? opts.resetAfterMs : INTERRUPTED_STATE_RESET_MS);
    }
  };

  NavaiVoiceWidget.prototype.updateStatusIndicator = function (overrideMessage) {
    if (!this.statusEl) {
      return;
    }

    if (typeof overrideMessage === "string" && overrideMessage.trim() !== "") {
      this.setStatus(overrideMessage);
      return;
    }

    if (this.state === "connecting") {
      return;
    }

    if (this.state === "error") {
      return;
    }

    if (this.state === "idle") {
      this.setStatus(getMessage(this.globalConfig, "idle", "Idle"));
      return;
    }

    if (this.activityState === "interrupted") {
      this.setStatus(getMessage(this.globalConfig, "interrupted", "Interrupted"));
      return;
    }

    if (this.activityState === "speaking") {
      this.setStatus(getMessage(this.globalConfig, "speaking", "Speaking..."));
      return;
    }

    if (this.activityState === "listening") {
      if (this.isPushToTalkMode() && this.pttPressed) {
        this.setStatus(getMessage(this.globalConfig, "pttRelease", "Release to send"));
      } else {
        this.setStatus(getMessage(this.globalConfig, "listening", "Listening..."));
      }
      return;
    }

    if (this.connectionMode === "text") {
      this.setStatus(getMessage(this.globalConfig, "connectedText", "Connected (text mode)"));
      return;
    }

    if (this.isPushToTalkMode()) {
      this.setStatus(getMessage(this.globalConfig, "pttHold", "Hold to talk"));
      return;
    }

    this.setStatus(getMessage(this.globalConfig, "connected", "Connected"));
  };

  NavaiVoiceWidget.prototype.updatePttButton = function () {
    var enabled = this.isPushToTalkArmed();
    this.container.classList.toggle("is-ptt-pressed", enabled && this.pttPressed);

    if (!this.button) {
      return;
    }

    if (enabled) {
      var pttLabel = this.pttPressed
        ? getMessage(this.globalConfig, "pttRelease", "Release to send")
        : getMessage(this.globalConfig, "pttHold", "Hold to talk");

      if (this.buttonLabelEl) {
        this.buttonLabelEl.textContent = pttLabel;
      }
      this.button.setAttribute("aria-pressed", this.pttPressed ? "true" : "false");
      this.button.setAttribute("aria-label", pttLabel);
      this.button.setAttribute("title", pttLabel);
      return;
    }

    this.button.removeAttribute("title");
  };

  NavaiVoiceWidget.prototype.updateTextControlsDisabledState = function (busy) {
    if (!this.textInputEnabled) {
      return;
    }
    var disableInput = !!busy || this.state === "connecting";
    if (this.textInput) {
      this.textInput.disabled = disableInput;
    }
    if (this.textSendButton) {
      this.textSendButton.disabled = disableInput;
    }
  };

  NavaiVoiceWidget.prototype.bindPushToTalkEvents = function () {
    if (!this.button) {
      return;
    }

    var widget = this;
    function isKeyboardTrigger(event) {
      var key = event && event.key ? String(event.key).toLowerCase() : "";
      return key === " " || key === "spacebar" || key === "enter";
    }

    function beginGesture(event) {
      if (!widget.isPushToTalkArmed()) {
        return;
      }

      if (event && typeof event.button === "number" && event.button !== 0) {
        return;
      }

      if (widget.pttPressed || widget.pttHoldTimer) {
        return;
      }

      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }

      widget.pttHoldTimer = window.setTimeout(function () {
        widget.pttHoldTimer = 0;
        widget.handlePttPress();
      }, PTT_HOLD_DELAY_MS);
    }

    function finishGesture(event) {
      if (!widget.isPushToTalkMode()) {
        return;
      }

      if (!widget.pttPressed && !widget.pttHoldTimer) {
        return;
      }

      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }

      if (widget.pttHoldTimer) {
        widget.clearPendingPttHoldTimer();
        if (widget.state === "connected" && widget.connectionMode !== "text") {
          widget.queueToggleClickSuppression();
          widget.stop();
        }
        return;
      }

      widget.queueToggleClickSuppression();
      widget.handlePttRelease();
    }

    function cancelGesture(event) {
      if (!widget.isPushToTalkMode()) {
        return;
      }

      if (!widget.pttPressed && !widget.pttHoldTimer) {
        return;
      }

      if (event && typeof event.preventDefault === "function") {
        event.preventDefault();
      }

      if (widget.pttHoldTimer) {
        widget.clearPendingPttHoldTimer();
        return;
      }

      widget.queueToggleClickSuppression();
      widget.handlePttRelease();
    }

    if (window.PointerEvent) {
      this.button.addEventListener("pointerdown", function (event) {
        beginGesture(event);
        if (!widget.isPushToTalkArmed()) {
          return;
        }

        if (
          event &&
          typeof event.pointerId === "number" &&
          typeof widget.button.setPointerCapture === "function"
        ) {
          try {
            widget.button.setPointerCapture(event.pointerId);
          } catch (_error) {
            // noop
          }
        }
      });
      this.button.addEventListener("pointerup", finishGesture);
      this.button.addEventListener("pointercancel", cancelGesture);
      this.button.addEventListener("lostpointercapture", cancelGesture);
      this.button.addEventListener("pointerleave", function (event) {
        if (event && event.pointerType === "mouse") {
          cancelGesture(event);
        }
      });
    } else {
      this.button.addEventListener("mousedown", beginGesture);
      this.button.addEventListener("mouseup", finishGesture);
      this.button.addEventListener("mouseleave", cancelGesture);
      this.button.addEventListener("touchstart", beginGesture, { passive: false });
      this.button.addEventListener("touchend", finishGesture);
      this.button.addEventListener("touchcancel", cancelGesture);
    }

    this.button.addEventListener("keydown", function (event) {
      if (!isKeyboardTrigger(event) || (event && event.repeat)) {
        return;
      }
      beginGesture(event);
    });
    this.button.addEventListener("keyup", function (event) {
      if (!isKeyboardTrigger(event)) {
        return;
      }
      finishGesture(event);
    });
    this.button.addEventListener("blur", cancelGesture);
  };

  NavaiVoiceWidget.prototype.requestRealtimeInterruption = function () {
    if (!this.realtimeSettings.interruptResponse) {
      return;
    }

    try {
      this.sendRealtimeEvent({ type: "response.cancel" });
    } catch (_error) {
      // noop
    }
    try {
      this.sendRealtimeEvent({ type: "output_audio_buffer.clear" });
    } catch (_error2) {
      // noop
    }
    this.setActivityState("interrupted");
  };

  NavaiVoiceWidget.prototype.handlePttPress = function () {
    if (!this.isPushToTalkMode() || this.state !== "connected" || this.connectionMode === "text") {
      return;
    }
    if (this.pttPressed) {
      return;
    }

    this.pttPressed = true;
    if (this.activityState === "speaking") {
      this.requestRealtimeInterruption();
    }

    try {
      this.sendRealtimeEvent({ type: "input_audio_buffer.clear" });
    } catch (error) {
      this.appendLog("Failed to start push-to-talk: " + String(error), "error");
      this.pttPressed = false;
      this.updatePttButton();
      return;
    }

    this.setActivityState("listening");
  };

  NavaiVoiceWidget.prototype.handlePttRelease = function () {
    if (!this.pttPressed) {
      return;
    }
    this.pttPressed = false;

    if (this.state !== "connected" || this.connectionMode === "text") {
      this.updatePttButton();
      this.updateStatusIndicator();
      return;
    }

    try {
      this.sendRealtimeEvent({ type: "input_audio_buffer.commit" });
      this.sendRealtimeEvent({ type: "response.create" });
      this.setActivityState("idle");
    } catch (error) {
      this.appendLog("Failed to finish push-to-talk: " + String(error), "error");
      this.setActivityState("idle");
    }
  };

  NavaiVoiceWidget.prototype.handleTextSubmit = function (event) {
    if (event && typeof event.preventDefault === "function") {
      event.preventDefault();
    }

    if (!this.textInput) {
      return;
    }

    var text = asTrimmedString(this.textInput.value);
    if (!text) {
      return;
    }

    this.textInput.value = "";
    this.sendTextMessage(text);
  };

  NavaiVoiceWidget.prototype.flushPendingTextMessages = function () {
    if (!Array.isArray(this.pendingTextMessages) || this.pendingTextMessages.length === 0) {
      return;
    }
    if (this.state !== "connected") {
      return;
    }

    var queued = this.pendingTextMessages.slice(0);
    this.pendingTextMessages = [];
    for (var i = 0; i < queued.length; i += 1) {
      this.sendRealtimeTextInput(queued[i]);
      if (this.state !== "connected") {
        break;
      }
    }
  };

  NavaiVoiceWidget.prototype.sendTextMessage = function (text) {
    var message = asTrimmedString(text);
    if (!message) {
      return;
    }

    this.queueSessionMessage({
      direction: "user",
      message_type: "text",
      content_text: message,
      content_json: {
        type: "text_input_manual",
        mode: this.state === "connected" ? this.connectionMode : "text"
      }
    });

    if (this.state === "connected") {
      this.updateTextControlsDisabledState(true);
      this.setStatus(getMessage(this.globalConfig, "sendingText", "Sending text..."));
      this.sendRealtimeTextInput(message);
      return;
    }

    this.pendingTextMessages.push(message);
    this.updateTextControlsDisabledState(true);
    this.setStatus(getMessage(this.globalConfig, "sendingText", "Sending text..."));

    if (this.state === "connecting") {
      return;
    }

    this.start({ skipMicrophone: true, reason: "text" });
  };

  NavaiVoiceWidget.prototype.sendRealtimeTextInput = function (text) {
    var message = asTrimmedString(text);
    if (!message) {
      return;
    }
    if (this.state !== "connected") {
      this.pendingTextMessages.push(message);
      return;
    }

    try {
      this.sendRealtimeEvent({
        type: "conversation.item.create",
        item: {
          type: "message",
          role: "user",
          content: [
            {
              type: "input_text",
              text: message
            }
          ]
        }
      });
      this.sendRealtimeEvent({ type: "response.create" });
      if (this.activityState !== "speaking") {
        this.setActivityState("idle");
      }
    } catch (error) {
      this.appendLog("Failed to send text message: " + String(error), "error");
      this.pendingTextMessages.unshift(message);
    } finally {
      this.updateTextControlsDisabledState(false);
      this.updateStatusIndicator();
    }
  };

  NavaiVoiceWidget.prototype.applyStateClass = function () {
    this.container.classList.remove(
      "is-idle",
      "is-connecting",
      "is-connected",
      "is-error",
      "is-listening",
      "is-speaking",
      "is-interrupted",
      "is-text-mode",
      "is-ptt-mode",
      "is-ptt-pressed"
    );

    if (this.state === "connected") {
      this.container.classList.add("is-connected");
    } else if (this.state === "connecting") {
      this.container.classList.add("is-connecting");
    } else if (this.state === "error") {
      this.container.classList.add("is-error");
    } else {
      this.container.classList.add("is-idle");
    }

    if (this.isPushToTalkMode()) {
      this.container.classList.add("is-ptt-mode");
    }

    if (this.connectionMode === "text" && (this.state === "connected" || this.state === "connecting")) {
      this.container.classList.add("is-text-mode");
    }

    if (this.state === "connected" && this.activityState === "listening") {
      this.container.classList.add("is-listening");
    } else if (this.state === "connected" && this.activityState === "speaking") {
      this.container.classList.add("is-speaking");
    } else if (this.state === "connected" && this.activityState === "interrupted") {
      this.container.classList.add("is-interrupted");
    }

    this.syncOrbRuntime();
    this.publishAgentState("apply_state_class");
  };

  NavaiVoiceWidget.prototype.storeActivePreference = function (enabled) {
    if (!this.persistActive || !this.storage) {
      return;
    }

    try {
      if (enabled) {
        this.storage.setItem(GLOBAL_ACTIVE_STORAGE_KEY, "1");
      } else {
        this.storage.removeItem(GLOBAL_ACTIVE_STORAGE_KEY);
      }
    } catch (_error) {
      // noop
    }
  };

  NavaiVoiceWidget.prototype.shouldAutoResume = function () {
    if (!this.persistActive || !this.storage) {
      return false;
    }

    try {
      return this.storage.getItem(GLOBAL_ACTIVE_STORAGE_KEY) === "1";
    } catch (_error) {
      return false;
    }
  };

  NavaiVoiceWidget.prototype.getSessionStorageKey = function () {
    if (this.widgetMode === "global") {
      return GLOBAL_SESSION_KEY_STORAGE_KEY;
    }
    return GLOBAL_SESSION_KEY_STORAGE_KEY + "_" + this.widgetMode;
  };

  NavaiVoiceWidget.prototype.loadSessionKey = function () {
    if (!this.storage) {
      return "";
    }

    try {
      var stored = this.storage.getItem(this.getSessionStorageKey());
      if (typeof sanitizeSessionKey === "function") {
        return sanitizeSessionKey(stored || "");
      }
      return asTrimmedString(stored);
    } catch (_error) {
      return "";
    }
  };

  NavaiVoiceWidget.prototype.storeSessionKey = function (sessionKey) {
    if (!this.storage) {
      return;
    }

    var clean = typeof sanitizeSessionKey === "function" ? sanitizeSessionKey(sessionKey || "") : asTrimmedString(sessionKey);
    if (!clean) {
      return;
    }

    try {
      this.storage.setItem(this.getSessionStorageKey(), clean);
    } catch (_error) {
      // noop
    }
  };

  NavaiVoiceWidget.prototype.ensureSessionKey = function () {
    var clean = typeof sanitizeSessionKey === "function" ? sanitizeSessionKey(this.sessionKey || "") : asTrimmedString(this.sessionKey);
    if (!clean) {
      clean = this.loadSessionKey();
    }
    if (!clean) {
      clean = typeof generateSessionKey === "function"
        ? generateSessionKey()
        : ("navai_" + (Date.now ? Date.now() : new Date().getTime()) + "_" + Math.random().toString(36).slice(2, 10));
    }

    this.sessionKey = clean;
    this.storeSessionKey(clean);
    return clean;
  };

  NavaiVoiceWidget.prototype.setSessionKey = function (sessionKey) {
    var clean = typeof sanitizeSessionKey === "function" ? sanitizeSessionKey(sessionKey || "") : asTrimmedString(sessionKey);
    if (!clean) {
      return;
    }
    this.sessionKey = clean;
    this.storeSessionKey(clean);
  };

  NavaiVoiceWidget.prototype.createDefaultLogFilterState = function () {
    return {
      agent_response: true,
      function_response: true,
      functions_loaded: true,
      routes_loaded: true,
      realtime_sent: true,
      realtime_recv: true,
      connection: true,
      phases_loaded: true,
      other: true,
      warn: true,
      error: true
    };
  };

  NavaiVoiceWidget.prototype.bindLogControls = function () {
    if (!this.debugEnabled) {
      return;
    }

    var widget = this;
    if (this.logCopyButton) {
      this.logCopyButton.addEventListener("click", function () {
        widget.copyVisibleLog();
      });
    }

    for (var i = 0; i < this.logFilterInputs.length; i += 1) {
      var input = this.logFilterInputs[i];
      var key = asTrimmedString(input && input.getAttribute("data-navai-log-filter")).toLowerCase();
      if (!key) {
        continue;
      }
      input.checked = widget.logFilterState[key] !== false;
      input.addEventListener("change", function (event) {
        var target = event && event.target ? event.target : null;
        if (!target) {
          return;
        }
        var filterKey = asTrimmedString(target.getAttribute("data-navai-log-filter")).toLowerCase();
        if (!filterKey) {
          return;
        }
        widget.logFilterState[filterKey] = !!target.checked;
        widget.renderLog();
      });
    }
  };

  NavaiVoiceWidget.prototype.copyVisibleLog = function () {
    if (!this.logEl) {
      return;
    }

    var text = this.logEl.textContent || "";
    var widget = this;
    var onCopied = function () {
      if (widget.logCopyButton) {
        var original = widget.logCopyButton.textContent;
        widget.logCopyButton.textContent = "Copiado";
        window.setTimeout(function () {
          if (widget.logCopyButton) {
            widget.logCopyButton.textContent = original || "Copiar log";
          }
        }, 1200);
      }
    };

    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      navigator.clipboard.writeText(text).then(onCopied).catch(function () {
        widget.copyVisibleLogFallback(text);
      });
      return;
    }

    this.copyVisibleLogFallback(text);
  };

  NavaiVoiceWidget.prototype.copyVisibleLogFallback = function (text) {
    try {
      var helper = document.createElement("textarea");
      helper.value = text;
      helper.setAttribute("readonly", "readonly");
      helper.style.position = "fixed";
      helper.style.opacity = "0";
      helper.style.pointerEvents = "none";
      document.body.appendChild(helper);
      helper.select();
      document.execCommand("copy");
      document.body.removeChild(helper);
      if (this.logCopyButton) {
        var original = this.logCopyButton.textContent;
        this.logCopyButton.textContent = "Copiado";
        var button = this.logCopyButton;
        window.setTimeout(function () {
          if (button) {
            button.textContent = original || "Copiar log";
          }
        }, 1200);
      }
    } catch (_error) {
      // noop
    }
  };

  NavaiVoiceWidget.prototype.classifyRealtimeLogEntry = function (direction, event) {
    var type = asTrimmedString(event && event.type).toLowerCase();
    var item = isRecord(event) && isRecord(event.item) ? event.item : null;
    var itemType = item && typeof item.type === "string" ? item.type.toLowerCase() : "";
    var itemRole = item && typeof item.role === "string" ? item.role.toLowerCase() : "";

    if (type === "error") {
      return "error";
    }

    if (type === "response.function_call_arguments.done" || type === "response.function_call_arguments.delta") {
      return "function_response";
    }

    if ((type.indexOf("conversation.item.") === 0 || type.indexOf("response.output_item.") === 0) && itemType === "function_call_output") {
      return "function_response";
    }

    if (direction === "sent" && type === "conversation.item.create" && isRecord(event.item) && event.item.type === "function_call_output") {
      return "function_response";
    }

    if (
      type.indexOf("response.output_audio_transcript.") === 0 ||
      type.indexOf("response.audio_transcript.") === 0 ||
      type.indexOf("response.output_text.") === 0 ||
      type.indexOf("response.text.") === 0
    ) {
      return "agent_response";
    }

    if ((type === "conversation.item.added" || type === "conversation.item.done" || type === "response.output_item.done") && itemType === "message" && itemRole === "assistant") {
      return "agent_response";
    }

    if (
      type === "session.created" ||
      type === "session.updated" ||
      type === "response.created" ||
      type === "response.done" ||
      type === "session.update"
    ) {
      return direction === "sent" ? "realtime_sent" : "realtime_recv";
    }

    return direction === "sent" ? "realtime_sent" : "realtime_recv";
  };

  NavaiVoiceWidget.prototype.classifyLogEntry = function (message, level, meta) {
    var cleanLevel = asTrimmedString(level).toLowerCase();
    if (cleanLevel === "error") {
      return "error";
    }
    if (cleanLevel === "warn") {
      return "warn";
    }

    if (isRecord(meta) && typeof meta.category === "string" && meta.category.trim() !== "") {
      return meta.category;
    }

    if (typeof meta === "string" && meta.trim() !== "") {
      return meta;
    }

    var text = asTrimmedString(message);
    if (!text) {
      return "other";
    }

    if (text.indexOf("Loaded routes (") === 0) {
      return "routes_loaded";
    }
    if (text.indexOf("Loaded functions (") === 0) {
      return "functions_loaded";
    }
    if (text.indexOf("Loaded roadmap phases (") === 0) {
      return "phases_loaded";
    }
    if (
      text.indexOf("Peer connection state:") === 0 ||
      text.indexOf("Realtime data channel ") === 0 ||
      text.indexOf("Auto-resuming global voice widget") === 0
    ) {
      return "connection";
    }
    if (text.indexOf("sent: ") === 0 || text.indexOf("recv: ") === 0) {
      return text.indexOf("sent: ") === 0 ? "realtime_sent" : "realtime_recv";
    }

    return "other";
  };

  NavaiVoiceWidget.prototype.shouldShowLogEntry = function (entry) {
    if (!entry || !entry.category) {
      return !!this.logFilterState.other;
    }
    if (Object.prototype.hasOwnProperty.call(this.logFilterState, entry.category)) {
      return this.logFilterState[entry.category] !== false;
    }
    return this.logFilterState.other !== false;
  };

  NavaiVoiceWidget.prototype.renderLog = function () {
    if (!this.logEl) {
      return;
    }
    if (!this.debugEnabled) {
      this.logEl.textContent = "";
      return;
    }

    var visible = [];
    for (var i = 0; i < this.logEntries.length; i += 1) {
      var entry = this.logEntries[i];
      if (!this.shouldShowLogEntry(entry)) {
        continue;
      }
      visible.push(entry.line);
    }
    this.logEl.textContent = visible.join("\n");
    this.logEl.scrollTop = this.logEl.scrollHeight;
  };

  NavaiVoiceWidget.prototype.appendLog = function (message, level, meta) {
    var cleanLevel = asTrimmedString(level) || "info";
    var line = "[" + cleanLevel + "] " + message;

    if (cleanLevel === "error") {
      console.error("[navai]", message);
    } else if (cleanLevel === "warn") {
      console.warn("[navai]", message);
    } else if (this.debugEnabled) {
      console.log("[navai]", message);
    }

    if (!this.debugEnabled || !this.logEl) {
      return;
    }

    this.logEntries.push({
      level: cleanLevel,
      category: this.classifyLogEntry(message, cleanLevel, meta),
      line: line
    });
    if (this.logEntries.length > MAX_LOG_LINES) {
      this.logEntries = this.logEntries.slice(this.logEntries.length - MAX_LOG_LINES);
    }
    this.renderLog();
  };

  NavaiVoiceWidget.prototype.refreshButton = function () {
    if (!this.button) {
      this.applyStateClass();
      this.updatePttButton();
      this.updateTextControlsDisabledState(false);
      return;
    }

    var textTarget = this.buttonLabelEl || this.button;
    var iconTarget = this.buttonIconEl;

    if (this.state === "connecting") {
      this.button.disabled = true;
      textTarget.textContent = this.startLabel;
      this.button.setAttribute("aria-pressed", "false");
      this.button.setAttribute(
        "aria-label",
        getMessage(this.globalConfig, "connectingRealtime", "Connecting realtime session...")
      );
      if (iconTarget && iconTarget.classList) {
        iconTarget.classList.remove("dashicons-microphone");
        iconTarget.classList.add("dashicons-update-alt", "is-spinner");
      }
      this.applyStateClass();
      this.updatePttButton();
      this.updateTextControlsDisabledState(true);
      return;
    }

    this.button.disabled = false;
    var buttonText = this.state === "connected" ? this.stopLabel : this.startLabel;
    textTarget.textContent = buttonText;
    this.button.setAttribute("aria-pressed", this.state === "connected" ? "true" : "false");
    this.button.setAttribute("aria-label", buttonText);
    if (iconTarget && iconTarget.classList) {
      iconTarget.classList.remove("dashicons-update-alt", "is-spinner");
      iconTarget.classList.add("dashicons-microphone");
    }
    this.applyStateClass();
    this.updatePttButton();
    this.updateTextControlsDisabledState(false);
  };

  NavaiVoiceWidget.prototype.start = async function (options) {
    if (this.state === "connecting" || this.state === "connected") {
      return;
    }

    var startOptions = isRecord(options) ? options : {};
    var skipMicrophone = !!startOptions.skipMicrophone;

    if (!window.RTCPeerConnection) {
      this.storeActivePreference(false);
      this.setStatus("WebRTC is not supported in this browser.");
      this.updateTextControlsDisabledState(false);
      return;
    }

    if (!skipMicrophone && (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia)) {
      this.storeActivePreference(false);
      this.setStatus("Microphone capture is not supported in this browser.");
      this.updateTextControlsDisabledState(false);
      return;
    }

    this.state = "connecting";
    this.connectionMode = skipMicrophone ? "text" : "voice";
    this.activityState = "idle";
    this.assistantVoiceState = "idle";
    this.clearPendingPttHoldTimer();
    this.pttPressed = false;
    this.navigationInProgress = false;
    this.storeActivePreference(true);
    this.refreshButton();
    this.updateStatusIndicator();
    this.handledCalls = {};
    this.ensureSessionKey();
    this.queueSessionMessage({
      direction: "system",
      message_type: "event",
      content_text: "Widget start requested.",
      content_json: {
        type: "widget_start",
        widget_mode: this.widgetMode,
        connection_mode: this.connectionMode
      }
    });

    try {
      this.setStatus(getMessage(this.globalConfig, "requestingSecret", "Requesting client secret..."));
      var routesResult = await requestRoutes(this.globalConfig);
      if (routesResult.ok) {
        // Replace routes on every start to avoid carrying stale routes between sessions.
        this.routes = routesResult.routes;
      }
      for (var r = 0; r < routesResult.warnings.length; r += 1) {
        this.appendLog(routesResult.warnings[r], "warn");
      }
      this.appendLog(
        "Loaded routes (" +
          this.routes.length +
          "): " +
          (this.routes.length
            ? this.routes
                .map(function (item) {
                  return item.name;
                })
                .join(", ")
            : "none"),
        "info"
      );

      var functionResult = await requestBackendFunctions(this.globalConfig);
      this.backendFunctions = functionResult.functions;
      for (var i = 0; i < functionResult.warnings.length; i += 1) {
        this.appendLog(functionResult.warnings[i], "warn");
      }
      this.appendLog(
        "Loaded functions (" +
          this.backendFunctions.length +
          "): " +
          (this.backendFunctions.length
            ? this.backendFunctions
                .map(function (item) {
                  return item && item.name ? item.name : "";
                })
                .filter(function (name) {
                  return !!name;
                })
                .join(", ")
            : "none"),
        "info"
      );

      var customJsFunctionsCount = 0;
      var mcpFunctionsCount = 0;
      for (var bf = 0; bf < this.backendFunctions.length; bf += 1) {
        var backendFn = this.backendFunctions[bf];
        var source = backendFn && backendFn.source ? String(backendFn.source).toLowerCase() : "";
        if (source === "navai-dashboard-custom") {
          customJsFunctionsCount += 1;
        } else if (source.indexOf("mcp:") === 0) {
          mcpFunctionsCount += 1;
        }
      }

      var phaseLabels = [];
      for (var p = 0; p < this.roadmapPhases.length; p += 1) {
        var phase = this.roadmapPhases[p];
        if (!phase) {
          continue;
        }

        var label = "Fase " + String(phase.phase || p + 1) + " " + (phase.label || "");
        var details = Array.isArray(phase.details) ? phase.details.slice(0) : [];
        if ((phase.phase || 0) === 5) {
          details.push("loaded_js_functions:" + String(customJsFunctionsCount));
        } else if ((phase.phase || 0) === 7) {
          details.push("loaded_mcp_functions:" + String(mcpFunctionsCount));
        }

        label += " [" + (phase.enabled ? "enabled" : "disabled");
        if (details.length) {
          label += "; " + details.join(", ");
        }
        label += "]";
        phaseLabels.push(label);
      }

      this.appendLog(
        "Loaded roadmap phases (" +
          this.roadmapPhases.length +
          "): " +
          (phaseLabels.length ? phaseLabels.join(" | ") : "none"),
        "info"
      );

      var secretInput = this.resolveClientSecretInput();
      var model = asTrimmedString(secretInput.model) || DEFAULT_MODEL;
      this.sessionModel = model;
      this.spokenAssistantKeys = Object.create(null);
      this.stopSpeechPlayback({
        invalidate: true,
        resetState: true
      });
      var secret = await requestClientSecret(this.globalConfig, secretInput);
      if (secret && secret.session && typeof secret.session.key === "string") {
        this.setSessionKey(secret.session.key);
      }
      this.speechProvider = normalizeSpeechProvider(
        secret && secret.speech && secret.speech.provider
          ? secret.speech.provider
          : isRecord(this.globalConfig.speech)
            ? this.globalConfig.speech.provider
            : ""
      );

      this.localStream = null;
      if (!skipMicrophone) {
        this.setStatus(getMessage(this.globalConfig, "requestingMicrophone", "Requesting microphone permission..."));
        this.localStream = await navigator.mediaDevices.getUserMedia({
          audio: true
        });
      }

      this.setStatus(getMessage(this.globalConfig, "connectingRealtime", "Connecting realtime session..."));
      await this.openRealtimeSession(secret.value, model);

      this.state = "connected";
      this.storeActivePreference(true);
      this.refreshButton();
      this.assistantVoiceState = "idle";
      this.setActivityState("idle");
      this.updateStatusIndicator();
      this.queueSessionMessage({
        direction: "system",
        message_type: "event",
        content_text: "Realtime session connected.",
        content_json: {
          type: "realtime_connected",
          model: model,
          connection_mode: this.connectionMode
        }
      });
      this.flushPendingTextMessages();
    } catch (error) {
      this.appendLog("Failed to start voice: " + String(error), "error");
      this.stopInternal();
      this.state = "error";
      this.connectionMode = skipMicrophone ? "text" : "voice";
      this.assistantVoiceState = "idle";
      this.storeActivePreference(false);
      this.refreshButton();
      this.setStatus(getMessage(this.globalConfig, "failed", "Failed to start voice session."));
      this.updateTextControlsDisabledState(false);
      this.queueSessionMessage({
        direction: "system",
        message_type: "event",
        content_text: "Widget failed to start.",
        content_json: {
          type: "widget_start_failed",
          error: String(error),
          connection_mode: this.connectionMode
        }
      });
    }
  };

  NavaiVoiceWidget.prototype.stop = function () {
    if (this.state === "idle") {
      return;
    }

    this.setStatus(getMessage(this.globalConfig, "stopping", "Stopping..."));
    var keepActiveAcrossNavigation = this.navigationInProgress;
    this.stopInternal();
    this.state = "idle";
    this.connectionMode = "voice";
    this.activityState = "idle";
    this.assistantVoiceState = "idle";
    this.storeActivePreference(keepActiveAcrossNavigation);
    this.navigationInProgress = false;
    this.refreshButton();
    this.setStatus(getMessage(this.globalConfig, "stopped", "Stopped"));
    this.queueSessionMessage({
      direction: "system",
      message_type: "event",
      content_text: "Widget stopped.",
      content_json: {
        type: "widget_stopped",
        navigated: !!keepActiveAcrossNavigation
      }
    });
    this.flushSessionMessages(false);
  };

  NavaiVoiceWidget.prototype.stopInternal = function () {
    if (this.sessionFlushTimer) {
      window.clearTimeout(this.sessionFlushTimer);
      this.sessionFlushTimer = 0;
    }
    this.clearInterruptedStateTimer();
    this.clearPendingPttHoldTimer();
    this.pttPressed = false;

    if (this.eventsChannel) {
      try {
        this.eventsChannel.close();
      } catch (_error) {
        // noop
      }
    }
    this.eventsChannel = null;

    if (this.peerConnection) {
      try {
        this.peerConnection.close();
      } catch (_error) {
        // noop
      }
    }
    this.peerConnection = null;

    if (this.localStream) {
      var tracks = this.localStream.getTracks();
      for (var i = 0; i < tracks.length; i += 1) {
        tracks[i].stop();
      }
    }
    this.localStream = null;

    if (this.remoteAudioEl && this.remoteAudioEl.parentNode) {
      this.remoteAudioEl.pause();
      this.remoteAudioEl.srcObject = null;
      this.remoteAudioEl.parentNode.removeChild(this.remoteAudioEl);
    }
    this.remoteAudioEl = null;
    this.stopSpeechPlayback({
      invalidate: true,
      resetState: true
    });
    this.assistantVoiceState = "idle";
    this.sessionModel = "";
    this.speechProvider = normalizeSpeechProvider(isRecord(this.globalConfig.speech) ? this.globalConfig.speech.provider : "");
    this.spokenAssistantKeys = Object.create(null);

    this.directAliases = [];
    this.handledCalls = {};
    this.updatePttButton();
    this.updateTextControlsDisabledState(false);
  };

  NavaiVoiceWidget.prototype.clearSpeechPlaybackUrl = function () {
    if (this.ttsAudioUrl && window.URL && typeof window.URL.revokeObjectURL === "function") {
      try {
        window.URL.revokeObjectURL(this.ttsAudioUrl);
      } catch (_error) {
        // noop
      }
    }

    this.ttsAudioUrl = "";
  };

  NavaiVoiceWidget.prototype.stopSpeechPlayback = function (options) {
    var invalidate = !options || options.invalidate !== false;
    var resetState = !options || options.resetState !== false;
    if (invalidate) {
      this.ttsPlaybackGeneration += 1;
    }

    if (this.ttsAudioEl) {
      try {
        this.ttsAudioEl.pause();
      } catch (_error) {
        // noop
      }

      try {
        this.ttsAudioEl.currentTime = 0;
      } catch (_error) {
        // noop
      }

      this.ttsAudioEl.src = "";
      this.ttsAudioEl = null;
    }

    this.clearSpeechPlaybackUrl();

    if (resetState) {
      this.setAssistantVoiceState("idle");
    }
  };

  NavaiVoiceWidget.prototype.playAssistantSpeech = async function (text) {
    if (this.speechProvider !== "elevenlabs") {
      return;
    }

    var normalizedText = asTrimmedString(text);
    if (!normalizedText) {
      return;
    }

    if (typeof requestSpeechSynthesis !== "function") {
      throw new Error("Speech synthesis runtime helper is not available.");
    }

    var generation = this.ttsPlaybackGeneration + 1;
    this.ttsPlaybackGeneration = generation;
    this.stopSpeechPlayback({
      invalidate: false,
      resetState: false
    });
    this.setAssistantVoiceState("speaking");
    var audioUrl = "";
    var audioEl = null;

    try {
      var synthesis = await requestSpeechSynthesis(this.globalConfig, {
        text: normalizedText
      });
      if (this.speechProvider !== "elevenlabs" || this.ttsPlaybackGeneration !== generation) {
        return;
      }

      audioUrl = audioUrlFromSynthesis(synthesis);
      if (!audioUrl) {
        throw new Error("Failed to create a playable audio URL from ElevenLabs response.");
      }

      this.ttsAudioUrl = audioUrl;
      audioEl = new Audio(audioUrl);
      audioEl.preload = "auto";
      this.ttsAudioEl = audioEl;

      await new Promise(function (resolve, reject) {
        var settled = false;

        function cleanup() {
          audioEl.onended = null;
          audioEl.onerror = null;
          audioEl.onpause = null;
        }

        function finish(error) {
          if (settled) {
            return;
          }

          settled = true;
          cleanup();
          if (error) {
            reject(error);
            return;
          }

          resolve();
        }

        audioEl.onended = function () {
          finish();
        };
        audioEl.onerror = function () {
          finish(new Error("Audio playback failed."));
        };
        audioEl.onpause = function () {
          if (audioEl.ended || audioEl.currentTime === 0 || generation !== this.ttsPlaybackGeneration) {
            finish();
          }
        }.bind(this);

        var playResult = audioEl.play();
        if (playResult && typeof playResult.then === "function") {
          playResult.catch(function (error) {
            finish(error instanceof Error ? error : new Error(String(error)));
          });
        }
      }.bind(this));
    } finally {
      if (audioEl && this.ttsAudioEl === audioEl) {
        this.ttsAudioEl = null;
      }

      if (audioUrl && this.ttsAudioUrl === audioUrl) {
        this.clearSpeechPlaybackUrl();
      }
      if (this.ttsPlaybackGeneration === generation) {
        this.setAssistantVoiceState("idle");
      }
    }
  };

  NavaiVoiceWidget.prototype.resolveClientSecretInput = function () {
    var defaults = isRecord(this.globalConfig.defaults) ? this.globalConfig.defaults : {};
    var input = {};

    var model = this.modelOverride || asTrimmedString(defaults.model);
    var voice = this.voiceOverride || asTrimmedString(defaults.voice);
    var instructions = this.instructionsOverride || asTrimmedString(defaults.instructions);
    var language = this.languageOverride || asTrimmedString(defaults.language);
    var voiceAccent = this.voiceAccentOverride || asTrimmedString(defaults.voiceAccent);
    var voiceTone = this.voiceToneOverride || asTrimmedString(defaults.voiceTone);

    if (model) {
      input.model = model;
    }
    if (voice) {
      input.voice = voice;
    }
    if (instructions) {
      input.instructions = instructions;
    }
    if (language) {
      input.language = language;
    }
    if (voiceAccent) {
      input.voiceAccent = voiceAccent;
    }
    if (voiceTone) {
      input.voiceTone = voiceTone;
    }
    if (this.ensureSessionKey()) {
      input.session_key = this.sessionKey;
    }

    return input;
  };

  NavaiVoiceWidget.prototype.openRealtimeSession = async function (clientSecret, model) {
    this.peerConnection = new RTCPeerConnection();
    this.remoteAudioEl = document.createElement("audio");
    this.remoteAudioEl.autoplay = true;
    this.remoteAudioEl.playsInline = true;
    this.remoteAudioEl.className = "navai-voice-remote-audio";
    this.remoteAudioEl.style.display = "none";
    this.container.appendChild(this.remoteAudioEl);

    this.peerConnection.ontrack = this.handlePeerTrack.bind(this);
    this.peerConnection.onconnectionstatechange = this.handleConnectionStateChange.bind(this);

    if (this.localStream && typeof this.localStream.getTracks === "function") {
      var tracks = this.localStream.getTracks();
      for (var i = 0; i < tracks.length; i += 1) {
        this.peerConnection.addTrack(tracks[i], this.localStream);
      }
    } else if (typeof this.peerConnection.addTransceiver === "function") {
      this.peerConnection.addTransceiver("audio", { direction: "recvonly" });
    }

    var channelReadyPromise = this.createEventsChannel();
    var offer = await this.peerConnection.createOffer();
    await this.peerConnection.setLocalDescription(offer);

    if (!offer.sdp) {
      throw new Error("Failed to generate SDP offer.");
    }

    var realtimeUrl = asTrimmedString(this.globalConfig.realtimeWebrtcUrl) || DEFAULT_WEBRTC_URL;
    var response = await fetch(realtimeUrl + "?model=" + encodeURIComponent(model), {
      method: "POST",
      body: offer.sdp,
      headers: {
        Authorization: "Bearer " + clientSecret,
        "Content-Type": "application/sdp"
      }
    });

    if (!response.ok) {
      throw new Error(await readErrorMessage(response));
    }

    var answerSdp = await response.text();
    await this.peerConnection.setRemoteDescription({
      type: "answer",
      sdp: answerSdp
    });

    await channelReadyPromise;
  };

  NavaiVoiceWidget.prototype.createEventsChannel = function () {
    var widget = this;
    var channel = this.peerConnection.createDataChannel("oai-events");
    this.eventsChannel = channel;

    return new Promise(function (resolve, reject) {
      var settled = false;
      var timeout = window.setTimeout(function () {
        if (settled) {
          return;
        }
        settled = true;
        reject(new Error("Timed out waiting for Realtime events channel."));
      }, 15000);

      channel.addEventListener("open", function () {
        if (settled) {
          return;
        }
        settled = true;
        window.clearTimeout(timeout);
        widget.appendLog("Realtime data channel opened.", "info");
        widget.sendSessionUpdate();
        resolve();
      });

      channel.addEventListener("message", function (event) {
        widget.handleRealtimeEvent(event.data);
      });

      channel.addEventListener("close", function () {
        widget.appendLog("Realtime data channel closed.", "warn");
      });

      channel.addEventListener("error", function (error) {
        widget.appendLog("Realtime data channel error: " + String(error), "error");
      });
    });
  };

  NavaiVoiceWidget.prototype.handlePeerTrack = function (event) {
    if (this.remoteAudioEl && event.streams && event.streams[0]) {
      this.remoteAudioEl.srcObject = event.streams[0];
    }
  };

  NavaiVoiceWidget.prototype.handleConnectionStateChange = function () {
    if (!this.peerConnection) {
      return;
    }

    var state = this.peerConnection.connectionState;
    this.appendLog("Peer connection state: " + state, "info");
    if (state === "failed" || state === "disconnected" || state === "closed") {
      this.stop();
    }
  };

  NavaiVoiceWidget.prototype.sendSessionUpdate = function () {
    var defaults = isRecord(this.globalConfig.defaults) ? this.globalConfig.defaults : {};
    var baseInstructions = this.instructionsOverride || asTrimmedString(defaults.instructions);
    var voice = this.voiceOverride || asTrimmedString(defaults.voice);
    var speechProvider = normalizeSpeechProvider(this.speechProvider);
    var turnDetection = this.buildTurnDetectionConfig();
    var toolOptions = {
      allowStopTool: this.assistantStopToolEnabled
    };
    var toolsResult = buildToolDefinitions(this.routes, this.backendFunctions, toolOptions);
    this.directAliases = toolsResult.directAliases;

    for (var i = 0; i < toolsResult.warnings.length; i += 1) {
      this.appendLog(toolsResult.warnings[i], "warn");
    }

    var instructions = buildAssistantInstructions(
      baseInstructions,
      this.routes,
      this.backendFunctions,
      this.roadmapPhases,
      toolOptions
    );
    var session = {
      type: "realtime",
      instructions: instructions,
      tools: toolsResult.tools,
      tool_choice: "auto",
      output_modalities: speechProvider === "elevenlabs" ? ["text"] : ["audio"]
    };

    if ((speechProvider !== "elevenlabs" && voice) || turnDetection !== undefined) {
      session.audio = {};
    }

    if (speechProvider !== "elevenlabs" && voice) {
      session.audio.output = {
        voice: voice
      };
    }

    if (turnDetection !== undefined) {
      session.audio.input = {
        turn_detection: turnDetection
      };
    }

    this.sendRealtimeEvent({
      type: "session.update",
      session: session
    });
  };

  NavaiVoiceWidget.prototype.sendRealtimeEvent = function (event) {
    if (!this.eventsChannel || this.eventsChannel.readyState !== "open") {
      throw new Error("Realtime channel is not open.");
    }

    var payload = safeJsonStringify(event);
    this.eventsChannel.send(payload);
    this.appendLog("sent: " + payload, "info", {
      category: this.classifyRealtimeLogEntry("sent", event)
    });
  };

  NavaiVoiceWidget.prototype.updateRealtimeActivityFromEvent = function (type, event) {
    if (this.state !== "connected") {
      return;
    }

    var normalizedType = asTrimmedString(type).toLowerCase();
    var assistantVoiceState = resolveAssistantVoiceStateFromRealtimeEvent(normalizedType, this.speechProvider);
    if (!normalizedType) {
      return;
    }

    if (normalizedType === "session.created" || normalizedType === "session.updated") {
      this.flushPendingTextMessages();
      this.updateStatusIndicator();
      return;
    }

    if (normalizedType === "input_audio_buffer.speech_started" || normalizedType === "conversation.item.input_audio_transcription.started") {
      this.stopSpeechPlayback({
        invalidate: true,
        resetState: true
      });
      this.setActivityState("listening");
      return;
    }

    if (
      normalizedType === "conversation.interrupted" ||
      normalizedType === "output_audio_buffer.cleared" ||
      normalizedType === "response.canceled" ||
      normalizedType === "response.cancelled"
    ) {
      this.stopSpeechPlayback({
        invalidate: true,
        resetState: false
      });
      this.setAssistantVoiceState("idle");
      this.setActivityState("interrupted");
      return;
    }

    if (assistantVoiceState === "speaking") {
      this.setAssistantVoiceState("speaking");
      return;
    }

    if (normalizedType === "input_audio_buffer.speech_stopped") {
      if (this.pttPressed) {
        this.setActivityState("listening");
      } else if (this.getAssistantVoiceState() !== "speaking") {
        this.setActivityState("idle");
      }
      return;
    }

    if (assistantVoiceState === "idle") {
      this.setAssistantVoiceState("idle");
      return;
    }

    if (normalizedType === "error") {
      this.stopSpeechPlayback({
        invalidate: true,
        resetState: false
      });
      this.setAssistantVoiceState("idle");
      this.setActivityState("idle");
      return;
    }
  };

  NavaiVoiceWidget.prototype.handleRealtimeEvent = function (rawEvent) {
    var parsed = parseJsonSafe(rawEvent);
    if (!isRecord(parsed)) {
      return;
    }

    this.appendLog("recv: " + safeJsonStringify(parsed), "info", {
      category: this.classifyRealtimeLogEntry("recv", parsed)
    });
    var type = asTrimmedString(parsed.type);
    if (!type) {
      return;
    }

    var eventModel = extractRealtimeModelFromEvent(parsed, this.sessionModel);
    if (eventModel) {
      this.sessionModel = eventModel;
    }

    var sessionMessages = this.extractSessionMessagesFromRealtimeEvent(parsed);
    for (var s = 0; s < sessionMessages.length; s += 1) {
      this.queueSessionMessage(sessionMessages[s]);
    }
    this.updateRealtimeActivityFromEvent(type, parsed);

    if (this.speechProvider === "elevenlabs") {
      var assistantTextPayload = extractAssistantTextFromRealtimeEvent(parsed);
      if (
        assistantTextPayload &&
        !Object.prototype.hasOwnProperty.call(this.spokenAssistantKeys, assistantTextPayload.key)
      ) {
        this.spokenAssistantKeys[assistantTextPayload.key] = true;
        this.playAssistantSpeech(assistantTextPayload.text).catch(
          function (error) {
            this.appendLog("Failed to synthesize assistant speech: " + String(error), "error");
          }.bind(this)
        );
      }
    }

    if (type === "error") {
      var errorMessage = "Realtime error event received.";
      if (isRecord(parsed.error) && typeof parsed.error.message === "string" && parsed.error.message.trim() !== "") {
        errorMessage = "Realtime error: " + parsed.error.message.trim();
      }
      this.appendLog(errorMessage, "error");
      return;
    }

    if (type === "response.function_call_arguments.done") {
      this.processFunctionCall({
        callId: asTrimmedString(parsed.call_id),
        name: asTrimmedString(parsed.name),
        argumentsText: typeof parsed.arguments === "string" ? parsed.arguments : safeJsonStringify(parsed.arguments)
      });
      return;
    }

    if (type === "response.output_item.done" && isRecord(parsed.item) && parsed.item.type === "function_call") {
      this.processFunctionCall({
        callId: asTrimmedString(parsed.item.call_id),
        name: asTrimmedString(parsed.item.name),
        argumentsText:
          typeof parsed.item.arguments === "string"
            ? parsed.item.arguments
            : safeJsonStringify(parsed.item.arguments)
      });
      return;
    }

    if (type === "response.done" && isRecord(parsed.response) && Array.isArray(parsed.response.output)) {
      for (var i = 0; i < parsed.response.output.length; i += 1) {
        var item = parsed.response.output[i];
        if (!isRecord(item) || item.type !== "function_call") {
          continue;
        }

        this.processFunctionCall({
          callId: asTrimmedString(item.call_id),
          name: asTrimmedString(item.name),
          argumentsText: typeof item.arguments === "string" ? item.arguments : safeJsonStringify(item.arguments)
        });
      }
    }
  };

  NavaiVoiceWidget.prototype.extractSessionMessagesFromRealtimeEvent = function (event) {
    var messages = [];
    var type = asTrimmedString(event && event.type);
    if (!type) {
      return messages;
    }
    var usageTrackingContent = buildRealtimeUsageTrackingContent(event, this.sessionModel);

    function push(direction, messageType, contentText, contentJson, meta) {
      messages.push({
        direction: direction,
        message_type: messageType,
        content_text: contentText || "",
        content_json: contentJson || null,
        meta: meta || null
      });
    }

    if (type === "conversation.item.input_audio_transcription.completed") {
      push(
        "user",
        "text",
        asTrimmedString(event.transcript || ""),
        {
          type: type,
          transcript: event.transcript || ""
        },
        {
          item_id: event.item_id || null
        }
      );
      if (usageTrackingContent && isRecord(usageTrackingContent.usage)) {
        push(
          "system",
          "event",
          type,
          usageTrackingContent,
          {
            item_id: event.item_id || null
          }
        );
      }
      return messages;
    }

    if (type === "response.audio_transcript.done" || type === "response.output_text.done" || type === "response.text.done") {
      var text = asTrimmedString(event.transcript || event.text || "");
      push(
        "assistant",
        "text",
        text,
        {
          type: type,
          transcript: event.transcript || null,
          text: event.text || null
        },
        {
          response_id: event.response_id || null,
          item_id: event.item_id || null
        }
      );
      if (usageTrackingContent && isRecord(usageTrackingContent.usage)) {
        push(
          "system",
          "event",
          type,
          usageTrackingContent,
          {
            response_id: event.response_id || null,
            item_id: event.item_id || null
          }
        );
      }
      return messages;
    }

    if (type === "response.function_call_arguments.done") {
      push(
        "tool",
        "tool_call",
        asTrimmedString(event.name || ""),
        {
          type: type,
          name: event.name || "",
          call_id: event.call_id || "",
          arguments: event.arguments || null
        },
        null
      );
      return messages;
    }

    if (type === "response.output_item.done" && isRecord(event.item) && event.item.type === "function_call") {
      push(
        "tool",
        "tool_call",
        asTrimmedString(event.item.name || ""),
        {
          type: type,
          item: event.item
        },
        null
      );
      return messages;
    }

    if (type === "error") {
      var errorMessage = "";
      if (isRecord(event.error) && typeof event.error.message === "string") {
        errorMessage = event.error.message;
      }
      push(
        "system",
        "event",
        errorMessage || "Realtime error event",
        {
          type: type,
          error: event.error || null
        },
        null
      );
      return messages;
    }

    if (type === "response.done") {
      push(
        "system",
        "event",
        type,
        usageTrackingContent || {
          type: type,
          response_id: asTrimmedString(event.response_id || ""),
          status: asTrimmedString(event.status || ""),
          model: extractRealtimeModelFromEvent(event, this.sessionModel),
          usage: null,
          output_item_count: Array.isArray(event.output) ? event.output.length : 0
        },
        null
      );
      return messages;
    }

    if (type === "session.created" || type === "session.updated") {
      push(
        "system",
        "event",
        type,
        {
          type: type
        },
        null
      );
    }

    if (usageTrackingContent && isRecord(usageTrackingContent.usage)) {
      push(
        "system",
        "event",
        type,
        usageTrackingContent,
        null
      );
    }

    return messages;
  };

  NavaiVoiceWidget.prototype.queueSessionMessage = function (message) {
    if (!message || typeof message !== "object") {
      return;
    }

    if (this.globalConfig && this.globalConfig.sessionMemoryEnabled === false) {
      return;
    }

    if (!this.ensureSessionKey()) {
      return;
    }

    this.sessionMessageQueue.push(message);
    if (this.sessionMessageQueue.length > 60) {
      this.sessionMessageQueue = this.sessionMessageQueue.slice(this.sessionMessageQueue.length - 60);
    }
    this.scheduleSessionFlush();
  };

  NavaiVoiceWidget.prototype.scheduleSessionFlush = function () {
    if (this.sessionFlushInFlight || this.sessionFlushTimer) {
      return;
    }

    var widget = this;
    this.sessionFlushTimer = window.setTimeout(function () {
      widget.sessionFlushTimer = 0;
      widget.flushSessionMessages(false);
    }, 700);
  };

  NavaiVoiceWidget.prototype.flushSessionMessages = function (syncMode) {
    if (!Array.isArray(this.sessionMessageQueue) || this.sessionMessageQueue.length === 0) {
      return;
    }
    if (this.sessionFlushInFlight) {
      return;
    }
    if (typeof sendSessionMessages !== "function") {
      return;
    }

    var sessionKey = this.ensureSessionKey();
    if (!sessionKey) {
      return;
    }

    var batch = this.sessionMessageQueue.splice(0, 20);
    if (!batch.length) {
      return;
    }

    if (syncMode && navigator && typeof navigator.sendBeacon === "function" && this.globalConfig && this.globalConfig.restBaseUrl) {
      try {
        var url = String(this.globalConfig.restBaseUrl).replace(/\/+$/, "") + "/sessions";
        var body = safeJsonStringify({
          session_key: sessionKey,
          items: batch
        });
        navigator.sendBeacon(url, new Blob([body], { type: "application/json" }));
      } catch (_error) {
        // noop
      }
      return;
    }

    this.sessionFlushInFlight = true;
    var widget = this;
    sendSessionMessages(this.globalConfig, sessionKey, batch)
      .then(function (response) {
        if (!isRecord(response) || response.ok === false) {
          widget.sessionMessageQueue = batch.concat(widget.sessionMessageQueue);
          return;
        }
        if (isRecord(response) && isRecord(response.session) && typeof response.session.session_key === "string") {
          widget.setSessionKey(response.session.session_key);
        }
      })
      .catch(function () {
        widget.sessionMessageQueue = batch.concat(widget.sessionMessageQueue);
      })
      .finally(function () {
        widget.sessionFlushInFlight = false;
        if (widget.sessionMessageQueue.length > 0) {
          widget.scheduleSessionFlush();
        }
      });
  };

  NavaiVoiceWidget.prototype.processFunctionCall = function (input) {
    var callId = asTrimmedString(input.callId);
    var functionName = normalizeMatchValue(input.name);
    if (!callId || !functionName) {
      return;
    }

    if (this.handledCalls[callId]) {
      return;
    }
    this.handledCalls[callId] = true;

    var args = {};
    if (asTrimmedString(input.argumentsText)) {
      var parsedArgs = parseJsonSafe(input.argumentsText);
      if (isRecord(parsedArgs)) {
        args = parsedArgs;
      }
    }

    var widget = this;
    this.runFunctionCall(functionName, args)
      .then(function (output) {
        widget.sendFunctionCallOutput(callId, output);
      })
      .catch(function (error) {
        widget.appendLog("Function call failed: " + String(error), "error");
        widget.sendFunctionCallOutput(callId, {
          ok: false,
          error: "Function execution failed.",
          details: String(error)
        });
      });
  };

  NavaiVoiceWidget.prototype.sendFunctionCallOutput = function (callId, output) {
    try {
      this.sendRealtimeEvent({
        type: "conversation.item.create",
        item: {
          type: "function_call_output",
          call_id: callId,
          output: safeJsonStringify(output)
        }
      });

      this.sendRealtimeEvent({
        type: "response.create"
      });
    } catch (error) {
      this.appendLog("Failed to send function_call_output: " + String(error), "error");
    }
  };

  NavaiVoiceWidget.prototype.runFunctionCall = async function (functionName, args) {
    if (functionName === "navigate_to") {
      var target = typeof args.target === "string" ? args.target : "";
      return this.runNavigateTo(target);
    }

    if (functionName === "stop_navai_voice") {
      var reason = typeof args.reason === "string" ? args.reason : "";
      return this.runStopNavaiVoice(reason);
    }

    if (functionName === "execute_app_function") {
      var requested = asTrimmedString(args.function_name).toLowerCase();
      var payload = isRecord(args.payload) || args.payload === null ? args.payload : null;
      return this.runExecuteAppFunction(requested, payload);
    }

    if (this.directAliases.indexOf(functionName) >= 0) {
      var directPayload = isRecord(args.payload) || args.payload === null ? args.payload : args;
      return this.runExecuteAppFunction(functionName, directPayload);
    }

    return {
      ok: false,
      error: "Unknown or disallowed function.",
      available_functions: this.directAliases
    };
  };

  NavaiVoiceWidget.prototype.runStopNavaiVoice = function (reason) {
    if (!this.assistantStopToolEnabled) {
      return {
        ok: false,
        error: "stop_navai_voice is disabled by configuration."
      };
    }

    var cleanReason = asTrimmedString(reason);
    if (this.state === "idle") {
      return {
        ok: true,
        stopping: false,
        status: "already_stopped",
        reason: cleanReason || null
      };
    }

    var widget = this;
    window.setTimeout(function () {
      widget.stop();
    }, 220);

    this.appendLog("Assistant requested NAVAI shutdown.", "info");
    return {
      ok: true,
      stopping: true,
      status: "stopping",
      reason: cleanReason || null
    };
  };

  NavaiVoiceWidget.prototype.runNavigateTo = function (target) {
    var path = resolveAllowedRoute(target, this.routes);
    if (!path) {
      return {
        ok: false,
        error: "Unknown or disallowed route."
      };
    }

    this.appendLog('Navigating to "' + path + '".', "info");
    this.navigationInProgress = true;
    this.storeActivePreference(true);
    window.setTimeout(function () {
      window.location.assign(path);
    }, 450);

    return {
      ok: true,
      path: path
    };
  };

  NavaiVoiceWidget.prototype.runExecuteAppFunction = async function (functionName, payload) {
    var cleanName = asTrimmedString(functionName).toLowerCase();
    if (!cleanName) {
      return {
        ok: false,
        error: "function_name is required."
      };
    }

    try {
      var backendResult = await executeBackendFunction(this.globalConfig, cleanName, payload, {
        sessionKey: this.ensureSessionKey()
      });
      if (isRecord(backendResult) && isRecord(backendResult.session) && typeof backendResult.session.key === "string") {
        this.setSessionKey(backendResult.session.key);
      }
      return await this.maybeExecuteClientFunctionCode(cleanName, payload, backendResult);
    } catch (error) {
      return {
        ok: false,
        function_name: cleanName,
        error: "Function execution failed.",
        details: String(error)
      };
    }
  };

  NavaiVoiceWidget.prototype.maybeExecuteClientFunctionCode = async function (functionName, payload, backendResult) {
    if (!isRecord(backendResult)) {
      return backendResult;
    }

    var executionSource = backendResult;
    var wrappedBackendResult = null;
    if (!asTrimmedString(backendResult.execution_mode) && isRecord(backendResult.result)) {
      executionSource = backendResult.result;
      wrappedBackendResult = backendResult;
    }

    var mode = asTrimmedString(executionSource.execution_mode).toLowerCase();
    if (mode !== "client_js") {
      return backendResult;
    }

    var code = asTrimmedString(executionSource.code);
    if (!code) {
      return {
        ok: false,
        function_name: functionName,
        error: "Missing JavaScript code for client execution."
      };
    }

    try {
      var payloadData = isRecord(payload) ? payload : {};
      if (isRecord(executionSource.payload)) {
        payloadData = executionSource.payload;
      }
      var context = {
        function_name: functionName,
        current_url: window.location.href,
        widget_mode: this.widgetMode,
        page_title: document && typeof document.title === "string" ? document.title : "",
        trace_id: wrappedBackendResult && typeof wrappedBackendResult.trace_id === "string" ? wrappedBackendResult.trace_id : "",
        backend_result: wrappedBackendResult || backendResult
      };

      var fn = new Function(
        "payload",
        "context",
        "widget",
        "config",
        "window",
        "document",
        code
      );

      var result = fn(payloadData, context, this, this.globalConfig, window, document);
      if (result && typeof result.then === "function") {
        result = await result;
      }

      var output = {
        ok: true,
        execution_mode: "client_js",
        function_name: functionName,
        result: result
      };
      if (typeof executionSource.plugin === "string" && executionSource.plugin) {
        output.plugin = executionSource.plugin;
      }
      if (wrappedBackendResult && typeof wrappedBackendResult.trace_id === "string" && wrappedBackendResult.trace_id) {
        output.trace_id = wrappedBackendResult.trace_id;
      }
      if (wrappedBackendResult && wrappedBackendResult.metadata) {
        output.metadata = wrappedBackendResult.metadata;
      }
      return output;
    } catch (error) {
      var failed = {
        ok: false,
        execution_mode: "client_js",
        function_name: functionName,
        error: "Client JavaScript execution failed.",
        details: String(error)
      };
      if (typeof executionSource.plugin === "string" && executionSource.plugin) {
        failed.plugin = executionSource.plugin;
      }
      if (wrappedBackendResult && typeof wrappedBackendResult.trace_id === "string" && wrappedBackendResult.trace_id) {
        failed.trace_id = wrappedBackendResult.trace_id;
      }
      return failed;
    }
  };

  function initWidgets() {
    var nodes = document.querySelectorAll(".navai-voice-widget");
    for (var i = 0; i < nodes.length; i += 1) {
      new NavaiVoiceWidget(nodes[i]);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initWidgets);
  } else {
    initWidgets();
  }

  runtime.NavaiVoiceWidget = NavaiVoiceWidget;
})();
