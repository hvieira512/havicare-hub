<?php

namespace Hub\Dashboard;

use Hub\Api\ApiKernel;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Auth\LoginThrottle;
use Hub\Api\Services\ApiUserService;
use Hub\Api\Services\AuthService;
use Hub\Api\Services\CapabilityService;
use Hub\Api\Repository\CapabilityDiscoveryRepository;
use Hub\Api\Services\CapabilityDiscoveryService;
use Hub\Api\Services\CompanyService;
use Hub\Api\Services\DeviceService;
use Hub\Api\Services\DashboardNotificationService;
use Hub\Api\Services\LicenseService;
use Hub\Api\Services\ModelService;
use Hub\Api\Services\ProtocolService;
use Hub\Api\Services\SupplierService;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Device\DeviceHubServer;
use Hub\Device\MessageFanout;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class DashboardHttpServer
{
    private const MODEL_IMAGE_DIR = __DIR__ . '/../../var/dashboard/model-images';
    private const MODEL_IMAGE_ROUTE = '/model-images';
    private const PUBLIC_ASSET_EXTENSIONS = ['css', 'ico', 'jpeg', 'jpg', 'js', 'png', 'svg', 'woff2'];
    private ApiKernel $apiKernel;
    /** @var array<string, string> */
    private array $assetCache = [];
    // Não é promovida: com um valor por omissão declarado, uma instância criada por
    // `newInstanceWithoutConstructor()` -- como fazem os testes dos recursos estáticos --
    // continua a lê-la sem fatal, e o `page()` dispensa o `isset()`.
    private bool $apiAuthRequired = true;

    public function __construct(
        private DashboardStore $store,
        private ApiTokenStore $tokens,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ApiDataAccess $db,
        bool $apiAuthRequired = true,
        private int $apiTokenTtlSeconds = 3600,
        private int $apiRefreshTokenTtlSeconds = 2592000,
        // A mesma instância que a ingestão usa para anunciar uma publicação. Quando falta, o
        // stream de inquilino existe e nunca recebe nada -- o que é o que os testes que não
        // se ocupam dele querem.
        private ?MessageFanout $messages = null,
        private int $maxOpenStreams = 200,
        private int $maxOpenStreamsPerUser = 5,
        private ?LoginThrottle $loginThrottle = null,
    ) {
        $this->apiAuthRequired = $apiAuthRequired;

        // O store anuncia as suas próprias escritas, e por isso o stream tem de subscrever
        // esse notificador exacto, e não um seu.
        $deviceService = new DeviceService(
            $this->store,
            $this->whitelist,
            $this->hub,
            $this->db,
        );
        $this->apiKernel = new ApiKernel(
            $this->apiAuthRequired,
            new AuthService(
                $this->tokens,
                $this->db,
                $this->apiTokenTtlSeconds,
                $this->apiRefreshTokenTtlSeconds,
                $this->loginThrottle,
            ),
            $deviceService,
            new ModelService($this->db),
            new CapabilityService($this->db),
            new CapabilityDiscoveryService(
                $this->db,
                $deviceService,
                new CapabilityDiscoveryRepository(dirname(__DIR__, 2) . '/var/dashboard/capability-discovery'),
            ),
            new SupplierService($this->db),
            new ApiUserService($this->db),
            new CompanyService($this->db),
            new LicenseService($this->db),
            new ProtocolService(),
            new DashboardNotificationService($this->db),
            new \Hub\Api\Http\JsonResponder(),
            new \Hub\Api\Http\HtmlResponder(),
            new \Hub\Api\Auth\BearerTokenResolver($this->tokens),
            new \Hub\Api\Auth\RouteAccessPolicy(),
            $this->messages ?? new MessageFanout(),
            $this->maxOpenStreams,
            $this->maxOpenStreamsPerUser,
        );
    }

    /**
     * Semeia no Redis os dispositivos da whitelist. Era o que o construtor fazia, e construir
     * um objecto não deve escrever num datastore: quem serve é que decide quando aquecer.
     */
    public function warmUp(): void
    {
        foreach ($this->whitelist->all() as $imei => $metadata) {
            $this->store->registerDevice(
                (string)$imei,
                $metadata->supplier,
                $metadata->model,
                $metadata->deviceType,
                $metadata->licenseId,
                $metadata->simNumber,
                $metadata->deviceId,
                $metadata->company
            );
        }
    }

    public function __invoke(ServerRequestInterface $request): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        if (str_starts_with($path, '/api/')) {
            return $this->apiKernel->handle($request);
        }

        try {
            if ($method === 'GET' && ($path === '/' || $path === '/dashboard')) {
                return $this->html($this->page());
            }
            if ($method === 'GET' && preg_match('#^' . self::MODEL_IMAGE_ROUTE . '/([a-f0-9]{32}\.jpg)$#', $path, $matches) === 1) {
                return $this->modelImage($matches[1]);
            }
            // O `/api/`, o `/` e o `/dashboard` já devolveram acima, por isso o que chega
            // aqui em GET é candidato a recurso estático.
            if ($method === 'GET') {
                $file = $this->publicAssetPath($path);
                if ($file !== null) {
                    return $this->staticFile($file, $request);
                }
            }
        } catch (\Throwable $e) {
            Logger::channel('hub')->error('Dashboard request failed', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return $this->json([
                'error' => ['code' => 'server_error', 'message' => 'Internal server error'],
            ], 500);
        }

        return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
    }

    private function json(array $payload, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function html(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    private function page(): string
    {
        $dashboardApiAuthRequired = $this->apiAuthRequired;

        ob_start();
        require __DIR__ . '/index.php';
        return (string) ob_get_clean();
    }

    private function staticFile(string $path, ServerRequestInterface $request): Response
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'js' => 'application/javascript',
            'css' => 'text/css',
            'html' => 'text/html; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            default => 'text/plain',
        };

        // O caminho dos recursos de terceiros muda quando eles mudam; o nosso não tem
        // impressão digital no URL, e por isso leva `ETag` em vez de `immutable`.
        if (str_contains($path, '/assets/vendor/') || str_contains($path, '/assets/fonts/')) {
            return new Response(
                200,
                ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=31536000, immutable'],
                $this->assetContents($path)
            );
        }

        $etag = sprintf('"%x-%x"', (int)filemtime($path), (int)filesize($path));
        $headers = ['Content-Type' => $mime, 'Cache-Control' => 'no-cache', 'ETag' => $etag];
        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return new Response(304, ['Cache-Control' => 'no-cache', 'ETag' => $etag]);
        }

        return new Response(200, $headers, $this->assetContents($path));
    }

    // O ficheiro não muda debaixo do processo: em produção o `make update` reinicia-o e
    // em desenvolvimento o vigia reinicia-o.
    private function assetContents(string $path): string
    {
        return $this->assetCache[$path] ??= (string)file_get_contents($path);
    }

    private function publicAssetPath(string $requestPath): ?string
    {
        $requestPath = rawurldecode($requestPath);
        if (str_contains($requestPath, "\0") || str_contains($requestPath, '\\')) {
            return null;
        }

        // Rotas nomeadas, uma a uma. O `html` fica fora das extensões públicas de propósito:
        // acrescentá-lo serviria qualquer ficheiro HTML de dentro de `assets/`.
        $routes = [
            '/main.css' => [__DIR__, 'main.css'],
            '/main.js' => [__DIR__, 'main.js'],
        ];
        if (isset($routes[$requestPath])) {
            // Uma rota nomeada é um ficheiro escrito aqui, e não um caminho que alguém
            // escolheu: a lista de extensões públicas não se lhe aplica.
            [$root, $relativePath] = $routes[$requestPath];
            return $this->assetWithinRoot($root, $relativePath, checkExtension: false);
        }

        foreach (['/assets/' => __DIR__ . '/assets', '/dashboard/' => __DIR__ . '/dashboard'] as $prefix => $root) {
            if (str_starts_with($requestPath, $prefix)) {
                return $this->assetWithinRoot($root, substr($requestPath, strlen($prefix)));
            }
        }

        return null;
    }

    private function assetWithinRoot(string $root, string $relativePath, bool $checkExtension = true): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $realRoot = realpath($root);
        $realPath = realpath($root . '/' . ltrim($relativePath, '/'));
        if ($realRoot === false || $realPath === false || !is_file($realPath)) {
            return null;
        }

        $rootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath, $rootPrefix)) {
            return null;
        }

        if (!$checkExtension) {
            return $realPath;
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        return in_array($extension, self::PUBLIC_ASSET_EXTENSIONS, true) ? $realPath : null;
    }

    private function modelImage(string $filename): Response
    {
        $path = self::MODEL_IMAGE_DIR . '/' . $filename;
        if (!is_file($path)) {
            return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }
        return new Response(200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=31536000, immutable'], (string)file_get_contents($path));
    }
}
