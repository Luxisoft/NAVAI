# NAVAI Voice for WordPress

<p align="center">
  <a href="./docs/README.es.md"><img alt="Spanish" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="./README.md"><img alt="English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="https://navai.luxisoft.com/documentation/installation-wordpress"><img alt="Documentation" src="https://img.shields.io/badge/WordPress%20Documentation-Open-146EF5?style=for-the-badge"></a>
  <a href="./release/build-zip.ps1"><img alt="Build ZIP" src="https://img.shields.io/badge/Build%20ZIP-PowerShell-5C2D91?style=for-the-badge"></a>
  <a href="./navai-voice.php"><img alt="Plugin Bootstrap" src="https://img.shields.io/badge/Plugin-Bootstrap-2F6FEB?style=for-the-badge"></a>
</p>

<p align="center">
  <img alt="WordPress 6.2+" src="https://img.shields.io/badge/WordPress-6.2%2B-21759B?style=for-the-badge">
  <img alt="PHP 8.0+" src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge">
  <img alt="OpenAI Realtime" src="https://img.shields.io/badge/OpenAI-Realtime-0B8F6A?style=for-the-badge">
</p>

NAVAI Voice is a WordPress plugin that adds a voice widget powered by OpenAI Realtime, with optional hybrid ElevenLabs TTS, plus an admin dashboard to control navigation routes, custom functions, and runtime settings without Node.js.

This plugin is implemented in PHP (server side) and vanilla JS (browser side) for easier WordPress deployment.

## Quick links

- `Install`: [WordPress admin install](#installation-wordpress-admin) | [Manual install](#installation-manual--filesystem)
- `Configure`: [Quick configuration](#quick-configuration-recommended)
- `Use`: [Global floating button](#option-a-global-floating-button) | [Shortcode](#option-b-shortcode)
- `Admin sections`: [Navigation](#navigation-tab-allowed-routes-for-ai) | [Functions](#functions-tab-custom-functions) | [Settings > Safety](#settings--safety-tab-guardrails-phase-1) | [Settings > Approvals/Traces/History](#integrated-phases-phase-2-to-phase-7) | [Agents](#integrated-phases-phase-2-to-phase-7) | [MCP](#integrated-phases-phase-2-to-phase-7)
- `Developer`: [REST endpoints](#rest-endpoints-current) | [Backend extensibility](#backend-extensibility-filters)
- `Ops`: [Build ZIP](#build-installable-zip-powershell) | [Troubleshooting](#troubleshooting)

## Usage examples (start here)

### Navigation examples

These examples work only if the target route is enabled in the `Navigation` tab and the current user has access.

- "Go to the contact page"
- "Open checkout"
- "Take me to my account"
- "Navigate to orders"
- "Open WooCommerce settings"
- "Go to Coupons in WooCommerce" (if configured as a private/admin route)
- "Open WPForms entries" (if added as a private route)

Tip: add route descriptions like "Use this route when the user asks to manage coupons" to improve routing decisions.

### Custom function examples

These examples depend on the functions you create and activate in the `Functions` tab.

Possible use cases in WordPress:

- Read WooCommerce recent orders
- Check low-stock products
- Create a support note in a plugin/system
- Fetch form submissions summary (WPForms / custom forms)
- Query user profile or membership status
- Trigger a CRM sync action
- Run an internal admin helper task (trusted environments only)

Example prompts a user can say:

- "Show me the last 5 orders"
- "Check if any products are low on stock"
- "Get the latest contact form submissions"
- "Run the order sync function"
- "Open the orders page and then fetch pending orders"

Recommended pattern:

- Use `Navigation` for moving the user to the correct page
- Use `Functions` custom functions for reading data or executing actions
- Add clear descriptions so NAVAI knows when each function should be called

### Guardrails examples (Phase 1)

Use cases for the `Safety` tab (guardrails):

- Block dangerous store actions (for example `delete_order`, `delete_product`)
- Block payloads containing sensitive data (card data, IDs, secrets)
- Mark high-risk actions as `warn` first to calibrate before blocking
- Block sensitive output (for example emails) using regex on `output`
- Restrict rules by role (`guest`, `subscriber`, `administrator`) and function/plugin

Quick WooCommerce example:

- `Scope`: `tool`
- `Type`: `keyword`
- `Action`: `block`
- `Pattern`: `delete`
- `Plugin/Function scope`: `run_plugin_action,order`

This helps prevent NAVAI from executing destructive backend actions.

## What the plugin can do today

- Add a voice widget to WordPress using OpenAI Realtime (WebRTC).
- Optionally keep OpenAI Realtime for microphone/tools while synthesizing final assistant speech with ElevenLabs.
- Run in two display modes:
  - Global floating button
  - Manual shortcode (`[navai_voice]`)
- Show the global widget in `wp-admin` too (for admins), forced to the right side so it does not cover the left WordPress menu.
- Restrict widget visibility by WordPress role (including guests).
- Define allowed navigation routes for the `navigate_to` tool.
- Create private custom routes by plugin + role + URL.
- Add route descriptions (to guide the AI when to use each route).
- Create custom plugin functions from the dashboard (by plugin + role) using a responsive modal (JavaScript-only editor).
- Configure per-function runtime metadata:
  - `function_name` (tool name)
  - execution scope (`frontend`, `admin`, `both`)
  - `timeout`, `retries`
  - `requires approval`
  - optional `JSON Schema`
- Test custom functions from the admin panel with a JSON payload before saving.
- Import/export custom functions as `.js` packs from the `Functions` panel.
- Assign existing custom functions to AI agents directly from the `Functions` modal (`Agentes IA permitidos`) using `function_name` sync.
- Enable/disable custom functions individually with checkboxes.
- Edit/delete custom functions directly from the list.
- Filter routes/functions by text, plugin, and role in the admin UI.
- Switch admin panel language from the NAVAI dashboard (`English`, `Español`, `Português`, `Français`, `Русский`, `한국어`, `日本語`, `中文`, `हिंदी`).
- Apply full dashboard translations in `English`/`Spanish` and fallback to English for the additional language selector options.
- Configure guardrails (Phase 1) in `Settings > Safety` for `input`, `tool`, and `output` using `keyword`/`regex` rules.
- Block function calls (`/functions/execute`) when a guardrail rule matches.
- Test guardrail rules from the admin panel (`Settings > Safety > Test rules`).
- Store minimal guardrail block events (`guardrail_blocked`) in the database for basic traceability.
- Manage human approvals (HITL) for sensitive functions (`pending`, `approved`, `rejected`) and execute pending actions from `Settings > Approvals`.
- Inspect runtime traces (tools, guardrails, approvals, handoffs, MCP policy blocks) from `Settings > Traces`.
- Persist sessions, transcripts, and tool calls with retention/cleanup controls in `Settings > History`.
- Configure specialist agents and handoff rules with modal CRUD UI and internal tabs (`Agents` / `Handoffs`).
- Integrate MCP servers (HTTP JSON-RPC), sync remote tools, and restrict them by role and/or agent with modal CRUD for servers/policies.
- Use built-in REST endpoints for client secret, routes, function listing, and execution.

## Requirements

- WordPress `6.2+`
- PHP `8.0+`
- OpenAI API key with Realtime access

## Admin dashboard overview

The plugin adds a left menu item:

- `NAVAI Voice`

The dashboard uses top-level operational tabs plus nested sub-tabs inside `Settings`:

- `Navigation`
  - Public menu routes
  - Private custom routes by role
  - Route descriptions
  - Filters and bulk selection actions
- `Functions`
  - Custom function editor in a responsive modal (create/edit)
  - JavaScript-only function code editor
  - Function metadata (`function_name`, scope, timeout, retries, approval, JSON Schema)
  - Test payload + `Test function`
  - Agent assignment (`Agentes IA permitidos`) synced to agent `allowed_tools`
  - Custom function list with activation checkboxes
  - Edit/Delete actions
  - Filters by text/plugin/role
  - Import/Export functions (`.js`) with selection/filtering
- `Agents` (Phase 6)
  - Toggle for multi-agent + handoffs
  - Two internal tabs: `Agents` and `Configured handoff rules`
  - Agent CRUD in modal (specialist profile + instructions)
  - Handoff rule CRUD in modal (intent/function/payload/roles/context conditions)
  - Function assignment is managed from the `Functions` panel (per `function_name`)
- `MCP` (Phase 7)
  - Toggle for MCP integrations
  - MCP server CRUD in modal (URL, auth, timeouts, SSL, extra headers)
  - Health check + sync/list remote tools
  - Remote tools cache viewer (`tool` -> runtime `function_name`)
  - Allow/Deny policy CRUD in modal by tool, role, and `agent_key`
- `Settings`
  - Internal sub-tabs:
    - `General`: connection/runtime, widget, visibility, shortcode, voice/text/VAD settings
    - `Safety` (Phase 1): guardrails rules + tester
    - `Approvals` (Phase 2): HITL queue + decisions
    - `Traces` (Phase 2): runtime traces + timelines
    - `History` (Phase 3): sessions, transcripts, retention/compaction

Extra controls in the top header:

- `Documentation` button (opens NAVAI WordPress documentation)
- `Panel language` selector (`English`, `Español`, `Português`, `Français`, `Русский`, `한국어`, `日本語`, `中文`, `हिंदी`) without code prefixes (`en`, `es`, etc.)
- Sticky header (logo + menu) while scrolling inside the NAVAI settings page
- Dashboard opens in `Navigation` by default (first tab) when entering NAVAI settings

## Installation (WordPress admin)

1. Build or obtain the plugin ZIP (`navai-voice.zip`).
2. In WordPress, go to `Plugins > Add New > Upload Plugin`.
3. Upload the ZIP and activate the plugin.
4. Open `NAVAI Voice` from the left admin menu.

## Installation (manual / filesystem)

1. Copy `apps/wordpress-plugin` to:
   - `wp-content/plugins/navai-voice`
2. Activate `NAVAI Voice` in WordPress.

## Quick configuration (recommended)

1. Open `NAVAI Voice` (the dashboard opens in `Navigation` first), then go to `Settings`.
2. Configure at minimum:
   - `OpenAI API Key`
   - `Realtime Model` (default: `gpt-realtime`)
   - `Voice` (default: `marin`)
3. Optional hybrid TTS with ElevenLabs:
   - set `NAVAI_TTS_PROVIDER=elevenlabs` as env var or PHP constant
   - configure `ELEVENLABS_API_KEY` and `ELEVENLABS_VOICE_ID`
   - optional overrides: `ELEVENLABS_BASE_URL`, `ELEVENLABS_MODEL_ID`, `ELEVENLABS_OUTPUT_FORMAT`, `ELEVENLABS_OPTIMIZE_STREAMING_LATENCY`, `ELEVENLABS_STABILITY`, `ELEVENLABS_SIMILARITY_BOOST`, `ELEVENLABS_STYLE`, `ELEVENLABS_USE_SPEAKER_BOOST`
4. Choose widget mode:
   - `Global floating button` (recommended for quick setup)
   - `Manual shortcode only`
5. Configure visibility:
   - Select which roles can see the widget (and whether guests are allowed)
6. If you need backend tools for public visitors, explicitly enable `Allow public backend functions` in `Settings`.
7. Click `Save changes`.

## How to use the plugin

## Option A: Global floating button

Set `Component render mode` to `Global floating button`.

The widget will render automatically:

- On the public site (if current user role is allowed)
- In `wp-admin` for administrators (global button is forced to the right side)

## Option B: Shortcode

Set `Component render mode` to `Manual shortcode only`, then insert:

```txt
[navai_voice]
```

Shortcode example with overrides:

```txt
[navai_voice label="Talk to NAVAI" stop_label="Stop NAVAI" model="gpt-realtime" voice="marin" debug="1"]
```

Supported shortcode attributes:

- `label`
- `stop_label`
- `model`
- `voice`
- `instructions`
- `language`
- `voice_accent`
- `voice_tone`
- `debug` (`0` or `1`)
- `class`

## Agent state events (frontend)

The widget emits state events on the `.navai-voice-widget` element and also on `window`, so external projects can react to voice lifecycle changes.

Events:

- `navai:agent-state` (on each state change)
- `navai:agent-speaking-start` (agent started speaking)
- `navai:agent-speaking-stop` (agent finished/interrupted speaking)

`event.detail` includes:

- `connection_state`: `idle | connecting | connected | error`
- `activity_state`: `idle | listening | speaking | interrupted`
- `assistant_voice_state`: `idle | speaking`
- `is_assistant_speaking`: `boolean`
- `connection_mode`: `voice | text`
- `widget_mode`

The widget container also updates these `data-*` attributes:

- `data-navai-connection-state`
- `data-navai-activity-state`
- `data-navai-assistant-voice-state`
- `data-navai-assistant-speaking` (`1`/`0`)

Quick example:

```js
const widget = document.querySelector(".navai-voice-widget");
if (widget) {
  widget.addEventListener("navai:agent-state", (event) => {
    const state = event.detail || {};
    console.log("NAVAI state:", state.assistant_voice_state, state.connection_state);
  });
}
```

## Navigation tab (allowed routes for AI)

Use this tab to control where the AI can navigate when it calls `navigate_to`.

### Public routes

- Detects WordPress public menu routes
- Lets you select which routes NAVAI can use
- Lets you add descriptions for each route (recommended)

### Private routes

- Add custom private URLs manually
- Assign each route to:
  - Plugin group
  - Role
  - URL
  - Description

This is useful for role-based admin pages or protected pages.

## Functions tab (custom functions)

Use this tab to define custom functions per plugin and role with a modal-based editor.

Workflow:

1. Click `Create function` (opens the responsive modal).
2. Select `Plugin` and `Role`.
3. Set `Function name (tool)` (normalized to `snake_case` on save).
4. Paste JavaScript in `NAVAI Function (JavaScript)`.
5. Add a clear `Description` (recommended for tool selection).
6. Optionally configure:
   - `Execution scope` (`Frontend and admin`, `Frontend only`, `Admin only`)
   - `Timeout (seconds)`
   - `Retries`
   - `Requires approval`
   - `Argument JSON Schema`
   - `Allowed AI agents` (syncs with agent `allowed_tools` by `function_name`)
7. Optionally run `Test function` with a JSON payload.
8. Click `Add function` / `Save changes`.

After creating:

- The function is added to the list.
- It is active by default.
- You can later:
  - Edit (reopens the same modal prefilled)
  - Delete
  - Activate/deactivate via checkbox
- You can import/export `.js` function packs from the same panel.

### Dashboard custom function format

- The dashboard editor accepts custom JavaScript functions (no PHP mode in the panel editor).
- The UI validates the code as JavaScript and blocks PHP snippets.
- Use the function `Description` + route descriptions to improve tool selection by NAVAI.

### Recommended function design

- Keep one function focused on one job (`search_products`, `list_orders`, `get_form_entries`).
- Validate expected payloads with `JSON Schema`.
- Use `Timeout` and `Retries` for external calls.
- Mark write/sensitive actions with `Requires approval`.
- Assign each function to the relevant specialist agents from the same modal.

## Settings > Safety tab (Guardrails, Phase 1)

Use this section to create rules that block or warn when NAVAI attempts to execute functions with inputs, payloads, or results you do not want to allow.

### What it evaluates

- `input`: input/payload before function execution
- `tool`: the tool/function call and its payload
- `output`: the function result after execution

### Rule types

- `keyword`: substring text match
- `regex`: regular expression match

### Rule actions

- `block`: blocks execution or output
- `warn`: does not block, but records a match (useful for tuning)
- `allow`: reference rule (no blocking)

### Useful fields

- `Roles (csv)`: limit by roles (`guest,subscriber,administrator`)
- `Plugin/Function scope (csv)`: limit by function name or source (for example `run_plugin_action,woocommerce`)
- `Priority`: lower number is evaluated first

### Example 1 (WooCommerce store): block destructive actions

Recommended rule:

- `Name`: `Block order deletion`
- `Scope`: `tool`
- `Type`: `keyword`
- `Action`: `block`
- `Pattern`: `delete`
- `Plugin/Function scope`: `run_plugin_action,order`

Usage:

- If NAVAI attempts a backend action with a payload containing `delete`, the call is blocked and returns `403`.

### Example 2 (blog): block email leaks in outputs

Recommended rule:

- `Scope`: `output`
- `Type`: `regex`
- `Action`: `block`
- `Pattern`: `/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i`

Usage:

- If a function returns emails by mistake, NAVAI blocks the output.

### Test rules before enabling in real flows

In `Settings > Safety > Test rules`, you can send:

- `Scope`
- `Function name`
- `Function source`
- `Test text`
- `Payload JSON`

This calls the test endpoint and returns whether a rule would match (`blocked`, `matched_count`, `matches`).

## Integrated phases (Phase 2 to Phase 7)

This release already includes the advanced roadmap phases. The summary below explains what each one is for, how to use it, and WordPress use cases.

### Phase 2: Approvals (HITL) + Traces

#### Approvals: what it is for

- Prevents automatic execution of sensitive functions.
- Lets an admin review payloads before allowing execution.
- Stores decision status and execution result for auditing.

#### Approvals: how to use

1. In `Functions`, mark the function as `Requires approval`.
2. In `Settings > Approvals`, enable `Enable approvals for sensitive functions`.
3. When NAVAI tries to run it, a `pending` request is created.
4. Open `Settings > Approvals > View details`.
5. Review function, payload, and trace.
6. Click `Approve` or `Reject`.

By default, approving can execute the pending function immediately.

#### Approvals: WordPress use cases

- WooCommerce order cancellation/refund flows
- Membership upgrades/downgrades
- CRM sync or external write actions
- Admin-only automation with business impact

#### Traces: what it is for

- Understand the sequence of runtime events.
- Debug tool failures, guardrail blocks, and approvals.
- Inspect agent handoffs and MCP policy blocks.

#### Traces: how to use

1. Enable tracing in `Settings > Traces`.
2. Run a test via widget/admin.
3. Open `Settings > Traces`.
4. Filter by event/severity.
5. Open a `trace_id` timeline.

#### Traces: WordPress use cases

- Debug failed WooCommerce helper functions
- Explain why a route/tool was blocked
- Validate handoff behavior between specialist agents

### Phase 3: Sessions + memory + transcript

#### What it is for

- Maintains conversation context between requests.
- Stores transcripts and tool calls for support/ops.
- Adds retention and cleanup controls.

#### How to use

1. Enable session persistence/memory in `Settings > History`.
2. Configure TTL/retention/compaction.
3. Use the widget normally.
4. Review sessions and transcripts in `Settings > History`.
5. Clear sessions or run retention cleanup when needed.

#### WordPress use cases

- Support review before human escalation
- Ecommerce troubleshooting for failed checkout journeys
- Audit of tools used in admin workflows

### Phase 4: Advanced voice UX + text/voice hybrid

#### What it is for

- Improves realtime voice control (VAD, interruption behavior).
- Adds text fallback while preserving the same session.

#### How to use

1. Configure turn detection / VAD settings in `Settings`.
2. Enable/disable interruptions.
3. Enable text input fallback.
4. Test continuity between text and voice.

#### WordPress use cases

- Public site assistant with voice + text accessibility
- Backoffice environments where text is preferred
- Faster voice interactions for internal teams

### Phase 5: Robust custom functions (JavaScript, schema, timeout, retries, test)

#### What it is for

- Validates payload shape before execution.
- Controls runtime behavior (timeout/retries).
- Adds a safe test workflow before production use.
- Lets you assign functions to specialist agents from the `Functions` modal.

#### How to use

1. In `Functions`, define an optional `JSON Schema`.
2. Configure `Timeout`, `Retries`, `Scope`, and `Requires approval`.
3. Use `Test function` with a payload.
4. (Optional) assign allowed AI agents for that function.
5. Save only after validation passes.

#### WordPress use cases

- WooCommerce functions requiring a valid `order_id`
- External API helpers with retry logic
- Form-processing helpers validated before enabling

### Phase 6: Multi-agent + handoffs

#### What it is for

- Splits responsibilities across specialist agents.
- Splits orchestration (agents + handoffs) from function authoring.
- Uses existing `Functions` entries (`function_name`) as the source of truth for agent tool access.
- Delegates automatically using handoff rules.

#### How to use

1. Create specialist agents in `Agents` (modal CRUD).
2. Assign function access from `Functions` (`Allowed AI agents`) for each `function_name`.
3. Add handoff rules in `Agents > Configured handoff rules` by intent/function/payload/roles/context.
4. Validate behavior in `Settings > Traces`.

#### WordPress use cases

- `support` agent for help pages and support tools
- `ecommerce` agent for cart/checkout/orders
- `content` agent for posts/pages/media
- Handoff rules that route order-related requests to ecommerce

### Phase 7: MCP + standard integrations

#### What it is for

- Connect NAVAI to standardized remote tools outside WordPress.
- Centralize ERP/CRM/BI/internal service integrations.
- Restrict remote tools by role and `agent_key`.

#### How to use

1. Open `MCP`.
2. Create an `MCP Server` with URL/auth/timeouts.
3. Run `Health` or `Sync tools`.
4. Review cached remote tools and their runtime function names.
5. Create `allow`/`deny` policies by `tool_name` (or `*`), role, and `agent_key`.
6. Test and inspect `Settings > Traces` if a call is blocked.

Current MCP compatibility implemented in the plugin:

- HTTP JSON-RPC transport
- `tools/list`
- `tools/call`

#### WordPress use cases

- WooCommerce + ERP stock/price queries
- Support knowledge-base search in an external system
- Backoffice reporting tools hosted outside WordPress
- Multi-agent setups where only `support` can use support MCP tools

## REST endpoints (current)

The plugin registers these REST routes:

- `POST /wp-json/navai/v1/realtime/client-secret`
- `POST /wp-json/navai/v1/speech/synthesize`
- `GET /wp-json/navai/v1/functions`
- `GET /wp-json/navai/v1/routes`
- `POST /wp-json/navai/v1/functions/execute`
- `POST /wp-json/navai/v1/functions/test` (admin)
- `GET /wp-json/navai/v1/guardrails` (admin)
- `POST /wp-json/navai/v1/guardrails` (admin)
- `PUT /wp-json/navai/v1/guardrails/{id}` (admin)
- `DELETE /wp-json/navai/v1/guardrails/{id}` (admin)
- `POST /wp-json/navai/v1/guardrails/test` (admin)
- `GET /wp-json/navai/v1/approvals` (admin)
- `POST /wp-json/navai/v1/approvals/{id}/approve` (admin)
- `POST /wp-json/navai/v1/approvals/{id}/reject` (admin)
- `GET /wp-json/navai/v1/traces` (admin)
- `GET /wp-json/navai/v1/traces/{trace_id}` (admin)
- `GET /wp-json/navai/v1/sessions` (admin)
- `POST /wp-json/navai/v1/sessions` (public/admin depending on config)
- `POST /wp-json/navai/v1/sessions/cleanup` (admin)
- `GET /wp-json/navai/v1/sessions/{id}` (admin)
- `GET /wp-json/navai/v1/sessions/{id}/messages` (admin)
- `POST /wp-json/navai/v1/sessions/{id}/clear` (admin)
- `GET /wp-json/navai/v1/agents` (admin)
- `POST /wp-json/navai/v1/agents` (admin)
- `PUT /wp-json/navai/v1/agents/{id}` (admin)
- `DELETE /wp-json/navai/v1/agents/{id}` (admin)
- `GET /wp-json/navai/v1/agents/handoffs` (admin)
- `POST /wp-json/navai/v1/agents/handoffs` (admin)
- `PUT /wp-json/navai/v1/agents/handoffs/{id}` (admin)
- `DELETE /wp-json/navai/v1/agents/handoffs/{id}` (admin)
- `GET /wp-json/navai/v1/mcp/servers` (admin)
- `POST /wp-json/navai/v1/mcp/servers` (admin)
- `PUT /wp-json/navai/v1/mcp/servers/{id}` (admin)
- `DELETE /wp-json/navai/v1/mcp/servers/{id}` (admin)
- `POST /wp-json/navai/v1/mcp/servers/{id}/health` (admin)
- `GET /wp-json/navai/v1/mcp/servers/{id}/tools` (admin)
- `GET /wp-json/navai/v1/mcp/policies` (admin)
- `POST /wp-json/navai/v1/mcp/policies` (admin)
- `PUT /wp-json/navai/v1/mcp/policies/{id}` (admin)
- `DELETE /wp-json/navai/v1/mcp/policies/{id}` (admin)

Notes:

- `client-secret` has a basic rate limit (per IP, short time window).
- Public access to `client-secret` and backend functions can be toggled in Settings.
- `guardrails` endpoints require administrator permissions (`manage_options`).
- `approvals`, `traces`, `sessions`, `agents`, and `mcp` endpoints require administrator permissions (`manage_options`).

## Backend extensibility (filters)

### Register backend functions directly in PHP

```php
add_filter('navai_voice_functions_registry', function (array $items): array {
    $items[] = [
        'name' => 'get_user_profile',
        'description' => 'Read current user profile.',
        'source' => 'my-plugin',
        'callback' => function (array $payload, array $context) {
            $user = wp_get_current_user();
            return [
                'id' => $user->ID,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
            ];
        },
    ];

    return $items;
});
```

### Register plugin action callbacks (legacy `@action:` integrations / backend compatibility)

```php
add_filter('navai_voice_plugin_actions', function (array $actions): array {
    $actions['woocommerce'] = [
        'list_recent_orders' => function (array $args, array $context) {
            return ['ok' => true, 'orders' => []];
        },
    ];
    return $actions;
});
```

### Extend allowed routes

```php
add_filter('navai_voice_routes', function (array $routes): array {
    $routes[] = [
        'name' => 'Contact',
        'path' => home_url('/contact/'),
        'description' => 'Contact page',
        'synonyms' => ['contact us'],
    ];
    return $routes;
});
```

### Override frontend config before it reaches the widget

```php
add_filter('navai_voice_frontend_config', function (array $config, array $settings): array {
    $config['messages']['idle'] = 'Ready';
    return $config;
}, 10, 2);
```

## Third-party services and privacy

- `OpenAI Realtime`: NAVAI Voice sends audio/text interaction data to OpenAI when a user starts a realtime session or an administrator runs related tests/configuration flows.
- `Optional ElevenLabs TTS`: when hybrid speech is enabled, NAVAI Voice sends final assistant text to ElevenLabs through the server-side synthesis proxy.
- `Optional MCP servers`: NAVAI Voice can send tool payloads to third-party MCP endpoints only after an administrator enables MCP and configures those servers.
- New installs now default to:
  - public backend functions disabled
  - session history disabled
  - runtime tracing disabled
  - MCP disabled until configured
- The plugin registers WordPress privacy policy guidance plus personal data exporter/eraser handlers for data linked to logged-in WordPress users.

## Security notes

- The OpenAI API key remains on the server.
- The ElevenLabs API key also remains on the server.
- If `Allow public client_secret` is disabled, only admins can request a client secret.
- If `Allow public backend functions` is disabled, only admins can list/execute backend functions.
- `Safety` (Phase 1) can block `functions/execute` calls by `input`, `tool`, and `output`.
- The dashboard `Functions` editor is JavaScript-only.
- PHP/backend callbacks can still be registered via filters (`navai_voice_functions_registry`, `navai_voice_plugin_actions`) and should be treated as trusted admin code.
- Restrict routes/functions to only what NAVAI should be allowed to use.

## Build installable ZIP (PowerShell)

From the repo root:

```powershell
& "apps/wordpress-plugin/release/build-zip.ps1"
```

Output:

- `apps/wordpress-plugin/release/navai-voice.zip`

The ZIP currently includes:

- `navai-voice.php`
- `README.md`
- `docs/README.es.md`
- `assets/`
- `includes/`

## Troubleshooting

### Admin UI looks broken or old after updating

- Clear browser cache
- Clear plugin/page cache
- Clear OPcache / restart PHP-FPM if your hosting caches PHP aggressively

### WordPress admin breaks after upload

If you are updating across versions that added/moved internal files, do a clean replace:

1. Deactivate the plugin
2. Delete `wp-content/plugins/navai-voice`
3. Upload/install the latest ZIP
4. Activate again

### Voice widget does not start

Check:

- OpenAI API key is configured
- Browser microphone permission is granted
- Realtime model/voice values are valid
- REST endpoints are reachable
- Security toggles are not blocking your current user/session
