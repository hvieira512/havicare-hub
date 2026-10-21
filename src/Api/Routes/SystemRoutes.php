<?php

declare(strict_types=1);

use Hub\Api\Routing\ApiRoute;
use Hub\Api\OpenApiSpec;
use React\Http\Message\Response;

return static function (callable $json): array {
    return [
        new ApiRoute('GET', '/api/openapi.json', fn(): Response => $json(OpenApiSpec::get())),
        new ApiRoute('GET', '/api/docs', fn(): Response => new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>API Docs</title>
  <link rel="stylesheet" href="/assets/vendor/swagger-ui/swagger-ui.css">
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="/assets/vendor/swagger-ui/swagger-ui-bundle.js"></script>
  <script>
    SwaggerUIBundle({
      url: "/api/openapi.json",
      dom_id: "#swagger-ui",
      persistAuthorization: true
    });
  </script>
</body>
</html>')),
    ];
};
