<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Sumee\Config;
use Sumee\Db;
use Sumee\JwtTokens;
use Sumee\RateLimiter;
use Sumee\RedisClient;
use Sumee\Snowflake;
use Sumee\Storage;
use Sumee\Turnstile;
use Sumee\Utils;

require __DIR__ . '/../vendor/autoload.php';

Config::load();

$epoch = Config::getInt('SNOWFLAKE_EPOCH_MS', 1420070400000);
$workerId = Config::getInt('SNOWFLAKE_WORKER_ID', 1);
$processId = Config::getInt('SNOWFLAKE_PROCESS_ID', 1);
$snowflake = new Snowflake($epoch, $workerId, $processId);

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$app->add(function (Request $request, RequestHandler $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-SUMEE-Properties')
        ->withHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
});

$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

function jsonResponse(Response $response, int $status, array $data): Response
{
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
    $response->getBody()->write($payload ?: '{}');
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
}

function jsonError(Response $response, int $status, string $message, array $extra = []): Response
{
    return jsonResponse($response, $status, array_merge(['error' => $message], $extra));
}

function getJsonBody(Request $request): array
{
    $parsed = $request->getParsedBody();
    return is_array($parsed) ? $parsed : [];
}

function getClientFacts(Request $request): ?array
{
    $header = $request->getHeaderLine('X-SUMEE-Properties');
    if (!$header) {
        return null;
    }
    $json = Utils::base64UrlDecode($header);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

$authMiddleware = function (Request $request, RequestHandler $handler): Response {
    $authHeader = $request->getHeaderLine('Authorization');
    if (!str_starts_with($authHeader, 'Bearer ')) {
        return jsonError(new Slim\Psr7\Response(), 401, 'missing_token');
    }
    $token = substr($authHeader, 7);
    try {
        $payload = JwtTokens::decode($token);
    } catch (Throwable $e) {
        return jsonError(new Slim\Psr7\Response(), 401, 'invalid_token');
    }
    $request = $request->withAttribute('auth', $payload);
    return $handler->handle($request);
};

function authPayload(Request $request): array
{
    return (array) $request->getAttribute('auth', []);
}

$app->get('/health', function (Request $request, Response $response): Response {
    return jsonResponse($response, 200, ['status' => 'ok']);
});

$app->post('/auth/register', function (Request $request, Response $response) use ($snowflake): Response {
    $body = getJsonBody($request);
    $email = strtolower(trim($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $username = strtolower(trim((string) ($body['username'] ?? '')));
    $displayName = trim((string) ($body['display_name'] ?? ($body['name'] ?? '')));
    $token = (string) ($body['turnstile_token'] ?? '');

    if ($email === '' || $password === '' || $username === '' || $token === '') {
        return jsonError($response, 422, 'missing_fields');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return jsonError($response, 422, 'invalid_email');
    }

    if (strlen($password) < 8) {
        return jsonError($response, 422, 'password_too_short');
    }

    if (!preg_match('/^[a-z0-9._]{3,12}$/', $username)) {
        return jsonError($response, 422, 'invalid_username');
    }

    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
    if (!Turnstile::verify($token, $ip)) {
        return jsonError($response, 400, 'turnstile_failed');
    }

    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        return jsonError($response, 409, 'email_taken');
    }

    $stmt = $pdo->prepare('SELECT user_id FROM user_profiles WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    if ($stmt->fetch()) {
        return jsonError($response, 409, 'username_taken');
    }

    $userId = $snowflake->nextId();
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    $now = Utils::nowMs();
    $skipVerify = Config::getBool('SKIP_EMAIL_VERIFICATION', true);
    $verifiedAt = $skipVerify ? $now : null;
    $displayValue = $displayName !== '' ? $displayName : null;

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO users (id, email, password_hash, status, created_at, email_verified_at) VALUES (:id, :email, :password_hash, :status, :created_at, :email_verified_at)');
    $stmt->execute([
        'id' => $userId,
        'email' => $email,
        'password_hash' => $hash,
        'status' => 'active',
        'created_at' => $now,
        'email_verified_at' => $verifiedAt,
    ]);

    $stmt = $pdo->prepare('INSERT INTO user_profiles (user_id, username, display_name, bio, avatar_url, banner_url, privacy_json, updated_at) VALUES (:user_id, :username, :display_name, :bio, :avatar_url, :banner_url, :privacy_json, :updated_at)');
    $stmt->execute([
        'user_id' => $userId,
        'username' => $username,
        'display_name' => $displayValue,
        'bio' => null,
        'avatar_url' => null,
        'banner_url' => null,
        'privacy_json' => null,
        'updated_at' => $now,
    ]);

    $pdo->commit();

    return jsonResponse($response, 201, [
        'user_id' => (string) $userId,
        'status' => $skipVerify ? 'active' : 'pending_verification',
    ]);
});

$app->get('/usernames/available', function (Request $request, Response $response): Response {
    $params = $request->getQueryParams();
    $username = strtolower(trim((string) ($params['username'] ?? '')));
    if ($username === '') {
        return jsonError($response, 422, 'missing_username');
    }
    if (!preg_match('/^[a-z0-9._]{3,12}$/', $username)) {
        return jsonResponse($response, 200, ['available' => false, 'reason' => 'invalid']);
    }
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT user_id FROM user_profiles WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    if ($stmt->fetch()) {
        return jsonResponse($response, 200, ['available' => false, 'reason' => 'taken']);
    }
    return jsonResponse($response, 200, ['available' => true, 'reason' => 'available']);
});

$app->post('/auth/verify-email', function (Request $request, Response $response): Response {
    $skipVerify = Config::getBool('SKIP_EMAIL_VERIFICATION', true);
    if ($skipVerify) {
        return jsonResponse($response, 200, ['status' => 'skipped']);
    }
    $body = getJsonBody($request);
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $token = (string) ($body['code_or_token'] ?? '');
    $turnstile = (string) ($body['turnstile_token'] ?? '');
    if ($email === '' || $token === '' || $turnstile === '') {
        return jsonError($response, 422, 'missing_fields');
    }
    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
    if (!Turnstile::verify($turnstile, $ip)) {
        return jsonError($response, 400, 'turnstile_failed');
    }
    return jsonError($response, 501, 'verification_not_implemented');
});

$app->post('/auth/login', function (Request $request, Response $response) use ($snowflake): Response {
    $body = getJsonBody($request);
    $identifier = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $token = (string) ($body['turnstile_token'] ?? '');

    if ($identifier === '' || $password === '') {
        return jsonError($response, 422, 'missing_fields');
    }

    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    if (!RateLimiter::allow('rl:login:' . $ip, 20, 300)) {
        return jsonError($response, 429, 'rate_limited');
    }

    if ($token === '') {
        return jsonError($response, 422, 'missing_turnstile_token');
    }
    if (!Turnstile::verify($token, $ip)) {
        return jsonError($response, 400, 'turnstile_failed');
    }

    $pdo = Db::conn();
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id, password_hash, email_verified_at, status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $identifier]);
    } else {
        if (!preg_match('/^[a-z0-9._]{3,12}$/', $identifier)) {
            return jsonError($response, 422, 'invalid_username');
        }
        $stmt = $pdo->prepare('SELECT u.id, u.password_hash, u.email_verified_at, u.status FROM users u JOIN user_profiles p ON u.id = p.user_id WHERE p.username = :username LIMIT 1');
        $stmt->execute(['username' => $identifier]);
    }
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return jsonError($response, 401, 'invalid_credentials');
    }

    $skipVerify = Config::getBool('SKIP_EMAIL_VERIFICATION', true);
    if (!$skipVerify && !$user['email_verified_at']) {
        return jsonError($response, 403, 'email_not_verified');
    }

    if ($user['status'] !== 'active') {
        return jsonError($response, 403, 'user_suspended');
    }

    $sessionId = $snowflake->nextId();
    $refreshToken = bin2hex(random_bytes(32));
    $refreshHash = hash('sha256', $refreshToken, true);
    $now = Utils::nowMs();
    $deviceId = (string) ($body['device_id'] ?? null);
    $clientFacts = getClientFacts($request);

    $stmt = $pdo->prepare('INSERT INTO sessions (id, user_id, refresh_token_hash, created_at, last_seen_at, revoked_at, device_id, client_facts_json) VALUES (:id, :user_id, :refresh, :created_at, :last_seen_at, :revoked_at, :device_id, :client_facts_json)');
    $stmt->execute([
        'id' => $sessionId,
        'user_id' => $user['id'],
        'refresh' => $refreshHash,
        'created_at' => $now,
        'last_seen_at' => $now,
        'revoked_at' => null,
        'device_id' => $deviceId ?: null,
        'client_facts_json' => $clientFacts ? json_encode($clientFacts, JSON_UNESCAPED_SLASHES) : null,
    ]);

    $accessTtl = Config::getInt('ACCESS_TOKEN_TTL', 900);
    $payload = [
        'sub' => (string) $user['id'],
        'sid' => (string) $sessionId,
        'iat' => time(),
        'exp' => time() + $accessTtl,
        'device_id' => $deviceId ?: null,
    ];
    $accessToken = JwtTokens::encode($payload);

    return jsonResponse($response, 200, [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'session_id' => (string) $sessionId,
        'user_id' => (string) $user['id'],
    ]);
});

$app->post('/auth/refresh', function (Request $request, Response $response) use ($snowflake): Response {
    $body = getJsonBody($request);
    $refreshToken = (string) ($body['refresh_token'] ?? '');
    if ($refreshToken === '') {
        return jsonError($response, 422, 'missing_refresh_token');
    }

    $refreshHash = hash('sha256', $refreshToken, true);
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT id, user_id, revoked_at FROM sessions WHERE refresh_token_hash = :refresh LIMIT 1');
    $stmt->execute(['refresh' => $refreshHash]);
    $session = $stmt->fetch();
    if (!$session) {
        return jsonError($response, 401, 'invalid_refresh_token');
    }
    if ($session['revoked_at']) {
        return jsonError($response, 401, 'session_revoked');
    }

    $newRefreshToken = bin2hex(random_bytes(32));
    $newRefreshHash = hash('sha256', $newRefreshToken, true);
    $now = Utils::nowMs();

    $stmt = $pdo->prepare('UPDATE sessions SET refresh_token_hash = :refresh, last_seen_at = :last_seen_at WHERE id = :id');
    $stmt->execute([
        'refresh' => $newRefreshHash,
        'last_seen_at' => $now,
        'id' => $session['id'],
    ]);

    $accessTtl = Config::getInt('ACCESS_TOKEN_TTL', 900);
    $payload = [
        'sub' => (string) $session['user_id'],
        'sid' => (string) $session['id'],
        'iat' => time(),
        'exp' => time() + $accessTtl,
    ];
    $accessToken = JwtTokens::encode($payload);

    return jsonResponse($response, 200, [
        'access_token' => $accessToken,
        'refresh_token' => $newRefreshToken,
        'session_id' => (string) $session['id'],
        'user_id' => (string) $session['user_id'],
    ]);
});

$app->post('/auth/logout', function (Request $request, Response $response): Response {
    $payload = authPayload($request);
    $sessionId = $payload['sid'] ?? null;
    if (!$sessionId) {
        return jsonError($response, 401, 'missing_session');
    }
    $pdo = Db::conn();
    $stmt = $pdo->prepare('UPDATE sessions SET revoked_at = :revoked_at WHERE id = :id');
    $stmt->execute(['revoked_at' => Utils::nowMs(), 'id' => $sessionId]);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->get('/me/sessions', function (Request $request, Response $response): Response {
    $payload = authPayload($request);
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT id, created_at, last_seen_at, revoked_at, device_id FROM sessions WHERE user_id = :user_id ORDER BY created_at DESC');
    $stmt->execute(['user_id' => $payload['sub'] ?? '']);
    $sessions = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        return $row;
    }, $stmt->fetchAll());
    return jsonResponse($response, 200, ['sessions' => $sessions]);
})->add($authMiddleware);

$app->delete('/me/sessions/{sid}', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $sid = (string) ($args['sid'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('UPDATE sessions SET revoked_at = :revoked_at WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'revoked_at' => Utils::nowMs(),
        'id' => $sid,
        'user_id' => $payload['sub'] ?? '',
    ]);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->get('/me', function (Request $request, Response $response): Response {
    $payload = authPayload($request);
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT u.id, u.email, u.created_at, u.email_verified_at, p.username, p.display_name, p.bio, p.avatar_url, p.banner_url, p.privacy_json FROM users u JOIN user_profiles p ON u.id = p.user_id WHERE u.id = :id');
    $stmt->execute(['id' => $payload['sub'] ?? '']);
    $row = $stmt->fetch();
    if (!$row) {
        return jsonError($response, 404, 'not_found');
    }
    $row['id'] = (string) $row['id'];
    if (!$row['display_name']) {
        $row['display_name'] = $row['username'];
    }
    return jsonResponse($response, 200, ['user' => $row]);
})->add($authMiddleware);

$app->patch('/me/profile', function (Request $request, Response $response): Response {
    $payload = authPayload($request);
    $body = getJsonBody($request);
    $username = isset($body['username']) ? strtolower(trim((string) $body['username'])) : null;
    if ($username !== null) {
        if (!preg_match('/^[a-z0-9._]{3,12}$/', $username)) {
            return jsonError($response, 422, 'invalid_username');
        }
    }
    $fields = [
        'display_name' => isset($body['display_name']) ? trim((string) $body['display_name']) : null,
        'bio' => $body['bio'] ?? null,
        'avatar_url' => $body['avatar_url'] ?? null,
        'banner_url' => $body['banner_url'] ?? null,
        'privacy_json' => isset($body['privacy_json']) ? json_encode($body['privacy_json'], JSON_UNESCAPED_SLASHES) : null,
    ];
    $updates = [];
    $params = ['user_id' => $payload['sub'] ?? '', 'updated_at' => Utils::nowMs()];
    if ($username !== null) {
        $pdo = Db::conn();
        $stmt = $pdo->prepare('SELECT user_id FROM user_profiles WHERE username = :username AND user_id != :user_id LIMIT 1');
        $stmt->execute(['username' => $username, 'user_id' => $payload['sub'] ?? '']);
        if ($stmt->fetch()) {
            return jsonError($response, 409, 'username_taken');
        }
        $updates[] = 'username = :username';
        $params['username'] = $username;
    }
    foreach ($fields as $key => $value) {
        if ($value !== null) {
            $updates[] = "$key = :$key";
            if ($key === 'display_name' && $value === '') {
                $params[$key] = null;
            } else {
                $params[$key] = $value;
            }
        }
    }
    if (!$updates) {
        return jsonError($response, 422, 'no_fields');
    }

    $sql = 'UPDATE user_profiles SET ' . implode(', ', $updates) . ', updated_at = :updated_at WHERE user_id = :user_id';
    $pdo = Db::conn();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->get('/users/{user_id}', function (Request $request, Response $response, array $args): Response {
    $userId = (string) ($args['user_id'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT u.id, p.username, p.display_name, p.bio, p.avatar_url, p.banner_url FROM users u JOIN user_profiles p ON u.id = p.user_id WHERE u.id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return jsonError($response, 404, 'not_found');
    }
    $row['id'] = (string) $row['id'];
    if (!$row['display_name']) {
        $row['display_name'] = $row['username'];
    }
    return jsonResponse($response, 200, ['user' => $row]);
});

$app->get('/users/{user_id}/links', function (Request $request, Response $response, array $args): Response {
    $userId = (string) ($args['user_id'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT id, type, url, visibility, sort_order FROM user_links WHERE user_id = :id ORDER BY sort_order ASC');
    $stmt->execute(['id' => $userId]);
    $links = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        return $row;
    }, $stmt->fetchAll());
    return jsonResponse($response, 200, ['links' => $links]);
});

$app->put('/me/links', function (Request $request, Response $response) use ($snowflake): Response {
    $payload = authPayload($request);
    $body = getJsonBody($request);
    $links = $body['links'] ?? null;
    if (!is_array($links)) {
        return jsonError($response, 422, 'invalid_links');
    }
    $pdo = Db::conn();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('DELETE FROM user_links WHERE user_id = :id');
    $stmt->execute(['id' => $payload['sub'] ?? '']);

    $insert = $pdo->prepare('INSERT INTO user_links (id, user_id, type, url, visibility, sort_order, created_at) VALUES (:id, :user_id, :type, :url, :visibility, :sort_order, :created_at)');
    $now = Utils::nowMs();
    foreach ($links as $index => $link) {
        $insert->execute([
            'id' => $snowflake->nextId(),
            'user_id' => $payload['sub'] ?? '',
            'type' => $link['type'] ?? 'web',
            'url' => $link['url'] ?? '',
            'visibility' => $link['visibility'] ?? 'public',
            'sort_order' => $link['sort_order'] ?? $index,
            'created_at' => $now,
        ]);
    }
    $pdo->commit();

    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->post('/friends/requests', function (Request $request, Response $response) use ($snowflake): Response {
    $payload = authPayload($request);
    $body = getJsonBody($request);
    $toUserId = (string) ($body['to_user_id'] ?? '');
    if ($toUserId === '' || $toUserId === (string) ($payload['sub'] ?? '')) {
        return jsonError($response, 422, 'invalid_target');
    }

    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT 1 FROM blocks WHERE (blocker_id = :me AND blocked_id = :other) OR (blocker_id = :other AND blocked_id = :me) LIMIT 1');
    $stmt->execute(['me' => $payload['sub'] ?? '', 'other' => $toUserId]);
    if ($stmt->fetch()) {
        return jsonError($response, 403, 'blocked');
    }

    $stmt = $pdo->prepare('SELECT 1 FROM friendships WHERE user_id = :me AND friend_user_id = :other LIMIT 1');
    $stmt->execute(['me' => $payload['sub'] ?? '', 'other' => $toUserId]);
    if ($stmt->fetch()) {
        return jsonError($response, 409, 'already_friends');
    }

    $stmt = $pdo->prepare('SELECT id, status FROM friend_requests WHERE from_user_id = :from AND to_user_id = :to LIMIT 1');
    $stmt->execute(['from' => $payload['sub'] ?? '', 'to' => $toUserId]);
    $existing = $stmt->fetch();
    if ($existing && $existing['status'] === 'pending') {
        return jsonError($response, 409, 'request_exists');
    }

    $requestId = $snowflake->nextId();
    $stmt = $pdo->prepare('INSERT INTO friend_requests (id, from_user_id, to_user_id, status, created_at) VALUES (:id, :from, :to, :status, :created_at)');
    $stmt->execute([
        'id' => $requestId,
        'from' => $payload['sub'] ?? '',
        'to' => $toUserId,
        'status' => 'pending',
        'created_at' => Utils::nowMs(),
    ]);

    return jsonResponse($response, 201, ['request_id' => (string) $requestId]);
})->add($authMiddleware);

$app->get('/friends/requests', function (Request $request, Response $response): Response {
    $payload = authPayload($request);
    $pdo = Db::conn();
    $incomingStmt = $pdo->prepare('SELECT id, from_user_id, to_user_id, status, created_at FROM friend_requests WHERE to_user_id = :me AND status = \'pending\' ORDER BY created_at DESC');
    $incomingStmt->execute(['me' => $payload['sub'] ?? '']);
    $incoming = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['from_user_id'] = (string) $row['from_user_id'];
        $row['to_user_id'] = (string) $row['to_user_id'];
        return $row;
    }, $incomingStmt->fetchAll());

    $outgoingStmt = $pdo->prepare('SELECT id, from_user_id, to_user_id, status, created_at FROM friend_requests WHERE from_user_id = :me AND status = \'pending\' ORDER BY created_at DESC');
    $outgoingStmt->execute(['me' => $payload['sub'] ?? '']);
    $outgoing = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['from_user_id'] = (string) $row['from_user_id'];
        $row['to_user_id'] = (string) $row['to_user_id'];
        return $row;
    }, $outgoingStmt->fetchAll());

    return jsonResponse($response, 200, [
        'incoming' => $incoming,
        'outgoing' => $outgoing,
    ]);
})->add($authMiddleware);

$app->get('/friends', function (Request $request, Response $response): Response {
    $payload = authPayload($request);
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT friend_user_id, created_at FROM friendships WHERE user_id = :me ORDER BY created_at DESC');
    $stmt->execute(['me' => $payload['sub'] ?? '']);
    $friends = array_map(function ($row) {
        return [
            'user_id' => (string) $row['friend_user_id'],
            'created_at' => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    $incomingStmt = $pdo->prepare('SELECT id, from_user_id, to_user_id, status, created_at FROM friend_requests WHERE to_user_id = :me AND status = \'pending\' ORDER BY created_at DESC');
    $incomingStmt->execute(['me' => $payload['sub'] ?? '']);
    $incoming = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['from_user_id'] = (string) $row['from_user_id'];
        $row['to_user_id'] = (string) $row['to_user_id'];
        return $row;
    }, $incomingStmt->fetchAll());

    $outgoingStmt = $pdo->prepare('SELECT id, from_user_id, to_user_id, status, created_at FROM friend_requests WHERE from_user_id = :me AND status = \'pending\' ORDER BY created_at DESC');
    $outgoingStmt->execute(['me' => $payload['sub'] ?? '']);
    $outgoing = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        $row['from_user_id'] = (string) $row['from_user_id'];
        $row['to_user_id'] = (string) $row['to_user_id'];
        return $row;
    }, $outgoingStmt->fetchAll());

    return jsonResponse($response, 200, [
        'friends' => $friends,
        'incoming_requests' => $incoming,
        'outgoing_requests' => $outgoing,
    ]);
})->add($authMiddleware);

$app->post('/friends/requests/{id}/accept', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $requestId = (string) ($args['id'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT * FROM friend_requests WHERE id = :id AND to_user_id = :me AND status = "pending" LIMIT 1');
    $stmt->execute(['id' => $requestId, 'me' => $payload['sub'] ?? '']);
    $req = $stmt->fetch();
    if (!$req) {
        return jsonError($response, 404, 'request_not_found');
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE friend_requests SET status = :status, responded_at = :responded_at WHERE id = :id');
    $stmt->execute([
        'status' => 'accepted',
        'responded_at' => Utils::nowMs(),
        'id' => $requestId,
    ]);

    $stmt = $pdo->prepare('INSERT IGNORE INTO friendships (user_id, friend_user_id, created_at) VALUES (:a, :b, :created_at), (:b, :a, :created_at)');
    $stmt->execute([
        'a' => $req['from_user_id'],
        'b' => $req['to_user_id'],
        'created_at' => Utils::nowMs(),
    ]);
    $pdo->commit();

    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->post('/friends/requests/{id}/reject', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $requestId = (string) ($args['id'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('UPDATE friend_requests SET status = :status, responded_at = :responded_at WHERE id = :id AND to_user_id = :me');
    $stmt->execute([
        'status' => 'rejected',
        'responded_at' => Utils::nowMs(),
        'id' => $requestId,
        'me' => $payload['sub'] ?? '',
    ]);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->post('/friends/{friend_user_id}/remove', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $friendId = (string) ($args['friend_user_id'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('DELETE FROM friendships WHERE (user_id = :me AND friend_user_id = :friend) OR (user_id = :friend AND friend_user_id = :me)');
    $stmt->execute(['me' => $payload['sub'] ?? '', 'friend' => $friendId]);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->post('/blocklist/{user_id}', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $blockedId = (string) ($args['user_id'] ?? '');
    $pdo = Db::conn();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT IGNORE INTO blocks (blocker_id, blocked_id, created_at) VALUES (:blocker, :blocked, :created_at)');
    $stmt->execute([
        'blocker' => $payload['sub'] ?? '',
        'blocked' => $blockedId,
        'created_at' => Utils::nowMs(),
    ]);
    $stmt = $pdo->prepare('DELETE FROM friendships WHERE (user_id = :me AND friend_user_id = :blocked) OR (user_id = :blocked AND friend_user_id = :me)');
    $stmt->execute(['me' => $payload['sub'] ?? '', 'blocked' => $blockedId]);
    $pdo->commit();
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->delete('/blocklist/{user_id}', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $blockedId = (string) ($args['user_id'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('DELETE FROM blocks WHERE blocker_id = :blocker AND blocked_id = :blocked');
    $stmt->execute(['blocker' => $payload['sub'] ?? '', 'blocked' => $blockedId]);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->post('/conversations', function (Request $request, Response $response) use ($snowflake): Response {
    $payload = authPayload($request);
    $body = getJsonBody($request);
    $type = $body['type'] ?? 'dm';
    $otherId = (string) ($body['user_id'] ?? '');
    if ($type !== 'dm' || $otherId === '') {
        return jsonError($response, 422, 'invalid_conversation');
    }

    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT c.id FROM conversations c JOIN conversation_members a ON a.conversation_id = c.id AND a.user_id = :me JOIN conversation_members b ON b.conversation_id = c.id AND b.user_id = :other WHERE c.type = "dm" LIMIT 1');
    $stmt->execute(['me' => $payload['sub'] ?? '', 'other' => $otherId]);
    $existing = $stmt->fetch();
    if ($existing) {
        return jsonResponse($response, 200, ['conversation_id' => (string) $existing['id'], 'existing' => true]);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM blocks WHERE (blocker_id = :me AND blocked_id = :other) OR (blocker_id = :other AND blocked_id = :me) LIMIT 1');
    $stmt->execute(['me' => $payload['sub'] ?? '', 'other' => $otherId]);
    if ($stmt->fetch()) {
        return jsonError($response, 403, 'blocked');
    }

    $conversationId = $snowflake->nextId();
    $now = Utils::nowMs();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO conversations (id, type, created_by, created_at) VALUES (:id, :type, :created_by, :created_at)');
    $stmt->execute([
        'id' => $conversationId,
        'type' => 'dm',
        'created_by' => $payload['sub'] ?? '',
        'created_at' => $now,
    ]);
    $stmt = $pdo->prepare('INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (:cid, :uid, :role, :joined_at), (:cid, :other, :role2, :joined_at)');
    $stmt->execute([
        'cid' => $conversationId,
        'uid' => $payload['sub'] ?? '',
        'other' => $otherId,
        'role' => 'member',
        'role2' => 'member',
        'joined_at' => $now,
    ]);
    $pdo->commit();

    return jsonResponse($response, 201, ['conversation_id' => (string) $conversationId]);
})->add($authMiddleware);

$app->get('/conversations', function (Request $request, Response $response): Response {
    $payload = authPayload($request);
    $userId = (string) ($payload['sub'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('
        SELECT c.id, c.type, c.title, c.icon_url, c.created_at,
               cm_other.user_id AS peer_id,
               p.username AS peer_username,
               p.display_name AS peer_display_name,
               p.avatar_url AS peer_avatar
        FROM conversations c
        JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = :user_id
        LEFT JOIN conversation_members cm_other ON cm_other.conversation_id = c.id AND cm_other.user_id <> :user_id
        LEFT JOIN user_profiles p ON p.user_id = cm_other.user_id
        ORDER BY c.created_at DESC
    ');
    $stmt->execute(['user_id' => $userId]);
    $items = array_map(function ($row) {
        $row['id'] = (string) $row['id'];
        if ($row['peer_id']) {
            $row['peer'] = [
                'id' => (string) $row['peer_id'],
                'username' => $row['peer_username'],
                'display_name' => $row['peer_display_name'] ?: $row['peer_username'],
                'avatar' => $row['peer_avatar'],
            ];
        } else {
            $row['peer'] = null;
        }
        unset($row['peer_id'], $row['peer_username'], $row['peer_display_name'], $row['peer_avatar']);
        return $row;
    }, $stmt->fetchAll());
    return jsonResponse($response, 200, ['conversations' => $items]);
})->add($authMiddleware);

$app->get('/conversations/{cid}/messages', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $cid = (string) ($args['cid'] ?? '');
    $params = $request->getQueryParams();
    $limit = (int) ($params['limit'] ?? 50);
    $before = $params['before'] ?? null;
    $limit = max(1, min(100, $limit));

    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid');
    $stmt->execute(['cid' => $cid, 'uid' => $payload['sub'] ?? '']);
    if (!$stmt->fetch()) {
        return jsonError($response, 403, 'not_member');
    }

    if ($before) {
        $stmt = $pdo->prepare('SELECT m.*, p.username, p.display_name, p.avatar_url FROM messages m JOIN user_profiles p ON m.sender_id = p.user_id WHERE m.conversation_id = :cid AND m.id < :before ORDER BY m.id DESC LIMIT :limit');
        $stmt->bindValue(':cid', $cid);
        $stmt->bindValue(':before', $before);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare('SELECT m.*, p.username, p.display_name, p.avatar_url FROM messages m JOIN user_profiles p ON m.sender_id = p.user_id WHERE m.conversation_id = :cid ORDER BY m.id DESC LIMIT :limit');
        $stmt->bindValue(':cid', $cid);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }
    $messages = $stmt->fetchAll();

    $ids = array_map(fn($row) => $row['id'], $messages);
    $attachmentsByMessage = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM message_attachments WHERE message_id IN ($in) AND deleted_at IS NULL");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $att) {
            $mid = (string) $att['message_id'];
            $att['id'] = (string) $att['id'];
            $attachmentsByMessage[$mid][] = $att;
        }
    }

    $pinsByMessage = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT message_id FROM message_pins WHERE message_id IN ($in)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $pinsByMessage[(string) $row['message_id']] = true;
        }
    }

    $result = [];
    foreach ($messages as $msg) {
        $msgId = (string) $msg['id'];
        $displayName = $msg['display_name'] ?: $msg['username'];
        $result[] = [
            'id' => $msgId,
            'type' => 0,
            'channel_id' => (string) $msg['conversation_id'],
            'content' => $msg['deleted_at'] ? '' : (string) ($msg['content'] ?? ''),
            'mentions' => [],
            'mention_everyone' => false,
            'attachments' => $attachmentsByMessage[$msgId] ?? [],
            'pinned' => (bool) ($pinsByMessage[$msgId] ?? false),
            'timestamp' => (new DateTimeImmutable($msg['created_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
            'edited_timestamp' => $msg['edited_at'] ? (new DateTimeImmutable($msg['edited_at'], new DateTimeZone('UTC')))->format(DATE_ATOM) : null,
            'author' => [
                'id' => (string) $msg['sender_id'],
                'username' => $msg['username'],
                'display_name' => $displayName,
                'avatar' => $msg['avatar_url'],
            ],
        ];
    }

    return jsonResponse($response, 200, ['messages' => $result]);
})->add($authMiddleware);

$app->post('/conversations/{cid}/typing/start', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $cid = (string) ($args['cid'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid');
    $stmt->execute(['cid' => $cid, 'uid' => $payload['sub'] ?? '']);
    if (!$stmt->fetch()) {
        return jsonError($response, 403, 'not_member');
    }
    $redis = RedisClient::client();
    if ($redis) {
        $stmt = $pdo->prepare('SELECT user_id FROM conversation_members WHERE conversation_id = :cid');
        $stmt->execute(['cid' => $cid]);
        $userIds = array_map(fn($row) => (string) $row['user_id'], $stmt->fetchAll());
        $event = [
            'op' => 'typing',
            'user_ids' => $userIds,
            'd' => [
                'conversation_id' => $cid,
                'user_id' => (string) ($payload['sub'] ?? ''),
                'expires_in_ms' => 8000,
                'ts' => (int) (microtime(true) * 1000),
            ],
        ];
        $redis->publish('sumee:events', json_encode($event, JSON_UNESCAPED_SLASHES));
    }
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->post('/conversations/{cid}/typing/stop', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $cid = (string) ($args['cid'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid');
    $stmt->execute(['cid' => $cid, 'uid' => $payload['sub'] ?? '']);
    if (!$stmt->fetch()) {
        return jsonError($response, 403, 'not_member');
    }
    $redis = RedisClient::client();
    if ($redis) {
        $stmt = $pdo->prepare('SELECT user_id FROM conversation_members WHERE conversation_id = :cid');
        $stmt->execute(['cid' => $cid]);
        $userIds = array_map(fn($row) => (string) $row['user_id'], $stmt->fetchAll());
        $event = [
            'op' => 'typing_stop',
            'user_ids' => $userIds,
            'd' => [
                'conversation_id' => $cid,
                'user_id' => (string) ($payload['sub'] ?? ''),
                'ts' => (int) (microtime(true) * 1000),
            ],
        ];
        $redis->publish('sumee:events', json_encode($event, JSON_UNESCAPED_SLASHES));
    }
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->get('/conversations/{cid}/typing', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $cid = (string) ($args['cid'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid');
    $stmt->execute(['cid' => $cid, 'uid' => $payload['sub'] ?? '']);
    if (!$stmt->fetch()) {
        return jsonError($response, 403, 'not_member');
    }
    $redis = RedisClient::client();
    if (!$redis) {
        return jsonResponse($response, 200, ['typing' => []]);
    }
    $keys = $redis->keys('typing:' . $cid . ':*');
    $userIds = [];
    foreach ($keys as $key) {
        $parts = explode(':', $key);
        $userId = $parts[count($parts) - 1] ?? null;
        if ($userId) {
            $userIds[] = $userId;
        }
    }
    return jsonResponse($response, 200, ['typing' => $userIds]);
})->add($authMiddleware);

$app->post('/conversations/{cid}/messages', function (Request $request, Response $response, array $args) use ($snowflake): Response {
    $payload = authPayload($request);
    $cid = (string) ($args['cid'] ?? '');
    $body = getJsonBody($request);
    $content = $body['content'] ?? null;
    $contentType = $body['content_type'] ?? 'text';
    $clientMsgId = $body['client_msg_id'] ?? null;
    $attachments = $body['attachments'] ?? [];

    if (!$clientMsgId) {
        return jsonError($response, 422, 'missing_client_msg_id');
    }

    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid');
    $stmt->execute(['cid' => $cid, 'uid' => $payload['sub'] ?? '']);
    if (!$stmt->fetch()) {
        return jsonError($response, 403, 'not_member');
    }

    $stmt = $pdo->prepare('SELECT id FROM messages WHERE sender_id = :sender AND client_msg_id = :client LIMIT 1');
    $stmt->execute(['sender' => $payload['sub'] ?? '', 'client' => $clientMsgId]);
    if ($stmt->fetch()) {
        return jsonError($response, 409, 'duplicate_client_msg_id');
    }

    $messageId = $snowflake->nextId();
    $now = Utils::nowMs();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO messages (id, conversation_id, sender_id, content, content_type, created_at, client_msg_id) VALUES (:id, :cid, :sender, :content, :content_type, :created_at, :client_msg_id)');
    $stmt->execute([
        'id' => $messageId,
        'cid' => $cid,
        'sender' => $payload['sub'] ?? '',
        'content' => $content,
        'content_type' => $contentType,
        'created_at' => $now,
        'client_msg_id' => $clientMsgId,
    ]);

    $attachmentRows = [];
    if (is_array($attachments) && $attachments) {
        $ids = array_values(array_filter($attachments));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM message_attachments WHERE id IN ($in) AND owner_user_id = ? AND status = 'active'");
        $params = array_merge($ids, [$payload['sub'] ?? '']);
        $stmt->execute($params);
        $valid = $stmt->fetchAll();
        if (count($valid) !== count($ids)) {
            $pdo->rollBack();
            return jsonError($response, 422, 'invalid_attachments');
        }
        $stmt = $pdo->prepare("UPDATE message_attachments SET message_id = ? WHERE id IN ($in)");
        $stmt->execute(array_merge([$messageId], $ids));
        $attachmentRows = array_map(function ($att) {
            $att['id'] = (string) $att['id'];
            return $att;
        }, $valid);
    }

    $pdo->commit();

    $stmt = $pdo->prepare('SELECT username, display_name, avatar_url FROM user_profiles WHERE user_id = :uid');
    $stmt->execute(['uid' => $payload['sub'] ?? '']);
    $profile = $stmt->fetch() ?: ['username' => null, 'display_name' => null, 'avatar_url' => null];
    $displayName = $profile['display_name'] ?: $profile['username'];

    $messagePayload = [
        'id' => (string) $messageId,
        'type' => 0,
        'channel_id' => (string) $cid,
        'content' => $content === null ? '' : (string) $content,
        'mentions' => [],
        'mention_everyone' => false,
        'attachments' => $attachmentRows,
        'pinned' => false,
        'timestamp' => (new DateTimeImmutable($now, new DateTimeZone('UTC')))->format(DATE_ATOM),
        'edited_timestamp' => null,
        'author' => [
            'id' => (string) ($payload['sub'] ?? ''),
            'username' => $profile['username'],
            'display_name' => $displayName,
            'avatar' => $profile['avatar_url'],
        ],
    ];

    $redis = RedisClient::client();
    if ($redis) {
        $stmt = $pdo->prepare('SELECT user_id FROM conversation_members WHERE conversation_id = :cid');
        $stmt->execute(['cid' => $cid]);
        $userIds = array_map(fn($row) => (string) $row['user_id'], $stmt->fetchAll());
        $event = [
            'op' => 'message_new',
            'user_ids' => $userIds,
            'd' => $messagePayload,
        ];
        $redis->publish('sumee:events', json_encode($event, JSON_UNESCAPED_SLASHES));
    }

    return jsonResponse($response, 201, $messagePayload);
})->add($authMiddleware);

$app->post('/messages/{message_id}/pin', function (Request $request, Response $response, array $args) use ($snowflake): Response {
    $payload = authPayload($request);
    $messageId = (string) ($args['message_id'] ?? '');
    if ($messageId === '') {
        return jsonError($response, 422, 'missing_message_id');
    }
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT conversation_id FROM messages WHERE id = :id');
    $stmt->execute(['id' => $messageId]);
    $msg = $stmt->fetch();
    if (!$msg) {
        return jsonError($response, 404, 'message_not_found');
    }
    $conversationId = (string) $msg['conversation_id'];
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid');
    $stmt->execute(['cid' => $conversationId, 'uid' => $payload['sub'] ?? '']);
    if (!$stmt->fetch()) {
        return jsonError($response, 403, 'not_member');
    }
    $pinId = $snowflake->nextId();
    $stmt = $pdo->prepare('INSERT IGNORE INTO message_pins (id, conversation_id, message_id, pinned_by, pinned_at) VALUES (:id, :cid, :mid, :uid, :pinned_at)');
    $stmt->execute([
        'id' => $pinId,
        'cid' => $conversationId,
        'mid' => $messageId,
        'uid' => $payload['sub'] ?? '',
        'pinned_at' => Utils::nowMs(),
    ]);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->delete('/messages/{message_id}/pin', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $messageId = (string) ($args['message_id'] ?? '');
    if ($messageId === '') {
        return jsonError($response, 422, 'missing_message_id');
    }
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT conversation_id FROM messages WHERE id = :id');
    $stmt->execute(['id' => $messageId]);
    $msg = $stmt->fetch();
    if (!$msg) {
        return jsonError($response, 404, 'message_not_found');
    }
    $conversationId = (string) $msg['conversation_id'];
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid');
    $stmt->execute(['cid' => $conversationId, 'uid' => $payload['sub'] ?? '']);
    if (!$stmt->fetch()) {
        return jsonError($response, 403, 'not_member');
    }
    $stmt = $pdo->prepare('DELETE FROM message_pins WHERE message_id = :mid');
    $stmt->execute(['mid' => $messageId]);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->get('/conversations/{cid}/pins', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $cid = (string) ($args['cid'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid');
    $stmt->execute(['cid' => $cid, 'uid' => $payload['sub'] ?? '']);
    if (!$stmt->fetch()) {
        return jsonError($response, 403, 'not_member');
    }
    $stmt = $pdo->prepare('SELECT p.message_id, p.pinned_at FROM message_pins p WHERE p.conversation_id = :cid ORDER BY p.pinned_at DESC');
    $stmt->execute(['cid' => $cid]);
    $pins = $stmt->fetchAll();
    if (!$pins) {
        return jsonResponse($response, 200, ['messages' => []]);
    }
    $ids = array_map(fn($row) => $row['message_id'], $pins);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT m.*, p.username, p.display_name, p.avatar_url FROM messages m JOIN user_profiles p ON m.sender_id = p.user_id WHERE m.id IN ($in)");
    $stmt->execute($ids);
    $messages = $stmt->fetchAll();
    $messageById = [];
    foreach ($messages as $msg) {
        $messageById[(string) $msg['id']] = $msg;
    }
    $attachmentsByMessage = [];
    $stmt = $pdo->prepare("SELECT * FROM message_attachments WHERE message_id IN ($in) AND deleted_at IS NULL");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $att) {
        $mid = (string) $att['message_id'];
        $att['id'] = (string) $att['id'];
        $attachmentsByMessage[$mid][] = $att;
    }
    $result = [];
    foreach ($pins as $pin) {
        $msg = $messageById[(string) $pin['message_id']] ?? null;
        if (!$msg) {
            continue;
        }
        $msgId = (string) $msg['id'];
        $displayName = $msg['display_name'] ?: $msg['username'];
        $result[] = [
            'id' => $msgId,
            'type' => 0,
            'channel_id' => (string) $msg['conversation_id'],
            'content' => $msg['deleted_at'] ? '' : (string) ($msg['content'] ?? ''),
            'mentions' => [],
            'mention_everyone' => false,
            'attachments' => $attachmentsByMessage[$msgId] ?? [],
            'pinned' => true,
            'timestamp' => (new DateTimeImmutable($msg['created_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
            'edited_timestamp' => $msg['edited_at'] ? (new DateTimeImmutable($msg['edited_at'], new DateTimeZone('UTC')))->format(DATE_ATOM) : null,
            'author' => [
                'id' => (string) $msg['sender_id'],
                'username' => $msg['username'],
                'display_name' => $displayName,
                'avatar' => $msg['avatar_url'],
            ],
            'pinned_at' => $pin['pinned_at'],
        ];
    }
    return jsonResponse($response, 200, ['messages' => $result]);
})->add($authMiddleware);

$app->patch('/messages/{message_id}', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $messageId = (string) ($args['message_id'] ?? '');
    $body = getJsonBody($request);
    $content = $body['content'] ?? null;
    $contentType = $body['content_type'] ?? null;

    if ($content === null && $contentType === null) {
        return jsonError($response, 422, 'missing_fields');
    }

    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT sender_id FROM messages WHERE id = :id');
    $stmt->execute(['id' => $messageId]);
    $msg = $stmt->fetch();
    if (!$msg || (string) $msg['sender_id'] !== (string) ($payload['sub'] ?? '')) {
        return jsonError($response, 403, 'not_allowed');
    }

    $updates = [];
    $params = ['id' => $messageId, 'edited_at' => Utils::nowMs()];
    if ($content !== null) {
        $updates[] = 'content = :content';
        $params['content'] = $content;
    }
    if ($contentType !== null) {
        $updates[] = 'content_type = :content_type';
        $params['content_type'] = $contentType;
    }

    $sql = 'UPDATE messages SET ' . implode(', ', $updates) . ', edited_at = :edited_at WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->delete('/messages/{message_id}', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $messageId = (string) ($args['message_id'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT sender_id FROM messages WHERE id = :id');
    $stmt->execute(['id' => $messageId]);
    $msg = $stmt->fetch();
    if (!$msg || (string) $msg['sender_id'] !== (string) ($payload['sub'] ?? '')) {
        return jsonError($response, 403, 'not_allowed');
    }

    $now = Utils::nowMs();
    $graceDays = Config::getInt('ATTACHMENT_GRACE_DAYS', 7);
    $expires = (new DateTimeImmutable($now, new DateTimeZone('UTC')))->modify('+' . $graceDays . ' days')->format('Y-m-d H:i:s.v');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE messages SET deleted_at = :deleted_at WHERE id = :id');
    $stmt->execute(['deleted_at' => $now, 'id' => $messageId]);
    $stmt = $pdo->prepare('UPDATE message_attachments SET deleted_at = :deleted_at, expires_at = :expires_at WHERE message_id = :message_id');
    $stmt->execute(['deleted_at' => $now, 'expires_at' => $expires, 'message_id' => $messageId]);
    $pdo->commit();

    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->post('/uploads/images/init', function (Request $request, Response $response) use ($snowflake): Response {
    $payload = authPayload($request);
    $body = getJsonBody($request);
    $mime = (string) ($body['mime'] ?? '');
    $size = (int) ($body['size'] ?? 0);
    $sha256 = $body['sha256'] ?? null;

    $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed, true)) {
        return jsonError($response, 422, 'invalid_mime');
    }
    $maxSize = Config::getInt('MAX_UPLOAD_BYTES', 8 * 1024 * 1024);
    if ($size <= 0 || $size > $maxSize) {
        return jsonError($response, 422, 'invalid_size');
    }

    $attachmentId = $snowflake->nextId();
    $storageKey = 'attachments/' . ($payload['sub'] ?? 'unknown') . '/' . $attachmentId;
    $cdnUrl = Storage::publicUrl($storageKey);

    $pdo = Db::conn();
    $stmt = $pdo->prepare('INSERT INTO message_attachments (id, owner_user_id, message_id, storage_key, cdn_url, mime, size, sha256, status, created_at) VALUES (:id, :owner, :message_id, :storage_key, :cdn_url, :mime, :size, :sha256, :status, :created_at)');
    $stmt->execute([
        'id' => $attachmentId,
        'owner' => $payload['sub'] ?? '',
        'message_id' => null,
        'storage_key' => $storageKey,
        'cdn_url' => $cdnUrl,
        'mime' => $mime,
        'size' => $size,
        'sha256' => $sha256 ? hex2bin($sha256) : null,
        'status' => 'pending',
        'created_at' => Utils::nowMs(),
    ]);

    $uploadUrl = Config::get('APP_URL', 'http://localhost:8080') . '/uploads/images/put/' . $attachmentId;

    return jsonResponse($response, 200, [
        'attachment_id' => (string) $attachmentId,
        'storage_key' => $storageKey,
        'upload_url' => $uploadUrl,
        'cdn_url' => $cdnUrl,
    ]);
})->add($authMiddleware);

$app->post('/uploads/images/put/{attachment_id}', function (Request $request, Response $response, array $args): Response {
    $payload = authPayload($request);
    $attachmentId = (string) ($args['attachment_id'] ?? '');
    $files = $request->getUploadedFiles();
    if (!isset($files['file'])) {
        return jsonError($response, 422, 'missing_file');
    }

    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT storage_key FROM message_attachments WHERE id = :id AND owner_user_id = :owner');
    $stmt->execute(['id' => $attachmentId, 'owner' => $payload['sub'] ?? '']);
    $att = $stmt->fetch();
    if (!$att) {
        return jsonError($response, 404, 'attachment_not_found');
    }

    Storage::saveUploadedFile($files['file'], $att['storage_key']);
    $stmt = $pdo->prepare('UPDATE message_attachments SET status = :status WHERE id = :id');
    $stmt->execute(['status' => 'active', 'id' => $attachmentId]);

    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->post('/uploads/images/complete', function (Request $request, Response $response): Response {
    $payload = authPayload($request);
    $body = getJsonBody($request);
    $attachmentId = (string) ($body['attachment_id'] ?? '');
    $pdo = Db::conn();
    $stmt = $pdo->prepare('SELECT storage_key FROM message_attachments WHERE id = :id AND owner_user_id = :owner');
    $stmt->execute(['id' => $attachmentId, 'owner' => $payload['sub'] ?? '']);
    $att = $stmt->fetch();
    if (!$att) {
        return jsonError($response, 404, 'attachment_not_found');
    }
    if (!file_exists(Storage::filePath($att['storage_key']))) {
        return jsonError($response, 400, 'file_missing');
    }
    $stmt = $pdo->prepare('UPDATE message_attachments SET status = :status WHERE id = :id');
    $stmt->execute(['status' => 'active', 'id' => $attachmentId]);
    return jsonResponse($response, 200, ['ok' => true]);
})->add($authMiddleware);

$app->get('/uploads/files/{path:.+}', function (Request $request, Response $response, array $args): Response {
    $path = $args['path'] ?? '';
    $full = Storage::filePath($path);
    if (!file_exists($full)) {
        return $response->withStatus(404);
    }
    $stream = fopen($full, 'rb');
    $response->getBody()->write(stream_get_contents($stream));
    fclose($stream);
    return $response->withHeader('Content-Type', 'application/octet-stream');
});

$app->post('/reports', function (Request $request, Response $response) use ($snowflake): Response {
    $payload = authPayload($request);
    $body = getJsonBody($request);
    $targetType = $body['target_type'] ?? null;
    $targetId = $body['target_id'] ?? null;
    $category = $body['category'] ?? null;
    $description = $body['description'] ?? null;
    $evidence = $body['evidence'] ?? [];

    if (!$targetType || !$targetId || !$category) {
        return jsonError($response, 422, 'missing_fields');
    }

    $reportId = $snowflake->nextId();
    $pdo = Db::conn();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO reports (id, reporter_id, target_type, target_id, category, description, status, created_at) VALUES (:id, :reporter_id, :target_type, :target_id, :category, :description, :status, :created_at)');
    $stmt->execute([
        'id' => $reportId,
        'reporter_id' => $payload['sub'] ?? '',
        'target_type' => $targetType,
        'target_id' => $targetId,
        'category' => $category,
        'description' => $description,
        'status' => 'open',
        'created_at' => Utils::nowMs(),
    ]);

    if (is_array($evidence)) {
        $stmt = $pdo->prepare('INSERT INTO report_evidence (id, report_id, type, value, created_at) VALUES (:id, :report_id, :type, :value, :created_at)');
        foreach ($evidence as $item) {
            if (!isset($item['type'], $item['value'])) {
                continue;
            }
            $stmt->execute([
                'id' => $snowflake->nextId(),
                'report_id' => $reportId,
                'type' => $item['type'],
                'value' => $item['value'],
                'created_at' => Utils::nowMs(),
            ]);
        }
    }

    $pdo->commit();
    return jsonResponse($response, 201, ['report_id' => (string) $reportId]);
})->add($authMiddleware);

$app->run();
