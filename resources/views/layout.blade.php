<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('dbReplicator::dbReplicator.importDatabases') }}</title>
    <style>
        body { font: 1rem/1.6 system-ui, sans-serif; margin: 0; color: #17263c; background: #f3f5f8; }
        main { max-width: 48rem; margin: 4rem auto; padding: 2rem; background: white; border: 1px solid #d4dce7; border-radius: .75rem; }
        h1 { font-size: 1.6rem; line-height: 1.3; } dt { font-weight: 600; } dd { margin: 0 0 1rem; overflow-wrap: anywhere; }
        .notice { padding: 1rem; border-left: 4px solid #a76014; background: #fff5e6; }
        button { padding: .9rem 1.2rem; background: #a52d23; color: white; border: 0; border-radius: .4rem; font: inherit; cursor: pointer; }
        button:disabled { opacity: .65; cursor: wait; } code { overflow-wrap: anywhere; }
        @media (max-width: 52rem) { main { margin: 1rem; padding: 1.25rem; } }
    </style>
</head>
<body><main>@yield('content')</main></body>
</html>
