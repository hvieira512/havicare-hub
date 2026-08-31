<?php

namespace Hub\Api\Http\Middleware;

use Hub\Api\Http\RequestContext;
use Hub\Log\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiRequestLogger
{
    private const LOG_REDACTION = '********';

    private int $logBodyMaxBytes;
    private int $logBodyScanMaxBytes;

    public function __construct()
    {
        $this->logBodyMaxBytes = max(0, (int)(getenv('LOG_BODY_MAX_BYTES') ?: 16384));
        $this->logBodyScanMaxBytes = max(0, (int)(getenv('LOG_BODY_SCAN_MAX_BYTES') ?: 1048576));
    }

    public function __invoke(ServerRequestInterface $request, callable $next): mixed
    {
        // Só o `/api/` entra no canal `api`. A dashboard serve o JS e o CSS inteiros a cada
        // carregamento, e o `/`, o `/dashboard` e as imagens dos modelos vêm pelo mesmo
        // manipulador: registá-los enchia o ficheiro antes de servir para alguma coisa.
        if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
            return $next($request);
        }

        $requestId = $request->getHeaderLine('X-Request-Id')
            ?: RequestContext::requestId($request)
            ?: bin2hex(random_bytes(16));
        $context = new ApiLogContext();
        $request = $request
            ->withAttribute(RequestContext::ATTR_REQUEST_ID, $requestId)
            ->withAttribute(RequestContext::ATTR_RAW_BODY, (string)$request->getBody())
            ->withAttribute(ApiLogContext::ATTRIBUTE, $context);
        $startedAt = microtime(true);

        $response = $next($request);
        if (!$response instanceof ResponseInterface) {
            return $response;
        }

        $response = $response->withHeader('X-Request-Id', $requestId);
        $this->safeLog($request, $response, $startedAt, $context);

        return $response;
    }

    // Uma falha a registar não pode derrubar o pedido que já foi respondido.
    private function safeLog(
        ServerRequestInterface $request,
        ResponseInterface $response,
        float $startedAt,
        ApiLogContext $context
    ): void {
        try {
            $this->log($request, $response, $startedAt, $context);
        } catch (\Throwable $e) {
            Logger::channel('api')->warning('Failed to log API request completion', [
                'request_id' => (string)$request->getAttribute(RequestContext::ATTR_REQUEST_ID, ''),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function log(
        ServerRequestInterface $request,
        ResponseInterface $response,
        float $startedAt,
        ApiLogContext $context
    ): void {
        $query = $this->sanitizeLogQuery($request->getUri()->getQuery());
        $serverParams = $request->getServerParams();
        $status = $response->getStatusCode();
        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');
        $path = $request->getUri()->getPath();
        $isAuthPath = str_starts_with($path, '/api/auth/');
        $auth = $context->auth;

        Logger::channel('api')->log($level, 'API request completed', [
            'request_id' => (string)$request->getAttribute(RequestContext::ATTR_REQUEST_ID, ''),
            'method' => strtoupper($request->getMethod()),
            'path' => $path,
            'query' => $query,
            'route' => $context->route,
            'status' => $status,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'remote_ip' => (string)($serverParams['REMOTE_ADDR'] ?? ''),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'auth_state' => $context->authState,
            'username' => $auth?->username,
            'role' => $auth?->role,
            'license_id' => $auth?->licenseId,
            'request_body' => $this->structuredLogBody(
                (string)$request->getAttribute(RequestContext::ATTR_RAW_BODY, ''),
                $isAuthPath
            ),
            'response_content_type' => $response->getHeaderLine('Content-Type'),
            'response_body' => $this->responseBodyForLog($response, $isAuthPath),
        ]);
    }

    private function responseBodyForLog(ResponseInterface $response, bool $redactUnstructured = false): mixed
    {
        $body = $response->getBody();
        try {
            if ($body->isSeekable()) {
                $position = $body->tell();
                $body->rewind();
                $contents = $body->getContents();
                $body->seek($position);
            } else {
                $contents = (string)$body;
            }
        } catch (\Throwable) {
            return null;
        }

        return $this->structuredLogBody($contents, $redactUnstructured);
    }

    private function structuredLogBody(string $body, bool $redactUnstructured = false): mixed
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        // Um corpo desta dimensão nem chega a ser descodificado: a descodificação e as
        // recursões que se lhe seguem correm no laço que também serve o TCP e as pontes MQTT.
        if (strlen($body) > $this->logBodyScanMaxBytes) {
            return sprintf('[corpo não registado: %d bytes]', strlen($body));
        }

        try {
            $sanitized = $this->sanitizeLogValue(json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return $this->cappedLogBody($redactUnstructured ? self::LOG_REDACTION : $body);
        }

        $encoded = json_encode($sanitized);

        return $encoded === false || strlen($encoded) <= $this->logBodyMaxBytes
            ? $sanitized
            : $this->cappedLogBody($encoded);
    }

    // O corte vem sempre depois da rasura: cortar o texto cru deixava credenciais por rasurar.
    private function cappedLogBody(string $value): string
    {
        $size = strlen($value);
        if ($size <= $this->logBodyMaxBytes) {
            return $value;
        }

        return substr($value, 0, $this->logBodyMaxBytes) . sprintf('…[truncado, %d bytes no total]', $size);
    }

    private function sanitizeLogValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveLogField($key, $item)) {
                $sanitized[$key] = self::LOG_REDACTION;
                continue;
            }

            $sanitized[$key] = $this->sanitizeLogValue($item);
        }

        return $sanitized;
    }

    private function sanitizeLogQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $parts = [];
        foreach (explode('&', $query) as $part) {
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = rawurldecode($rawKey);
            $value = rawurldecode($rawValue);
            $parts[] = $this->isSensitiveLogField($key, $value)
                ? $rawKey . '=' . self::LOG_REDACTION
                : $part;
        }

        return implode('&', $parts);
    }

    private function isSensitiveLogField(string $key, mixed $value): bool
    {
        $normalized = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $normalized = strtolower(str_replace(['-', ' '], '_', $normalized));

        if (
            $normalized === 'password'
            || str_ends_with($normalized, '_password')
            || $normalized === 'secret'
            || str_ends_with($normalized, '_secret')
            || $normalized === 'authorization'
            || $normalized === 'api_key'
            // O bilhete do stream viaja no URL, e o URL é exactamente o campo que se regista
            // aqui. Já vem gasto quando esta linha se escreve -- o registo corre depois da
            // resposta, e o bilhete queima-se ao ser lido --, mas uma credencial em claro num
            // ficheiro de registo não se justifica por ser de curta duração.
            || $normalized === 'ticket'
        ) {
            return true;
        }

        return !is_array($value)
            && ($normalized === 'token' || str_ends_with($normalized, '_token'));
    }
}
