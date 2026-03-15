# NAVAI Voice para WordPress

<p align="center">
  <a href="./README.es.md"><img alt="Spanish" src="https://img.shields.io/badge/Idioma-ES-0A66C2?style=for-the-badge"></a>
  <a href="../README.md"><img alt="English" src="https://img.shields.io/badge/Language-EN-1D9A6C?style=for-the-badge"></a>
</p>

<p align="center">
  <a href="https://navai.luxisoft.com/documentation/installation-wordpress"><img alt="Documentacion" src="https://img.shields.io/badge/Documentacion%20WordPress-Abrir-146EF5?style=for-the-badge"></a>
  <a href="./release/build-zip.ps1"><img alt="Generar ZIP" src="https://img.shields.io/badge/Generar%20ZIP-PowerShell-5C2D91?style=for-the-badge"></a>
  <a href="./navai-voice.php"><img alt="Plugin Bootstrap" src="https://img.shields.io/badge/Plugin-Bootstrap-2F6FEB?style=for-the-badge"></a>
</p>

<p align="center">
  <img alt="WordPress 6.2+" src="https://img.shields.io/badge/WordPress-6.2%2B-21759B?style=for-the-badge">
  <img alt="PHP 8.0+" src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge">
  <img alt="OpenAI Realtime" src="https://img.shields.io/badge/OpenAI-Realtime-0B8F6A?style=for-the-badge">
</p>

NAVAI Voice es un plugin para WordPress que agrega un widget de voz usando OpenAI Realtime, con TTS hibrido opcional mediante ElevenLabs, y un panel de administracion para controlar rutas de navegacion, funciones personalizadas y configuracion del runtime sin usar Node.js.

El plugin esta implementado en PHP (servidor) y JavaScript vanilla (navegador) para facilitar el despliegue en WordPress.

## Accesos rapidos

- `Instalar`: [WordPress admin](#instalacion-desde-wordpress-admin) | [Manual](#instalacion-manual-filesystem)
- `Configurar`: [Configuracion rapida](#configuracion-rapida-recomendada)
- `Usar`: [Boton global flotante](#opcion-a-boton-global-flotante) | [Shortcode](#opcion-b-shortcode)
- `Secciones admin`: [Navegacion](#tab-navegacion-rutas-permitidas-para-la-ia) | [Funciones](#tab-funciones-funciones-personalizadas) | [Ajustes > Seguridad](#ajustes--tab-seguridad-guardrails-fase-1) | [Ajustes > Aprobaciones/Trazas/Historial](#fases-integradas-fase-2-a-fase-7) | [Agentes](#fases-integradas-fase-2-a-fase-7) | [MCP](#fases-integradas-fase-2-a-fase-7)
- `Desarrollo`: [Endpoints REST](#endpoints-rest-actuales) | [Extensibilidad backend](#extensibilidad-backend-filters)
- `Operaciones`: [Generar ZIP](#generar-zip-instalable-powershell) | [Problemas comunes](#troubleshooting--problemas-comunes)

## Ejemplos de uso (inicio rapido)

### Ejemplos de navegacion

Estos ejemplos funcionan solo si la ruta objetivo esta habilitada en la tab `Navegacion` y el usuario actual tiene acceso.

- "Ve a la pagina de contacto"
- "Abre checkout"
- "Llevame a mi cuenta"
- "Abre pedidos"
- "Abre ajustes de WooCommerce"
- "Ve a Cupones en WooCommerce" (si fue configurada como ruta privada/admin)
- "Abre entradas de WPForms" (si fue agregada como ruta privada)

Tip: agrega descripciones de ruta como "Usar cuando el usuario pida gestionar cupones" para mejorar la decision de navegacion.

### Ejemplos de funciones personalizadas

Estos ejemplos dependen de las funciones que crees y dejes activas en la tab `Funciones`.

Casos de uso posibles en WordPress:

- Leer pedidos recientes de WooCommerce
- Revisar productos con bajo stock
- Crear una nota de soporte en un plugin/sistema
- Obtener resumen de envios de formularios (WPForms / formularios custom)
- Consultar perfil de usuario o estado de membresia
- Disparar una accion de sincronizacion con CRM
- Ejecutar una tarea interna de administracion (solo en entornos confiables)

Ejemplos de prompts que puede decir el usuario:

- "Muestrame los ultimos 5 pedidos"
- "Revisa si hay productos con poco stock"
- "Trae los ultimos envios del formulario de contacto"
- "Ejecuta la funcion de sincronizar pedidos"
- "Abre la pagina de pedidos y luego consulta los pendientes"

Patron recomendado:

- Usa `Navegacion` para mover al usuario a la pagina correcta
- Usa funciones personalizadas en `Funciones` para leer datos o ejecutar acciones
- Agrega descripciones claras para que NAVAI sepa cuando llamar cada funcion

### Ejemplos de guardrails (Fase 1)

Casos de uso para la tab `Seguridad` (guardrails):

- Bloquear acciones peligrosas en tienda (ej. `delete_order`, `delete_product`)
- Bloquear payloads con datos sensibles (tarjeta, documento, claves)
- Marcar como `warn` acciones de alto riesgo para calibrar reglas antes de bloquear
- Bloquear salida de datos sensibles (ej. emails) usando regex en `output`
- Limitar reglas por rol (`guest`, `subscriber`, `administrator`) y por funcion/plugin

Ejemplo rapido (WooCommerce):

- `Scope`: `tool`
- `Tipo`: `keyword`
- `Accion`: `block`
- `Pattern`: `delete`
- `Plugin/Function scope`: `run_plugin_action,order`

Esto sirve para evitar que NAVAI ejecute acciones destructivas en funciones backend.

## Que puede hacer actualmente el plugin

- Agregar un widget de voz a WordPress usando OpenAI Realtime (WebRTC).
- Mantener OpenAI Realtime para microfono/tools y sintetizar la voz final del asistente con ElevenLabs cuando el modo hibrido esta activo.
- Trabajar en dos modos de visualizacion:
  - Boton global flotante
  - Shortcode manual (`[navai_voice]`)
- Mostrar el widget global tambien en `wp-admin` (para administradores), forzado al lado derecho para no tapar el menu lateral de WordPress.
- Restringir visibilidad del widget por rol de WordPress (incluyendo invitados).
- Definir rutas de navegacion permitidas para la tool `navigate_to`.
- Crear rutas privadas personalizadas por plugin + rol + URL.
- Agregar descripciones de rutas (para guiar a la IA sobre cuando usar cada una).
- Crear funciones personalizadas por plugin y rol desde el dashboard con editor en modal responsive (solo JavaScript).
- Configurar metadata por funcion:
  - `function_name` (nombre de tool)
  - scope de ejecucion (`frontend`, `admin`, `both`)
  - `timeout`, `retries`
  - `requires approval`
  - `JSON Schema` opcional
- Probar funciones con payload JSON antes de guardarlas (`Test function`).
- Importar/exportar funciones como paquetes `.js` desde la tab `Funciones`.
- Asignar funciones a agentes IA directamente desde el modal de `Funciones` (`Agentes IA permitidos`), sincronizando `allowed_tools` por `function_name`.
- Activar/desactivar funciones personalizadas individualmente con checkboxes.
- Editar/eliminar funciones personalizadas directamente desde la lista.
- Filtrar rutas/funciones por texto, plugin y rol en el panel admin.
- Cambiar idioma del panel desde el dashboard de NAVAI (`English`, `Español`, `Português`, `Français`, `Русский`, `한국어`, `日本語`, `中文`, `हिंदी`).
- Aplicar traduccion completa del panel en `English`/`Spanish` y fallback a ingles para los idiomas adicionales del selector.
- Configurar guardrails (Fase 1) desde `Ajustes > Seguridad` para `input`, `tool` y `output` con reglas `keyword`/`regex`.
- Bloquear llamadas a funciones (`/functions/execute`) cuando una regla de guardrail coincide.
- Probar reglas de guardrails desde el panel admin (`Ajustes > Seguridad > Probar reglas`).
- Registrar eventos minimos de bloqueo (`guardrail_blocked`) en base de datos para trazabilidad basica.
- Gestionar aprobaciones humanas (HITL) para funciones sensibles (`pending`, `approved`, `rejected`) y ejecutar la accion pendiente desde `Ajustes > Aprobaciones`.
- Consultar trazas de runtime (tool_start, tool_success, tool_error, guardrails, approvals, handoffs, MCP bloqueado) desde `Ajustes > Trazas`.
- Persistir sesiones, transcriptos y tool calls con retencion/limpieza y vista de historial en `Ajustes > Historial`.
- Configurar agentes especialistas y reglas de handoff con UI en modales y tabs internas (`Agentes` / `Reglas de handoff configuradas`).
- Integrar servidores MCP (JSON-RPC HTTP), sincronizar tools remotas y restringir su uso con politicas por rol/agente (CRUD de servidores/politicas en modal).
- Usar endpoints REST integrados para client secret, rutas, listado de funciones y ejecucion.

## Requisitos

- WordPress `6.2+`
- PHP `8.0+`
- API key de OpenAI con acceso a Realtime

## Resumen del panel de administracion

El plugin agrega un item en el menu lateral:

- `NAVAI Voice`

El dashboard usa tabs principales y sub-tabs internas dentro de `Ajustes`:

- `Navegacion`
  - Rutas publicas desde menus de WordPress
  - Rutas privadas personalizadas por rol
  - Descripciones de rutas
  - Filtros y acciones de seleccion masiva
- `Funciones`
  - Editor de funciones personalizadas en modal responsive (crear/editar)
  - Editor de codigo solo JavaScript
  - Metadata de funcion (`function_name`, scope, timeout, retries, aprobacion, JSON Schema)
  - Payload de prueba + `Test function`
  - Asignacion de agentes (`Agentes IA permitidos`) sincronizada con `allowed_tools`
  - Lista de funciones con checkbox de activacion
  - Acciones Editar/Eliminar
  - Filtros por texto/plugin/rol
  - Importar/Exportar funciones (`.js`) con filtros/seleccion
- `Agentes` (Fase 6)
  - Toggle de multiagente + handoffs
  - Dos tabs internas: `Agentes` y `Reglas de handoff configuradas`
  - CRUD de agentes en modal (perfil + instrucciones)
  - CRUD de handoffs en modal (condiciones por intencion/funcion/payload/roles/contexto)
  - La asignacion de funciones a agentes se gestiona desde `Funciones`
- `MCP` (Fase 7)
  - Toggle de integraciones MCP
  - CRUD de servidores MCP en modal (URL, auth, timeouts, SSL, headers extra)
  - Health check + sync/listado de tools remotas
  - Vista de tools remotas cacheadas (`tool` -> `function_name` runtime)
  - CRUD de politicas allow/deny en modal por `tool`, rol y `agent_key`
- `Ajustes`
  - Sub-tabs internas:
    - `General`: conexion/runtime, widget, visibilidad, shortcode, voz/texto/VAD
    - `Seguridad` (Fase 1): guardrails + probador
    - `Aprobaciones` (Fase 2): cola HITL + decisiones
    - `Trazas` (Fase 2): trazas runtime + timelines
    - `Historial` (Fase 3): sesiones, transcriptos, retencion/compactacion

Controles extra en la cabecera:

- Boton `Documentation` (abre documentacion de NAVAI para WordPress)
- Selector de idioma del panel (`English`, `Español`, `Português`, `Français`, `Русский`, `한국어`, `日本語`, `中文`, `हिंदी`) sin prefijos (`en`, `es`, etc.)
- Cabecera sticky (logo + menu) al hacer scroll dentro de la pagina de ajustes NAVAI
- El dashboard abre por defecto en `Navegacion` al entrar a NAVAI

## Instalacion (desde WordPress admin)

1. Genera u obten el ZIP del plugin (`navai-voice.zip`).
2. En WordPress ve a `Plugins > Add New > Upload Plugin`.
3. Sube el ZIP y activa el plugin.
4. Abre `NAVAI Voice` desde el menu lateral.

## Instalacion manual (filesystem)

1. Copia `apps/wordpress-plugin` en:
   - `wp-content/plugins/navai-voice`
2. Activa `NAVAI Voice` en WordPress.

## Configuracion rapida (recomendada)

1. Abre `NAVAI Voice` (el dashboard abre primero en `Navegacion`) y luego entra a `Ajustes`.
2. Configura como minimo:
   - `OpenAI API Key`
   - `Modelo Realtime` (default: `gpt-realtime`)
   - `Voz` (default: `marin`)
3. Opcional: TTS hibrido con ElevenLabs:
   - define `NAVAI_TTS_PROVIDER=elevenlabs` como variable de entorno o constante PHP
   - configura `ELEVENLABS_API_KEY` y `ELEVENLABS_VOICE_ID`
   - overrides opcionales: `ELEVENLABS_BASE_URL`, `ELEVENLABS_MODEL_ID`, `ELEVENLABS_OUTPUT_FORMAT`, `ELEVENLABS_OPTIMIZE_STREAMING_LATENCY`, `ELEVENLABS_STABILITY`, `ELEVENLABS_SIMILARITY_BOOST`, `ELEVENLABS_STYLE`, `ELEVENLABS_USE_SPEAKER_BOOST`
4. Elige modo de widget:
   - `Boton global flotante` (recomendado para iniciar rapido)
   - `Solo shortcode manual`
5. Configura visibilidad:
   - Selecciona que roles pueden ver el widget (y si invitados estan permitidos)
6. Si necesitas tools backend para visitantes, activa manualmente `Permitir funciones backend publicas` en `Ajustes`.
7. Click en `Guardar cambios`.

## Como usar el plugin

## Opcion A: Boton global flotante

Configura `Render del componente` en `Boton global flotante`.

El widget se renderiza automaticamente:

- En el sitio publico (si el rol del usuario actual esta permitido)
- En `wp-admin` para administradores (forzado al lado derecho)

## Opcion B: Shortcode

Configura `Render del componente` en `Solo shortcode manual`, luego inserta:

```txt
[navai_voice]
```

Ejemplo con overrides:

```txt
[navai_voice label="Hablar con NAVAI" stop_label="Detener NAVAI" model="gpt-realtime" voice="marin" debug="1"]
```

Atributos soportados por el shortcode:

- `label`
- `stop_label`
- `model`
- `voice`
- `instructions`
- `language`
- `voice_accent`
- `voice_tone`
- `debug` (`0` o `1`)
- `class`

## Eventos de estado del agente (frontend)

El widget publica eventos en el `elemento .navai-voice-widget` y tambien en `window` para que puedas reaccionar desde otro proyecto.

Eventos:

- `navai:agent-state` (cada cambio de estado)
- `navai:agent-speaking-start` (cuando el agente inicia respuesta por voz)
- `navai:agent-speaking-stop` (cuando el agente termina/interrumpe respuesta por voz)

`event.detail` incluye:

- `connection_state`: `idle | connecting | connected | error`
- `activity_state`: `idle | listening | speaking | interrupted`
- `assistant_voice_state`: `idle | speaking`
- `is_assistant_speaking`: `boolean`
- `connection_mode`: `voice | text`
- `widget_mode`

Tambien se actualizan atributos `data-*` en el contenedor:

- `data-navai-connection-state`
- `data-navai-activity-state`
- `data-navai-assistant-voice-state`
- `data-navai-assistant-speaking` (`1`/`0`)

Ejemplo rapido:

```js
const widget = document.querySelector(".navai-voice-widget");
if (widget) {
  widget.addEventListener("navai:agent-state", (event) => {
    const state = event.detail || {};
    console.log("NAVAI state:", state.assistant_voice_state, state.connection_state);
  });
}
```

## Tab Navegacion (rutas permitidas para la IA)

Usa esta seccion para controlar a donde puede navegar la IA cuando llama `navigate_to`.

### Rutas publicas

- Detecta rutas de menus publicos de WordPress
- Permite seleccionar que rutas puede usar NAVAI
- Permite agregar descripcion por ruta (recomendado)

### Rutas privadas

- Permite agregar URLs privadas manualmente
- Asignando a cada ruta:
  - Grupo de plugin
  - Rol
  - URL
  - Descripcion

Esto sirve para paginas protegidas o pantallas admin por rol.

## Tab Funciones (funciones personalizadas)

Usa esta seccion para definir funciones personalizadas por plugin y por rol usando el editor en modal.

Flujo:

1. Click en `Crear funcion` (abre el modal responsive).
2. Selecciona `Plugin` y `Rol`.
3. Define `Nombre de funcion (tool)` (se normaliza a `snake_case` al guardar).
4. Pega codigo en `Funcion NAVAI (JavaScript)`.
5. Agrega una `Descripcion` clara (recomendado para que NAVAI elija mejor la tool).
6. Configura opcionalmente:
   - `Scope de ejecucion` (`Frontend y admin`, `Solo frontend`, `Solo admin`)
   - `Timeout (segundos)`
   - `Retries`
   - `Requiere aprobacion`
   - `JSON Schema de argumentos`
   - `Agentes IA permitidos` (sincroniza `allowed_tools` por `function_name`)
7. (Opcional) usa `Test function` con payload JSON.
8. Guarda con `Anadir funcion` / `Guardar cambios`.

Despues de crear:

- La funcion se agrega a la lista.
- Queda activa por defecto.
- Luego puedes:
  - Editar (reabre el mismo modal con datos precargados)
  - Eliminar
  - Activar/desactivar con checkbox
- Puedes importar/exportar paquetes `.js` desde la misma tab.

### Formato de funciones personalizadas en dashboard

- El editor del dashboard acepta funciones personalizadas en JavaScript (sin modo PHP en el editor).
- La UI valida el codigo como JavaScript y bloquea snippets PHP.
- Usa la `Descripcion` de la funcion + descripciones de ruta para mejorar la seleccion de tools por parte de NAVAI.

### Recomendaciones de diseño de funciones

- Mantener una funcion por tarea (`buscar_productos`, `listar_pedidos`, `obtener_formularios`).
- Validar payloads con `JSON Schema`.
- Ajustar `Timeout` y `Retries` para llamadas externas.
- Marcar acciones sensibles/escritura con `Requiere aprobacion`.
- Asignar cada funcion a los agentes especialistas correctos desde el mismo modal.

## Ajustes > Tab Seguridad (Guardrails, Fase 1)

Usa esta seccion para crear reglas que bloquean o advierten cuando NAVAI intenta ejecutar funciones con entradas, payloads o resultados que no quieres permitir.

### Que evalua

- `input`: entrada/payload antes de ejecutar la funcion
- `tool`: llamada a la herramienta/funcion y su payload
- `output`: resultado devuelto por la funcion

### Tipos de regla

- `keyword`: coincidencia por texto (substring)
- `regex`: coincidencia por expresion regular

### Acciones de regla

- `block`: bloquea la ejecucion o salida
- `warn`: no bloquea, pero marca coincidencia (util para calibrar)
- `allow`: regla de referencia (sin bloqueo)

### Campos utiles

- `Roles (csv)`: limita por roles (`guest,subscriber,administrator`)
- `Plugin/Function scope (csv)`: limita por nombre de funcion o source (ej. `run_plugin_action,woocommerce`)
- `Prioridad`: menor numero se evalua primero

### Ejemplo 1 (tienda WooCommerce): bloquear acciones destructivas

Regla recomendada:

- `Nombre`: `Bloquear borrado de pedidos`
- `Scope`: `tool`
- `Tipo`: `keyword`
- `Accion`: `block`
- `Pattern`: `delete`
- `Plugin/Function scope`: `run_plugin_action,order`

Uso:

- Si NAVAI intenta ejecutar una accion backend con payload que incluya `delete`, la llamada se bloquea y devuelve error `403`.

### Ejemplo 2 (blog): bloquear fuga de emails en resultados

Regla recomendada:

- `Scope`: `output`
- `Tipo`: `regex`
- `Accion`: `block`
- `Pattern`: `/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i`

Uso:

- Si una funcion devuelve correos por error, NAVAI bloquea la salida.

### Probar reglas antes de activar bloqueo en flujos reales

En `Ajustes > Seguridad > Probar reglas` puedes enviar:

- `Scope`
- `Function name`
- `Function source`
- `Texto de prueba`
- `Payload JSON`

Esto llama al endpoint de prueba y devuelve si la regla haria match (`blocked`, `matched_count`, `matches`).

## Fases integradas (Fase 2 a Fase 7)

Esta version ya integra las fases avanzadas del roadmap. Abajo tienes para que sirve cada una, como usarla y ejemplos de uso en WordPress.

### Fase 2: Aprobaciones (HITL) + Trazas basicas

#### Aprobaciones: para que sirve

- Evita ejecutar automaticamente funciones sensibles.
- Permite que un admin revise el payload antes de aprobar.
- Guarda estado y resultado para auditoria.

#### Aprobaciones: como usarla

1. En `Funciones`, marca una funcion con `Requiere aprobacion`.
2. En `Ajustes > Aprobaciones`, activa `Activar aprobaciones para funciones sensibles`.
3. Cuando NAVAI intente ejecutar la funcion, se crea una solicitud `pending`.
4. En `Ajustes > Aprobaciones`, abre `Ver detalle`.
5. Revisa payload, trace y funcion solicitada.
6. Haz `Aprobar` o `Rechazar`.

Nota: al aprobar, el plugin puede ejecutar la funcion pendiente en ese momento (flujo por defecto).

#### Aprobaciones: ejemplos en WordPress

- WooCommerce: aprobar devoluciones, cancelaciones o cambios de pedido.
- Membership: aprobar cambios de plan o extension manual.
- CRM/ERP: aprobar sincronizaciones manuales o reenvio de datos.
- Soporte: aprobar una accion que cree/edite tickets en sistemas externos.

#### Trazas: para que sirve

- Ver que ocurrio en cada llamada (inicio, exito, error, bloqueos).
- Depurar por que una tool fue bloqueada por guardrail, agente o MCP.
- Seguir handoffs entre agentes.

#### Trazas: como usarla

1. Activa `Trazas` desde `Ajustes > Trazas`.
2. Ejecuta pruebas desde widget o panel.
3. Abre `Ajustes > Trazas`.
4. Filtra por evento/severidad.
5. Entra al `timeline` del `trace_id` para ver la secuencia completa.

#### Trazas: ejemplos en WordPress

- "La funcion de pedidos falla": ver `tool_error` y payload de entrada.
- "No navega a una pagina privada": validar guardrails/roles/ruta.
- "Se delego al agente incorrecto": revisar evento `agent_handoff`.

### Fase 3: Sesiones + memoria + transcript

#### Para que sirve

- Mantiene contexto entre interacciones del usuario.
- Guarda transcriptos y tool calls para soporte/analisis.
- Permite retencion y limpieza por cumplimiento u operacion.

#### Como usarla

1. Activa `Historial` / memoria de sesiones desde `Ajustes > Historial`.
2. Configura `TTL`, `Retencion` y compactacion.
3. Usa el widget (texto/voz) normalmente.
4. Abre `Ajustes > Historial` para revisar sesiones y mensajes.
5. Usa `Limpiar sesion` o `Aplicar retencion` cuando necesites.

#### Ejemplos en WordPress

- Soporte: revisar que dijo un usuario antes de una escalacion.
- Ecommerce: ver la secuencia de acciones antes de una compra fallida.
- Admin interno: auditar que tools uso NAVAI durante una tarea.

### Fase 4: UX de voz avanzada + texto/voz

#### Para que sirve

- Mejora la experiencia realtime (VAD, interrupciones, modo texto).
- Permite usar texto como fallback sin perder sesion.

#### Como usarla

1. En `Ajustes`, define modo de deteccion de turno y parametros VAD.
2. Activa/desactiva interrupciones segun tu caso.
3. Activa input de texto para fallback.
4. Prueba continuidad entre texto y voz en una misma sesion.

#### Ejemplos en WordPress

- Call center interno: voz con interrupciones habilitadas.
- Backoffice silencioso: usar solo texto manteniendo memoria.
- Sitio publico: voz + texto para accesibilidad.

### Fase 5: Funciones robustas (JavaScript, schema, timeout, retries, test)

#### Para que sirve

- Reduce errores de payload con validacion por schema.
- Controla tiempo de ejecucion y reintentos.
- Permite probar funciones antes de usarlas en produccion.
- Permite asignar funciones a agentes especialistas desde el modal de `Funciones`.

#### Como usarla

1. En `Funciones`, define `JSON Schema` (opcional pero recomendado).
2. Ajusta `Timeout`, `Retries`, `Scope` y `Requiere aprobacion`.
3. Usa `Test function` con un payload de prueba.
4. (Opcional) asigna agentes IA permitidos para esa funcion.
5. Guarda solo cuando la prueba sea correcta.

#### Ejemplos en WordPress

- WooCommerce: schema para exigir `order_id` entero.
- CRM: timeout corto y retries para endpoints inestables.
- Formularios: testear payloads antes de habilitar ejecucion publica.

### Fase 6: Multiagente + handoffs

#### Para que sirve

- Separa responsabilidades (soporte, ecommerce, contenido, backoffice).
- Separa la orquestacion (agentes + handoffs) de la autoria de funciones.
- Usa las funciones existentes de `Funciones` (`function_name`) como fuente de verdad para acceso a tools.
- Permite delegacion automatica por reglas (handoff).

#### Como usarla

1. En `Agentes`, crea agentes especialistas (CRUD en modal).
2. Asigna acceso a funciones desde `Funciones` (`Agentes IA permitidos`) por `function_name`.
3. Crea reglas de handoff en `Agentes > Reglas de handoff configuradas` por intencion, funcion, payload, roles o contexto.
4. Ejecuta pruebas y revisa `Ajustes > Trazas` para validar el handoff.

#### Ejemplos en WordPress

- Agente `support`: FAQ, tickets, paginas de ayuda.
- Agente `ecommerce`: carrito, checkout, pedidos, cupones.
- Agente `content`: entradas, paginas, SEO, media.
- Handoff: si detecta "pedido" o "checkout", delegar a `ecommerce`.

### Fase 7: MCP + integraciones estandar

#### Para que sirve

- Conectar NAVAI a tools remotas estandarizadas fuera de WordPress.
- Centralizar integraciones (ERP, CRM, inventario, BI) via un servidor MCP.
- Restringir tools remotas por rol y/o `agent_key`.

#### Como usarla

1. Ve a la tab `MCP`.
2. Crea un `Servidor MCP` con:
   - `URL base`
   - `Auth type` y credencial
   - timeouts
3. Ejecuta `Health` o `Sync tools`.
4. Revisa las tools remotas cacheadas y su `Function name (runtime)`.
5. Crea politicas `allow` / `deny` por:
   - `tool_name` (o `*`)
   - rol
   - `agent_key`
6. Prueba la ejecucion y revisa `Ajustes > Trazas` si algo se bloquea.

Compatibilidad implementada en el plugin:

- transporte HTTP JSON-RPC
- `tools/list`
- `tools/call`

#### Ejemplos en WordPress

- WooCommerce + ERP: consultar stock y precios desde sistema externo.
- Soporte: buscar articulos en una base de conocimiento externa.
- Backoffice: ejecutar consultas de reportes en un servicio interno.
- Multiagente: permitir tools MCP solo al agente `support` y bloquearlas para `guest`.

## Endpoints REST (actuales)

El plugin registra estas rutas REST:

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
- `POST /wp-json/navai/v1/sessions` (public/admin segun config)
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

Notas:

- `client-secret` tiene rate limit basico (por IP, ventana corta).
- El acceso publico a `client-secret` y funciones backend se puede activar/desactivar desde Ajustes.
- Los endpoints `guardrails` requieren permisos de administrador (`manage_options`).
- `approvals`, `traces`, `sessions`, `agents` y `mcp` requieren permisos de administrador (`manage_options`).

## Extensibilidad backend (filters)

### Registrar funciones backend directamente en PHP

```php
add_filter('navai_voice_functions_registry', function (array $items): array {
    $items[] = [
        'name' => 'get_user_profile',
        'description' => 'Lee el perfil del usuario actual.',
        'source' => 'mi-plugin',
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

### Registrar acciones de plugins (compatibilidad backend / integraciones legacy `@action:`)

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

### Extender rutas permitidas

```php
add_filter('navai_voice_routes', function (array $routes): array {
    $routes[] = [
        'name' => 'Contacto',
        'path' => home_url('/contacto/'),
        'description' => 'Pagina de contacto',
        'synonyms' => ['contact us'],
    ];
    return $routes;
});
```

### Ajustar config frontend antes de enviarla al widget

```php
add_filter('navai_voice_frontend_config', function (array $config, array $settings): array {
    $config['messages']['idle'] = 'Listo';
    return $config;
}, 10, 2);
```

## Servicios de terceros y privacidad

- `OpenAI Realtime`: NAVAI Voice envia audio/texto a OpenAI cuando un usuario inicia una sesion realtime o cuando un administrador ejecuta pruebas o configuraciones relacionadas.
- `ElevenLabs opcional`: cuando la voz hibrida esta activa, NAVAI Voice envia el texto final del asistente a ElevenLabs mediante el proxy server-side de sintesis.
- `Servidores MCP opcionales`: NAVAI Voice puede enviar payloads de tools a endpoints MCP de terceros solo cuando un administrador habilita MCP y configura esos servidores.
- En instalaciones nuevas, el plugin queda por defecto con:
  - funciones backend publicas desactivadas
  - historial de sesiones desactivado
  - trazas runtime desactivadas
  - MCP desactivado hasta ser configurado
- El plugin registra texto sugerido para la politica de privacidad de WordPress y handlers de exportacion/borrado de datos para informacion asociada a usuarios autenticados.

## Notas de seguridad

- La API key de OpenAI permanece en el servidor.
- La API key de ElevenLabs tambien permanece en el servidor.
- Si `Permitir client_secret publico` esta desactivado, solo admins pueden solicitar client secret.
- Si `Permitir funciones backend publicas` esta desactivado, solo admins pueden listar/ejecutar funciones backend.
- `Seguridad` (Fase 1) permite bloquear llamadas por `input`, `tool` y `output` antes/despues de `functions/execute`.
- El editor de `Funciones` del dashboard es solo JavaScript.
- Los callbacks PHP/backend se pueden registrar via filters (`navai_voice_functions_registry`, `navai_voice_plugin_actions`) y deben tratarse como codigo confiable de admin.
- Restringe rutas y funciones a lo estrictamente necesario.

## Generar ZIP instalable (PowerShell)

Desde la raiz del repo:

```powershell
& "apps/wordpress-plugin/release/build-zip.ps1"
```

Salida:

- `apps/wordpress-plugin/release/navai-voice.zip`

El ZIP actualmente incluye:

- `navai-voice.php`
- `README.md`
- `docs/README.es.md`
- `assets/`
- `includes/`

## Troubleshooting / Problemas comunes

### El panel admin se ve roto o viejo despues de actualizar

- Limpia cache del navegador
- Limpia cache de plugin/pagina
- Limpia OPcache / reinicia PHP-FPM si tu hosting cachea PHP agresivamente

### WordPress admin se rompe al subir una actualizacion

Si actualizas entre versiones que agregaron o movieron archivos internos, haz reemplazo limpio:

1. Desactiva el plugin
2. Borra `wp-content/plugins/navai-voice`
3. Sube/instala el ZIP mas reciente
4. Activa nuevamente

### El widget de voz no inicia

Revisa:

- API key de OpenAI configurada
- Permiso de microfono en el navegador
- Modelo/voz Realtime validos
- Endpoints REST accesibles
- Toggles de seguridad no bloqueando tu usuario/sesion
