<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

class CsrfService
{
    private const SESSION_KEY = '_csrf_tokens';
    public const ADMIN_TOKEN_ID = 'admin';
    public const FIELD_NAME = '_csrf_token';
    public const HEADER_NAME = 'X-CSRF-Token';

    public function getToken(string $tokenId = self::ADMIN_TOKEN_ID): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        if (!isset($_SESSION[self::SESSION_KEY][$tokenId]) || !is_string($_SESSION[self::SESSION_KEY][$tokenId])) {
            $_SESSION[self::SESSION_KEY][$tokenId] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY][$tokenId];
    }

    public function isTokenValid(Request $request, string $tokenId = self::ADMIN_TOKEN_ID): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY][$tokenId] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        $submittedToken = $request->request->get(self::FIELD_NAME);
        if (!is_string($submittedToken) || $submittedToken === '') {
            $submittedToken = $request->headers->get(self::HEADER_NAME, '');
        }

        return is_string($submittedToken)
            && $submittedToken !== ''
            && hash_equals($sessionToken, $submittedToken);
    }
}
