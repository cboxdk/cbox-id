{{--
    The deployment booted and is not configured.

    Deliberately a plain page with no layout: the layouts resolve branding, which resolves
    the environment, which is exactly the machinery that cannot start yet. A page that
    needed the thing it is reporting missing would render nothing at all — which is what
    happened before this file existed.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cbox ID — not configured</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
        }
        main { max-width: 34rem; }
        h1 { font-size: 1.35rem; margin: 0 0 .6em; }
        p { margin: 0 0 1em; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .86em; }
        pre {
            padding: .8em 1em; border-radius: 8px; overflow-x: auto;
            background: color-mix(in srgb, currentColor 7%, transparent);
        }
        .reason { color: color-mix(in srgb, currentColor 65%, transparent); }
    </style>
</head>
<body>
    <main>
        <h1>This Cbox ID deployment is not configured yet</h1>

        <p class="reason">{{ $reason }}</p>

        <p>
            Cbox ID seals every secret it stores — client secrets, connection credentials,
            TOTP keys — under one master key. It will not invent one at boot, because a key
            invented at boot is a key lost at the next restart, taking everything sealed
            under it.
        </p>

        <p>Set it, and keep it somewhere you will still have it next year:</p>

        <pre>CBOX_ID_CRYPTO_KEY={{ $suggested ?? 'base64:'.base64_encode(random_bytes(32)) }}</pre>

        <p>
            Or let the installer generate and write one for you, which also creates the
            first operator, environment and organization:
        </p>

        <pre>php artisan cbox-id:install</pre>

        <p>
            <code>php artisan cbox-id:doctor</code> checks the rest of the configuration
            and names anything else still missing.
        </p>
    </main>
</body>
</html>
