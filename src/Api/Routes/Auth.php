<?php

namespace Hub\Api\Routes;

use Hub\Dashboard\ApiTokenStore;

final class Auth
{
    public function __construct(
        private string $username,
        private string $password,
        private ApiTokenStore $tokens,
        private int $tokenTtlSeconds = 3600,
    ) {
    }

    public function login(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        $username = trim((string)($decoded['username'] ?? ''));
        $password = (string)($decoded['password'] ?? '');
        if ($username === '' || $password === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'username and password are required']];
        }

        if (!hash_equals($this->username, $username) || !hash_equals($this->password, $password)) {
            return ['error' => ['code' => 'invalid_credentials', 'message' => 'Invalid credentials']];
        }

        return [
            'status' => 'ok',
            'token' => $this->tokens->issue($username, $this->tokenTtlSeconds),
        ];
    }
}
