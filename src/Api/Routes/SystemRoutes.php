<?php

use Hub\Api\Routing\ApiRoute;
use Hub\Http\OpenApiSpec;
use React\Http\Message\Response;

return static function (
    callable $json,
    callable $html
): array {
    return [
        new ApiRoute('GET', '/api/openapi.json', fn(): Response => $json(OpenApiSpec::get())),
        new ApiRoute('GET', '/api/docs', fn(): Response => $html('<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>API Docs</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
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
