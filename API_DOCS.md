# Documentación de API + WebSocket (Sumee)

## URLs base

- API (dev): `http://localhost:8080`
- Web: `http://localhost:5173`
- WS (dev): `ws://localhost:8787`
- Web (Codespace): `https://<codespace>-5173.app.github.dev`
- WS (Codespace): `wss://<codespace>-8787.app.github.dev`

Todas las APIs HTTP responden JSON. Errores en HTTP:

```json
{ "error": "codigo_error" }
```

Códigos HTTP comunes:

- 401 `missing_token` / `invalid_token`
- 403 `not_member` / `not_allowed`
- 404 `not_found`
- 409 `username_taken` / `email_taken`
- 422 `missing_fields` / `invalid_*`
- 429 `rate_limited`
- 500 `server_error`

---

## Autenticación

### POST `/auth/register`
Crea un usuario.

Body:

```json
{
  "email": "user@example.com",
  "password": "Secret123",
  "username": "mi.usuario",
  "display_name": "Opcional",
  "turnstile_token": "dev-pass"
}
```

Reglas:

- `email` válido
- `password` mínimo 8 caracteres
- `username` debe cumplir `^[a-z0-9._]{3,12}$`
- `display_name` opcional; si va vacío se usa `username`
- `turnstile_token` obligatorio (en dev puede ser `dev-pass`)

Success (201):

```json
{ "user_id": "...", "status": "active" }
```

Errores:

- 409 `email_taken`
- 409 `username_taken`
- 422 `invalid_email`
- 422 `invalid_username`
- 422 `password_too_short`
- 422 `missing_fields`
- 400 `turnstile_failed`

### GET `/usernames/available?username=<name>`
Verifica si un username está disponible.

Respuesta:

```json
{ "available": true, "reason": "available" }
```

Si es inválido o está tomado:

```json
{ "available": false, "reason": "invalid" }
```

```json
{ "available": false, "reason": "taken" }
```

Errores:

- 422 `missing_username`

### POST `/auth/login`
Login con email o username.

Body:

```json
{
  "email": "user@example.com",
  "password": "Secret123",
  "turnstile_token": "dev-pass"
}
```

Si `email` no es un email válido, se trata como username.

Success:

```json
{
  "access_token": "...",
  "refresh_token": "...",
  "session_id": "...",
  "user_id": "..."
}
```

Errores:

- 422 `missing_fields`
- 422 `missing_turnstile_token`
- 422 `invalid_username`
- 400 `turnstile_failed`
- 401 `invalid_credentials`
- 429 `rate_limited`

### POST `/auth/refresh`
Renueva tokens.

Body:

```json
{ "refresh_token": "..." }
```

Success: igual que login.

Errores: 401 `invalid_refresh`.

### POST `/auth/logout`
Cierra la sesión actual.

Auth: `Authorization: Bearer <access_token>`

Success:

```json
{ "ok": true }
```

---

## Usuario / Perfil

### GET `/me`
Perfil del usuario actual.

Auth requerido.

Respuesta:

```json
{
  "user": {
    "id": "...",
    "email": "...",
    "username": "...",
    "display_name": "...",
    "avatar_url": null,
    "banner_url": null,
    "bio": null
  }
}
```

### PATCH `/me/profile`
Actualiza campos del perfil.

Auth requerido.

Body:

```json
{
  "username": "nuevo.nombre",
  "display_name": "Nuevo Nombre",
  "bio": "...",
  "avatar_url": "https://...",
  "banner_url": "https://...",
  "privacy_json": { "show_activity": true }
}
```

Reglas:

- `username` validado y único
- `display_name` puede ser `""` para limpiar

Respuesta:

```json
{ "ok": true }
```

Errores:

- 409 `username_taken`
- 422 `invalid_username`
- 422 `no_fields`

### GET `/users/{user_id}`
Perfil público.

Respuesta:

```json
{ "user": { "id": "...", "username": "...", "display_name": "...", "avatar_url": null } }
```

---

## Amigos

### GET `/friends`
Lista amigos y solicitudes pendientes.

Auth requerido.

Respuesta:

```json
{
  "friends": [{ "user_id": "...", "created_at": "..." }],
  "incoming_requests": [{ "id": "...", "from_user_id": "..." }],
  "outgoing_requests": [{ "id": "...", "to_user_id": "..." }]
}
```

### GET `/friends/requests`
Solo pendientes.

Respuesta:

```json
{ "incoming": [...], "outgoing": [...] }
```

### POST `/friends/requests`
Enviar solicitud.

Body:

```json
{ "to_user_id": "..." }
```

Respuesta:

```json
{ "request_id": "..." }
```

Errores:

- 409 `already_friends`
- 409 `request_exists`
- 422 `missing_fields`

### POST `/friends/requests/{id}/accept`
Aceptar.

### POST `/friends/requests/{id}/reject`
Rechazar.

### POST `/friends/{friend_user_id}/remove`
Eliminar amigo.

---

## Conversaciones

### GET `/conversations`
Lista conversaciones.

Respuesta:

```json
{
  "conversations": [
    {
      "id": "...",
      "type": "dm",
      "peer": {
        "id": "...",
        "username": "...",
        "display_name": "...",
        "avatar": null
      }
    }
  ]
}
```

### POST `/conversations`
Crear DM (o devolver existente).

Body:

```json
{ "type": "dm", "user_id": "..." }
```

Respuesta:

```json
{ "conversation_id": "..." }
```

Errores:

- 422 `invalid_type`
- 404 `user_not_found`

---

## Mensajes

### GET `/conversations/{cid}/messages`
Mensajes (más nuevos primero).

Query:

- `limit` (1..100)
- `before` (message id)

Respuesta:

```json
{
  "messages": [
    {
      "id": "...",
      "type": 0,
      "channel_id": "...",
      "content": "...",
      "mentions": [],
      "mention_everyone": false,
      "attachments": [],
      "pinned": false,
      "timestamp": "2025-12-26T23:37:12.827000+00:00",
      "edited_timestamp": null,
      "author": {
        "id": "...",
        "username": "...",
        "display_name": "...",
        "avatar": null
      }
    }
  ]
}
```

### POST `/conversations/{cid}/messages`
Enviar mensaje (HTTP). Persiste antes de WS.

Body:

```json
{
  "content": "hola",
  "client_msg_id": "uuid-v4",
  "content_type": "text",
  "attachments": []
}
```

Respuesta (201): objeto mensaje (mismo shape de GET).

Errores:

- 403 `not_member`
- 409 `duplicate_client_msg_id`
- 422 `missing_client_msg_id`
- 422 `invalid_attachments`

### PATCH `/messages/{message_id}`
Editar mensaje.

Body:

```json
{ "content": "actualizado" }
```

### DELETE `/messages/{message_id}`
Borrado lógico.

---

## Mensajes fijados (Pins)

### POST `/messages/{message_id}/pin`
Fijar un mensaje.

Respuesta:

```json
{ "ok": true }
```

Errores:

- 404 `message_not_found`
- 403 `not_member`

### DELETE `/messages/{message_id}/pin`
Desfijar un mensaje.

Respuesta:

```json
{ "ok": true }
```

### GET `/conversations/{cid}/pins`
Lista mensajes fijados (ordenados por `pinned_at` desc).

Respuesta:

```json
{ "messages": [ { "id": "...", "pinned": true, "pinned_at": "..." } ] }
```

---

## Typing (HTTP)

El typing se envía por HTTP y se notifica por WS. No se persiste.

### POST `/conversations/{cid}/typing/start`
Body: `{}`

### POST `/conversations/{cid}/typing/stop`
Body: `{}`

### GET `/conversations/{cid}/typing`
Retorna IDs escribiendo (best-effort):

```json
{ "typing": ["userId1", "userId2"] }
```

---

## Uploads

### POST `/uploads/images/init`
Init.

Body:

```json
{ "mime": "image/png", "size": 12345 }
```

Respuesta:

```json
{ "attachment_id": "...", "upload_url": "..." }
```

### POST `/uploads/images/complete`
Complete.

Body:

```json
{ "attachment_id": "..." }
```

---

## Reportes / Bloqueos

### POST `/blocklist/{user_id}`
Bloquear usuario.

### POST `/reports`
Enviar reporte.

Body:

```json
{
  "target_type": "user|message",
  "target_id": "...",
  "category": "...",
  "description": "..."
}
```

---

# WebSocket

WS URL: `ws://localhost:8787` (o `wss://sumee.discord-bot-network.com`)

## Handshake

Servidor envía:

```json
{ "op": "hello", "d": { "heartbeat_interval_ms": 30000 } }
```

Cliente autentica:

```json
{ "op": "auth", "d": { "access_token": "..." } }
```

Servidor responde:

```json
{ "op": "ready", "d": { "user_id": "...", "friends": ["..."] } }
```

## Suscripción a notificaciones

No existe un `SUBSCRIBE` explícito. El servidor decide qué enviar basándose en:

1) **Tu `user_id` autenticado**: se guarda la conexión en memoria.
2) **Eventos con `user_ids`**: el servidor reenvía el evento a esas conexiones.
3) **Eventos por conversación**: se calculan los miembros y se les notifica.

En resumen: **si eres miembro de la conversación, recibes typing/mensajes** sin “subscribe” manual.

Recomendación cliente:

- Conéctate y autentica.
- Verifica `ready`.
- Envía `heartbeat` cada 25–30s.
- Cuando cambies de conversación, carga mensajes por HTTP; WS es solo notificación.

## Client → Server

### `heartbeat`

```json
{ "op": "heartbeat", "d": {} }
```

### `friends_refresh`

```json
{ "op": "friends_refresh", "d": {} }
```

### `presence_update`

```json
{ "op": "presence_update", "d": { "status": "online|idle|dnd|invisible" } }
```

### `rich_presence_update`

```json
{ "op": "rich_presence_update", "d": { "activity_name": "Juego", "started_at_unix": 1766812820 } }
```

### `message_send` (legacy)
Mensajes vía WS (legacy). HTTP es recomendado.

```json
{ "op": "message_send", "d": { "conversation_id": "...", "client_msg_id": "uuid", "content": "hi" } }
```

---

## Server → Client

### `ready`
Después de auth.

### `friends_update`
Lista de amigos actualizada.

### `friend_presence_update`

```json
{ "op": "friend_presence_update", "d": { "user_id": "...", "status": "online" } }
```

### `friend_rich_presence_update`
Actualización de actividad.

### `typing`

```json
{ "op": "typing", "d": { "conversation_id": "...", "user_id": "...", "expires_in_ms": 8000 } }
```

### `typing_stop`

### `message_new`

```json
{ "op": "message_new", "d": { "id": "...", "channel_id": "...", "content": "...", "author": { "id": "..." } } }
```

### `message_ack`
Ack para `message_send` legacy.

### `presence_ack`, `rich_presence_ack`
Ack de presence.

### `error`

```json
{ "op": "error", "d": { "message": "not_member" } }
```

---

## Garantías y sincronización

- Mensajes: se persisten por HTTP y luego se notifican por WS.
- Typing: solo hint visual; no se persiste ni reintenta.
- Recomendación: al reconectar, re-cargar mensajes vía HTTP.

---

## Notas

- Usa `client_msg_id` para idempotencia.
- `display_name` vuelve a `username` si está vacío.
- `turnstile` puede usarse con `dev-pass` en dev.
