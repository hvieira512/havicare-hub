<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\OpenApiSpec;
use PHPUnit\Framework\TestCase;

/**
 * The spec is hand-maintained, so nothing stops it drifting from the routes it
 * documents. This pins the two together: a new route is undocumented until it
 * appears in the spec, and a removed one cannot linger there.
 */
final class OpenApiSpecRoutesTest extends TestCase
{
    private const ROUTES_DIR = __DIR__ . '/../../../src/Api/Routes';

    public function testEveryRegisteredRouteIsDocumentedAndViceVersa(): void
    {
        $registered = $this->registeredPaths();
        $documented = $this->documentedPaths();

        self::assertSame(
            [],
            array_values(array_diff($registered, $documented)),
            'routes that exist but are missing from the OpenAPI spec'
        );
        self::assertSame(
            [],
            array_values(array_diff($documented, $registered)),
            'paths documented in the OpenAPI spec that no route serves'
        );
    }

    /** @return list<string> */
    private function registeredPaths(): array
    {
        $paths = [];
        foreach (glob(self::ROUTES_DIR . '/*.php') ?: [] as $file) {
            preg_match_all(
                "/new ApiRoute\(\s*'[A-Z]+'\s*,\s*'([^']+)'/",
                (string)file_get_contents($file),
                $matches
            );
            foreach ($matches[1] as $pattern) {
                // '/api/models/{id:\d+}' and '/api/models/{id}' are the same
                // path as far as the spec is concerned.
                $paths[] = preg_replace('/\{([A-Za-z]+):[^}]*\}/', '{$1}', $pattern);
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        // If the pattern above ever stops matching, the diffs would both be
        // empty and the test would pass while checking nothing.
        self::assertGreaterThan(20, count($paths), 'route extraction found suspiciously few routes');

        return $paths;
    }

    /** @return list<string> */
    private function documentedPaths(): array
    {
        $paths = array_keys(OpenApiSpec::get()['paths'] ?? []);
        sort($paths);

        return array_map(strval(...), $paths);
    }
}
