<?php
declare(strict_types=1);

namespace App\Middleware;

/**
 * Lightweight, zero-dependency JWT Auth Middleware using HMAC-SHA256
 */
class Auth
{
    private static string $secretKey = 'HYBRID_AI_ACCOUNTING_JWT_SECURE_SECRET_2026_LEAD_DEV';

    public static function setSecret(string $secret): void
    {
        self::$secretKey = $secret;
    }

    /**
     * Generates a signed JWT token
     */
    public static function generateToken(array $payload, int $expirySeconds = 86400): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_UNESCAPED_SLASHES);
        
        $payload['iat'] = time();
        $payload['exp'] = time() + $expirySeconds;
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $base64UrlHeader  = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payloadJson);

        $signature = hash_hmac('sha256', "{$base64UrlHeader}.{$base64UrlPayload}", self::$secretKey, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return "{$base64UrlHeader}.{$base64UrlPayload}.{$base64UrlSignature}";
    }

    /**
     * Verifies JWT token and returns payload or null if invalid/expired
     */
    public static function verifyToken(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $parts;

        // Verify signature
        $expectedSignature = hash_hmac('sha256', "{$base64UrlHeader}.{$base64UrlPayload}", self::$secretKey, true);
        $expectedBase64UrlSignature = self::base64UrlEncode($expectedSignature);

        if (!hash_equals($expectedBase64UrlSignature, $base64UrlSignature)) {
            return null;
        }

        // Decode payload
        $payloadJson = self::base64UrlDecode($base64UrlPayload);
        $payload = json_decode($payloadJson, true);

        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Extracts token from Authorization header (Bearer <token>)
     */
    public static function getBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($header) && function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            $header = $apacheHeaders['Authorization'] ?? $apacheHeaders['authorization'] ?? '';
        }

        if (preg_match('/Bearer\s(\S+)/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Guard: Requires valid JWT or halts request with 401 Unauthorized
     */
    public static function requireAuth(): array
    {
        $token = self::getBearerToken();
        if (!$token) {
            if (!headers_sent()) { @http_response_code(401); }
            echo json_encode([
                'success' => false,
                'error' => 'Authentication required: Missing Bearer Token.'
            ]);
            exit;
        }

        $payload = self::verifyToken($token);
        if (!$payload) {
            if (!headers_sent()) { @http_response_code(401); }
            echo json_encode([
                'success' => false,
                'error' => 'Authentication failed: Invalid or expired token.'
            ]);
            exit;
        }

        return $payload;
    }

    /**
     * Guard: Requires valid JWT with role === 'admin' or halts request with 403 Forbidden
     */
    public static function requireAdmin(): array
    {
        $payload = self::requireAuth();
        
        $role = $payload['role'] ?? 'user';
        if ($role !== 'admin') {
            if (!headers_sent()) { @http_response_code(403); }
            echo json_encode([
                'success' => false,
                'error' => 'Access denied: Administrator privileges required.'
            ]);
            exit;
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $data .= str_repeat('=', $padLen);
        }
        return (string)base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
