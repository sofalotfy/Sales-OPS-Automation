<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('document_title', config('app.name'))</title>
    <style>
        :root {
            --bg: #f4f6f9;
            --card: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --accent: #0f6bff;
            --border: #e5e7eb;
            --good: #0f766e;
            --warn: #b45309;
            --bad: #b91c1c;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--ink);
        }
        main { max-width: 640px; margin: 56px auto; padding: 0 16px 64px; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px 28px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .06);
        }
        h1 { font-size: 1.5rem; margin: 0 0 6px; letter-spacing: -.01em; }
        h2 { font-size: 1rem; margin: 0 0 10px; }
        .sub { color: var(--muted); margin: 0 0 24px; font-size: .95rem; }
        .badge {
            display: inline-block;
            margin-bottom: 16px;
            padding: 4px 10px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: #f8fafc;
            color: var(--muted);
            font-size: .68rem;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .hint { margin: 6px 0 0; font-size: .8rem; color: var(--muted); }
        label { display: block; font-size: .85rem; font-weight: 600; margin: 14px 0 6px; }
        label small { font-weight: 400; color: var(--muted); }
        input, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font: inherit;
        }
        input:focus, textarea:focus { outline: 2px solid rgba(15, 107, 255, .35); border-color: var(--accent); }
        textarea { min-height: 120px; resize: vertical; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        button { font: inherit; cursor: pointer; }
        button.submit {
            margin-top: 20px;
            width: 100%;
            padding: 12px;
            border: 0;
            border-radius: 10px;
            background: var(--accent);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
        }
        button.submit:disabled { opacity: .6; cursor: wait; }
        a.cta {
            display: inline-block;
            margin-top: 14px;
            padding: 10px 20px;
            border-radius: 10px;
            background: var(--accent);
            color: #fff;
            font-size: .9rem;
            font-weight: 600;
            text-decoration: none;
        }
        button.link {
            margin-top: 14px;
            padding: 0;
            border: 0;
            background: none;
            color: var(--muted);
            font-size: .85rem;
            text-decoration: underline;
        }
        .status { margin-top: 12px; font-size: .9rem; color: var(--muted); }
        .status.error { color: var(--bad); }
        .examples { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border); }
        .examples-title { margin: 0 0 10px; font-size: .78rem; color: var(--muted); }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        button.chip {
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: #fff;
            color: var(--ink);
            font-size: .8rem;
        }
        button.chip:hover { border-color: var(--accent); color: var(--accent); }

        /* Response card: what the visitor is shown once triage resolves. */
        .reply {
            margin: 18px 0 0;
            padding: 18px 20px;
            border: 1px solid var(--border);
            border-left: 3px solid var(--accent);
            border-radius: 12px;
            background: #f8fafc;
            font-size: 1rem;
            line-height: 1.6;
        }
        .reply a { color: var(--accent); }
        .stages { list-style: none; margin: 18px 0 0; padding: 0; }
        .stages li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 0;
            border-bottom: 1px solid var(--border);
            font-size: .88rem;
            color: var(--muted);
        }
        .stages li:last-child { border-bottom: 0; }
        .stages .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--border); flex: none; }
        .stages li.done { color: var(--ink); }
        .stages li.done .dot { background: var(--good); }
        .stages li.active { color: var(--accent); font-weight: 600; }
        .stages li.active .dot { background: var(--accent); animation: pulse 1.2s ease-in-out infinite; }
        .stages li.failed { color: var(--bad); font-weight: 600; }
        .stages li.failed .dot { background: var(--bad); }
        @keyframes pulse { 50% { opacity: .25; } }

        details.run { margin-top: 22px; border-top: 1px solid var(--border); padding-top: 16px; }
        details.run summary { cursor: pointer; font-size: .85rem; color: var(--muted); }
        .kv { display: flex; flex-wrap: wrap; gap: 8px 18px; margin: 16px 0 0; font-size: .85rem; }
        .kv div { color: var(--muted); }
        .kv b { color: var(--ink); font-weight: 600; }
        .verdict { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: .78rem; font-weight: 600; }
        .verdict.high { background: #dcfce7; color: #166534; }
        .verdict.medium { background: #dbeafe; color: #1d4ed8; }
        .verdict.low { background: #fef3c7; color: #92400e; }
        .verdict.disqualify { background: #fee2e2; color: #991b1b; }
        .factor { margin-top: 18px; font-size: .85rem; }
        .factor h3 { margin: 0 0 8px; font-size: .78rem; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
        .factor table { width: 100%; border-collapse: collapse; }
        .factor th, .factor td { padding: 6px 8px 6px 0; text-align: left; vertical-align: top; border-bottom: 1px solid var(--border); }
        .factor th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
        .factor td.num { white-space: nowrap; }
        .factor p { margin: 14px 0 0; color: var(--ink); line-height: 1.5; }
        pre {
            margin: 18px 0 0;
            padding: 14px;
            max-height: 320px;
            overflow: auto;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 10px;
            font-size: .75rem;
            line-height: 1.5;
        }
    </style>
</head>
<body>
<main>
    <div class="card">
        @hasSection('badge')
            <span class="badge">@yield('badge')</span>
        @endif
        <h1>@yield('title')</h1>
        <p class="sub">@yield('subtitle', 'Tell us what you are looking for — we will point you the right way.')</p>
        @yield('content')
    </div>
</main>
@stack('scripts')
</body>
</html>
