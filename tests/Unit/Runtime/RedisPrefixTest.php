<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Runtime\HubServices;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * O prefixo das chaves do Redis permite dois hubs no mesmo servidor, e vai no cliente para
 * nenhum store nascer sem ele.
 */
final class RedisPrefixTest extends TestCase
{
    /** Vazio é produção: as chaves têm de ficar exactamente onde sempre estiveram. */
    public function testAnEmptyPrefixDeclaresNoOptionAtAll(): void
    {
        self::assertSame([], HubServices::redisOptions(['prefix' => '']));
        self::assertSame([], HubServices::redisOptions([]), 'ausente é o mesmo que vazio');
        self::assertSame([], HubServices::redisOptions(['prefix' => '   ']), 'só espaços também');
    }

    public function testAPrefixBecomesAPredisOption(): void
    {
        self::assertSame(['prefix' => 'dev:'], HubServices::redisOptions(['prefix' => 'dev:']));
    }

    /**
     * O prefixo apanha as chaves de todos os stores. Não fala com o Redis: olha para o argumento
     * que o cliente poria no fio.
     *
     * @dataProvider hubKeyspaces
     */
    public function testEveryHubKeyspaceGetsThePrefix(string $key): void
    {
        $client = new RedisClient([], HubServices::redisOptions(['prefix' => 'dev:']));

        $command = $client->createCommand('get', [$key]);

        self::assertSame('dev:' . $key, $command->getArgument(0));
    }

    /**
     * As raízes que o hub usa, uma por store; o
     * `testTheKeyspaceListCoversEveryRootDeclaredInTheCode` compara-as com o código.
     *
     * @return array<string, array{string}>
     */
    public static function hubKeyspaces(): array
    {
        return [
            'dashboard' => ['hub:dashboard:861265061009822:telemetry'],
            'api tokens' => ['hub:api-tokens:abc123'],
            'downlink' => ['hub:downlink:861265061009822'],
            'moko' => ['hub:moko:d48c49f7909c'],
            'location circuit' => ['hub:location:circuit:beacondb'],
            'location resolution' => ['hub:location:resolution:861265061009822'],
            'login throttle' => ['hub:login-throttle:ip:203.0.113.9:29123456'],
            'firmware upgrade' => ['hub:firmware-upgrade:869243062262262'],
        ];
    }

    /** Sem prefixo, a chave sai intacta: o cliente sem a opção não tem processador. */
    public function testWithoutAPrefixTheKeyIsUntouched(): void
    {
        $client = new RedisClient([], HubServices::redisOptions(['prefix' => '']));

        self::assertNull($client->getOptions()->prefix);
    }

    /**
     * Quem constrói o cliente tem de lhe dar as opções, senão escreve na raiz da produção. Os
     * testes ficam de fora de propósito: podem querer o seu próprio espaço de chaves.
     */
    public function testEveryClientBuiltByTheApplicationReceivesTheOptions(): void
    {
        $offenders = [];
        foreach (self::phpFilesIn(['src', 'bin', 'simulator']) as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (!str_contains($line, 'new RedisClient(') && !str_contains($line, 'new Client(')) {
                    continue;
                }
                // A construção pode ocupar várias linhas: o que interessa é haver uma segunda
                // expressão antes do fecho, e o `redisOptions` é a única fonte legítima dela.
                $tail = implode('', array_slice(file($file) ?: [], $number, 12));
                if (!str_contains($tail, 'redisOptions') && !str_contains($line, 'redisOptions')) {
                    $offenders[] = self::relative($file) . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'cliente Redis construído sem as opções do prefixo');
    }

    /** A lista acima é escrita à mão, e isto compara-a com o que o código declara. */
    public function testTheKeyspaceListCoversEveryRootDeclaredInTheCode(): void
    {
        $declared = [];
        foreach (self::phpFilesIn(['src']) as $file) {
            preg_match_all("/'(hub:[a-z-]+)/", (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $root) {
                $declared[$root] = true;
            }
        }

        $covered = [];
        foreach (self::hubKeyspaces() as [$key]) {
            preg_match("/^(hub:[a-z-]+)/", $key, $match);
            $covered[$match[1] ?? $key] = true;
        }

        self::assertSame(
            [],
            array_keys(array_diff_key($declared, $covered)),
            'raízes de chaves que o código usa e o teste não cobre'
        );
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private static function phpFilesIn(array $directories): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private static function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 3) . '/', '', $path);
    }
}
