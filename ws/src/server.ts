import { WebSocketServer, type WebSocket, type RawData } from "ws";
import * as http from "http";
import mysql from "mysql2/promise";
import Redis from "ioredis";
import jwt from "jsonwebtoken";
import dotenv from "dotenv";

const envPath = new URL("../.env", import.meta.url).pathname;
dotenv.config({ path: envPath });

type AuthPayload = {
  sub: string;
  sid: string;
};

type ClientContext = {
  userId: string | null;
  sessionId: string | null;
  authenticated: boolean;
  lastHeartbeat: number;
  presence: string | null;
  friends: string[];
};

type IncomingMessage = {
  op?: string;
  d?: Record<string, any>;
};

const config = {
  wsPort: Number(process.env.WS_PORT || 8787),
  dbHost: process.env.DB_HOST || "127.0.0.1",
  dbPort: Number(process.env.DB_PORT || 3306),
  dbName: process.env.DB_NAME || "sumee",
  dbUser: process.env.DB_USER || "sumee",
  dbPass: process.env.DB_PASS || "sumee",
  jwtSecret: process.env.JWT_SECRET || "dev_secret_change_me",
  epochMs: Number(process.env.SNOWFLAKE_EPOCH_MS || 1420070400000),
  workerId: Number(process.env.SNOWFLAKE_WORKER_ID || 2) & 0x1f,
  processId: Number(process.env.SNOWFLAKE_PROCESS_ID || 1) & 0x1f,
  redisUrl: process.env.REDIS_URL || "",
  presenceTtl: Number(process.env.PRESENCE_TTL || 60),
  heartbeatInterval: 30000,
};

class Snowflake {
  private epochMs: number;
  private workerId: number;
  private processId: number;
  private sequence = 0;
  private lastMs = 0;

  constructor(epochMs: number, workerId: number, processId: number) {
    this.epochMs = epochMs;
    this.workerId = workerId;
    this.processId = processId;
  }

  nextId(): string {
    let now = Date.now();
    if (now === this.lastMs) {
      this.sequence = (this.sequence + 1) & 0xfff;
      if (this.sequence === 0) {
        while (now <= this.lastMs) {
          now = Date.now();
        }
      }
    } else {
      this.sequence = 0;
    }
    this.lastMs = now;
    const ts = now - this.epochMs;
    const id = (BigInt(ts) << 22n) |
      (BigInt(this.workerId) << 17n) |
      (BigInt(this.processId) << 12n) |
      BigInt(this.sequence);
    return id.toString();
  }
}

const snowflake = new Snowflake(config.epochMs, config.workerId, config.processId);
const pool = mysql.createPool({
  host: config.dbHost,
  port: config.dbPort,
  user: config.dbUser,
  password: config.dbPass,
  database: config.dbName,
  waitForConnections: true,
  connectionLimit: 10,
});

const redis = config.redisUrl
  ? new Redis(config.redisUrl, { maxRetriesPerRequest: 1, enableReadyCheck: false })
  : null;

const redisSub = redis ? redis.duplicate() : null;
let redisErrorLogged = false;
let redisSubErrorLogged = false;

const subscribeToEvents = () => {
  if (!redisSub) return;
  redisSub.subscribe("sumee:events").catch((err) => {
    console.error("[redis-sub] subscribe failed", err?.message || err);
  });
};

const setupRedis = () => {
  if (!redis || !redisSub) return;
  redis.on("error", (err) => {
    if ((err as any)?.code === "ECONNREFUSED") {
      if (!redisErrorLogged) {
        console.error("[redis]", err?.message || err);
        redisErrorLogged = true;
      }
      return;
    }
    console.error("[redis]", err?.message || err);
  });
  redisSub.on("error", (err) => {
    if ((err as any)?.code === "ECONNREFUSED") {
      if (!redisSubErrorLogged) {
        console.error("[redis-sub]", err?.message || err);
        redisSubErrorLogged = true;
      }
      return;
    }
    console.error("[redis-sub]", err?.message || err);
  });
  redis.on("ready", () => {
    if (redisErrorLogged) {
      console.log("[redis] connected");
      redisErrorLogged = false;
    }
  });
  redisSub.on("ready", () => {
    if (redisSubErrorLogged) {
      console.log("[redis-sub] connected");
      redisSubErrorLogged = false;
    }
    subscribeToEvents();
  });
  subscribeToEvents();
  redisSub.on("message", (_channel, message) => {
    try {
      const evt = JSON.parse(message);
      if (!evt || !evt.op || !Array.isArray(evt.user_ids)) {
        return;
      }
      if (evt.op === "typing" || evt.op === "typing_stop") {
        const conversationId = String(evt.d?.conversation_id || "");
        if (evt.user_ids.length > 0) {
          broadcastToUsers(evt.user_ids, evt.op, evt.d || {});
          return;
        }
        if (conversationId) {
          fetchConversationMembers(conversationId).then((members) => {
            broadcastToUsers(members, evt.op, evt.d || {});
          });
        }
        return;
      }
      broadcastToUsers(evt.user_ids, evt.op, evt.d || {});
    } catch {
      // ignore
    }
  });
};

const server = http.createServer((req, res) => {
  if (req.url === "/health") {
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ status: "ok" }));
    return;
  }
  res.writeHead(404);
  res.end();
});

const wss = new WebSocketServer({ server });
const clients = new Map<WebSocket, ClientContext>();
const userSockets = new Map<string, Set<WebSocket>>();

function send(ws: WebSocket, op: string, d: any) {
  ws.send(JSON.stringify({ op, d }));
}

function addUserSocket(userId: string, ws: WebSocket) {
  if (!userSockets.has(userId)) {
    userSockets.set(userId, new Set());
  }
  userSockets.get(userId)!.add(ws);
}

function removeUserSocket(userId: string, ws: WebSocket) {
  const set = userSockets.get(userId);
  if (!set) return;
  set.delete(ws);
  if (set.size === 0) {
    userSockets.delete(userId);
  }
}

async function fetchFriends(userId: string): Promise<string[]> {
  const [rows] = await pool.query(
    "SELECT friend_user_id FROM friendships WHERE user_id = ?",
    [userId]
  );
  return (rows as any[]).map((row) => String(row.friend_user_id));
}

async function refreshFriends(ctx: ClientContext): Promise<void> {
  if (!ctx.userId) return;
  ctx.friends = await fetchFriends(ctx.userId);
}

async function broadcastToUsers(userIds: string[], op: string, payload: any) {
  for (const uid of userIds) {
    const sockets = userSockets.get(uid);
    if (!sockets) continue;
    for (const sock of sockets) {
      send(sock, op, payload);
    }
  }
}

async function fetchConversationMembers(conversationId: string): Promise<string[]> {
  const [rows] = await pool.query(
    "SELECT user_id FROM conversation_members WHERE conversation_id = ?",
    [conversationId]
  );
  return (rows as any[]).map((row) => String(row.user_id));
}

async function ensureMember(conversationId: string, userId: string): Promise<boolean> {
  const [rows] = await pool.query(
    "SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1",
    [conversationId, userId]
  );
  return (rows as any[]).length > 0;
}

async function isBlocked(a: string, b: string): Promise<boolean> {
  const [rows] = await pool.query(
    "SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1",
    [a, b, b, a]
  );
  return (rows as any[]).length > 0;
}

async function fetchUserProfile(userId: string) {
  const [rows] = await pool.query(
    "SELECT username, display_name, avatar_url FROM user_profiles WHERE user_id = ? LIMIT 1",
    [userId]
  );
  const row = (rows as any[])[0];
  if (!row) return null;
  return {
    id: userId,
    username: row.username,
    display_name: row.display_name || row.username,
    avatar: row.avatar_url,
  };
}

wss.on("connection", (ws: WebSocket) => {
  const ctx: ClientContext = {
    userId: null,
    sessionId: null,
    authenticated: false,
    lastHeartbeat: Date.now(),
    presence: null,
    friends: [],
  };
  clients.set(ws, ctx);
  send(ws, "hello", { heartbeat_interval_ms: config.heartbeatInterval });

  const heartbeatTimer = setInterval(() => {
    if (Date.now() - ctx.lastHeartbeat > config.heartbeatInterval * 2) {
      ws.close();
    }
  }, config.heartbeatInterval);

  ws.on("message", async (raw: RawData) => {
    let message: IncomingMessage;
    try {
      message = JSON.parse(raw.toString());
    } catch {
      return;
    }
    const op = message.op;
    const data = message.d || {};

    if (op === "heartbeat") {
      ctx.lastHeartbeat = Date.now();
      send(ws, "heartbeat_ack", { ts: Date.now() });
      return;
    }

    if (op === "auth") {
      const token = data.access_token;
      if (!token) {
        send(ws, "error", { message: "missing_token" });
        return;
      }
      try {
        const verified = jwt.verify(token, config.jwtSecret);
        if (typeof verified === "string") {
          throw new Error("invalid_token");
        }
        const payload = verified as AuthPayload;
        ctx.userId = String(payload.sub);
        ctx.sessionId = String(payload.sid);
        ctx.authenticated = true;
        addUserSocket(ctx.userId, ws);
        await refreshFriends(ctx);
        send(ws, "ready", { user_id: ctx.userId, friends: ctx.friends });

        if (redis) {
          await redis.setex(`presence:${ctx.userId}`, config.presenceTtl, "online");
        }
        await broadcastToUsers(ctx.friends, "friend_presence_update", {
          user_id: ctx.userId,
          status: "online",
        });
      } catch {
        send(ws, "error", { message: "invalid_token" });
      }
      return;
    }

    if (!ctx.authenticated || !ctx.userId) {
      send(ws, "error", { message: "not_authenticated" });
      return;
    }

    if (op === "friends_refresh") {
      await refreshFriends(ctx);
      send(ws, "friends_update", { friends: ctx.friends });
      return;
    }

    if (op === "presence_update") {
      await refreshFriends(ctx);
      const status = data.status || "online";
      ctx.presence = status;
      if (redis) {
        await redis.setex(`presence:${ctx.userId}`, config.presenceTtl, status);
      }
      await broadcastToUsers(ctx.friends, "friend_presence_update", {
        user_id: ctx.userId,
        status,
      });
      send(ws, "presence_ack", { status });
      return;
    }

    if (op === "rich_presence_update") {
      await refreshFriends(ctx);
      const activity = data.activity_name;
      const startedAt = data.started_at_unix;
      if (!activity || !startedAt) {
        send(ws, "error", { message: "invalid_rich_presence" });
        return;
      }
      const visibility = data.visibility || "friends";
      const metadataJson = data.metadata ? JSON.stringify(data.metadata) : null;

      await pool.query(
        "UPDATE rich_presence_sessions SET ended_at = NOW(3) WHERE user_id = ? AND ended_at IS NULL",
        [ctx.userId]
      );
      const rpId = snowflake.nextId();
      await pool.query(
        "INSERT INTO rich_presence_sessions (id, user_id, activity_name, started_at, ended_at, metadata_json, visibility) VALUES (?, ?, ?, FROM_UNIXTIME(?), NULL, ?, ?)",
        [rpId, ctx.userId, activity, startedAt, metadataJson, visibility]
      );

      await broadcastToUsers(ctx.friends, "friend_rich_presence_update", {
        user_id: ctx.userId,
        activity_name: activity,
        started_at_unix: startedAt,
        metadata: data.metadata || null,
      });
      send(ws, "rich_presence_ack", { activity_name: activity, started_at_unix: startedAt });
      return;
    }

    if (op === "typing_start") {
      const conversationId = String(data.conversation_id || "");
      if (!conversationId) {
        send(ws, "error", { message: "invalid_conversation" });
        return;
      }
      if (!(await ensureMember(conversationId, ctx.userId))) {
        send(ws, "error", { message: "not_member" });
        return;
      }
      if (redis) {
        await redis.setex(`typing:${conversationId}:${ctx.userId}`, 8, "1");
      }
      const members = await fetchConversationMembers(conversationId);
      await broadcastToUsers(members, "typing", {
        conversation_id: conversationId,
        user_id: ctx.userId,
        expires_in_ms: 8000,
        ts: Date.now(),
      });
      return;
    }

    if (op === "typing_stop") {
      const conversationId = String(data.conversation_id || "");
      if (!conversationId) {
        send(ws, "error", { message: "invalid_conversation" });
        return;
      }
      if (redis) {
        await redis.del(`typing:${conversationId}:${ctx.userId}`);
      }
      const members = await fetchConversationMembers(conversationId);
      await broadcastToUsers(members, "typing_stop", {
        conversation_id: conversationId,
        user_id: ctx.userId,
      });
      return;
    }

    if (op === "message_send") {
      const conversationId = String(data.conversation_id || "");
      const clientMsgId = data.client_msg_id;
      const content = data.content ?? null;
      const contentType = data.content_type || "text";
      const attachments = Array.isArray(data.attachments)
        ? data.attachments.map((att) => String(att)).filter((att) => att !== "")
        : [];

      if (!conversationId || !clientMsgId) {
        send(ws, "error", { message: "invalid_message" });
        return;
      }

      const [existingRows] = await pool.query(
        "SELECT id FROM messages WHERE sender_id = ? AND client_msg_id = ? LIMIT 1",
        [ctx.userId, clientMsgId]
      );
      if ((existingRows as any[]).length > 0) {
        send(ws, "message_ack", {
          client_msg_id: clientMsgId,
          message_id: String((existingRows as any[])[0].id),
        });
        return;
      }

      const members = await fetchConversationMembers(conversationId);
      if (!members.includes(ctx.userId)) {
        send(ws, "error", { message: "not_member" });
        return;
      }

      for (const memberId of members) {
        if (memberId === ctx.userId) continue;
        if (await isBlocked(ctx.userId, memberId)) {
          send(ws, "error", { message: "blocked" });
          return;
        }
      }

      const messageId = snowflake.nextId();
      const conn = await pool.getConnection();
      try {
        await conn.beginTransaction();
        await conn.query(
          "INSERT INTO messages (id, conversation_id, sender_id, content, content_type, created_at, client_msg_id) VALUES (?, ?, ?, ?, ?, NOW(3), ?)",
          [messageId, conversationId, ctx.userId, content, contentType, clientMsgId]
        );

        let attachmentRows: any[] = [];
        if (attachments.length > 0) {
          const placeholders = attachments.map(() => "?").join(",");
          const [rows] = await conn.query(
            `SELECT * FROM message_attachments WHERE id IN (${placeholders}) AND owner_user_id = ? AND status = 'active'`,
            [...attachments, ctx.userId]
          );
          attachmentRows = rows as any[];
          if (attachmentRows.length !== attachments.length) {
            await conn.rollback();
            send(ws, "error", { message: "invalid_attachments" });
            return;
          }
          await conn.query(
            `UPDATE message_attachments SET message_id = ? WHERE id IN (${placeholders})`,
            [messageId, ...attachments]
          );
        }

        await conn.commit();

        const author = await fetchUserProfile(ctx.userId);
        const payload = {
          id: String(messageId),
          type: 0,
          channel_id: conversationId,
          content: content ?? "",
          mentions: [],
          mention_everyone: false,
          attachments: attachmentRows.map((att) => ({
            id: String(att.id),
            cdn_url: att.cdn_url,
            mime: att.mime,
            size: att.size,
          })),
          pinned: false,
          timestamp: new Date().toISOString(),
          edited_timestamp: null,
          author,
        };

        await broadcastToUsers(members, "message_new", payload);
        send(ws, "message_ack", { client_msg_id: clientMsgId, message_id: messageId });
      } finally {
        conn.release();
      }
      return;
    }

    if (op === "message_edit") {
      const messageId = String(data.message_id || "");
      const content = data.content ?? null;
      const contentType = data.content_type || "text";
      if (!messageId) {
        send(ws, "error", { message: "invalid_message" });
        return;
      }
      const [rows] = await pool.query(
        "SELECT sender_id, conversation_id FROM messages WHERE id = ?",
        [messageId]
      );
      const row = (rows as any[])[0];
      if (!row || String(row.sender_id) !== ctx.userId) {
        send(ws, "error", { message: "not_allowed" });
        return;
      }
      await pool.query(
        "UPDATE messages SET content = ?, content_type = ?, edited_at = NOW(3) WHERE id = ?",
        [content, contentType, messageId]
      );
      const members = await fetchConversationMembers(String(row.conversation_id));
      await broadcastToUsers(members, "message_edited", {
        message_id: messageId,
        edited_at_unix: Math.floor(Date.now() / 1000),
        content,
      });
      return;
    }

    if (op === "message_delete") {
      const messageId = String(data.message_id || "");
      if (!messageId) {
        send(ws, "error", { message: "invalid_message" });
        return;
      }
      const [rows] = await pool.query(
        "SELECT sender_id, conversation_id FROM messages WHERE id = ?",
        [messageId]
      );
      const row = (rows as any[])[0];
      if (!row || String(row.sender_id) !== ctx.userId) {
        send(ws, "error", { message: "not_allowed" });
        return;
      }
      await pool.query(
        "UPDATE messages SET deleted_at = NOW(3) WHERE id = ?",
        [messageId]
      );
      await pool.query(
        "UPDATE message_attachments SET deleted_at = NOW(3), expires_at = DATE_ADD(NOW(3), INTERVAL 7 DAY) WHERE message_id = ?",
        [messageId]
      );
      const members = await fetchConversationMembers(String(row.conversation_id));
      await broadcastToUsers(members, "message_deleted", {
        message_id: messageId,
        deleted_at_unix: Math.floor(Date.now() / 1000),
      });
      return;
    }
  });

  ws.on("close", async () => {
    clearInterval(heartbeatTimer);
    clients.delete(ws);
    if (ctx.userId) {
      removeUserSocket(ctx.userId, ws);
      if (redis) {
        await redis.setex(`presence:${ctx.userId}`, config.presenceTtl, "offline");
      }
      await broadcastToUsers(ctx.friends, "friend_presence_update", {
        user_id: ctx.userId,
        status: "offline",
      });
    }
  });
});

setupRedis();

server.listen(config.wsPort, () => {
  console.log(`SUMEE WS listening on ${config.wsPort}`);
});
