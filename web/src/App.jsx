import { useEffect, useMemo, useRef, useState } from "react";

const host = typeof window !== "undefined" ? window.location.hostname : "";
const isCodespace = host.includes("app.github.dev");
const codespaceWs = isCodespace
  ? `wss://${host.replace(/-\\d+\\.app\\.github\\.dev$/, "-8787.app.github.dev")}`
  : "";

const envApi = import.meta.env.VITE_API_URL;
const envWs = import.meta.env.VITE_WS_URL;
const browserWsBase =
  typeof window !== "undefined"
    ? `${window.location.protocol === "https:" ? "wss" : "ws"}://${window.location.host}/ws`
    : "ws://localhost:8787";

const defaultApi =
  envApi && envApi !== "" ? envApi : isCodespace ? "" : "http://localhost:8080";
const defaultWs = envWs && envWs !== "" ? envWs : browserWsBase || codespaceWs || "ws://localhost:8787";

const emptyTokens = {
  access: "",
  refresh: "",
  sessionId: "",
  userId: "",
};

function App() {
  const [apiUrl, setApiUrl] = useState(defaultApi);
  const [wsUrl, setWsUrl] = useState(defaultWs);
  const [turnstileToken, setTurnstileToken] = useState("");
  const [loginTurnstileToken, setLoginTurnstileToken] = useState("");
  const [tokens, setTokens] = useState(emptyTokens);
  const [status, setStatus] = useState("ready");
  const [lastError, setLastError] = useState("");
  const [wsLog, setWsLog] = useState([]);
  const [messages, setMessages] = useState([]);
  const [conversationId, setConversationId] = useState("");
  const [conversations, setConversations] = useState([]);
  const [friends, setFriends] = useState([]);
  const [incomingRequests, setIncomingRequests] = useState([]);
  const [outgoingRequests, setOutgoingRequests] = useState([]);
  const [activeView, setActiveView] = useState("chat");
  const [friendsTab, setFriendsTab] = useState("online");
  const [presenceStatus, setPresenceStatus] = useState("online");
  const [presenceByUser, setPresenceByUser] = useState({});
  const [attachmentId, setAttachmentId] = useState("");
  const [typingByConversation, setTypingByConversation] = useState({});
  const [userIndex, setUserIndex] = useState({});
  const [regUsername, setRegUsername] = useState("");
  const [regUsernameStatus, setRegUsernameStatus] = useState({
    state: "idle",
    reason: "",
  });
  const [richPresence, setRichPresence] = useState({
    activity_name: "Sumee Lab",
    started_at_unix: Math.floor(Date.now() / 1000),
  });

  const wsRef = useRef(null);
  const heartbeatRef = useRef(null);
  const typingTimerRef = useRef(null);
  const lastTypingRef = useRef(0);
  const messageIdsRef = useRef(new Set());
  const conversationIdRef = useRef("");
  const registerWidgetRef = useRef(null);
  const loginWidgetRef = useRef(null);

  const headers = useMemo(() => {
    const base = { "Content-Type": "application/json" };
    if (tokens.access) {
      base.Authorization = `Bearer ${tokens.access}`;
    }
    return base;
  }, [tokens.access]);

  useEffect(() => {
    const saved = localStorage.getItem("sumee_tokens");
    if (saved) {
      setTokens(JSON.parse(saved));
    }
  }, []);

  useEffect(() => {
    localStorage.setItem("sumee_tokens", JSON.stringify(tokens));
  }, [tokens]);

  useEffect(() => {
    conversationIdRef.current = conversationId;
  }, [conversationId]);

  const logWs = (entry) => {
    setWsLog((prev) => {
      const next = [entry, ...prev];
      return next.slice(0, 60);
    });
  };

  const getMessageId = (msg) => msg?.id || msg?.message_id || "";
  const getConversationId = (msg) => msg?.channel_id || msg?.conversation_id || "";
  const resolveAuthorName = (msg) => {
    const author = msg?.author || msg?.d?.author || null;
    const authorId = author?.id || msg?.sender_id || msg?.author_id || "";
    const fromIndex = authorId ? userIndex[authorId] : "";
    const name = author?.display_name || author?.username || fromIndex || (authorId ? authorId.slice(-6) : "?");
    return { id: authorId, name, avatar: author?.avatar };
  };

  useEffect(() => {
    if (!isCodespace && !import.meta.env.VITE_TURNSTILE_SITE_KEY) {
      setTurnstileToken("dev-pass");
      setLoginTurnstileToken("dev-pass");
      return;
    }
    if (window.turnstile) {
      return;
    }
    if (document.querySelector("script[data-turnstile]")) {
      return;
    }
    const script = document.createElement("script");
    script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    script.async = true;
    script.defer = true;
    script.dataset.turnstile = "true";
    document.head.appendChild(script);
  }, []);

  useEffect(() => {
    let timer;
    const ensureWidgets = () => {
      if (!window.turnstile) {
        timer = setTimeout(ensureWidgets, 300);
        return;
      }
      const siteKey =
        import.meta.env.VITE_TURNSTILE_SITE_KEY ||
        "0x4AAAAAACJOoJ-eZ9VkWNeM";

      if (registerWidgetRef.current && !registerWidgetRef.current.dataset.ready) {
        window.turnstile.render(registerWidgetRef.current, {
          sitekey: siteKey,
          callback: (token) => setTurnstileToken(token),
        });
        registerWidgetRef.current.dataset.ready = "true";
      }

      if (loginWidgetRef.current && !loginWidgetRef.current.dataset.ready) {
        window.turnstile.render(loginWidgetRef.current, {
          sitekey: siteKey,
          callback: (token) => setLoginTurnstileToken(token),
        });
        loginWidgetRef.current.dataset.ready = "true";
      }
    };
    ensureWidgets();
    return () => {
      if (timer) clearTimeout(timer);
    };
  }, [registerWidgetRef, loginWidgetRef]);

  const apiFetch = async (path, options = {}) => {
    const res = await fetch(`${apiUrl}${path}`, {
      ...options,
      headers: { ...headers, ...(options.headers || {}) },
    });
    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      data = {};
    }
    if (!res.ok) {
      const message = data.error || text || res.statusText || "request_failed";
      throw new Error(`${res.status} ${message}`);
    }
    return data;
  };

  const loadUserProfiles = async (ids) => {
    const uniqueIds = Array.from(new Set(ids)).filter(Boolean);
    const missing = uniqueIds.filter((id) => !userIndex[id]);
    if (!missing.length) return;
    const results = await Promise.all(
      missing.map(async (id) => {
        try {
          const res = await apiFetch(`/users/${id}`);
          return res.user || null;
        } catch {
          return null;
        }
      })
    );
    const next = {};
    results.forEach((user) => {
      if (user?.id) {
        next[user.id] = user.display_name || user.username;
      }
    });
    if (Object.keys(next).length) {
      setUserIndex((prev) => ({ ...prev, ...next }));
    }
  };

  const handleRegister = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    try {
      setStatus("registering...");
      const usernameValue = String(form.get("reg_username") || "").trim().toLowerCase();
      if (usernameValue) {
        const check = await apiFetch(`/usernames/available?username=${encodeURIComponent(usernameValue)}`);
        if (!check.available) {
          setStatus(`register error: username_${check.reason || "unavailable"}`);
          setLastError(`username_${check.reason || "unavailable"}`);
          return;
        }
      }
      const payload = {
        email: form.get("reg_email"),
        password: form.get("reg_password"),
        username: form.get("reg_username"),
        display_name: form.get("reg_name"),
        turnstile_token: turnstileToken,
      };
      const res = await apiFetch("/auth/register", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      setStatus(`registered: ${res.user_id}`);
      setLastError("");
    } catch (err) {
      setStatus(`register error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleLogin = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    try {
      setStatus("logging in...");
      const payload = {
        email: form.get("login_email"),
        password: form.get("login_password"),
        turnstile_token: loginTurnstileToken,
      };
      const res = await apiFetch("/auth/login", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      setTokens({
        access: res.access_token,
        refresh: res.refresh_token,
        sessionId: res.session_id,
        userId: res.user_id,
      });
      setStatus("login ok");
      setLastError("");
    } catch (err) {
      setStatus(`login error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleRefresh = async () => {
    try {
      const res = await apiFetch("/auth/refresh", {
        method: "POST",
        body: JSON.stringify({ refresh_token: tokens.refresh }),
      });
      setTokens({
        access: res.access_token,
        refresh: res.refresh_token,
        sessionId: res.session_id,
        userId: res.user_id,
      });
      setStatus("token refreshed");
      setLastError("");
    } catch (err) {
      setStatus(`refresh error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleLogout = async () => {
    try {
      await apiFetch("/auth/logout", { method: "POST" });
    } catch {
      // ignore
    }
    setTokens(emptyTokens);
    setStatus("logged out");
    setLastError("");
  };

  const handleCreateDm = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    const userId = form.get("dm_user_id");
    try {
      const res = await apiFetch("/conversations", {
        method: "POST",
        body: JSON.stringify({ type: "dm", user_id: userId }),
      });
      setConversationId(res.conversation_id);
      setActiveView("chat");
      setStatus(`dm ready: ${res.conversation_id}`);
      setLastError("");
    } catch (err) {
      setStatus(`dm error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleLoadMessages = async () => {
    if (!conversationId) return;
    try {
      const res = await apiFetch(`/conversations/${conversationId}/messages`);
      const list = res.messages || [];
      messageIdsRef.current = new Set(list.map((msg) => msg.id || msg.message_id));
      setMessages(list);
      setStatus(`loaded ${res.messages?.length || 0} messages`);
      if (res.messages?.length) {
        setUserIndex((prev) => {
          const next = { ...prev };
          res.messages.forEach((msg) => {
            if (msg.author?.id) {
              next[msg.author.id] = msg.author.display_name || msg.author.username;
            }
          });
          return next;
        });
      }
      setLastError("");
    } catch (err) {
      setStatus(`messages error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleLoadConversations = async () => {
    try {
      const res = await apiFetch("/conversations");
      setConversations(res.conversations || []);
      if (!conversationId && res.conversations?.length) {
        setConversationId(res.conversations[0].id);
      }
      if (conversationId && res.conversations?.length) {
        const exists = res.conversations.some((conv) => conv.id === conversationId);
        if (!exists) {
          setConversationId(res.conversations[0].id);
        }
      }
      setStatus(`loaded ${res.conversations?.length || 0} conversations`);
      if (res.conversations?.length) {
        setUserIndex((prev) => {
          const next = { ...prev };
          res.conversations.forEach((conv) => {
            if (conv.peer?.id) {
              next[conv.peer.id] = conv.peer.display_name || conv.peer.username;
            }
          });
          return next;
        });
      }
      setLastError("");
    } catch (err) {
      setStatus(`conversations error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleSendMessage = async (event) => {
    event.preventDefault();
    if (!conversationId) {
      setStatus("select conversation");
      setLastError("no_conversation");
      return;
    }
    const form = new FormData(event.target);
    const content = form.get("msg_content");
    const clientMsgId = crypto.randomUUID();
    const payload = {
      conversation_id: conversationId,
      client_msg_id: clientMsgId,
      content,
      content_type: "text",
      attachments: attachmentId ? [attachmentId] : [],
    };

    try {
      const res = await apiFetch(`/conversations/${conversationId}/messages`, {
        method: "POST",
        body: JSON.stringify(payload),
      });
      const msgId = res.id || res.message_id;
      if (!messageIdsRef.current.has(msgId)) {
        messageIdsRef.current.add(msgId);
        setMessages((prev) => [res, ...prev]);
      }
      setStatus("message sent");
      setLastError("");
    } catch (err) {
      setStatus(`send error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleProfileUpdate = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    const payload = {
      username: form.get("profile_username") || undefined,
      display_name: form.get("profile_display_name") || undefined,
      avatar_url: form.get("profile_avatar_url") || undefined,
    };
    Object.keys(payload).forEach((key) => {
      if (payload[key] === "" || payload[key] === undefined) {
        delete payload[key];
      }
    });
    try {
      await apiFetch("/me/profile", {
        method: "PATCH",
        body: JSON.stringify(payload),
      });
      setStatus("profile updated");
      setLastError("");
    } catch (err) {
      setStatus(`profile error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleUpload = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    const file = form.get("upload_file");
    if (!file || !file.type) {
      setStatus("pick a file");
      return;
    }
    try {
      const init = await apiFetch("/uploads/images/init", {
        method: "POST",
        body: JSON.stringify({
          mime: file.type,
          size: file.size,
        }),
      });
      const fd = new FormData();
      fd.append("file", file);
      await fetch(init.upload_url, {
        method: "POST",
        headers: { Authorization: `Bearer ${tokens.access}` },
        body: fd,
      });
      await apiFetch("/uploads/images/complete", {
        method: "POST",
        body: JSON.stringify({ attachment_id: init.attachment_id }),
      });
      setAttachmentId(init.attachment_id);
      setStatus(`upload ok: ${init.attachment_id}`);
      setLastError("");
    } catch (err) {
      setStatus(`upload error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const connectWs = () => {
    if (!tokens.access) {
      return;
    }
    if (wsRef.current && wsRef.current.readyState === 1) {
      return;
    }
    const ws = new WebSocket(wsUrl);
    wsRef.current = ws;
    ws.onopen = () => {
      logWs("ws open");
      ws.send(JSON.stringify({ op: "auth", d: { access_token: tokens.access } }));
      heartbeatRef.current = setInterval(() => {
        if (ws.readyState === 1) {
          ws.send(JSON.stringify({ op: "heartbeat", d: {} }));
        }
      }, 25000);
    };
    ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data);
        logWs(`${msg.op}`);
        if (msg.op === "message_new") {
          const payload = msg.d || {};
          const msgId = getMessageId(payload);
          const msgConvId = getConversationId(payload);
          if (!msgId) {
            return;
          }
          if (messageIdsRef.current.has(msgId)) {
            return;
          }
          messageIdsRef.current.add(msgId);
          if (payload.author?.id) {
            setUserIndex((prev) => ({
              ...prev,
              [payload.author.id]: payload.author.display_name || payload.author.username,
            }));
          }
          if (!msgConvId || msgConvId === conversationIdRef.current) {
            setMessages((prev) => [payload, ...prev]);
          }
        }
        if (msg.op === "ready" && msg.d?.friends) {
          setFriends(msg.d.friends.map((id) => ({ user_id: id })));
          ws.send(JSON.stringify({ op: "friends_refresh", d: {} }));
        }
        if (msg.op === "friends_update" && msg.d?.friends) {
          setFriends(msg.d.friends.map((id) => ({ user_id: id })));
        }
        if (msg.op === "friend_presence_update") {
          const userId = msg.d?.user_id;
          const status = msg.d?.status;
          if (userId) {
            setPresenceByUser((prev) => ({ ...prev, [userId]: status }));
          }
          logWs(`presence ${userId} ${status}`);
        }
        if (msg.op === "friend_rich_presence_update") {
          logWs(`rich ${msg.d?.user_id}`);
        }
        if (msg.op === "typing") {
          const uid = msg.d?.user_id;
          const convId = msg.d?.conversation_id;
          if (uid && convId && uid !== tokens.userId) {
            setTypingByConversation((prev) => {
              const next = { ...prev };
              const convTyping = { ...(next[convId] || {}) };
              convTyping[uid] = Date.now() + (msg.d?.expires_in_ms || 8000);
              next[convId] = convTyping;
              return next;
            });
          }
        }
        if (msg.op === "typing_stop") {
          const uid = msg.d?.user_id;
          const convId = msg.d?.conversation_id;
          if (uid && convId) {
            setTypingByConversation((prev) => {
              const next = { ...prev };
              const convTyping = { ...(next[convId] || {}) };
              delete convTyping[uid];
              next[convId] = convTyping;
              return next;
            });
          }
        }
        if (msg.op === "message_ack") {
          setStatus(`message ack: ${msg.d?.message_id || ""}`.trim());
          setLastError("");
        }
        if (msg.op === "presence_ack") {
          setStatus(`presence: ${msg.d?.status}`);
        }
        if (msg.op === "rich_presence_ack") {
          setStatus(`rich: ${msg.d?.activity_name}`);
        }
        if (msg.op === "error") {
          const detail = msg.d?.message || "ws_error";
          setStatus(`ws error: ${detail}`);
          setLastError(detail);
          if (detail === "not_member") {
            handleLoadConversations();
          }
        }
      } catch {
        logWs("ws parse error");
      }
    };
    ws.onerror = () => {
      logWs("ws error");
      setStatus("ws error");
    };
    ws.onclose = () => {
      logWs("ws closed");
      wsRef.current = null;
      if (heartbeatRef.current) {
        clearInterval(heartbeatRef.current);
      }
    };
  };

  const disconnectWs = () => {
    if (wsRef.current) {
      wsRef.current.close();
    }
  };

  const sendPresence = () => {
    if (!wsRef.current || wsRef.current.readyState !== 1) {
      setStatus("ws not connected");
      return;
    }
    wsRef.current.send(
      JSON.stringify({ op: "presence_update", d: { status: presenceStatus } })
    );
  };

  const sendRichPresence = () => {
    if (!wsRef.current || wsRef.current.readyState !== 1) {
      setStatus("ws not connected");
      return;
    }
    wsRef.current.send(
      JSON.stringify({ op: "rich_presence_update", d: richPresence })
    );
  };

  const handleFriendRequest = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    const toUserId = form.get("friend_user_id");
    try {
      const res = await apiFetch("/friends/requests", {
        method: "POST",
        body: JSON.stringify({ to_user_id: toUserId }),
      });
      setStatus(`request id: ${res.request_id}`);
      setLastError("");
    } catch (err) {
      setStatus(`friend request error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleLoadFriends = async () => {
    try {
      const res = await apiFetch("/friends");
      setFriends(res.friends || []);
      setIncomingRequests(res.incoming_requests || []);
      setOutgoingRequests(res.outgoing_requests || []);
      setStatus("friends loaded");
      await loadUserProfiles((res.friends || []).map((f) => f.user_id));
      if (wsRef.current && wsRef.current.readyState === 1) {
        wsRef.current.send(JSON.stringify({ op: "friends_refresh", d: {} }));
      }
      setLastError("");
    } catch (err) {
      setStatus(`friends error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleFriendAccept = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    const reqId = form.get("friend_request_id");
    try {
      await apiFetch(`/friends/requests/${reqId}/accept`, { method: "POST" });
      setStatus("friend request accepted");
      setLastError("");
    } catch (err) {
      setStatus(`accept error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleFriendReject = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    const reqId = form.get("friend_request_id_reject");
    try {
      await apiFetch(`/friends/requests/${reqId}/reject`, { method: "POST" });
      setStatus("friend request rejected");
      setLastError("");
    } catch (err) {
      setStatus(`reject error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleFriendRemove = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    const friendId = form.get("friend_remove_id");
    try {
      await apiFetch(`/friends/${friendId}/remove`, { method: "POST" });
      setStatus("friend removed");
      setLastError("");
    } catch (err) {
      setStatus(`remove error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleBlock = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    const blockedId = form.get("block_user_id");
    try {
      await apiFetch(`/blocklist/${blockedId}`, { method: "POST" });
      setStatus("user blocked");
      setLastError("");
    } catch (err) {
      setStatus(`block error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleReport = async (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    try {
      const payload = {
        target_type: form.get("report_target_type"),
        target_id: form.get("report_target_id"),
        category: form.get("report_category"),
        description: form.get("report_description"),
      };
      const res = await apiFetch("/reports", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      setStatus(`report id: ${res.report_id}`);
      setLastError("");
    } catch (err) {
      setStatus(`report error: ${err.message}`);
      setLastError(err.message);
    }
  };

  const handleTyping = (value) => {
    if (!conversationId || !tokens.access) {
      return;
    }
    const now = Date.now();
    if (now - lastTypingRef.current > 2500) {
      apiFetch(`/conversations/${conversationId}/typing/start`, {
        method: "POST",
        body: JSON.stringify({}),
      }).catch(() => {});
      lastTypingRef.current = now;
    }
    if (typingTimerRef.current) {
      clearTimeout(typingTimerRef.current);
    }
    typingTimerRef.current = setTimeout(() => {
      apiFetch(`/conversations/${conversationId}/typing/stop`, {
        method: "POST",
        body: JSON.stringify({}),
      }).catch(() => {});
    }, 1800);
  };

  useEffect(() => {
    if (tokens.access) {
      connectWs();
      handleLoadConversations();
      handleLoadFriends();
    }
  }, [tokens.access]);

  useEffect(() => {
    if (!regUsername) {
      setRegUsernameStatus({ state: "idle", reason: "" });
      return;
    }
    let active = true;
    const timer = setTimeout(() => {
      apiFetch(`/usernames/available?username=${encodeURIComponent(regUsername)}`)
        .then((res) => {
          if (!active) return;
          setRegUsernameStatus({
            state: res.available ? "available" : "unavailable",
            reason: res.reason || "",
          });
        })
        .catch((err) => {
          if (!active) return;
          setRegUsernameStatus({ state: "error", reason: err.message });
        });
    }, 400);
    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [regUsername, apiUrl]);

  useEffect(() => {
    if (conversationId) {
      handleLoadMessages();
    }
  }, [conversationId]);

  useEffect(() => {
    const interval = setInterval(() => {
      setTypingByConversation((prev) => {
        const now = Date.now();
        const next = {};
        Object.keys(prev).forEach((convId) => {
          const convTyping = prev[convId] || {};
          const convNext = {};
          Object.keys(convTyping).forEach((uid) => {
            if (convTyping[uid] > now) {
              convNext[uid] = convTyping[uid];
            }
          });
          if (Object.keys(convNext).length) {
            next[convId] = convNext;
          }
        });
        return next;
      });
    }, 1000);
    return () => clearInterval(interval);
  }, []);

  const currentConversation = conversations.find((conv) => conv.id === conversationId);
  const typingList = conversationId
    ? Object.keys(typingByConversation[conversationId] || {}).map((uid) => userIndex[uid] || uid.slice(-6))
    : [];
  const friendItems = friends.map((friend) => {
    const name = userIndex[friend.user_id] || friend.user_id.slice(-6);
    const status = presenceByUser[friend.user_id] || "offline";
    return { ...friend, name, status };
  });
  const visibleFriends =
    friendsTab === "online"
      ? friendItems.filter((friend) => friend.status !== "offline" && friend.status !== "invisible")
      : friendItems;

  return (
    <div className="app">
      <aside className="sidebar">
        <div className="sidebar-header">
          <h2>Sumee</h2>
          <span className="badge">testing</span>
        </div>

        <div className="sidebar-nav">
          <button
            type="button"
            className={`nav-item ${activeView === "friends" ? "active" : ""}`}
            onClick={() => setActiveView("friends")}
          >
            Amigos
          </button>
        </div>

        <div className="sidebar-section">
          <div className="section-title">
            Mensajes directos
            <button type="button" className="icon-btn" onClick={handleLoadConversations}>↻</button>
          </div>
          <div className="dm-list">
            {conversations.map((conv) => {
              const peer = conv.peer || {};
              const title = peer.display_name || peer.username || `DM ${conv.id.slice(-6)}`;
              const peerStatus = peer.id ? presenceByUser[peer.id] : null;
              return (
                <button
                  key={conv.id}
                  type="button"
                  className={`dm-item ${conv.id === conversationId ? "active" : ""}`}
                  onClick={() => {
                    setConversationId(conv.id);
                    setActiveView("chat");
                  }}
                >
                  <span className="avatar">{title.slice(0, 2)}</span>
                  <span className="dm-name">
                    {title}
                    <span className="dm-status">{peerStatus || "offline"}</span>
                  </span>
                </button>
              );
            })}
            {conversations.length === 0 && (
              <div className="empty">Carga conversaciones</div>
            )}
          </div>
        </div>

        <div className="sidebar-footer">
          <div className="user-card">
            <div className="avatar">{tokens.userId ? tokens.userId.slice(-2) : "?"}</div>
            <div>
              <div className="user-name">{tokens.userId || "Guest"}</div>
              <div className="user-status">{presenceStatus}</div>
            </div>
          </div>
          <div className="footer-actions">
            <button type="button" className="icon-btn" onClick={handleRefresh}>⟳</button>
            <button type="button" className="icon-btn" onClick={handleLogout}>⎋</button>
          </div>
        </div>
      </aside>

      <main className="chat">
        {activeView === "friends" ? (
          <div className="friends-view">
            <header className="friends-header">
              <div className="friends-tabs">
                <button
                  type="button"
                  className={friendsTab === "online" ? "active" : ""}
                  onClick={() => setFriendsTab("online")}
                >
                  En linea
                </button>
                <button
                  type="button"
                  className={friendsTab === "all" ? "active" : ""}
                  onClick={() => setFriendsTab("all")}
                >
                  Todos
                </button>
                <button
                  type="button"
                  className={friendsTab === "add" ? "active" : ""}
                  onClick={() => setFriendsTab("add")}
                >
                  Añadir amigos
                </button>
              </div>
              <button type="button" className="refresh-btn" onClick={handleLoadFriends}>
                Actualizar
              </button>
            </header>

            {friendsTab === "add" ? (
              <div className="friends-add">
                <h3>Añadir amigo</h3>
                <p>Envía una solicitud usando el ID del usuario.</p>
                <form onSubmit={handleFriendRequest} className="stack">
                  <input name="friend_user_id" placeholder="User ID" />
                  <button type="submit">Enviar solicitud</button>
                </form>
              </div>
            ) : (
              <div className="friends-list">
                {visibleFriends.length === 0 ? (
                  <div className="empty">Sin amigos para mostrar</div>
                ) : (
                  visibleFriends.map((friend) => (
                    <div key={friend.user_id} className="friend-row">
                      <div className="avatar">{friend.name.slice(0, 2)}</div>
                      <div className="friend-meta">
                        <div className="friend-name">{friend.name}</div>
                        <div className="friend-status">{friend.status}</div>
                      </div>
                      <div className="friend-actions">
                        <button type="button" className="icon-btn">💬</button>
                        <button type="button" className="icon-btn">⋯</button>
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}
          </div>
        ) : (
          <>
            <header className="chat-header">
              <div>
                <h3>{currentConversation ? (currentConversation.peer?.display_name || currentConversation.peer?.username || "DM") : "Selecciona una conversación"}</h3>
                <p>{conversationId || ""}</p>
              </div>
              <div className="chat-actions">
                <button type="button" onClick={handleLoadMessages}>Cargar mensajes</button>
              </div>
            </header>

            <section className="chat-body">
              {messages.length === 0 ? (
                <div className="empty">Sin mensajes</div>
              ) : (
                messages.map((msg) => {
                  const content = msg.content || msg.content === "" ? msg.content : msg.d?.content;
                  const authorInfo = resolveAuthorName(msg);
                  const name = authorInfo.name;
                  return (
                    <article key={msg.id || msg.message_id} className="message">
                      <div className="avatar">{name.slice(0, 2)}</div>
                      <div>
                        <div className="message-meta">
                          <span className="message-author">{name}</span>
                          <span className="message-time">{msg.timestamp || msg.created_at}</span>
                        </div>
                        <div className="message-content">{content || "(deleted)"}</div>
                      </div>
                    </article>
                  );
                })
              )}
            </section>

            <div className="typing">
              {typingList.length > 0 ? `${typingList.join(", ")} esta escribiendo...` : ""}
            </div>

            <form className="composer" onSubmit={handleSendMessage}>
              <button type="button" className="composer-add">+</button>
              <input
                name="msg_content"
                placeholder={conversationId ? "Mensaje" : "Selecciona una conversacion"}
                onChange={(event) => handleTyping(event.target.value)}
              />
              <button type="submit" className="composer-send">Enviar</button>
            </form>

            <div className="debug-bar">
              <div className="chip">status: {status}</div>
              {lastError ? <div className="chip danger">error: {lastError}</div> : null}
            </div>
          </>
        )}
      </main>

      <aside className="panel">
        <div className="panel-card">
          <h4>Auth</h4>
          <form onSubmit={handleLogin} className="stack">
            <input name="login_email" placeholder="email o username" />
            <input name="login_password" type="password" placeholder="password" />
            <div ref={loginWidgetRef} className="turnstile"></div>
            <button type="submit">Login</button>
          </form>
          <form onSubmit={handleRegister} className="stack">
            <input name="reg_email" placeholder="email" />
            <input name="reg_password" type="password" placeholder="password" />
            <input
              name="reg_username"
              placeholder="username"
              value={regUsername}
              onChange={(event) => setRegUsername(event.target.value.toLowerCase())}
            />
            {regUsernameStatus.state !== "idle" && (
              <div className={`mini ${regUsernameStatus.state === "available" ? "ok" : "warn"}`}>
                {regUsernameStatus.state === "available"
                  ? "username disponible"
                  : `username ${regUsernameStatus.reason || "no disponible"}`}
              </div>
            )}
            <input name="reg_name" placeholder="display name (optional)" />
            <div ref={registerWidgetRef} className="turnstile"></div>
            <button type="submit">Register</button>
          </form>
        </div>

        <div className="panel-card">
          <h4>Perfil</h4>
          <form onSubmit={handleProfileUpdate} className="stack">
            <input name="profile_username" placeholder="nuevo username" />
            <input name="profile_display_name" placeholder="display name" />
            <input name="profile_avatar_url" placeholder="avatar url" />
            <button type="submit">Guardar cambios</button>
          </form>
        </div>

        <div className="panel-card">
          <h4>Presence</h4>
          <select
            value={presenceStatus}
            onChange={(e) => setPresenceStatus(e.target.value)}
          >
            <option value="online">online</option>
            <option value="idle">idle</option>
            <option value="dnd">dnd</option>
            <option value="invisible">invisible</option>
          </select>
          <button type="button" onClick={sendPresence}>Send presence</button>
          <input
            value={richPresence.activity_name}
            onChange={(e) =>
              setRichPresence((prev) => ({
                ...prev,
                activity_name: e.target.value,
              }))
            }
            placeholder="Activity"
          />
          <button type="button" onClick={sendRichPresence}>Send rich</button>
        </div>

        <div className="panel-card">
          <h4>Friends</h4>
          <button type="button" onClick={handleLoadFriends}>Load friends</button>
          <div className="mini">friends: {friends.map((f) => f.user_id).join(", ") || "-"}</div>
          <div className="mini">incoming: {incomingRequests.map((r) => r.from_user_id).join(", ") || "-"}</div>
          <div className="mini">outgoing: {outgoingRequests.map((r) => r.to_user_id).join(", ") || "-"}</div>
          <form onSubmit={handleFriendRequest} className="stack">
            <input name="friend_user_id" placeholder="to user id" />
            <button type="submit">Send request</button>
          </form>
          <form onSubmit={handleFriendAccept} className="stack">
            <input name="friend_request_id" placeholder="request id" />
            <button type="submit">Accept</button>
          </form>
          <form onSubmit={handleFriendReject} className="stack">
            <input name="friend_request_id_reject" placeholder="request id" />
            <button type="submit">Reject</button>
          </form>
        </div>

        <div className="panel-card">
          <h4>DM Tools</h4>
          <form onSubmit={handleCreateDm} className="stack">
            <input name="dm_user_id" placeholder="user id" />
            <button type="submit">Create DM</button>
          </form>
          <div className="stack">
            <input
              value={conversationId}
              onChange={(e) => setConversationId(e.target.value)}
              placeholder="conversation id"
            />
            <button type="button" onClick={handleLoadConversations}>Load conversations</button>
          </div>
        </div>

        <div className="panel-card">
          <h4>Uploads</h4>
          <form onSubmit={handleUpload} className="stack">
            <input name="upload_file" type="file" />
            <button type="submit">Upload image</button>
          </form>
          <div className="mini">attachment: {attachmentId || "-"}</div>
        </div>

        <div className="panel-card">
          <h4>Moderation</h4>
          <form onSubmit={handleBlock} className="stack">
            <input name="block_user_id" placeholder="block user id" />
            <button type="submit">Block</button>
          </form>
          <form onSubmit={handleReport} className="stack">
            <input name="report_target_type" placeholder="target type" />
            <input name="report_target_id" placeholder="target id" />
            <input name="report_category" placeholder="category" />
            <textarea name="report_description" placeholder="description" />
            <button type="submit">Report</button>
          </form>
        </div>

        <div className="panel-card">
          <h4>Debug</h4>
          <div className="stack">
            <label>
              API URL
              <input value={apiUrl} onChange={(e) => setApiUrl(e.target.value)} />
            </label>
            <label>
              WS URL
              <input value={wsUrl} onChange={(e) => setWsUrl(e.target.value)} />
            </label>
          </div>
          <div className="log">
            {wsLog.map((line, index) => (
              <div key={`${line}-${index}`}>{line}</div>
            ))}
          </div>
        </div>
      </aside>
    </div>
  );
}

export default App;
