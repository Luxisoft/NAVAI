(function () {
  "use strict";

  var runtime = window.NAVAI_VOICE_RUNTIME || (window.NAVAI_VOICE_RUNTIME = {});


  var RESERVED_TOOL_NAMES = {
    navigate_to: true,
    execute_app_function: true,
    stop_navai_voice: true
  };
  var TOOL_NAME_REGEXP = /^[a-zA-Z0-9_-]{1,64}$/;
  var DEFAULT_WEBRTC_URL = "https://api.openai.com/v1/realtime/calls";
  var DEFAULT_MODEL = "gpt-realtime";
  var MAX_LOG_LINES = 120;
  var GLOBAL_ACTIVE_STORAGE_KEY = "navai_voice_global_active";
  var GLOBAL_SESSION_KEY_STORAGE_KEY = "navai_voice_session_key";

  function isRecord(value) {
    return Boolean(value) && typeof value === "object" && !Array.isArray(value);
  }

  function asTrimmedString(value) {
    if (typeof value !== "string") {
      return "";
    }
    return value.trim();
  }

  function parseJsonSafe(raw) {
    try {
      return JSON.parse(raw);
    } catch (_error) {
      return null;
    }
  }

  function getSafeStorage() {
    try {
      if (!window.localStorage) {
        return null;
      }
      return window.localStorage;
    } catch (_error) {
      return null;
    }
  }

  function safeJsonStringify(value) {
    try {
      return JSON.stringify(value);
    } catch (_error) {
      return JSON.stringify({ ok: false, error: "Failed to serialize result." });
    }
  }

  function sanitizeSessionKey(value) {
    var clean = asTrimmedString(value).toLowerCase().replace(/[^a-z0-9:_-]/g, "");
    if (clean.length > 191) {
      clean = clean.slice(0, 191);
    }
    return clean;
  }

  function generateSessionKey() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return sanitizeSessionKey(window.crypto.randomUUID());
    }

    var now = Date.now ? Date.now() : new Date().getTime();
    var rand = Math.random().toString(36).slice(2, 12);
    return sanitizeSessionKey("navai_" + now + "_" + rand);
  }

  function joinUrl(baseUrl, pathName) {
    var base = asTrimmedString(baseUrl).replace(/\/+$/, "");
    var path = pathName.charAt(0) === "/" ? pathName : "/" + pathName;
    return base + path;
  }

  function normalizeMatchValue(value) {
    return asTrimmedString(value).toLowerCase();
  }

  function normalizeTextForMatch(value) {
    var text = asTrimmedString(value).toLowerCase();
    if (!text) {
      return "";
    }

    if (typeof text.normalize === "function") {
      text = text.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    }

    text = text
      .replace(/[\u2018\u2019\u201C\u201D'"`´]/g, " ")
      .replace(/[^a-z0-9/_\s-]/g, " ")
      .replace(/[_-]+/g, " ")
      .replace(/\s+/g, " ")
      .trim();

    return text;
  }

  function uniqueStrings(values) {
    var output = [];
    var seen = {};

    for (var i = 0; i < values.length; i += 1) {
      var item = asTrimmedString(values[i]);
      if (!item) {
        continue;
      }

      var key = item.toLowerCase();
      if (seen[key]) {
        continue;
      }

      seen[key] = true;
      output.push(item);
    }

    return output;
  }

  function getGlobalConfig() {
    if (isRecord(window.NAVAI_VOICE_CONFIG)) {
      return window.NAVAI_VOICE_CONFIG;
    }
    return {};
  }

  function getMessage(config, key, fallback) {
    if (isRecord(config.messages) && typeof config.messages[key] === "string") {
      return config.messages[key];
    }
    return fallback;
  }

  function normalizeRoutePathForCompare(routePath) {
    var raw = asTrimmedString(routePath);
    if (!raw) {
      return "";
    }

    try {
      var url = new URL(raw, window.location.origin);
      if (url.origin === window.location.origin) {
        return url.pathname.replace(/\/+$/, "").toLowerCase() || "/";
      }
      return url.href.replace(/\/+$/, "").toLowerCase();
    } catch (_error) {
      return raw.replace(/\/+$/, "").toLowerCase();
    }
  }

  function extractPathTokens(path) {
    var tokens = [];
    var pathname = "";

    try {
      pathname = new URL(path, window.location.origin).pathname || "";
    } catch (_error) {
      pathname = asTrimmedString(path);
    }

    if (!pathname) {
      return tokens;
    }

    var parts = pathname.split("/");
    for (var i = 0; i < parts.length; i += 1) {
      var decoded = "";
      try {
        decoded = decodeURIComponent(parts[i]);
      } catch (_error2) {
        decoded = parts[i];
      }

      var token = normalizeTextForMatch(decoded);
      if (token) {
        tokens.push(token);
      }
    }

    return uniqueStrings(tokens);
  }

  function buildTargetCandidates(target) {
    var raw = asTrimmedString(target);
    if (!raw) {
      return [];
    }

    var base = normalizeTextForMatch(raw);
    var candidates = [];
    if (base) {
      candidates.push(base);
    }

    var stripped = base
      .replace(/^(por favor|porfa)\s+/, "")
      .replace(/^(ve|vaya|ir|ir a|abre|abrir|navega|navegar|ll[eé]vame|llevarme|go to|open)\s+/, "")
      .trim();
    if (stripped) {
      candidates.push(stripped);
    }

    var withoutArticle = stripped.replace(/^(al|a la|a el|a)\s+/, "").trim();
    if (withoutArticle) {
      candidates.push(withoutArticle);
    }

    var prepositions = [" a ", " al ", " a la ", " to "];
    for (var i = 0; i < prepositions.length; i += 1) {
      var sep = prepositions[i];
      var idx = withoutArticle.lastIndexOf(sep);
      if (idx > -1) {
        var tail = withoutArticle.slice(idx + sep.length).trim();
        if (tail) {
          candidates.push(tail);
        }
      }
    }

    return uniqueStrings(candidates);
  }

  function normalizeRoutes(raw) {
    if (!Array.isArray(raw)) {
      return [];
    }

    var routes = [];
    var seen = {};
    for (var i = 0; i < raw.length; i += 1) {
      var item = raw[i];
      if (!isRecord(item)) {
        continue;
      }

      var name = asTrimmedString(item.name);
      var path = asTrimmedString(item.path);
      var description = asTrimmedString(item.description) || "Allowed route.";
      if (!name || !path) {
        continue;
      }

      var synonyms = [];
      if (Array.isArray(item.synonyms)) {
        for (var j = 0; j < item.synonyms.length; j += 1) {
          var synonym = asTrimmedString(item.synonyms[j]);
          if (synonym) {
            synonyms.push(normalizeTextForMatch(synonym));
          }
        }
      }

      var normalizedName = normalizeTextForMatch(name);
      var pathTokens = extractPathTokens(path);
      var normalizedSynonyms = uniqueStrings(synonyms.concat(pathTokens));
      var normalizedPath = normalizeRoutePathForCompare(path);
      var dedupeKey = normalizedName + "|" + normalizedPath;
      if (seen[dedupeKey]) {
        continue;
      }
      seen[dedupeKey] = true;

      routes.push({
        name: name,
        path: path,
        description: description,
        synonyms: normalizedSynonyms,
        normalizedName: normalizedName,
        pathTokens: pathTokens,
        normalizedPath: normalizedPath
      });
    }

    return routes;
  }

  function normalizeRoadmapPhases(raw) {
    if (!Array.isArray(raw)) {
      return [];
    }

    var output = [];
    var seen = {};
    for (var i = 0; i < raw.length; i += 1) {
      var item = raw[i];
      if (!isRecord(item)) {
        continue;
      }

      var phaseNumber = Number(item.phase);
      if (!isFinite(phaseNumber) || phaseNumber <= 0) {
        phaseNumber = output.length + 1;
      }
      phaseNumber = Math.round(phaseNumber);

      var key = asTrimmedString(item.key).toLowerCase() || "phase_" + String(phaseNumber);
      if (seen[key]) {
        continue;
      }
      seen[key] = true;

      var label = asTrimmedString(item.label) || "Fase " + String(phaseNumber);
      var details = [];
      if (Array.isArray(item.details)) {
        for (var d = 0; d < item.details.length; d += 1) {
          var detail = asTrimmedString(item.details[d]);
          if (detail) {
            details.push(detail);
          }
        }
      }

      output.push({
        phase: phaseNumber,
        key: key,
        label: label,
        enabled: !!item.enabled,
        details: details
      });
    }

    output.sort(function (a, b) {
      return a.phase - b.phase;
    });

    return output;
  }

  function resolveAllowedRoute(target, routes) {
    if (!routes.length) {
      return null;
    }

    var candidates = buildTargetCandidates(target);
    if (!candidates.length) {
      return null;
    }

    for (var i = 0; i < candidates.length; i += 1) {
      var candidate = candidates[i];

      for (var j = 0; j < routes.length; j += 1) {
        var route = routes[j];
        if (route.normalizedName === candidate) {
          return route.path;
        }

        if (route.synonyms.indexOf(candidate) >= 0) {
          return route.path;
        }
      }
    }

    for (var c = 0; c < candidates.length; c += 1) {
      var candidatePath = normalizeRoutePathForCompare(candidates[c]);
      if (!candidatePath) {
        continue;
      }

      for (var p = 0; p < routes.length; p += 1) {
        if (routes[p].normalizedPath && routes[p].normalizedPath === candidatePath) {
          return routes[p].path;
        }
      }
    }

    var best = null;
    var bestScore = 0;
    for (var r = 0; r < routes.length; r += 1) {
      var routeItem = routes[r];
      var routeName = routeItem.normalizedName;
      var routeSynonyms = routeItem.synonyms || [];
      var score = 0;

      for (var k = 0; k < candidates.length; k += 1) {
        var current = candidates[k];
        if (!current) {
          continue;
        }

        if (routeName && current.indexOf(routeName) > -1) {
          score = Math.max(score, 90 + routeName.length);
        }
        if (routeName && routeName.indexOf(current) > -1) {
          score = Math.max(score, 70 + current.length);
        }

        for (var s = 0; s < routeSynonyms.length; s += 1) {
          var synonymValue = routeSynonyms[s];
          if (!synonymValue) {
            continue;
          }
          if (current === synonymValue) {
            score = Math.max(score, 88 + synonymValue.length);
          } else if (current.indexOf(synonymValue) > -1) {
            score = Math.max(score, 64 + synonymValue.length);
          } else if (synonymValue.indexOf(current) > -1) {
            score = Math.max(score, 58 + current.length);
          }
        }
      }

      if (score > bestScore) {
        bestScore = score;
        best = routeItem.path;
      }
    }

    if (best && bestScore >= 60) {
      return best;
    }

    return null;
  }

  function sanitizeBackendFunctions(rawItems) {
    if (!Array.isArray(rawItems)) {
      return [];
    }

    var seen = {};
    var output = [];
    for (var i = 0; i < rawItems.length; i += 1) {
      var item = rawItems[i];
      if (!isRecord(item)) {
        continue;
      }

      var name = normalizeMatchValue(item.name);
      if (!name || seen[name]) {
        continue;
      }

      seen[name] = true;
      output.push({
        name: name,
        description: asTrimmedString(item.description),
        source: asTrimmedString(item.source)
      });
    }

    return output;
  }

  function getRoutePromptLines(routes) {
    if (!routes.length) {
      return ["- none"];
    }

    var lines = [];
    for (var i = 0; i < routes.length; i += 1) {
      var route = routes[i];
      lines.push("- " + route.name + ": " + route.description + " (" + route.path + ")");
    }
    return lines;
  }

  function getRoadmapPhasePromptLines(phases) {
    if (!Array.isArray(phases) || !phases.length) {
      return ["- none"];
    }

    var lines = [];
    for (var i = 0; i < phases.length; i += 1) {
      var phase = phases[i];
      if (!isRecord(phase)) {
        continue;
      }

      var phaseNumber = typeof phase.phase === "number" && isFinite(phase.phase) ? Math.round(phase.phase) : i + 1;
      var label = asTrimmedString(phase.label) || "Fase " + String(phaseNumber);
      var suffix = phase.enabled ? "enabled" : "disabled";
      if (Array.isArray(phase.details) && phase.details.length) {
        suffix += "; " + phase.details.join(", ");
      }
      lines.push("- Fase " + String(phaseNumber) + ": " + label + " [" + suffix + "]");
    }

    if (!lines.length) {
      return ["- none"];
    }

    return lines;
  }

  function buildToolDefinitions(routes, backendFunctions, options) {
    var opts = isRecord(options) ? options : {};
    var allowStopTool = !!opts.allowStopTool;
    var tools = [
      {
        type: "function",
        name: "navigate_to",
        description: "Navigate to an allowed route in the current website.",
        parameters: {
          type: "object",
          additionalProperties: false,
          properties: {
            target: {
              type: "string",
              description: "Route name or route path. Example: inicio, /contacto"
            }
          },
          required: ["target"]
        }
      },
      {
        type: "function",
        name: "execute_app_function",
        description: "Execute an allowed backend function by name.",
        parameters: {
          type: "object",
          additionalProperties: false,
          properties: {
            function_name: {
              type: "string",
              description: "Allowed backend function name."
            },
            payload: {
              type: ["object", "null"],
              additionalProperties: true,
              description: "Payload object for the backend function."
            }
          },
          required: ["function_name", "payload"]
        }
      }
    ];

    if (allowStopTool) {
      tools.push({
        type: "function",
        name: "stop_navai_voice",
        description: "Stop and deactivate NAVAI voice interaction on user request.",
        parameters: {
          type: "object",
          additionalProperties: false,
          properties: {
            reason: {
              type: "string",
              description: "Optional reason provided by the user."
            }
          }
        }
      });
    }

    var aliasWarnings = [];
    var directAliases = [];
    for (var i = 0; i < backendFunctions.length; i += 1) {
      var functionName = backendFunctions[i].name;
      if (!functionName) {
        continue;
      }

      if (RESERVED_TOOL_NAMES[functionName]) {
        aliasWarnings.push(
          '[navai] Function "' +
            functionName +
            '" is available only via execute_app_function because it conflicts with a built-in tool.'
        );
        continue;
      }

      if (!TOOL_NAME_REGEXP.test(functionName)) {
        aliasWarnings.push(
          '[navai] Function "' +
            functionName +
            '" is available only via execute_app_function because its name is not a valid tool id.'
        );
        continue;
      }

      directAliases.push(functionName);
      tools.push({
        type: "function",
        name: functionName,
        description: 'Direct alias for execute_app_function("' + functionName + '").',
        parameters: {
          type: "object",
          additionalProperties: false,
          properties: {
            payload: {
              type: ["object", "null"],
              additionalProperties: true,
              description: "Payload object for backend function."
            }
          }
        }
      });
    }

    return {
      tools: tools,
      directAliases: directAliases,
      warnings: aliasWarnings
    };
  }

  function buildAssistantInstructions(baseInstructions, routes, backendFunctions, roadmapPhases, options) {
    var opts = isRecord(options) ? options : {};
    var allowStopTool = !!opts.allowStopTool;
    var functionLines = backendFunctions.length
      ? backendFunctions.map(function (item) {
          return "- " + item.name + ": " + (item.description || "Execute backend function.");
        })
      : ["- none"];

    var lines = [
      asTrimmedString(baseInstructions) || "You are a voice assistant embedded in a WordPress website.",
      "Allowed routes:"
    ];

    lines = lines.concat(getRoutePromptLines(routes));
    lines.push("Allowed app functions:");
    lines = lines.concat(functionLines);
    if (Array.isArray(roadmapPhases) && roadmapPhases.length) {
      lines.push("NAVAI roadmap phases:");
      lines = lines.concat(getRoadmapPhasePromptLines(roadmapPhases));
    }
    lines.push("Rules:");
    lines.push("- For product searches, catalog queries, prices, stock, orders, or plugin data, prefer execute_app_function or a direct function alias before navigate_to.");
    lines.push("- Use navigate_to only when the user explicitly wants to open/go to a page or website section.");
    lines.push("- If the user asks to open a website section, call navigate_to.");
    lines.push("- In navigate_to.target, prefer the exact route name listed in Allowed routes.");
    lines.push("- If the user asks to run an internal action, call execute_app_function or a direct function alias.");
    lines.push("- Always pass payload in execute_app_function. Use null when no arguments are needed.");
    if (allowStopTool) {
      lines.push("- If the user asks to stop, turn off, close, pause, deactivate, or shut down NAVAI, call stop_navai_voice.");
    }
    lines.push("- Never invent routes or functions that are not listed.");
    lines.push("- If destination/action is unclear, ask a brief clarifying question.");

    return lines.join("\n");
  }

  async function readErrorMessage(response) {
    var raw = "";
    try {
      raw = await response.text();
    } catch (_error) {
      raw = "";
    }

    var parsed = parseJsonSafe(raw);
    if (isRecord(parsed)) {
      if (typeof parsed.message === "string" && parsed.message.trim() !== "") {
        return parsed.message.trim();
      }
      if (isRecord(parsed.error) && typeof parsed.error.message === "string" && parsed.error.message.trim() !== "") {
        return parsed.error.message.trim();
      }
    }

    if (raw.trim() !== "") {
      return raw.trim();
    }

    return "HTTP " + response.status;
  }

  function buildWpHeaders(config) {
    var headers = {
      "Content-Type": "application/json"
    };

    var nonce = asTrimmedString(config.restNonce);
    if (nonce) {
      headers["X-WP-Nonce"] = nonce;
    }

    return headers;
  }

  function normalizeSpeechProvider(value) {
    return asTrimmedString(value).toLowerCase() === "elevenlabs" ? "elevenlabs" : "openai";
  }

  async function requestClientSecret(config, input) {
    var restBase = asTrimmedString(config.restBaseUrl);
    if (!restBase) {
      throw new Error("Missing restBaseUrl in NAVAI_VOICE_CONFIG.");
    }

    var response = await fetch(joinUrl(restBase, "/realtime/client-secret"), {
      method: "POST",
      headers: buildWpHeaders(config),
      body: safeJsonStringify(input || {})
    });

    if (!response.ok) {
      throw new Error(await readErrorMessage(response));
    }

    var payload = null;
    try {
      payload = await response.json();
    } catch (_error) {
      payload = null;
    }

    if (!isRecord(payload) || typeof payload.value !== "string") {
      throw new Error("Invalid client secret response.");
    }

    return {
      value: payload.value,
      expiresAt: typeof payload.expires_at === "number" ? payload.expires_at : null,
      session: isRecord(payload.session) ? payload.session : null,
      speech: isRecord(payload.speech)
        ? {
            provider: normalizeSpeechProvider(payload.speech.provider)
          }
        : null
    };
  }

  async function requestSpeechSynthesis(config, input) {
    var restBase = asTrimmedString(config.restBaseUrl);
    if (!restBase) {
      throw new Error("Missing restBaseUrl in NAVAI_VOICE_CONFIG.");
    }

    var response = await fetch(joinUrl(restBase, "/speech/synthesize"), {
      method: "POST",
      headers: buildWpHeaders(config),
      body: safeJsonStringify(input || {})
    });

    if (!response.ok) {
      throw new Error(await readErrorMessage(response));
    }

    var payload = null;
    try {
      payload = await response.json();
    } catch (_error) {
      payload = null;
    }

    if (!isRecord(payload) || typeof payload.audioBase64 !== "string" || payload.audioBase64.trim() === "") {
      throw new Error("Invalid speech synthesis response.");
    }

    return {
      provider: normalizeSpeechProvider(payload.provider),
      mimeType: asTrimmedString(payload.mimeType) || "audio/mpeg",
      audioBase64: payload.audioBase64
    };
  }

  async function requestBackendFunctions(config) {
    var restBase = asTrimmedString(config.restBaseUrl);
    if (!restBase) {
      return {
        functions: [],
        warnings: ["[navai] Missing restBaseUrl in NAVAI_VOICE_CONFIG."]
      };
    }

    try {
      var response = await fetch(joinUrl(restBase, "/functions"), {
        method: "GET",
        headers: buildWpHeaders(config)
      });

      if (!response.ok) {
        return {
          functions: [],
          warnings: ['[navai] Failed to list backend functions: ' + (await readErrorMessage(response))]
        };
      }

      var payload = null;
      try {
        payload = await response.json();
      } catch (_error) {
        payload = null;
      }

      if (!isRecord(payload)) {
        return {
          functions: [],
          warnings: ["[navai] Invalid backend functions response."]
        };
      }

      var warnings = [];
      if (Array.isArray(payload.warnings)) {
        for (var i = 0; i < payload.warnings.length; i += 1) {
          if (typeof payload.warnings[i] === "string") {
            warnings.push(payload.warnings[i]);
          }
        }
      }

      return {
        functions: sanitizeBackendFunctions(payload.items),
        warnings: warnings
      };
    } catch (error) {
      return {
        functions: [],
        warnings: ["[navai] Failed to list backend functions: " + String(error)]
      };
    }
  }

  async function requestRoutes(config) {
    var restBase = asTrimmedString(config.restBaseUrl);
    if (!restBase) {
      return {
        ok: false,
        routes: [],
        warnings: ["[navai] Missing restBaseUrl in NAVAI_VOICE_CONFIG."]
      };
    }

    try {
      var endpoint = joinUrl(restBase, "/routes");
      var separator = endpoint.indexOf("?") === -1 ? "?" : "&";
      endpoint = endpoint + separator + "_navai_t=" + Date.now();

      var response = await fetch(endpoint, {
        method: "GET",
        headers: buildWpHeaders(config),
        cache: "no-store"
      });

      if (!response.ok) {
        return {
          ok: false,
          routes: [],
          warnings: ['[navai] Failed to load routes: ' + (await readErrorMessage(response))]
        };
      }

      var payload = null;
      try {
        payload = await response.json();
      } catch (_error) {
        payload = null;
      }

      if (!isRecord(payload) || !Array.isArray(payload.items)) {
        return {
          ok: false,
          routes: [],
          warnings: ["[navai] Invalid routes response."]
        };
      }

      return {
        ok: true,
        routes: normalizeRoutes(payload.items),
        warnings: []
      };
    } catch (error) {
      return {
        ok: false,
        routes: [],
        warnings: ["[navai] Failed to load routes: " + String(error)]
      };
    }
  }

  async function executeBackendFunction(config, functionName, payload, options) {
    var restBase = asTrimmedString(config.restBaseUrl);
    if (!restBase) {
      throw new Error("Missing restBaseUrl in NAVAI_VOICE_CONFIG.");
    }

    var sessionKey = "";
    if (isRecord(options) && typeof options.sessionKey === "string") {
      sessionKey = sanitizeSessionKey(options.sessionKey);
    }

    var response = await fetch(joinUrl(restBase, "/functions/execute"), {
      method: "POST",
      headers: buildWpHeaders(config),
      body: safeJsonStringify({
        function_name: functionName,
        payload: payload,
        session_key: sessionKey || undefined
      })
    });

    if (!response.ok) {
      throw new Error(await readErrorMessage(response));
    }

    var result = null;
    try {
      result = await response.json();
    } catch (_error) {
      result = null;
    }

    if (!isRecord(result)) {
      throw new Error("Invalid backend function response.");
    }

    if (result.ok !== true) {
      if (result.pending_approval === true) {
        return result;
      }
      if (typeof result.details === "string" && result.details.trim() !== "") {
        throw new Error(result.details.trim());
      }
      if (typeof result.error === "string" && result.error.trim() !== "") {
        throw new Error(result.error.trim());
      }
      throw new Error("Backend function execution failed.");
    }

    return result;
  }

  async function sendSessionMessages(config, sessionKey, items) {
    var restBase = asTrimmedString(config.restBaseUrl);
    if (!restBase) {
      return { ok: false, error: "Missing restBaseUrl in NAVAI_VOICE_CONFIG." };
    }

    var cleanSessionKey = sanitizeSessionKey(sessionKey);
    if (!cleanSessionKey) {
      return { ok: false, error: "session_key is required." };
    }

    if (!Array.isArray(items) || !items.length) {
      return { ok: true, saved: 0, failed: 0, persisted: false };
    }

    try {
      var response = await fetch(joinUrl(restBase, "/sessions"), {
        method: "POST",
        headers: buildWpHeaders(config),
        body: safeJsonStringify({
          session_key: cleanSessionKey,
          items: items
        })
      });

      var result = null;
      try {
        result = await response.json();
      } catch (_error) {
        result = null;
      }

      if (!response.ok) {
        return {
          ok: false,
          error: await readErrorMessage(response)
        };
      }

      if (!isRecord(result)) {
        return { ok: false, error: "Invalid session messages response." };
      }

      return result;
    } catch (error) {
      return { ok: false, error: String(error) };
    }
  }


  runtime.RESERVED_TOOL_NAMES = RESERVED_TOOL_NAMES;
  runtime.TOOL_NAME_REGEXP = TOOL_NAME_REGEXP;
  runtime.DEFAULT_WEBRTC_URL = DEFAULT_WEBRTC_URL;
  runtime.DEFAULT_MODEL = DEFAULT_MODEL;
  runtime.MAX_LOG_LINES = MAX_LOG_LINES;
  runtime.GLOBAL_ACTIVE_STORAGE_KEY = GLOBAL_ACTIVE_STORAGE_KEY;
  runtime.GLOBAL_SESSION_KEY_STORAGE_KEY = GLOBAL_SESSION_KEY_STORAGE_KEY;
  runtime.isRecord = isRecord;
  runtime.asTrimmedString = asTrimmedString;
  runtime.parseJsonSafe = parseJsonSafe;
  runtime.getSafeStorage = getSafeStorage;
  runtime.safeJsonStringify = safeJsonStringify;
  runtime.sanitizeSessionKey = sanitizeSessionKey;
  runtime.generateSessionKey = generateSessionKey;
  runtime.joinUrl = joinUrl;
  runtime.normalizeMatchValue = normalizeMatchValue;
  runtime.normalizeTextForMatch = normalizeTextForMatch;
  runtime.uniqueStrings = uniqueStrings;
  runtime.getGlobalConfig = getGlobalConfig;
  runtime.getMessage = getMessage;
  runtime.normalizeRoutePathForCompare = normalizeRoutePathForCompare;
  runtime.extractPathTokens = extractPathTokens;
  runtime.buildTargetCandidates = buildTargetCandidates;
  runtime.normalizeRoutes = normalizeRoutes;
  runtime.normalizeRoadmapPhases = normalizeRoadmapPhases;
  runtime.resolveAllowedRoute = resolveAllowedRoute;
  runtime.sanitizeBackendFunctions = sanitizeBackendFunctions;
  runtime.getRoutePromptLines = getRoutePromptLines;
  runtime.getRoadmapPhasePromptLines = getRoadmapPhasePromptLines;
  runtime.buildToolDefinitions = buildToolDefinitions;
  runtime.buildAssistantInstructions = buildAssistantInstructions;
  runtime.readErrorMessage = readErrorMessage;
  runtime.buildWpHeaders = buildWpHeaders;
  runtime.normalizeSpeechProvider = normalizeSpeechProvider;
  runtime.requestClientSecret = requestClientSecret;
  runtime.requestSpeechSynthesis = requestSpeechSynthesis;
  runtime.requestBackendFunctions = requestBackendFunctions;
  runtime.requestRoutes = requestRoutes;
  runtime.executeBackendFunction = executeBackendFunction;
  runtime.sendSessionMessages = sendSessionMessages;
})();
