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
use Hub\Api\Services\RadarCredentialsService;
use Hub\Api\Services\ModelImageStore;
use Hub\Api\Services\ModelService;
use Hub\Api\Services\ProtocolService;
use Hub\Api\Services\SupplierService;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Device\DeviceHubServer;
use Hub\Device\MessageFanout;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use Psr\Http\Message\ServerRequestInterface;
use Hub\Api\Services\RadarLayoutService;
use Hub\Ingress\Http\Qinglanst\LayoutParser;
use Hub\Ingress\Http\Qinglanst\QinglanstApiClient;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

final class DashboardHttpServer
{
    private const MODEL_IMAGE_ROUTE = ModelImageStore::ROUTE;
    private const PUBLIC_ASSET_EXTENSIONS = ['css', 'ico', 'jpeg', 'jpg', 'js', 'png', 'svg', 'woff2'];
    // Só texto, e só o que passa pelo `staticFile()`: as imagens e o `woff2` já vêm
    // comprimidos, e passá-los por gzip gasta CPU para não poupar fio nenhum. A página fica
    // de fora porque é o `html()` que a serve, e não este caminho.
    private const COMPRESSIBLE_EXTENSIONS = ['css', 'js', 'svg'];
    private ApiKernel $apiKernel;
    /** @var array<string, string> */
    private array $assetCache = [];
    // Não é promovida: com um valor por omissão declarado, uma instância criada por
    // `newInstanceWithoutConstructor()` -- como fazem os testes dos recursos estáticos --
    // continua a lê-la sem fatal, e o `page()` dispensa o `isset()`.
    private bool $apiAuthRequired = true;
    /* Com valor por omissão e não promovida no construtor: a página desenha-se sem o servidor
     * montado -- é o que o teste dos componentes faz -- e uma propriedade promovida ficaria
     * por inicializar nesse caminho. */
    private int $downlinkQueueTtlSeconds = 300;
    /* Com valor por omissão pela mesma razão das duas acima: a página desenha-se sem o
     * servidor montado. Vazia, o amCharts desenha o logótipo dele em cada gráfico. */
    private string $amchartsLicense = '';

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
        // A mesma instância que o processo do hub monta. Quando falta, monta-se uma daqui: a
        // sincronização só acontece quando alguém carrega no botão, e até lá não custa nada.
        ?RadarLayoutSync $radarLayoutSync = null,
        string $amchartsLicense = '',
    ) {
        $this->amchartsLicense = $amchartsLicense;
        $radarLayoutSync ??= new RadarLayoutSync(
            new QinglanstApiClient(new Browser()),
            new LayoutParser(),
            $this->db->radarLayouts,
            $this->db->radarCredentials,
            $this->db->whitelist,
        );
        $this->apiAuthRequired = $apiAuthRequired;
        $this->downlinkQueueTtlSeconds = $hub->downlinkQueueTtlSeconds();

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
            new RadarCredentialsService($this->db),
            new RadarLayoutService($this->db, $radarLayoutSync),
            new ProtocolService(),
            new DashboardNotificationService($this->db),
            new \Hub\Api\Services\DenylistService($this->db),
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

    /** Uma rota que espera por um serviço de terceiros devolve a promessa, e o React drena-a. */
    public function __invoke(ServerRequestInterface $request): Response|PromiseInterface
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
        // A página é quem diz que versão dos módulos carregar, e por isso é a única peça que
        // não pode ficar guardada: guardada, apontava para a versão anterior e o deploy não
        // chegava a quem já lá tinha estado. Dizê-lo é preciso -- sem cabeçalho nenhum, a
        // decisão fica ao critério de quem estiver pelo meio.
        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'],
            $body
        );
    }

    private function page(): string
    {
        $dashboardApiAuthRequired = $this->apiAuthRequired;
        $downlinkQueueTtlSeconds = $this->downlinkQueueTtlSeconds;
        $amchartsLicense = $this->amchartsLicense;
        $assetVersion = $this->assetVersion();

        ob_start();
        require __DIR__ . '/index.php';
        return (string) ob_get_clean();
    }

    /**
     * A impressão digital do conjunto de ficheiros que servimos com `no-cache`.
     *
     * Vai no caminho, e não numa etiqueta de revalidação, porque pelo meio pode estar quem
     * não obedeça ao `no-cache` -- a Cloudflare à frente do hub reescreve-o para quatro horas
     * de cache no browser. Com o conjunto no URL, um deploy muda todos os endereços de uma
     * vez e nenhuma cópia velha chega a ser pedida; sem isso, um arranque pode misturar
     * módulos de duas versões, e um módulo velho não conhece nem os caminhos nem os
     * descritores da nova.
     *
     * Não se guarda entre pedidos: o processo é longo, e um ficheiro alterado por baixo dele
     * -- o que acontece a cada gravação no hub local -- tem de mudar a versão logo, ou o
     * `immutable` que a acompanha prendia o browser à cópia antiga.
     */
    private function assetVersion(): string
    {
        $parts = array_map(
            static fn(string $path): string => self::assetFingerprint(__DIR__ . '/' . $path),
            ['main.js', 'main.css', 'dashboard', 'assets/css', 'assets/js'],
        );

        return hash('xxh128', implode('|', $parts));
    }

    /** O caminho, a data e o tamanho de cada ficheiro: muda ao acrescentar, apagar ou alterar. */
    private static function assetFingerprint(string $root): string
    {
        if (is_file($root)) {
            return sprintf('%x-%x', (int)filemtime($root), (int)filesize($root));
        }
        if (!is_dir($root)) {
            return '';
        }

        $entries = [];
        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($tree as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $entries[] = sprintf(
                '%s|%x|%x',
                substr($file->getPathname(), strlen($root)),
                $file->getMTime(),
                $file->getSize(),
            );
        }
        sort($entries);

        return hash('xxh128', implode("\n", $entries));
    }

    /** Um caminho com impressão digital serve o mesmo ficheiro que o caminho nu. */
    private static function withoutAssetVersion(string $requestPath): string
    {
        return (string)preg_replace('#^/v/[0-9a-f]{6,64}/#', '/', $requestPath, 1);
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

        // Quem não anuncia `gzip` recebe os bytes tal e qual. O `Vary` vai sempre, mesmo em
        // cru: sem ele uma cache partilhada serve a variante errada ao pedido seguinte.
        $gzip = in_array($ext, self::COMPRESSIBLE_EXTENSIONS, true)
            && str_contains(strtolower($request->getHeaderLine('Accept-Encoding')), 'gzip');
        $encoding = $gzip
            ? ['Content-Encoding' => 'gzip', 'Vary' => 'Accept-Encoding']
            : ['Vary' => 'Accept-Encoding'];

        // O corpo comprimido é outro corpo, e por isso leva sufixo no ETag: partilhar a
        // etiqueta entregava a variante errada a quem revalidasse com a outra.
        //
        // A etiqueta calcula-se sempre, mesmo onde não vai no cabeçalho: é ela que indexa a
        // cache do corpo, e sem isso um ficheiro alterado debaixo do processo -- cada gravação
        // no hub local -- ficaria a servir os bytes velhos do endereço novo.
        $etag = sprintf('"%x-%x%s"', (int)filemtime($path), (int)filesize($path), $gzip ? '-gz' : '');

        // Guarda-se para sempre o que não pode mudar debaixo do URL por onde foi pedido: os
        // recursos de terceiros, cujo caminho muda quando eles mudam, e o que a página pediu
        // com a impressão digital do conjunto. O resto revalida pelo ETag.
        $requestPath = $request->getUri()->getPath();
        $fingerprinted = $requestPath !== self::withoutAssetVersion($requestPath);
        if ($fingerprinted || str_contains($path, '/assets/vendor/') || str_contains($path, '/assets/fonts/')) {
            return new Response(
                200,
                ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=31536000, immutable'] + $encoding,
                $this->assetContents($path, $path . $etag, $gzip)
            );
        }

        $headers = ['Content-Type' => $mime, 'Cache-Control' => 'no-cache', 'ETag' => $etag] + $encoding;
        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return new Response(304, ['Cache-Control' => 'no-cache', 'ETag' => $etag] + $encoding);
        }

        // A cache do corpo é indexada pelo ETag: um ficheiro alterado debaixo do processo muda
        // o ETag e o corpo servido acompanha-o, em vez de ficar preso aos bytes velhos.
        return new Response(200, $headers, $this->assetContents($path, $path . $etag, $gzip));
    }

    private function assetContents(string $path, ?string $cacheKey = null, bool $gzip = false): string
    {
        return $this->assetCache[$cacheKey ?? $path] ??= $gzip
            ? (string)gzencode((string)file_get_contents($path), 6)
            : (string)file_get_contents($path);
    }

    private function publicAssetPath(string $requestPath): ?string
    {
        $requestPath = rawurldecode($requestPath);
        if (str_contains($requestPath, "\0") || str_contains($requestPath, '\\')) {
            return null;
        }

        // A versão é um endereço e não uma pasta: tira-se aqui, antes das rotas, e o que
        // sobra passa pelas mesmas verificações de sempre.
        $requestPath = self::withoutAssetVersion($requestPath);

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
        $path = ModelImageStore::pathFor($filename);
        if (!is_file($path)) {
            return $this->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }
        return new Response(200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=31536000, immutable'], (string)file_get_contents($path));
    }
}
