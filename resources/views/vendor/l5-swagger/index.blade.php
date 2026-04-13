<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>L5 Swagger UI</title>

    <link rel="stylesheet" type="text/css" href="{{ l5_swagger_asset('default', 'swagger-ui.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ l5_swagger_asset('default', 'swagger-ui.css') }}">

    <style>
        body {
            margin: 0;
            background: #fafafa;
        }
    </style>
</head>

<body>

<div id="swagger-ui"></div>

<script src="{{ l5_swagger_asset('default', 'swagger-ui-bundle.js') }}"></script>
<script src="{{ l5_swagger_asset('default', 'swagger-ui-standalone-preset.js') }}"></script>

<script>
    window.onload = function() {
        const ui = SwaggerUIBundle({
            url: "{{ route('l5-swagger.default.docs', ['format' => config('l5-swagger.defaults.paths.format_to_use_for_docs')]) }}",
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset
            ],
            layout: "StandaloneLayout"
        });

        window.ui = ui;
    };
</script>

</body>
</html>
