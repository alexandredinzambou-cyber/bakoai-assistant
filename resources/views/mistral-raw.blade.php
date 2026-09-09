<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>PVIT Assistant</title>
    <style>
        :root {
            --bg: #fdfbf3;
            --bg-raise: #f5f0dd;
            --bg-composer: #ffffff;
            --bg-bubble: #c9f45d;
            --ink-on-lime: #16240f;
            --text: #1b2420;
            --text-dim: #56645b;
            --text-faint: #74806f;
            --lime: #c9f45d;
            --orange: #ef5a24;
            --orange-deep: #b8410f;
            --orange-wash: rgba(239, 90, 36, .09);
            --teal: #0aa688;
            --line: rgba(27, 36, 32, .13);
            --line-strong: rgba(27, 36, 32, .24);
            --display: "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
            --body: Bahnschrift, "Avenir Next", "Trebuchet MS", sans-serif;
            --mono: ui-monospace, "Cascadia Code", "SF Mono", Consolas, "Roboto Mono", monospace;
        }

        * { box-sizing: border-box; }

        html { min-width: 320px; background: var(--bg); }

        body {
            position: relative;
            margin: 0;
            height: 100vh;
            overflow: hidden;
            color: var(--text);
            font: 15px/1.6 var(--body);
            background: var(--bg);
        }

        body::before, body::after {
            content: "";
            position: absolute;
            width: 46vw;
            height: 46vw;
            border-radius: 50%;
            filter: blur(70px);
            pointer-events: none;
            z-index: 0;
        }

        body::before {
            top: -14%;
            left: -12%;
            background: rgba(201, 244, 93, .5);
            animation: drift-a 16s ease-in-out infinite;
        }

        body::after {
            bottom: -18%;
            right: -12%;
            background: rgba(239, 90, 36, .3);
            animation: drift-b 20s ease-in-out infinite;
        }

        @keyframes drift-a {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(5%, 7%) scale(1.12); }
        }

        @keyframes drift-b {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(-6%, -5%) scale(1.15); }
        }

        button, input, select, textarea { font: inherit; color: inherit; }
        button, select { cursor: pointer; }
        textarea:focus, select:focus, button:focus-visible { outline: none; }

        .app {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-rows: auto 1fr auto;
            height: 100vh;
        }

        /* ---------- topbar ---------- */

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--line);
        }

        .brand { display: flex; align-items: center; gap: 9px; }

        .mark {
            width: 26px;
            aspect-ratio: 1;
            display: grid;
            place-items: center;
            border-radius: 50%;
            color: var(--ink-on-lime);
            background: var(--lime);
            font-weight: 800;
            font-size: 11px;
            transition: transform .3s cubic-bezier(.34, 1.56, .64, 1);
        }

        .brand:hover .mark { transform: rotate(-10deg) scale(1.12); }

        .brand-name { font: 700 14.5px/1 var(--display); color: var(--text); }
        .brand-sep { color: var(--text-faint); font-size: 13px; }
        .brand-sub { color: var(--text-dim); font-size: 13px; }

        .compare-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border: 1px solid var(--line-strong);
            border-radius: 999px;
            color: var(--text-dim);
            font-size: 12px;
            text-decoration: none;
            transition: border-color .15s ease, color .15s ease, background .15s ease;
        }

        .compare-link:hover { border-color: var(--teal); color: var(--teal); background: rgba(12, 143, 118, .08); }

        /* ---------- thread ---------- */

        .scroll {
            min-height: 0;
            overflow-y: auto;
            scroll-behavior: smooth;
        }

        .thread {
            width: min(760px, calc(100% - 40px));
            margin: 0 auto;
            min-height: 100%;
            display: flex;
            flex-direction: column;
            padding: 8px 0 28px;
        }

        /* empty state */

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 18px;
            padding: 40px 0;
            text-align: center;
        }

       

        .empty-state h1 {
            margin: 0;
            font: 400 27px/1.2 var(--display);
            color: var(--text);
            opacity: 0;
            animation: rise .5s ease .12s forwards;
        }

        .empty-state p {
            margin: 0;
            max-width: 420px;
            color: var(--text-dim);
            font-size: 13.5px;
            opacity: 0;
            animation: rise .5s ease .2s forwards;
        }

        .suggestions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
            max-width: 560px;
        }

        .suggestions button {
            padding: 9px 14px;
            border: 1px solid var(--line-strong);
            border-radius: 999px;
            background: #fff;
            color: var(--text-dim);
            font-size: 12.5px;
            opacity: 0;
            animation: rise .45s ease forwards;
            transition: border-color .15s ease, color .15s ease, background .15s ease, transform .18s ease, box-shadow .18s ease;
        }

        .suggestions button:nth-child(1) { animation-delay: .28s; }
        .suggestions button:nth-child(2) { animation-delay: .34s; }
        .suggestions button:nth-child(3) { animation-delay: .4s; }
        .suggestions button:nth-child(4) { animation-delay: .46s; }

        .suggestions button:hover {
            border-color: var(--teal);
            color: var(--text);
            background: rgba(10, 166, 136, .09);
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 8px 18px rgba(10, 166, 136, .18);
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        @keyframes pop-in {
            from { opacity: 0; transform: scale(.6); }
            to { opacity: 1; transform: scale(1); }
        }

        /* turns */

        .turn { padding: 18px 0; animation: rise .28s ease both; }
        .turn.user { display: flex; justify-content: flex-end; }

        .turn.assistant + .turn.assistant { padding-top: 0; }

        .bubble {
            max-width: 78%;
            padding: 11px 16px;
            border-radius: 20px;
            background: var(--bg-bubble);
            color: var(--ink-on-lime);
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font-size: 14.5px;
            box-shadow: 0 8px 22px rgba(201, 244, 93, .3);
            animation: pop-in .32s cubic-bezier(.34, 1.56, .64, 1) both;
        }

        .assistant-body { width: 100%; }

        .assistant-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .model-tag {
            padding: 2px 8px;
            border: 1px solid var(--line-strong);
            border-radius: 999px;
            color: var(--teal);
            font: 800 10px/1.6 var(--mono);
            letter-spacing: .03em;
            animation: pop-in .3s cubic-bezier(.34, 1.56, .64, 1) both;
        }

        .answer-text {
            overflow-wrap: anywhere;
            font: 15px/1.7 var(--body);
            color: var(--text);
        }

        .answer-text > *:first-child { margin-top: 0; }
        .answer-text > *:last-child { margin-bottom: 0; }
        .answer-text p { margin: 0 0 14px; }

        .answer-text h1, .answer-text h2 {
            margin: 22px 0 10px;
            font-family: var(--display);
            font-weight: 600;
            line-height: 1.25;
            color: var(--orange);
        }

        .answer-text h1 { font-size: 19px; }
        .answer-text h2 { font-size: 17px; }
        .answer-text h3 {
            margin: 22px 0 10px;
            font: 800 11px/1 var(--mono);
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--orange-deep);
        }
        .answer-text h4 { margin: 22px 0 10px; font-size: 13px; color: var(--text); }

        .answer-text strong { color: var(--text); font-weight: 700; }
        .answer-text em { color: var(--text-dim); }

        .answer-text ul, .answer-text ol { margin: 0 0 14px; padding-left: 22px; }
        .answer-text li { margin: 4px 0; }
        .answer-text li::marker { color: var(--teal); }

        .answer-text hr { border: none; border-top: 1px solid var(--line); margin: 20px 0; }

        .answer-text blockquote {
            margin: 0 0 14px;
            padding: 2px 14px;
            border-left: 2px solid var(--orange);
            color: var(--text-dim);
            font-style: italic;
        }

        .answer-text a { color: var(--teal); text-underline-offset: 3px; }

        .answer-text code {
            font: 13px/1.5 var(--mono);
            background: var(--bg-raise);
            color: var(--orange-deep);
            padding: 1px 5px;
            border-radius: 3px;
        }

        .answer-text pre {
            margin: 0 0 14px;
            padding: 13px 15px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--bg-raise);
            overflow-x: auto;
        }

        .answer-text pre code { padding: 0; background: none; color: var(--text); }

        .answer-text table {
            display: block;
            max-width: 100%;
            overflow-x: auto;
            border-collapse: collapse;
            margin: 4px 0 16px;
            font-size: 13px;
        }

        .answer-text th, .answer-text td {
            padding: 8px 12px;
            border-bottom: 1px solid var(--line);
            text-align: left;
        }

        .answer-text thead th {
            background: var(--orange-wash);
            color: var(--text-dim);
            font: 800 10.5px/1 var(--mono);
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .source-list { margin: 16px 0 0; padding: 14px 0 0; border-top: 1px solid var(--line); list-style: none; }
        .source-list li { margin-top: 6px; color: var(--text-faint); font: 12px/1.5 var(--mono); }
        .source-list a { color: var(--teal); text-underline-offset: 3px; }

        .error-bubble { color: var(--orange-deep); }

        .loading-row { display: flex; align-items: center; gap: 6px; padding: 4px 0; }
        .loading-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            opacity: .5;
            animation: loading-bounce 1.1s ease-in-out infinite;
        }
        .loading-dot:nth-child(1) { background: var(--lime); }
        .loading-dot:nth-child(2) { background: var(--orange); animation-delay: .15s; }
        .loading-dot:nth-child(3) { background: var(--teal); animation-delay: .3s; }

        @keyframes loading-bounce {
            0%, 80%, 100% { transform: translateY(0) scale(1); opacity: .5; }
            40% { transform: translateY(-5px) scale(1.2); opacity: 1; }
        }

        .loading-bubbles {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 0;
        }

        .bubble-loader {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            opacity: .5;
            animation: bubble-bounce 1.1s ease-in-out infinite;
        }

        .bubble-loader:nth-child(1) { background: var(--lime); }
        .bubble-loader:nth-child(2) { background: var(--orange); animation-delay: .15s; }
        .bubble-loader:nth-child(3) { background: var(--teal); animation-delay: .3s; }

        .loading-text {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dim);
            transition: opacity .25s ease;
        }

        @keyframes bubble-bounce {
            0%, 80%, 100% { transform: translateY(0) scale(1); opacity: .5; }
            40% { transform: translateY(-5px) scale(1.2); opacity: 1; }
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ---------- composer ---------- */

        .composer-wrap {
            padding: 10px 20px 18px;
            background: linear-gradient(180deg, rgba(253, 251, 243, 0), var(--bg) 45%);
        }

        .composer {
            width: min(760px, calc(100% - 40px));
            margin: 0 auto;
        }

        .toolbar {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
        }

        .pill-select { position: relative; }

        .pill-select select {
            appearance: none;
            padding: 6px 26px 6px 11px;
            border: 1px solid var(--line-strong);
            border-radius: 999px;
            background: var(--bg-raise);
            color: var(--text-dim);
            font-size: 11.5px;
        }

        .pill-select::after {
            content: "";
            position: absolute;
            right: 10px;
            top: 50%;
            width: 6px;
            height: 6px;
            border-right: 1.5px solid var(--text-faint);
            border-bottom: 1.5px solid var(--text-faint);
            transform: translateY(-65%) rotate(45deg);
            pointer-events: none;
        }

        .pill-select select:focus { border-color: var(--teal); }

        .input-shell {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            padding: 8px 8px 8px 18px;
            border: 1px solid var(--line-strong);
            border-radius: 26px;
            background: var(--bg-composer);
            box-shadow: 0 14px 34px rgba(27, 36, 32, .1);
            transition: box-shadow .2s ease, border-color .2s ease, transform .2s ease;
        }

        .input-shell:focus-within {
            border-color: var(--teal);
            transform: translateY(-2px);
            box-shadow: 0 16px 36px rgba(27, 36, 32, .12), 0 0 0 3px rgba(10, 166, 136, .16);
        }

        textarea#question {
            flex: 1;
            max-height: 200px;
            border: none;
            background: transparent;
            color: var(--text);
            font-size: 14.5px;
            line-height: 1.5;
            padding: 7px 0;
            resize: none;
            overflow-y: auto;
        }

        textarea#question::placeholder { color: var(--text-faint); }

        .send {
            position: relative;
            flex: 0 0 auto;
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            overflow: hidden;
            border: none;
            border-radius: 50%;
            background: var(--orange);
            color: #fff;
            transition: transform .15s cubic-bezier(.34, 1.56, .64, 1), background .15s ease;
        }

        .send::after {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, .4), transparent 65%);
            opacity: 0;
            transition: opacity .25s ease;
        }

        .send:hover:not(:disabled)::after { opacity: 1; }
        .send:hover:not(:disabled) { transform: scale(1.1); }
        .send:active:not(:disabled) { transform: scale(.9); }
        .send:disabled { background: var(--bg-raise); color: var(--text-faint); cursor: not-allowed; }
        .send.is-loading svg { animation: spin .7s linear infinite; }

        @keyframes spin { to { transform: rotate(360deg); } }

        .composer-note {
            margin: 9px 0 0;
            color: var(--text-faint);
            font-size: 10.5px;
            text-align: center;
        }

        @media (max-width: 640px) {
            .thread, .composer { width: calc(100% - 24px); }
            .bubble { max-width: 88%; }
            .brand-sub { display: none; }
            .panther-track { width: 160px; height: 48px; }
            .panther { width: 44px; height: 12px; bottom: 11px; }
            .panther-ground { height: 8px; }
            .panther-dust { bottom: 8px; }
        }

        @media (prefers-reduced-motion: reduce) {
            body::before, body::after,
            .bubble-loader,
            .loading-dot,
            .turn, .bubble,
            .empty-mark, .empty-state h1, .empty-state p, .suggestions button,
            .model-tag,
            .send::after, .send svg {
                animation: none !important;
                opacity: 1;
            }
            .mark, .send, .input-shell, .suggestions button { transition: none; }
        }
    </style>
</head>
<body>
<div class="app">
    <header class="topbar">
        <div class="brand">
            <span class="mark">B</span>
            <span class="brand-name">BakoAI</span>
            <span class="brand-sep">/</span>
            <span class="brand-sub">Mistral</span>
        </div>
        <a class="compare-link" href="/assistant">Comparer avec /assistant ↗</a>
    </header>

    <div class="scroll" id="scroll">
        <div class="thread" id="thread">
            <div class="empty-state" id="emptyState">
                <div class="empty-mark"> <img src="" alt=""></div>
                <img src="{{ asset('bako1.png') }}" alt="BAKO AI" style="width: 350px; margin-bottom: 15px;">
                <h1>Pvit ASSISTANT </h1>
                <p>Posez une question sur la documentation PVIT.</p>
                <div class="suggestions">
                    <button type="button" data-q="Comment un compte de production PVIT est-il relié à GIMAC ?">Compte GIMAC via PVIT</button>
                    <button type="button" data-q="Que dit la documentation sur les callbacks Airtel Money ?">Callback Airtel Money</button>
                    <button type="button" data-q="Comment renouveler le secret PVIT ?">Renouveler le secret</button>
                    <button type="button" data-q="Quels sont les champs obligatoires pour initier un paiement ?">Initier un paiement</button>
                </div>
            </div>
        </div>
    </div>

    <div class="composer-wrap">
        <form class="composer" id="askForm">
            <div class="toolbar">
                <div class="pill-select">
                    <select id="paymentMethod" aria-label="Moyen de paiement">
                        <option value="PVIT" selected>PVIT</option>
                    </select>
                </div>
                <div class="pill-select">
                    <select id="operation" aria-label="Opération">
                        <option value="">Toutes les opérations</option>
                        <option>authentification</option><option>inscription</option><option>initiation de paiement</option>
                        <option>paiement</option><option>callback</option><option>gestion des erreurs</option>
                        <option>renouvellement de secret</option>
                    </select>
                </div>
            </div>
            <div class="input-shell">
                <textarea id="question" rows="1" maxlength="2000" placeholder="Posez une question sur PVIT…" required></textarea>
                <button class="send" id="sendButton" type="submit" aria-label="Envoyer" title="Envoyer">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
                </button>
            </div>
            <p class="composer-note">PVIT ASSISTANT</p>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/4.3.0/marked.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js"></script>
<script>
    const form = document.getElementById('askForm');
    const questionInput = document.getElementById('question');
    const paymentSelect = document.getElementById('paymentMethod');
    const operationSelect = document.getElementById('operation');
    const sendButton = document.getElementById('sendButton');
    const thread = document.getElementById('thread');
    const emptyState = document.getElementById('emptyState');
    const scrollEl = document.getElementById('scroll');

    if (window.marked) marked.setOptions({ gfm: true, breaks: true });

    function renderMarkdown(raw) {
        const text = raw || '(réponse vide)';
        if (!window.marked || !window.DOMPurify) return text;
        return DOMPurify.sanitize(marked.parse(text));
    }

    function scrollToLatest() {
        scrollEl.scrollTop = scrollEl.scrollHeight;
    }

    function autoGrow() {
        questionInput.style.height = 'auto';
        questionInput.style.height = Math.min(questionInput.scrollHeight, 200) + 'px';
    }

    questionInput.addEventListener('input', autoGrow);
    questionInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    document.querySelectorAll('.suggestions [data-q]').forEach((button) => {
        button.addEventListener('click', () => {
            questionInput.value = button.dataset.q || '';
            autoGrow();
            questionInput.focus();
        });
    });

    function addUserTurn(text) {
        const turn = document.createElement('div');
        turn.className = 'turn user';
        turn.innerHTML = '<div class="bubble"></div>';
        turn.querySelector('.bubble').textContent = text;
        thread.append(turn);
    }

const THINKING_PHRASES = [
        'PVIT réfléchit…',
        'Consultation de la documentation…',
        'Vérification des sources…',
        'Rédaction de la réponse…'
    ];

    function addLoadingTurn() {
        const turn = document.createElement('div');
        turn.className = 'turn assistant';
        turn.innerHTML = '<div class="assistant-body"><div class="loading-bubbles" role="status" aria-label="Réponse en cours"><span class="bubble-loader"></span><span class="bubble-loader"></span><span class="bubble-loader"></span><span class="loading-text"></span></div></div>';
        thread.appendChild(turn);

        const textEl = turn.querySelector('.loading-text');
        let phraseIndex = 0;
        textEl.textContent = THINKING_PHRASES[0];
        turn._thinkingInterval = window.setInterval(() => {
            phraseIndex = (phraseIndex + 1) % THINKING_PHRASES.length;
            textEl.style.opacity = '0';
            window.setTimeout(() => {
                textEl.textContent = THINKING_PHRASES[phraseIndex];
                textEl.style.opacity = '1';
            }, 250);
        }, 1800);

        return turn;
    }

    function stopLoadingTurn(turn) {
        window.clearInterval(turn._thinkingInterval);
    }

    function addAssistantTurn(payload) {
        const turn = document.createElement('div');
        turn.className = 'turn assistant';

        const sourceItems = (payload.sources || []).map((source) => {
            const label = `${source.document} / ${source.section}`;
            return source.url
                ? `<li><a href="${source.url}" target="_blank" rel="noopener noreferrer">${label}</a></li>`
                : `<li>${label}</li>`;
        }).join('');

        turn.innerHTML = `
            <div class="assistant-body">
                ${payload.model ? `<div class="assistant-meta"><span class="model-tag">${payload.model}</span></div>` : ''}
                <div class="answer-text">${renderMarkdown(payload.answer)}</div>
                ${sourceItems ? `<ul class="source-list">${sourceItems}</ul>` : ''}
            </div>
        `;
        return turn;
    }

    function addErrorTurn(message) {
        const turn = document.createElement('div');
        turn.className = 'turn assistant';
        turn.innerHTML = '<div class="assistant-body error-bubble"></div>';
        turn.querySelector('.error-bubble').textContent = message;
        return turn;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const question = questionInput.value.trim();
        if (!question || sendButton.disabled) return;

        emptyState.remove();
        addUserTurn(question);
        questionInput.value = '';
        autoGrow();
        sendButton.disabled = true;
        sendButton.classList.add('is-loading');
        const loadingTurn = addLoadingTurn();
        scrollToLatest();

        try {
            const response = await fetch('/api/mistral-raw/ask', {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify({
                    question,
                    moyen_paiement: paymentSelect.value || null,
                    operation: operationSelect.value || null
                })
            });
            const payload = await response.json();
            if (!response.ok) {
                const validation = payload.errors ? Object.values(payload.errors).flat().join('\n') : (payload.error || payload.message);
                throw new Error(validation || 'La requête a échoué.');
            }
            stopLoadingTurn(loadingTurn);
            loadingTurn.replaceWith(addAssistantTurn(payload));
        } catch (error) {
            stopLoadingTurn(loadingTurn);
            loadingTurn.replaceWith(addErrorTurn(error.message || 'Erreur inconnue.'));
        } finally {
            sendButton.disabled = false;
            sendButton.classList.remove('is-loading');
            scrollToLatest();
            questionInput.focus();
        }
    });
</script>
</body>
</html>
