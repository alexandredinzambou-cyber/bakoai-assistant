<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>PVIT Assistant</title>
    <style>
        :root {
            --bg: #ffffff;
            --sidebar: #f9f9f9;
            --sidebar-hover: #ececec;
            --sidebar-active: #e3e3e3;
            --surface: #ffffff;
            --bubble: #f4f4f4;
            --ink: #0d0d0d;
            --ink-dim: #5d5d5d;
            --ink-faint: #8f8f8f;
            --line: rgba(0, 0, 0, .1);
            --line-strong: rgba(0, 0, 0, .18);
            --composer-shadow: 0 4px 16px rgba(0, 0, 0, .05), 0 0 1px rgba(0, 0, 0, .25);
            --send-bg: #0d0d0d;
            --send-ink: #ffffff;
            --code-bg: #f9f9f9;
            --code-head: #ececec;
            --inline-code: #ececec;
            --accent: #e4571e;
            --danger: #d12d2d;
            --error-bg: #fdecec;
            --scrim: rgba(0, 0, 0, .3);
            --sans: ui-sans-serif, -apple-system, system-ui, "Segoe UI", Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji";
            --mono: ui-monospace, "SF Mono", "Cascadia Code", Consolas, "Roboto Mono", monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #212121;
                --sidebar: #171717;
                --sidebar-hover: #212121;
                --sidebar-active: #2f2f2f;
                --surface: #303030;
                --bubble: #303030;
                --ink: #ececec;
                --ink-dim: #b4b4b4;
                --ink-faint: #8f8f8f;
                --line: rgba(255, 255, 255, .1);
                --line-strong: rgba(255, 255, 255, .2);
                --composer-shadow: 0 0 0 1px rgba(255, 255, 255, .06);
                --send-bg: #ececec;
                --send-ink: #0d0d0d;
                --code-bg: #171717;
                --code-head: #2f2f2f;
                --inline-code: #424242;
                --danger: #f26b6b;
                --error-bg: #3a2222;
                --scrim: rgba(0, 0, 0, .55);
            }
        }

        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            overflow: hidden;
            color: var(--ink);
            background: var(--bg);
            font: 16px/1.6 var(--sans);
            -webkit-font-smoothing: antialiased;
        }

        button, input, select, textarea { font: inherit; color: inherit; }
        button { cursor: pointer; }

        .icon-btn {
            display: inline-grid;
            place-items: center;
            width: 36px;
            height: 36px;
            padding: 0;
            border: 0;
            border-radius: 8px;
            color: var(--ink-dim);
            background: transparent;
        }
        .icon-btn:hover { background: var(--sidebar-hover); color: var(--ink); }
        .icon-btn:focus-visible, .chat-item:focus-visible, .side-action:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }

        .layout { display: flex; height: 100dvh; }

        /* ---------- barre latérale ---------- */

        .sidebar {
            flex: 0 0 260px;
            width: 260px;
            display: flex;
            flex-direction: column;
            background: var(--sidebar);
            border-right: 1px solid var(--line);
            transition: margin-left .2s ease;
            z-index: 30;
        }
        .layout.sidebar-closed .sidebar { margin-left: -260px; }

        .sidebar-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 8px 4px 12px;
            height: 56px;
        }
        .sidebar-logo { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 15px; }
        .sidebar-logo img { width: 28px; height: 28px; border-radius: 8px; object-fit: cover; }

        .side-actions { padding: 4px 8px; display: grid; gap: 2px; }
        .side-action {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 8px 10px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            font-size: 14px;
            text-align: left;
            text-decoration: none;
            color: inherit;
        }
        .side-action:hover { background: var(--sidebar-hover); }
        .side-action kbd { margin-left: auto; font: 12px var(--sans); color: var(--ink-faint); visibility: hidden; }
        .side-action:hover kbd { visibility: visible; }

        .search-box {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            padding: 8px 10px;
            border-radius: 8px;
            color: var(--ink-dim);
        }
        .search-box:focus-within { background: var(--sidebar-hover); }
        .search-box input {
            flex: 1;
            min-width: 0;
            border: 0;
            outline: 0;
            background: transparent;
            font-size: 14px;
            color: var(--ink);
        }
        .search-box input::placeholder { color: var(--ink); }

        .history { flex: 1; overflow-y: auto; padding: 8px 8px 16px; }
        .history-group { margin-top: 16px; }
        .history-label {
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            color: var(--ink-faint);
        }
        .history-empty { padding: 12px 10px; font-size: 13px; color: var(--ink-faint); }

        .chat-item {
            position: relative;
            display: flex;
            align-items: center;
            border-radius: 8px;
            font-size: 14px;
        }
        .chat-item:hover, .chat-item.has-menu { background: var(--sidebar-hover); }
        .chat-item.active { background: var(--sidebar-active); }
        .chat-link {
            flex: 1;
            min-width: 0;
            padding: 8px 10px;
            border: 0;
            background: transparent;
            text-align: left;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .chat-item .pending-dot {
            width: 7px; height: 7px; margin-right: 4px; flex: none;
            border-radius: 50%; background: var(--accent);
            animation: pulse 1.2s ease-in-out infinite;
        }
        .chat-more {
            flex: none;
            width: 30px;
            height: 30px;
            margin-right: 4px;
            opacity: 0;
        }
        .chat-item:hover .chat-more, .chat-item.active .chat-more, .chat-item.has-menu .chat-more, .chat-more:focus-visible { opacity: 1; }
        .chat-more:hover { background: transparent; }
        .rename-input {
            flex: 1;
            min-width: 0;
            margin: 3px;
            padding: 5px 7px;
            border: 1px solid var(--accent);
            border-radius: 6px;
            outline: 0;
            background: var(--bg);
            font-size: 14px;
        }

        .menu {
            position: fixed;
            z-index: 60;
            min-width: 170px;
            padding: 6px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--surface);
            box-shadow: 0 8px 28px rgba(0, 0, 0, .14);
        }
        .menu button {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 8px 10px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            font-size: 14px;
            text-align: left;
        }
        .menu button:hover { background: var(--sidebar-hover); }
        .menu .danger { color: var(--danger); }

        .sidebar-foot {
            padding: 10px 12px 14px;
            border-top: 1px solid var(--line);
            font-size: 12px;
            line-height: 1.45;
            color: var(--ink-faint);
        }
        .sidebar-foot a { color: var(--ink-dim); }

        .scrim { display: none; }

        /* ---------- zone principale ---------- */

        .main {
            position: relative;
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            background: var(--bg);
        }

        .topbar {
            display: flex;
            align-items: center;
            gap: 4px;
            height: 56px;
            padding: 0 8px;
            flex: none;
        }
        .topbar .open-sidebar, .topbar .new-chat-top { display: none; }
        .layout.sidebar-closed .topbar .open-sidebar,
        .layout.sidebar-closed .topbar .new-chat-top { display: inline-grid; }
        .topbar-title {
            display: flex;
            align-items: baseline;
            gap: 8px;
            padding: 6px 10px;
            font-size: 18px;
            font-weight: 500;
        }
        .topbar-title small { font-size: 14px; color: var(--ink-faint); font-weight: 400; }

        .scroll { flex: 1; overflow-y: auto; }

        .thread {
            width: 100%;
            max-width: 768px;
            margin: 0 auto;
            padding: 8px 24px 32px;
        }

        .turn { margin: 0 0 28px; }
        .turn.user { display: flex; flex-direction: column; align-items: flex-end; }
        .user-bubble {
            max-width: 80%;
            padding: 10px 18px;
            border-radius: 22px;
            background: var(--bubble);
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .user-actions { display: flex; gap: 2px; margin-top: 4px; opacity: 0; transition: opacity .15s; }
        .turn.user > [hidden] { display: none; }
        .turn.user:hover .user-actions, .user-actions:focus-within { opacity: 1; }
        .user-actions .icon-btn { width: 32px; height: 32px; }
        .user-actions .icon-btn:disabled { opacity: .35; cursor: default; }
        @media (hover: none) { .user-actions { opacity: 1; } }

        .edit-box {
            width: 100%;
            padding: 12px 14px;
            border-radius: 22px;
            background: var(--bubble);
        }
        .edit-box textarea {
            width: 100%;
            min-height: 48px;
            max-height: 320px;
            padding: 2px 4px;
            border: 0;
            outline: 0;
            resize: none;
            background: transparent;
            line-height: 1.5;
        }
        .edit-note { margin: 4px 4px 0; font-size: 12.5px; color: var(--ink-dim); }
        .edit-buttons { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
        .edit-buttons button {
            padding: 7px 14px;
            border: 1px solid var(--line);
            border-radius: 999px;
            font-size: 14px;
            background: var(--bg);
        }
        .edit-buttons .primary { border-color: transparent; color: var(--send-ink); background: var(--send-bg); }
        .edit-buttons .primary:disabled { opacity: .3; cursor: default; }

        .attachment {
            margin-bottom: 6px;
        }
        .attachment img {
            display: block;
            max-width: 240px;
            max-height: 240px;
            border-radius: 14px;
            border: 1px solid var(--line);
        }
        .file-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px 8px 8px;
            border: 1px solid var(--line);
            border-radius: 14px;
            font-size: 14px;
            background: var(--bg);
        }
        .file-chip .file-icon {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            color: #fff;
            background: var(--accent);
        }

        .assistant-row { display: flex; gap: 14px; }
        .avatar {
            flex: none;
            width: 28px;
            height: 28px;
            margin-top: 2px;
            border-radius: 50%;
            border: 1px solid var(--line);
            object-fit: cover;
            background: #fff;
        }
        .assistant-body { flex: 1; min-width: 0; }

        .answer-text { overflow-wrap: anywhere; }
        .answer-text > *:first-child { margin-top: 0; }
        .answer-text > *:last-child { margin-bottom: 0; }
        .answer-text p { margin: 0 0 14px; }
        .answer-text h1, .answer-text h2, .answer-text h3, .answer-text h4 { margin: 22px 0 10px; line-height: 1.35; font-weight: 600; }
        .answer-text h1 { font-size: 22px; }
        .answer-text h2 { font-size: 19px; }
        .answer-text h3 { font-size: 17px; }
        .answer-text h4 { font-size: 16px; }
        .answer-text ul, .answer-text ol { margin: 0 0 14px; padding-left: 26px; }
        .answer-text li { margin: 4px 0; }
        .answer-text a { color: inherit; text-decoration: underline; text-decoration-color: var(--line-strong); text-underline-offset: 3px; }
        .answer-text a:hover { text-decoration-color: currentColor; }
        .answer-text blockquote { margin: 0 0 14px; padding-left: 14px; border-left: 3px solid var(--line-strong); color: var(--ink-dim); }
        .answer-text hr { border: 0; border-top: 1px solid var(--line); margin: 22px 0; }
        .answer-text code {
            padding: 2px 5px;
            border-radius: 5px;
            background: var(--inline-code);
            font: .875em var(--mono);
        }
        .code-block {
            margin: 0 0 16px;
            border-radius: 12px;
            overflow: hidden;
            background: var(--code-bg);
            border: 1px solid var(--line);
        }
        .code-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 8px 6px 14px;
            font-size: 12px;
            color: var(--ink-dim);
            background: var(--code-head);
        }
        .code-head button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 6px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            font-size: 12px;
            color: var(--ink-dim);
        }
        .code-head button:hover { color: var(--ink); }
        .answer-text pre { margin: 0; padding: 14px 16px; overflow-x: auto; }
        .answer-text pre code { padding: 0; background: none; font-size: 13.5px; line-height: 1.55; white-space: pre; }
        .table-wrap { margin: 0 0 16px; overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
        .answer-text table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .answer-text th, .answer-text td { padding: 8px 12px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        .answer-text tr:last-child td { border-bottom: 0; }
        .answer-text th { font-weight: 600; background: var(--sidebar); }

        .sources { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; }
        .source-chip {
            display: inline-block;
            max-width: 100%;
            padding: 4px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            font-size: 12.5px;
            color: var(--ink-dim);
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        a.source-chip:hover { color: var(--ink); background: var(--bubble); }
        .source-chip b { margin-right: 6px; font-weight: 600; color: var(--ink); }

        .actions { display: flex; align-items: center; gap: 2px; margin-top: 8px; margin-left: -8px; }
        .actions .icon-btn { width: 32px; height: 32px; }
        .actions .model-name { margin-left: 6px; font-size: 12px; color: var(--ink-faint); }

        .support-box {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            padding: 10px 12px 10px 14px;
            border: 1px solid var(--line);
            border-radius: 14px;
            font-size: 14px;
            color: var(--ink-dim);
        }
        .support-box .support-label { flex: 1; min-width: 180px; }
        .support-box .support-label b { color: var(--ink); font-weight: 600; }
        .ticket-button {
            padding: 7px 14px;
            border: 0;
            border-radius: 999px;
            font-size: 14px;
            color: var(--send-ink);
            background: var(--send-bg);
        }
        .ticket-button:disabled { opacity: .55; cursor: default; }
        .ticket-button.done { color: var(--ink); background: var(--bubble); opacity: 1; }

        .error-bubble {
            padding: 12px 16px;
            border-radius: 14px;
            color: var(--danger);
            background: var(--error-bg);
            white-space: pre-wrap;
        }

        .thinking { display: flex; align-items: center; gap: 10px; min-height: 30px; color: var(--ink-dim); }
        .thinking .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--ink); animation: pulse 1.2s ease-in-out infinite; }
        .thinking .label { font-size: 15px; transition: opacity .25s; }
        @keyframes pulse { 0%, 100% { transform: scale(.75); opacity: .55; } 50% { transform: scale(1); opacity: 1; } }

        /* ---------- accueil + saisie ---------- */

        .hero { display: none; text-align: center; padding: 0 24px; }
        .hero h1 { margin: 0 0 6px; font-size: 28px; font-weight: 500; letter-spacing: -.01em; }
        .hero p { margin: 0; color: var(--ink-dim); font-size: 15px; }

        .main.is-empty { justify-content: flex-start; }
        .main.is-empty .scroll { flex: 0 0 auto; overflow: visible; margin-top: calc(28vh - 56px); }
        .main.is-empty .hero { display: block; margin-bottom: 28px; }
        .main.is-empty .thread { display: none; }

        .composer-wrap {
            flex: none;
            width: 100%;
            max-width: 816px;
            margin: 0 auto;
            padding: 0 24px max(12px, env(safe-area-inset-bottom));
        }
        .composer {
            display: flex;
            flex-direction: column;
            padding: 10px 12px;
            border-radius: 28px;
            background: var(--surface);
            box-shadow: var(--composer-shadow);
            cursor: text;
        }
        .composer textarea {
            width: 100%;
            min-height: 28px;
            max-height: 220px;
            padding: 4px 8px;
            border: 0;
            outline: 0;
            resize: none;
            background: transparent;
            line-height: 1.5;
        }
        .composer textarea::placeholder { color: var(--ink-faint); }
        .composer-row { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
        .composer-row .spacer { flex: 1; }
        .pill-select {
            position: relative;
            display: inline-flex;
            align-items: center;
            height: 36px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--ink-dim);
            font-size: 14px;
        }
        .pill-select:hover { background: var(--sidebar-hover); }
        .pill-select svg { position: absolute; left: 11px; pointer-events: none; }
        .pill-select select {
            appearance: none;
            height: 100%;
            max-width: 210px;
            padding: 0 14px 0 32px;
            border: 0;
            outline: 0;
            background: transparent;
            cursor: pointer;
        }
        .pill-select select option { background: var(--surface); color: var(--ink); }
        .send {
            display: grid;
            place-items: center;
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 50%;
            color: var(--send-ink);
            background: var(--send-bg);
        }
        .send:disabled { opacity: .3; cursor: default; }
        .preview { display: flex; flex-wrap: wrap; gap: 8px; padding: 4px 4px 8px; }
        .preview[hidden] { display: none; }
        .preview .attachment { position: relative; margin: 0; }
        .preview .attachment img { width: 64px; height: 64px; object-fit: cover; }
        .preview-remove {
            position: absolute;
            top: -6px;
            right: -6px;
            display: grid;
            place-items: center;
            width: 20px;
            height: 20px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            color: var(--send-ink);
            background: var(--send-bg);
            font-size: 13px;
            line-height: 1;
        }
        .composer-note { margin: 8px 0 0; text-align: center; font-size: 12px; color: var(--ink-faint); }

        .suggestions { display: none; flex-wrap: wrap; justify-content: center; gap: 8px; max-width: 768px; margin: 18px auto 0; padding: 0 24px; }
        .main.is-empty .suggestions { display: flex; }
        .suggestions button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: transparent;
            font-size: 14px;
            color: var(--ink-dim);
        }
        .suggestions button:hover { background: var(--bubble); color: var(--ink); }
        .main.is-empty .composer-note { visibility: hidden; }

        .toast {
            position: fixed;
            left: 50%;
            bottom: 24px;
            z-index: 80;
            transform: translate(-50%, 20px);
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 14px;
            color: var(--send-ink);
            background: var(--send-bg);
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s, transform .2s;
        }
        .toast.show { opacity: 1; transform: translate(-50%, 0); }

        /* ---------- mobile : la barre latérale devient un tiroir ---------- */

        @media (max-width: 767px) {
            .sidebar { position: fixed; inset: 0 auto 0 0; margin-left: 0; transform: translateX(-100%); visibility: hidden; transition: transform .2s ease, visibility .2s; }
            .layout.drawer-open .sidebar { transform: none; visibility: visible; box-shadow: 4px 0 24px rgba(0, 0, 0, .15); }
            .layout.sidebar-closed .sidebar { margin-left: 0; }
            .layout.drawer-open .scrim { display: block; position: fixed; inset: 0; z-index: 20; background: var(--scrim); }
            .topbar .open-sidebar, .topbar .new-chat-top { display: inline-grid; }
            .topbar .new-chat-top { order: 2; margin-left: auto; }
            .topbar-title small { display: none; }
            .thread, .composer-wrap { padding-left: 16px; padding-right: 16px; }
            .user-bubble { max-width: 90%; }
            .avatar { display: none; }
            .main.is-empty .scroll { margin-top: 18vh; }
            .hero h1 { font-size: 24px; }
            .pill-select select { max-width: 190px; font-size: 13.5px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
<div class="layout" id="layout">
    <aside class="sidebar" id="sidebar" aria-label="Historique des conversations">
        <div class="sidebar-head">
            <div class="sidebar-logo">
                <img src="{{ asset('bako1.png') }}" alt="">
                <span>PVIT Assistant</span>
            </div>
            <button class="icon-btn" id="closeSidebar" type="button" aria-label="Fermer la barre latérale" title="Fermer la barre latérale">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><line x1="9" y1="4" x2="9" y2="20"/></svg>
            </button>
        </div>

        <div class="side-actions">
            <button class="side-action" id="newChat" type="button">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                Nouvelle conversation
                <kbd>Ctrl ⇧ O</kbd>
            </button>
            <label class="search-box">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.5" y2="16.5"/></svg>
                <input id="searchInput" type="search" placeholder="Rechercher" aria-label="Rechercher dans les conversations">
            </label>
        </div>

        <nav class="history" id="history"></nav>

        <div class="sidebar-foot">
            L'historique est enregistré dans ce navigateur uniquement.
        </div>
    </aside>
    <div class="scrim" id="scrim"></div>

    <main class="main is-empty" id="main">
        <header class="topbar">
            <button class="icon-btn open-sidebar" id="openSidebar" type="button" aria-label="Ouvrir la barre latérale" title="Ouvrir la barre latérale">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><line x1="9" y1="4" x2="9" y2="20"/></svg>
            </button>
            <button class="icon-btn new-chat-top" id="newChatTop" type="button" aria-label="Nouvelle conversation" title="Nouvelle conversation">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
            <div class="topbar-title">PVIT Assistant <small>Documentation PVIT</small></div>
        </header>

        <div class="scroll" id="scroll">
            <div class="hero">
                <h1>Comment puis-je vous aider avec PVIT ?</h1>
                <p>Réponses tirées de la documentation officielle, avec leurs sources.</p>
            </div>
            <div class="thread" id="thread"></div>
        </div>

        <div class="composer-wrap">
            <form class="composer" id="askForm">
                <div class="preview" id="preview" hidden></div>
                <textarea id="question" rows="1" maxlength="2000" placeholder="Posez une question sur PVIT" aria-label="Votre question" required></textarea>
                <div class="composer-row">
                    <button class="icon-btn" id="attachButton" type="button" aria-label="Joindre une capture d'écran ou un fichier log" title="Joindre une capture d'écran ou un fichier .log/.txt">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                    <input type="file" id="fileInput" accept="image/png,image/jpeg,image/webp,image/gif,.log,.txt,text/plain" hidden>
                    <input type="hidden" id="paymentMethod" value="PVIT">
                    <label class="pill-select" title="Limiter la recherche à une opération">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18l-7 8v6l-4 2v-8Z"/></svg>
                        <select id="operation" aria-label="Opération">
                            <option value="">Toutes les opérations</option>
                            <option>authentification</option><option>inscription</option><option>initiation de paiement</option>
                            <option>paiement</option><option>callback</option><option>gestion des erreurs</option>
                            <option>renouvellement de secret</option>
                        </select>
                    </label>
                    <span class="spacer"></span>
                    <button class="send" id="sendButton" type="submit" aria-label="Envoyer" title="Envoyer" disabled>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                    </button>
                </div>
            </form>
            <p class="composer-note">PVIT Assistant peut faire des erreurs. Vérifiez les informations importantes dans la documentation officielle.</p>
        </div>

        <div class="suggestions" id="suggestions">
            <button type="button" data-q="Comment un compte de production PVIT est-il relié à GIMAC ?">Compte GIMAC via PVIT</button>
            <button type="button" data-q="Que dit la documentation sur les callbacks Airtel Money ?">Callback Airtel Money</button>
            <button type="button" data-q="Comment renouveler le secret PVIT ?">Renouveler le secret</button>
            <button type="button" data-q="Quels sont les champs obligatoires pour initier un paiement ?">Initier un paiement</button>
        </div>
    </main>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/4.3.0/marked.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js"></script>
<script>
    const API_BASE = '';
    const AVATAR_URL = '{{ asset('bako1.png') }}';
    const STORE_KEY = 'pvit-assistant.conversations.v1';
    const SIDEBAR_KEY = 'pvit-assistant.sidebar-closed';
    const MAX_CONVERSATIONS = 100;
    const MAX_FILE_BYTES = 8 * 1024 * 1024;
    const THINKING_PHRASES = ['Réflexion…', 'Consultation de la documentation…', 'Vérification des sources…', 'Rédaction de la réponse…'];

    const $ = (id) => document.getElementById(id);
    const layout = $('layout');
    const main = $('main');
    const form = $('askForm');
    const questionInput = $('question');
    const operationSelect = $('operation');
    const paymentMethod = $('paymentMethod');
    const sendButton = $('sendButton');
    const thread = $('thread');
    const scrollEl = $('scroll');
    const historyEl = $('history');
    const searchInput = $('searchInput');
    const fileInput = $('fileInput');
    const previewEl = $('preview');

    const ICONS = {
        copy: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>',
        check: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        dots: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>',
        edit: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        trash: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>',
        file: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/></svg>'
    };

    // ---------------------------------------------------------------- stockage local

    // Pas de compte utilisateur sur cette page : l'historique reste dans le navigateur.
    // Le serveur garde la mémoire de chaque conversation sous son conversation_id (serverId),
    // ce qui permet de reprendre une ancienne conversation avec son contexte.
    function loadConversations() {
        try {
            const parsed = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');
            const valid = Array.isArray(parsed) ? parsed.filter((c) => c && c.id && Array.isArray(c.messages)) : [];
            // Les messages enregistrés avant l'ajout de l'édition n'ont pas d'identifiant.
            valid.forEach((c) => c.messages.forEach((m) => { m.id ??= newId(); }));
            return valid;
        } catch {
            return [];
        }
    }

    function saveConversations() {
        conversations.sort((a, b) => b.updatedAt - a.updatedAt);
        conversations.splice(MAX_CONVERSATIONS);
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify(conversations));
        } catch {
            // Stockage indisponible (navigation privée, quota) : l'historique reste en mémoire pour cette session.
        }
    }

    const newId = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`);

    let conversations = loadConversations();
    let activeId = null;
    const pending = new Map(); // id de conversation -> tour « réflexion » en cours
    // Fichiers joints de cette session, par id de message : un message modifié les renvoie.
    // Ils ne survivent pas au rechargement, l'historique ne gardant que leur nom.
    const liveFiles = new Map();
    let selectedFile = null;
    const findConversation = (id) => conversations.find((c) => c.id === id) || null;

    function titleFrom(question) {
        const clean = question.replace(/\s+/g, ' ').trim();
        return clean.length > 60 ? `${clean.slice(0, 57).trimEnd()}…` : clean;
    }

    // ---------------------------------------------------------------- barre latérale

    function groupLabel(timestamp) {
        const startOfToday = new Date();
        startOfToday.setHours(0, 0, 0, 0);
        const day = 86400000;
        if (timestamp >= startOfToday.getTime()) return "Aujourd'hui";
        if (timestamp >= startOfToday.getTime() - day) return 'Hier';
        if (timestamp >= startOfToday.getTime() - 7 * day) return '7 jours précédents';
        if (timestamp >= startOfToday.getTime() - 30 * day) return '30 jours précédents';
        return new Date(timestamp).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
    }

    function matchesSearch(conversation, term) {
        if (!term) return true;
        const haystack = [conversation.title, ...conversation.messages.map((m) => m.text || m.answer || '')].join(' ').toLowerCase();
        return haystack.includes(term);
    }

    function renderHistory() {
        const term = searchInput.value.trim().toLowerCase();
        const visible = conversations.filter((c) => matchesSearch(c, term));
        historyEl.innerHTML = '';

        if (!visible.length) {
            const empty = document.createElement('div');
            empty.className = 'history-empty';
            empty.textContent = term ? 'Aucune conversation trouvée.' : 'Vos conversations apparaîtront ici.';
            historyEl.append(empty);
            return;
        }

        let group = null;
        let currentLabel = null;
        visible.forEach((conversation) => {
            const label = groupLabel(conversation.updatedAt);
            if (label !== currentLabel) {
                currentLabel = label;
                group = document.createElement('div');
                group.className = 'history-group';
                const heading = document.createElement('div');
                heading.className = 'history-label';
                heading.textContent = label.charAt(0).toUpperCase() + label.slice(1);
                group.append(heading);
                historyEl.append(group);
            }
            group.append(historyItem(conversation));
        });
    }

    function historyItem(conversation) {
        const item = document.createElement('div');
        item.className = 'chat-item' + (conversation.id === activeId ? ' active' : '');
        item.dataset.id = conversation.id;

        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'chat-link';
        link.textContent = conversation.title;
        link.title = conversation.title;
        link.addEventListener('click', () => openConversation(conversation.id));
        item.append(link);

        if (pending.has(conversation.id)) {
            const dot = document.createElement('span');
            dot.className = 'pending-dot';
            dot.title = 'Réponse en cours';
            item.append(dot);
        }

        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'icon-btn chat-more';
        more.setAttribute('aria-label', 'Options de la conversation');
        more.innerHTML = ICONS.dots;
        more.addEventListener('click', (event) => {
            event.stopPropagation();
            openMenu(more, conversation.id);
        });
        item.append(more);
        return item;
    }

    let openMenuEl = null;

    function closeMenu() {
        if (!openMenuEl) return;
        openMenuEl.remove();
        openMenuEl = null;
        historyEl.querySelectorAll('.has-menu').forEach((el) => el.classList.remove('has-menu'));
    }

    function openMenu(anchor, id) {
        closeMenu();
        const menu = document.createElement('div');
        menu.className = 'menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = `<button type="button" role="menuitem" data-action="rename">${ICONS.edit} Renommer</button>`
            + `<button type="button" role="menuitem" class="danger" data-action="delete">${ICONS.trash} Supprimer</button>`;
        menu.addEventListener('click', (event) => {
            const action = event.target.closest('button')?.dataset.action;
            closeMenu();
            if (action === 'rename') startRename(id);
            if (action === 'delete') deleteConversation(id);
        });
        document.body.append(menu);
        const rect = anchor.getBoundingClientRect();
        menu.style.top = `${Math.min(rect.bottom + 4, window.innerHeight - menu.offsetHeight - 8)}px`;
        menu.style.left = `${Math.min(rect.left, window.innerWidth - menu.offsetWidth - 8)}px`;
        anchor.closest('.chat-item').classList.add('has-menu');
        openMenuEl = menu;
        menu.querySelector('button').focus();
    }

    document.addEventListener('click', (event) => {
        if (openMenuEl && !openMenuEl.contains(event.target)) closeMenu();
    });

    function startRename(id) {
        const conversation = findConversation(id);
        const item = historyEl.querySelector(`.chat-item[data-id="${CSS.escape(id)}"]`);
        if (!conversation || !item) return;
        const input = document.createElement('input');
        input.className = 'rename-input';
        input.value = conversation.title;
        input.maxLength = 100;
        item.replaceChildren(input);
        input.focus();
        input.select();

        let done = false;
        const finish = (commit) => {
            if (done) return;
            done = true;
            const value = input.value.trim();
            if (commit && value) {
                conversation.title = value;
                saveConversations();
            }
            renderHistory();
        };
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') finish(true);
            if (event.key === 'Escape') finish(false);
        });
        input.addEventListener('blur', () => finish(true));
    }

    function deleteConversation(id) {
        const conversation = findConversation(id);
        if (!conversation || !confirm(`Supprimer la conversation « ${conversation.title} » ?`)) return;
        conversations = conversations.filter((c) => c.id !== id);
        saveConversations();
        if (id === activeId) startNewConversation();
        else renderHistory();
        showToast('Conversation supprimée');
    }

    searchInput.addEventListener('input', renderHistory);

    // ---------------------------------------------------------------- ouverture / tiroir

    const isMobile = () => window.matchMedia('(max-width: 767px)').matches;

    function setSidebarClosed(closed) {
        if (isMobile()) {
            layout.classList.toggle('drawer-open', !closed);
            return;
        }
        layout.classList.toggle('sidebar-closed', closed);
        try { localStorage.setItem(SIDEBAR_KEY, closed ? '1' : '0'); } catch {}
    }

    try { if (localStorage.getItem(SIDEBAR_KEY) === '1') layout.classList.add('sidebar-closed'); } catch {}

    $('closeSidebar').addEventListener('click', () => setSidebarClosed(true));
    $('openSidebar').addEventListener('click', () => setSidebarClosed(false));
    $('scrim').addEventListener('click', () => setSidebarClosed(true));

    function closeDrawerOnMobile() {
        if (isMobile()) layout.classList.remove('drawer-open');
    }

    function setHash(id) {
        history.replaceState(null, '', id ? `#c/${id}` : location.pathname + location.search);
    }

    function startNewConversation() {
        activeId = null;
        setHash(null);
        thread.innerHTML = '';
        main.classList.add('is-empty');
        renderHistory();
        updateSendState();
        closeDrawerOnMobile();
        questionInput.focus();
    }

    function openConversation(id) {
        const conversation = findConversation(id);
        if (!conversation) return startNewConversation();
        activeId = id;
        setHash(id);
        main.classList.remove('is-empty');
        thread.innerHTML = '';
        conversation.messages.forEach((message) => thread.append(messageNode(message)));
        if (pending.has(id)) thread.append(pending.get(id));
        renderHistory();
        updateSendState();
        closeDrawerOnMobile();
        scrollToLatest();
        if (!isMobile()) questionInput.focus();
    }

    $('newChat').addEventListener('click', startNewConversation);
    $('newChatTop').addEventListener('click', startNewConversation);
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === 'o') {
            event.preventDefault();
            startNewConversation();
        }
        if (event.key === 'Escape') {
            closeMenu();
            closeDrawerOnMobile();
        }
    });

    // ---------------------------------------------------------------- rendu des messages

    if (window.marked) marked.setOptions({ gfm: true, breaks: true });

    function normalizeMarkdownExamples(text) {
        let opening = null;
        const lines = [];
        for (const line of text.split(/\r\n|\n|\r/)) {
            if (opening === null) {
                const match = line.match(/^([ \t]*)(`{3,}|~{3,})([A-Za-z0-9_+-]*)[ \t]*$/);
                if (match) opening = { indent: match[1], marker: match[2] };
                lines.push(line);
                continue;
            }
            const closing = line.match(new RegExp('^[ \\t]*(' + opening.marker[0] + '{' + opening.marker.length + ',})[ \\t]*(.*)$'));
            if (closing && (!closing[2].trim() || /^(?:\[SOURCE:\d+\]|\[\d+\])/.test(closing[2].trim()))) {
                lines.push(opening.indent + closing[1]);
                if (closing[2].trim()) lines.push('', opening.indent + closing[2].trim());
                opening = null;
            } else {
                lines.push(line);
            }
        }
        return lines.join('\n');
    }

    function renderMarkdown(container, raw) {
        // Old browser history may contain fences damaged by an earlier API response.
        const text = normalizeMarkdownExamples(raw || '(réponse vide)');
        if (!window.marked || !window.DOMPurify) {
            container.textContent = text;
            return;
        }
        container.innerHTML = DOMPurify.sanitize(marked.parse(text));
        container.querySelectorAll('a[href]').forEach((a) => {
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
        });
        container.querySelectorAll('table').forEach((table) => {
            const wrap = document.createElement('div');
            wrap.className = 'table-wrap';
            table.replaceWith(wrap);
            wrap.append(table);
        });
        container.querySelectorAll('pre').forEach((pre) => {
            const code = pre.querySelector('code');
            const language = (code?.className.match(/language-([\w-]+)/) || [])[1] || 'code';
            const block = document.createElement('div');
            block.className = 'code-block';
            const head = document.createElement('div');
            head.className = 'code-head';
            const name = document.createElement('span');
            name.textContent = language;
            const copy = document.createElement('button');
            copy.type = 'button';
            copy.innerHTML = `${ICONS.copy} Copier`;
            copy.addEventListener('click', () => copyText(pre.innerText, copy, `${ICONS.copy} Copier`, `${ICONS.check} Copié`));
            head.append(name, copy);
            pre.replaceWith(block);
            block.append(head, pre);
        });
    }

    async function copyText(text, button, idleHtml, doneHtml) {
        try {
            await navigator.clipboard.writeText(text);
            button.innerHTML = doneHtml;
            setTimeout(() => { button.innerHTML = idleHtml; }, 1600);
        } catch {
            showToast('Copie impossible dans ce navigateur');
        }
    }

    function attachmentNode(attachment, liveFile) {
        const wrap = document.createElement('div');
        wrap.className = 'attachment';
        if (attachment.kind === 'image' && liveFile) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(liveFile);
            img.alt = attachment.name;
            wrap.append(img);
        } else {
            // Les captures ne sont pas conservées dans l'historique : seul leur nom est gardé.
            const chip = document.createElement('div');
            chip.className = 'file-chip';
            chip.innerHTML = `<span class="file-icon">${ICONS.file}</span><span></span>`;
            chip.lastElementChild.textContent = attachment.name;
            wrap.append(chip);
        }
        return wrap;
    }

    function userNode(message) {
        const turn = document.createElement('div');
        turn.className = 'turn user';
        turn.dataset.messageId = message.id;
        if (message.attachment) turn.append(attachmentNode(message.attachment, liveFiles.get(message.id)));
        const bubble = document.createElement('div');
        bubble.className = 'user-bubble';
        bubble.textContent = message.text;

        const actions = document.createElement('div');
        actions.className = 'user-actions';
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'icon-btn';
        copy.title = 'Copier';
        copy.setAttribute('aria-label', 'Copier le message');
        copy.innerHTML = ICONS.copy;
        copy.addEventListener('click', () => copyText(message.text, copy, ICONS.copy, ICONS.check));
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'icon-btn edit-message';
        edit.title = 'Modifier le message';
        edit.setAttribute('aria-label', 'Modifier le message');
        edit.innerHTML = ICONS.edit;
        edit.disabled = pending.has(activeId);
        edit.addEventListener('click', () => startEdit(turn, message));
        actions.append(copy, edit);

        turn.append(bubble, actions);
        return turn;
    }

    function startEdit(turn, message) {
        if (pending.has(activeId)) return;
        thread.querySelectorAll('.edit-box').forEach((box) => box._cancel());

        const box = document.createElement('div');
        box.className = 'edit-box';
        const area = document.createElement('textarea');
        area.value = message.text;
        area.maxLength = 2000;
        area.setAttribute('aria-label', 'Message modifié');
        box.append(area);

        if (message.attachment && !liveFiles.has(message.id)) {
            const note = document.createElement('p');
            note.className = 'edit-note';
            note.textContent = `La pièce jointe « ${message.attachment.name} » n'est plus disponible : le message sera envoyé sans elle.`;
            box.append(note);
        }

        const buttons = document.createElement('div');
        buttons.className = 'edit-buttons';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Annuler';
        const submit = document.createElement('button');
        submit.type = 'button';
        submit.className = 'primary';
        submit.textContent = 'Envoyer';
        buttons.append(cancel, submit);
        box.append(buttons);

        const hidden = [...turn.children];
        hidden.forEach((el) => { el.hidden = true; });
        turn.append(box);
        turn.style.alignItems = 'stretch';

        const grow = () => {
            area.style.height = 'auto';
            area.style.height = Math.min(area.scrollHeight, 320) + 'px';
            submit.disabled = !area.value.trim();
        };
        box._cancel = () => {
            box.remove();
            hidden.forEach((el) => { el.hidden = false; });
            turn.style.alignItems = '';
        };
        cancel.addEventListener('click', box._cancel);
        submit.addEventListener('click', () => submitEdit(message, area.value.trim()));
        area.addEventListener('input', grow);
        area.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') box._cancel();
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                if (!submit.disabled) submit.click();
            }
        });
        grow();
        area.focus();
        area.setSelectionRange(area.value.length, area.value.length);
    }

    // Comme sur ChatGPT, la question modifiée remplace l'originale et ce qui la suivait.
    function submitEdit(message, text) {
        const conversation = findConversation(activeId);
        if (!conversation || !text || pending.has(conversation.id)) return;
        const index = conversation.messages.findIndex((m) => m.id === message.id);
        if (index < 0) return;

        // La mémoire du serveur ne garde que le dernier échange, qui est justement remplacé :
        // la suite repart d'une nouvelle conversation serveur, avec la question précédente pour contexte.
        const previous = conversation.messages.slice(0, index).reverse().find((m) => m.role === 'user');
        const file = liveFiles.get(message.id) || null;
        conversation.messages.slice(index).forEach((m) => liveFiles.delete(m.id));
        conversation.messages = conversation.messages.slice(0, index);
        conversation.serverId = null;
        if (index === 0) conversation.title = titleFrom(text);

        openConversation(conversation.id);
        send(conversation, text, file, previous ? previous.text : null);
    }

    function assistantShell() {
        const turn = document.createElement('div');
        turn.className = 'turn assistant';
        const row = document.createElement('div');
        row.className = 'assistant-row';
        const avatar = document.createElement('img');
        avatar.className = 'avatar';
        avatar.src = AVATAR_URL;
        avatar.alt = '';
        const body = document.createElement('div');
        body.className = 'assistant-body';
        row.append(avatar, body);
        turn.append(row);
        return { turn, body };
    }

    function assistantNode(message) {
        const { turn, body } = assistantShell();
        const answer = document.createElement('div');
        answer.className = 'answer-text';
        renderMarkdown(answer, message.answer);
        body.append(answer);

        const sources = message.sources || [];
        if (sources.length) {
            const list = document.createElement('div');
            list.className = 'sources';
            sources.forEach((source) => {
                const url = source.url && /^https?:\/\//i.test(String(source.url)) ? String(source.url) : null;
                const chip = document.createElement(url ? 'a' : 'span');
                chip.className = 'source-chip';
                if (url) {
                    chip.href = url;
                    chip.target = '_blank';
                    chip.rel = 'noopener noreferrer';
                }
                // Les sources numérotées sont celles que la réponse cite en [n].
                if (source.number) {
                    const n = document.createElement('b');
                    n.textContent = source.number;
                    chip.append(n);
                }
                const label = source.section && source.section !== source.document ? [source.document, source.section] : [source.document];
                chip.append(document.createTextNode(label.filter(Boolean).join(' · ')));
                chip.title = chip.textContent;
                list.append(chip);
            });
            body.append(list);
        }

        if (message.escalation) body.append(supportNode(message));

        const actions = document.createElement('div');
        actions.className = 'actions';
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'icon-btn';
        copy.title = 'Copier la réponse';
        copy.setAttribute('aria-label', 'Copier la réponse');
        copy.innerHTML = ICONS.copy;
        copy.addEventListener('click', () => copyText(message.answer || '', copy, ICONS.copy, ICONS.check));
        actions.append(copy);
        if (message.model) {
            const model = document.createElement('span');
            model.className = 'model-name';
            model.textContent = message.model;
            actions.append(model);
        }
        body.append(actions);
        return turn;
    }

    function errorNode(message) {
        const { turn, body } = assistantShell();
        const bubble = document.createElement('div');
        bubble.className = 'error-bubble';
        bubble.textContent = message.text;
        body.append(bubble);
        if (message.escalation) body.append(supportNode(message));
        return turn;
    }

    // L'assistant n'a pas de réponse fiable : il propose d'ouvrir un ticket sur la question posée.
    function supportNode(message) {
        const box = document.createElement('div');
        box.className = 'support-box';
        const label = document.createElement('span');
        label.className = 'support-label';
        label.innerHTML = '<b>Support conseillé.</b> ';
        box.append(label);

        const escalation = message.escalation;
        if (!escalation.questionId || !escalation.token) {
            label.append('Contactez le support PVit pour cette question.');
            return box;
        }

        label.append("L'équipe support peut reprendre cette question.");
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ticket-button';
        const showOpened = () => {
            button.textContent = `Ticket n° ${escalation.ticketId} ouvert`;
            button.classList.add('done');
            button.disabled = true;
        };
        if (escalation.ticketId) showOpened();
        else button.textContent = 'Ouvrir un ticket de support';

        button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Ouverture du ticket…';
            try {
                const response = await fetch(`${API_BASE}/api/ticket`, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ question_id: escalation.questionId, ticket_token: escalation.token })
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ticket_id) throw new Error(payload.message || 'échec');
                escalation.ticketId = payload.ticket_id;
                saveConversations();
                showOpened();
            } catch {
                button.disabled = false;
                button.textContent = "Réessayer d'ouvrir le ticket";
            }
        });
        box.append(button);
        return box;
    }

    const escalationOf = (payload) => (payload && payload.should_escalate
        ? { questionId: payload.question_id || null, token: payload.ticket_token || null, ticketId: null }
        : null);

    function messageNode(message) {
        if (message.role === 'user') return userNode(message);
        if (message.role === 'assistant') return assistantNode(message);
        return errorNode(message);
    }

    function thinkingNode() {
        const { turn, body } = assistantShell();
        body.innerHTML = '<div class="thinking" role="status"><span class="dot"></span><span class="label"></span></div>';
        const label = body.querySelector('.label');
        let index = 0;
        label.textContent = THINKING_PHRASES[0];
        turn._interval = setInterval(() => {
            index = (index + 1) % THINKING_PHRASES.length;
            label.style.opacity = '0';
            setTimeout(() => {
                label.textContent = THINKING_PHRASES[index];
                label.style.opacity = '1';
            }, 250);
        }, 1800);
        return turn;
    }

    function scrollToLatest() {
        scrollEl.scrollTop = scrollEl.scrollHeight;
    }

    let toastTimer = null;
    function showToast(text) {
        const toast = $('toast');
        toast.textContent = text;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 1800);
    }

    // ---------------------------------------------------------------- saisie

    function autoGrow() {
        questionInput.style.height = 'auto';
        questionInput.style.height = Math.min(questionInput.scrollHeight, 220) + 'px';
    }

    function updateSendState() {
        const busy = pending.has(activeId);
        sendButton.disabled = !questionInput.value.trim() || busy;
        thread.querySelectorAll('.edit-message').forEach((button) => { button.disabled = busy; });
    }

    questionInput.addEventListener('input', () => {
        autoGrow();
        updateSendState();
    });
    questionInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });
    form.addEventListener('click', (event) => {
        if (event.target === form) questionInput.focus();
    });

    document.querySelectorAll('.suggestions [data-q]').forEach((button) => {
        button.addEventListener('click', () => {
            questionInput.value = button.dataset.q || '';
            autoGrow();
            updateSendState();
            questionInput.focus();
        });
    });

    $('attachButton').addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        const file = fileInput.files[0] || null;
        fileInput.value = '';
        if (file && file.size > MAX_FILE_BYTES) {
            showToast('Fichier trop volumineux (8 Mo maximum)');
            return;
        }
        selectedFile = file;
        renderPreview();
    });

    const attachmentOf = (file) => (file ? { name: file.name, kind: file.type.startsWith('image/') ? 'image' : 'file' } : null);

    function renderPreview() {
        previewEl.innerHTML = '';
        previewEl.hidden = !selectedFile;
        if (!selectedFile) return;
        const node = attachmentNode(attachmentOf(selectedFile), selectedFile);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'preview-remove';
        remove.setAttribute('aria-label', 'Retirer la pièce jointe');
        remove.textContent = '×';
        remove.addEventListener('click', () => {
            selectedFile = null;
            renderPreview();
        });
        node.append(remove);
        previewEl.append(node);
    }

    async function ask(conversation, question, file, contextQuestion) {
        const body = {
            question,
            moyen_paiement: paymentMethod.value || null,
            operation: operationSelect.value || null,
            conversation_id: conversation.serverId || null,
            context_question: contextQuestion || null
        };
        let request;
        if (file) {
            const data = new FormData();
            Object.entries(body).forEach(([key, value]) => { if (value) data.append(key, value); });
            data.append('image', file);
            request = { method: 'POST', headers: { Accept: 'application/json' }, body: data };
        } else {
            request = { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
        }
        const response = await fetch(`${API_BASE}/api/mistral-raw/ask`, request);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = payload.errors ? Object.values(payload.errors).flat().join('\n') : (payload.error || payload.message);
            const error = new Error(validation || 'La requête a échoué.');
            error.payload = payload;
            throw error;
        }
        return payload;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const question = questionInput.value.trim();
        if (!question || pending.has(activeId)) return;

        let conversation = findConversation(activeId);
        if (!conversation) {
            conversation = { id: newId(), serverId: null, title: titleFrom(question), createdAt: Date.now(), updatedAt: Date.now(), messages: [] };
            conversations.unshift(conversation);
            activeId = conversation.id;
            setHash(conversation.id);
            main.classList.remove('is-empty');
        }

        const file = selectedFile;
        selectedFile = null;
        renderPreview();
        questionInput.value = '';
        autoGrow();

        send(conversation, question, file, null);
    });

    async function send(conversation, question, file, contextQuestion) {
        const userMessage = { id: newId(), role: 'user', text: question, attachment: attachmentOf(file) };
        if (file) liveFiles.set(userMessage.id, file);
        conversation.messages.push(userMessage);
        conversation.updatedAt = Date.now();
        saveConversations();
        thread.append(messageNode(userMessage));

        const thinking = thinkingNode();
        pending.set(conversation.id, thinking);
        thread.append(thinking);
        renderHistory();
        updateSendState();
        scrollToLatest();

        let reply;
        let serverId = null;
        try {
            const payload = await ask(conversation, question, file, contextQuestion);
            serverId = payload.conversation_id || null;
            reply = { id: newId(), role: 'assistant', answer: payload.answer, model: payload.model || null, sources: payload.sources || [], escalation: escalationOf(payload) };
        } catch (error) {
            reply = { id: newId(), role: 'error', text: error.message || 'Erreur inconnue.', escalation: escalationOf(error.payload) };
        }

        clearInterval(thinking._interval);
        pending.delete(conversation.id);
        // Relu par id : l'historique a pu être rechargé par une autre fenêtre, ou la conversation supprimée pendant l'attente.
        const live = findConversation(conversation.id);
        if (live) {
            if (serverId) live.serverId = serverId;
            live.messages.push(reply);
            live.updatedAt = Date.now();
            saveConversations();
        }
        if (thinking.isConnected) {
            thinking.replaceWith(messageNode(reply));
            scrollToLatest();
        }
        renderHistory();
        updateSendState();
    }

    // ---------------------------------------------------------------- démarrage

    window.addEventListener('storage', (event) => {
        // Une autre fenêtre a modifié l'historique.
        if (event.key !== STORE_KEY) return;
        conversations = loadConversations();
        renderHistory();
    });

    function openFromHash() {
        const id = decodeURIComponent((location.hash.match(/^#c\/(.+)$/) || [])[1] || '');
        if (id && !findConversation(id)) conversations = loadConversations();
        if (id && findConversation(id)) openConversation(id);
        else startNewConversation();
    }

    window.addEventListener('hashchange', openFromHash);
    openFromHash();
</script>
</body>
</html>
