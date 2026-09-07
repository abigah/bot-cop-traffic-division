<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $undone ? 'Notifications resumed' : 'Notifications muted' }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            background: #f6f7f9; color: #1a1d21; padding: 2rem;
        }
        .card {
            background: #fff; border-radius: 12px; padding: 2rem; max-width: 32rem;
            box-shadow: 0 1px 3px rgb(0 0 0 / 0.08), 0 8px 24px rgb(0 0 0 / 0.06);
        }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1rem; color: #4a5057; }
        .url { font-weight: 600; color: #1a1d21; overflow-wrap: anywhere; }
        a.button {
            display: inline-block; padding: .55rem 1rem; border-radius: 8px;
            background: #1a1d21; color: #fff; text-decoration: none; font-size: .9375rem;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #17191c; color: #e8eaed; }
            .card { background: #1f2225; box-shadow: none; }
            p { color: #a8aeb5; }
            .url { color: #e8eaed; }
            a.button { background: #e8eaed; color: #17191c; }
        }
    </style>
</head>
<body>
    <div class="card">
        @if ($undone)
            <h1>Notifications resumed</h1>
            <p>
                You will hear about this outage on
                <span class="url">{{ $incident->monitor?->url }}</span> again.
            </p>
        @else
            <h1>Notifications muted</h1>
            <p>
                You will not hear about this outage on
                <span class="url">{{ $incident->monitor?->url }}</span> again.
            </p>
            <p>
                This covers every channel, and only this outage — notifications
                resume by themselves once it is resolved.
            </p>
        @endif

        <a class="button" href="{{ $toggleUrl }}">
            {{ $undone ? 'Mute again' : 'Undo' }}
        </a>
    </div>
</body>
</html>
