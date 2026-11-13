{{--
    Shared by the connector's approval and refusal screens. Self-contained on
    purpose, like the screens themselves: see authorize.blade.php.
--}}
    <style>
        :root {
            --bg: #f4f4f5;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e4e4e7;
            --panel: #f9fafb;
            --accent: #00a859;
            --accent-text: #ffffff;
            --warn: #7a5b00;
            --warn-bg: #fff8e1;
            --warn-border: #ffc400;
        }

        html.dark {
            --bg: #09090b;
            --card: #18181b;
            --text: #f4f4f5;
            --muted: #a1a1aa;
            --border: #2f2f35;
            --panel: #202024;
            --accent: #00a859;
            --accent-text: #ffffff;
            --warn: #ffd75e;
            --warn-bg: #2a2410;
            --warn-border: #7a5b00;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text);
        }

        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .card {
            width: 100%;
            max-width: 27rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .08), 0 8px 24px rgba(0, 0, 0, .06);
            overflow: hidden;
        }

        .card-body { padding: 1.5rem; }

        .icon-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .icon-wrap svg {
            width: 2.75rem;
            height: 2.75rem;
            color: var(--accent);
            fill: none;
        }

        h1 {
            margin: 0 0 .5rem;
            font-size: 1.4rem;
            font-weight: 600;
            text-align: center;
            line-height: 1.3;
        }

        .lede {
            margin: 0;
            text-align: center;
            font-size: .875rem;
            color: var(--muted);
            line-height: 1.5;
        }

        .panel {
            margin-top: 1.25rem;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: .5rem;
            padding: .875rem 1rem;
        }

        .panel-label {
            margin: 0 0 .25rem;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--muted);
        }

        .panel-value {
            margin: 0;
            font-size: .9rem;
            font-weight: 500;
            word-break: break-all;
        }

        .scopes {
            list-style: none;
            margin: .5rem 0 0;
            padding: 0;
        }

        .scopes li {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
            font-size: .875rem;
            color: var(--muted);
            padding: .2rem 0;
        }

        .dot {
            flex: 0 0 auto;
            width: .4rem;
            height: .4rem;
            margin-top: .45rem;
            border-radius: 999px;
            background: var(--accent);
        }

        .notice {
            margin-top: 1.25rem;
            background: var(--warn-bg);
            border: 1px solid var(--warn-border);
            border-radius: .5rem;
            padding: .75rem .875rem;
            font-size: .8125rem;
            line-height: 1.45;
            color: var(--warn);
        }

        .actions {
            display: flex;
            gap: .75rem;
            padding: 0 1.5rem 1.5rem;
        }

        .actions form { flex: 1 1 0; margin: 0; }

        button {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            height: 2.6rem;
            padding: 0 1rem;
            border-radius: .5rem;
            font-family: inherit;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s ease, background-color .15s ease;
        }

        button:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .btn-approve {
            background: var(--accent);
            color: var(--accent-text);
            border: 1px solid var(--accent);
        }

        .btn-approve:hover { opacity: .9; }
        .btn-approve[disabled] { opacity: .6; cursor: default; }

        .btn-deny {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-deny:hover { background: var(--panel); }

        .spinner {
            width: 1rem;
            height: 1rem;
            animation: spin 1s linear infinite;
        }

        .hidden { display: none; }

        @keyframes spin { to { transform: rotate(360deg); } }
        .given {
            margin: -.25rem 0 .75rem;
            text-align: center;
            font-size: .75rem;
            color: var(--muted);
        }

        .dot--never { background: var(--muted); }

        .refusal {
            margin-top: 1.25rem;
            background: var(--warn-bg);
            border: 1px solid var(--warn-border);
            border-radius: .5rem;
            padding: .875rem 1rem;
            font-size: .875rem;
            line-height: 1.5;
            color: var(--warn);
        }
    </style>
