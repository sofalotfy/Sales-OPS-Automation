<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <style>
        :root {
            --bg: #f4f6f9;
            --card: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --accent: #0f6bff;
            --border: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--ink);
        }
        main { max-width: 560px; margin: 64px auto; padding: 0 16px; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px 28px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .06);
        }
        h1 { font-size: 1.4rem; margin: 0 0 6px; }
        .sub { color: var(--muted); margin: 0 0 24px; font-size: .95rem; }
        label { display: block; font-size: .85rem; font-weight: 600; margin: 14px 0 6px; }
        input, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font: inherit;
        }
        textarea { min-height: 120px; resize: vertical; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        button {
            margin-top: 20px;
            width: 100%;
            padding: 12px;
            border: 0;
            border-radius: 10px;
            background: var(--accent);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        button:disabled { opacity: .6; cursor: wait; }
        .status { margin-top: 12px; font-size: .9rem; color: var(--muted); }
        .status.error { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>@yield('title')</h1>
        <p class="sub">@yield('subtitle', 'Tell us what you are looking for — we will point you the right way.')</p>
        @yield('content')
    </div>
</main>
@stack('scripts')
</body>
</html>