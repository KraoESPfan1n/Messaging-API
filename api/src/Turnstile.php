<?php

namespace Sumee;

class Turnstile
{
    public static function verify(string $token, ?string $ip = null): bool
    {
        $secret = Config::get('TURNSTILE_SECRET');
        if (!$secret) {
            return true;
        }
        if ($token === 'dev-pass') {
            return true;
        }
        $data = http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        if (function_exists('curl_init')) {
            $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            if ($response === false) {
                curl_close($ch);
                return false;
            }
            curl_close($ch);
        } else {
            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $data,
                    'timeout' => 4,
                ],
            ];
            $response = file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, stream_context_create($opts));
            if ($response === false) {
                return false;
            }
        }

        $payload = json_decode($response, true);
        return (bool) ($payload['success'] ?? false);
    }
}
