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
        .hint { color: var(--muted); font-size: .78rem; margin: 4px 0 0; }
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
        .result { margin-top: 20px; border-top: 1px solid var(--border); padding-top: 16px; }
        .result .tag { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .result .reply { margin-top: 6px; font-size: 1rem; }
        .retrieved { margin-top: 20px; border-top: 1px dashed var(--border); padding-top: 14px; }
        .retrieved-title { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .retrieved-title span { font-weight: 400; text-transform: none; letter-spacing: 0; }
        .retrieved ul { list-style: none; margin: 10px 0 0; padding: 0; display: grid; gap: 10px; }
        .retrieved-item { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; background: var(--bg); }
        .retrieved-head { font-size: .85rem; font-weight: 600; }
        .retrieved-source { font-size: .75rem; color: var(--muted); margin-top: 2px; }
        .retrieved-text { font-size: .82rem; line-height: 1.45; margin: 6px 0 0; color: #374151; }
        .prompt-text { font-size: .82rem; line-height: 1.55; margin: 10px 0 0; padding: 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; white-space: pre-wrap; word-break: break-word; color: #374151; }
        .error { color: #b91c1c; margin-top: 12px; font-size: .9rem; }
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