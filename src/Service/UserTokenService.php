<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UserTokenService
{
    private const COOKIE_NAME = 'tracker_token';
    private const COOKIE_TTL  = 31_536_000; // 1 year

    public function __construct(
        private readonly string $appEnv = 'dev',
    ) {}

    public function getToken(Request $request): ?string
    {
        $token = $request->cookies->get(self::COOKIE_NAME);
        return $this->isValidUuid($token) ? $token : null;
    }

    public function generateToken(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function setTokenCookie(Response $response, string $token): void
    {
        $response->headers->setCookie(
            Cookie::create(self::COOKIE_NAME)
                ->withValue($token)
                ->withExpires(time() + self::COOKIE_TTL)
                ->withPath('/')
                ->withHttpOnly(true)
                ->withSameSite('lax')
                ->withSecure($this->appEnv === 'prod')
        );
    }

    public function clearTokenCookie(Response $response): void
    {
        $response->headers->clearCookie(self::COOKIE_NAME, '/');
    }

    private function isValidUuid(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
