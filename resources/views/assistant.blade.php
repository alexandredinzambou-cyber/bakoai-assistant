<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>BakoAI — Bureau d’intégration</title>
    <style>
        :root {
            --ink: #111916;
            --ink-soft: #33433d;
            --paper: #f1efe5;
            --paper-deep: #e4e0d0;
            --panel: #fbfaf5;
            --lime: #c9f45d;
            --orange: #e66537;
            --teal: #147b69;
            --line: rgba(17, 25, 22, .17);
            --shadow: 0 22px 70px rgba(17, 25, 22, .12);
            --display: "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
            --body: Bahnschrift, "Avenir Next", "Trebuchet MS", sans-serif;
        }

        * { box-sizing: border-box; }

        html { min-width: 320px; background: var(--paper); }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            font: 15px/1.55 var(--body);
            background:
                radial-gradient(circle at 6% 12%, rgba(201, 244, 93, .32), transparent 24rem),
                radial-gradient(circle at 96% 88%, rgba(230, 101, 55, .17), transparent 27rem),
                var(--paper);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: .18;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 180 180' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.72' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.13'/%3E%3C/svg%3E");
        }

        button, input, select { font: inherit; }
        button, select { cursor: pointer; }

        .shell {
            position: relative;
            width: min(1440px, calc(100vw - 36px));
            min-height: calc(100vh - 36px);
            margin: 18px auto;
            display: grid;
            grid-template-columns: 310px minmax(0, 1fr);
            border: 1px solid rgba(17, 25, 22, .26);
            background: rgba(251, 250, 245, .82);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .rail {
            position: relative;
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 32px 28px 26px;
            color: #f6f5ed;
            background: var(--ink);
            overflow: hidden;
        }

        .rail::after {
            content: "05";
            position: absolute;
            right: -16px;
            bottom: 54px;
            color: rgba(201, 244, 93, .09);
            font: 190px/.7 var(--display);
            letter-spacing: -.09em;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .mark {
            width: 37px;
            aspect-ratio: 1;
            display: grid;
            place-items: center;
            border-radius: 50%;
            color: var(--ink);
            background: var(--lime);
            font-weight: 800;
            box-shadow: inset 0 0 0 6px rgba(17, 25, 22, .11);
        }

        .brand-name {
            font: 700 21px/1 var(--display);
            letter-spacing: -.02em;
        }

        .eyebrow {
            margin: 70px 0 16px;
            color: var(--lime);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .17em;
            text-transform: uppercase;
        }

        h1 {
            max-width: 235px;
            margin: 0;
            font: 400 42px/.96 var(--display);
            letter-spacing: -.045em;
        }

        .rail-copy {
            max-width: 235px;
            margin: 22px 0 0;
            color: rgba(246, 245, 237, .66);
            font-size: 13px;
        }

        .principles {
            position: relative;
            z-index: 1;
            margin: auto 0 24px;
            padding: 0;
            list-style: none;
        }

        .principles li {
            display: grid;
            grid-template-columns: 24px 1fr;
            gap: 10px;
            padding: 10px 0;
            border-top: 1px solid rgba(246, 245, 237, .13);
            color: rgba(246, 245, 237, .72);
            font-size: 12px;
        }

        .principles strong { color: var(--lime); font-weight: 600; }

        .status {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 9px;
            color: rgba(246, 245, 237, .64);
            font-size: 12px;
        }

        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #e9b34e;
            box-shadow: 0 0 0 5px rgba(233, 179, 78, .12);
        }

        .status-dot.ready { background: var(--lime); box-shadow: 0 0 0 5px rgba(201, 244, 93, .12); }
        .status-dot.offline { background: var(--orange); box-shadow: 0 0 0 5px rgba(230, 101, 55, .13); }

        .workspace {
            min-width: 0;
            min-height: calc(100vh - 36px);
            display: grid;
            grid-template-rows: auto minmax(320px, 1fr) auto;
        }

        .topbar {
            min-height: 90px;
            padding: 20px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            border-bottom: 1px solid var(--line);
        }

        .topbar-title { font: 600 18px/1.2 var(--display); }
        .topbar-subtitle { margin-top: 4px; color: #6c7771; font-size: 12px; }

        .scope {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 6px;
        }

        .scope span {
            padding: 5px 9px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--ink-soft);
            background: rgba(255, 255, 255, .45);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .035em;
        }

        .conversation {
            min-height: 0;
            overflow-y: auto;
            scroll-behavior: smooth;
        }

        .messages {
            width: min(850px, calc(100% - 48px));
            margin: 0 auto;
            padding: 44px 0 36px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .message {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            gap: 14px;
            animation: rise .36s ease both;
        }

        .message.user { grid-template-columns: minmax(0, 1fr) 42px; }
        .message.user .avatar { grid-column: 2; background: var(--orange); color: white; }
        .message.user .bubble { grid-column: 1; grid-row: 1; justify-self: end; background: var(--ink); color: #f8f7f0; }

        .avatar {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border: 1px solid var(--line);
            border-radius: 50%;
            color: var(--ink);
            background: var(--lime);
            font: 800 12px/1 var(--body);
        }

        .bubble {
            width: fit-content;
            max-width: 720px;
            padding: 17px 19px;
            border: 1px solid var(--line);
            background: var(--panel);
            box-shadow: 0 8px 28px rgba(17, 25, 22, .06);
        }

        .message-text { white-space: pre-wrap; overflow-wrap: anywhere; }

        .answer-meta {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
        }

        .meta-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

        .pill {
            padding: 4px 8px;
            border-radius: 999px;
            color: var(--ink-soft);
            background: var(--paper);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .pill.warn { color: #8b3217; background: #f9dfd5; }
        .pill.ok { color: #185d4d; background: #daf0e8; }

        .source-list { margin: 12px 0 0; padding: 0; list-style: none; }
        .source-list li { margin-top: 5px; color: #62706a; font-size: 12px; }
        .source-list a { color: var(--teal); text-underline-offset: 3px; }

        .ticket-button {
            margin-top: 14px;
            padding: 9px 12px;
            border: 1px solid var(--ink);
            color: var(--ink);
            background: var(--lime);
            font-size: 12px;
            font-weight: 800;
        }

        .ticket-button:disabled { opacity: .55; cursor: wait; }

        .loading-bubble { display: flex; align-items: center; gap: 8px; padding: 16px 19px; }

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
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .03em;
            color: var(--ink-soft);
            transition: opacity .25s ease;
        }

        @keyframes bubble-bounce {
            0%, 80%, 100% { transform: translateY(0) scale(1); opacity: .5; }
            40% { transform: translateY(-5px) scale(1.2); opacity: 1; }
        }

        @media (prefers-reduced-motion: reduce) {
            .bubble-loader { animation: none; }
        }

        .composer {
            border-top: 1px solid var(--line);
            background: rgba(241, 239, 229, .89);
            backdrop-filter: blur(16px);
        }

        .quick-prompts {
            width: min(900px, calc(100% - 48px));
            margin: 0 auto;
            padding: 14px 0 0;
            display: flex;
            gap: 7px;
            overflow-x: auto;
            scrollbar-width: thin;
        }

        .quick-prompts button {
            flex: 0 0 auto;
            padding: 7px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--ink-soft);
            background: rgba(251, 250, 245, .75);
            font-size: 11px;
        }

        .quick-prompts button:hover { border-color: var(--teal); color: var(--teal); }

        form {
            width: min(900px, calc(100% - 48px));
            margin: 0 auto;
            padding: 12px 0 20px;
        }

        .filters {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 8px;
        }

        .field { position: relative; }
        .field label { position: absolute; left: 12px; top: 7px; z-index: 1; color: #77827d; font-size: 9px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }

        select, input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 0;
            color: var(--ink);
            background: var(--panel);
            outline: none;
        }

        select { height: 52px; padding: 19px 34px 5px 11px; font-size: 12px; }
        select:focus, input:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(20, 123, 105, .1); }

        .question-row { display: grid; grid-template-columns: minmax(0, 1fr) 58px; }
        input { height: 58px; padding: 0 18px; border-right: 0; font-size: 14px; }

        .send {
            display: grid;
            place-items: center;
            border: 1px solid var(--ink);
            color: var(--ink);
            background: var(--lime);
            font-size: 21px;
            transition: transform .18s ease, background .18s ease;
        }

        .send:hover { background: #b7e542; }
        .send:active { transform: scale(.96); }
        .send:disabled { cursor: wait; opacity: .55; }

        .form-note { margin: 8px 0 0; color: #77827d; font-size: 10px; text-align: center; }

        @keyframes rise {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 900px) {
            .shell { width: 100%; min-height: 100vh; margin: 0; grid-template-columns: 1fr; border: 0; }
            .rail { min-height: auto; padding: 20px 22px; display: grid; grid-template-columns: 1fr auto; align-items: center; }
            .eyebrow, .rail h1, .rail-copy, .principles, .rail::after { display: none; }
            .workspace { min-height: calc(100vh - 78px); }
            .topbar { min-height: 78px; }
        }

        @media (max-width: 620px) {
            .shell { min-width: 320px; }
            .rail { grid-template-columns: 1fr; gap: 10px; }
            .status { display: none; }
            .topbar { padding: 16px 18px; }
            .scope { display: none; }
            .messages, form, .quick-prompts { width: calc(100% - 28px); }
            .messages { padding-top: 28px; }
            .message, .message.user { grid-template-columns: 32px minmax(0, 1fr); gap: 9px; }
            .message.user { grid-template-columns: minmax(0, 1fr) 32px; }
            .avatar { width: 32px; height: 32px; font-size: 9px; }
            .bubble { padding: 14px; }
            .filters { grid-template-columns: 1fr; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; animation: none !important; transition: none !important; }
            .bubble-loader { opacity: 1; }
        }
    </style>
</head>
<body>
<main class="shell">
    <aside class="rail" aria-label="Présentation du service">
        <div class="brand"><span class="mark">B</span><span class="brand-name">BakoAI</span></div>
        <p class="eyebrow">Bureau d’intégration</p>
        <h1>La documentation, sans détour.</h1>
        <p class="rail-copy">Un assistant de lecture pour la passerelle PVIT. Il explique comment elle relie les moyens de paiement couverts, mais n’écrit jamais votre code.</p>
        <ol class="principles">
            <li><strong>01</strong><span>Réponses fondées sur les extraits indexés</span></li>
            <li><strong>02</strong><span>Sources et liens officiels systématiques</span></li>
            <li><strong>03</strong><span>Escalade quand la documentation ne suffit pas</span></li>
        </ol>
        <div class="status" role="status" aria-live="polite">
            <span class="status-dot" id="statusDot"></span>
            <span id="statusText">Vérification des services…</span>
        </div>
    </aside>

    <section class="workspace" aria-label="Assistant d’intégration">
        <header class="topbar">
            <div>
                <div class="topbar-title">Assistant API</div>
                <div class="topbar-subtitle">Documentation officielle PVIT · citations contrôlées</div>
            </div>
            <div class="scope" aria-label="Passerelle de paiement interrogée">
                <span>PVIT</span>
            </div>
        </header>

        <div class="conversation" id="conversation">
            <div class="messages" id="messages">
                <article class="message assistant">
                    <div class="avatar" aria-hidden="true">AI</div>
                    <div class="bubble">
                        <div class="message-text">Bonjour. PVIT est la passerelle commune vers Airtel Money, Moov Money, Visa, Mastercard et GIMAC. Je peux expliquer ses flux à partir des documents officiels indexés. Si une information manque ou se contredit, je vous orienterai vers le support.</div>
                    </div>
                </article>
            </div>
        </div>

        <div class="composer">
            <div class="quick-prompts" aria-label="Questions suggérées">
                <button type="button" data-question="Comment créer un compte marchand PVIT ?" data-payment="PVIT" data-operation="inscription">Créer un compte PVIT</button>
                <button type="button" data-question="La documentation PVIT v2 est-elle claire sur le renouvellement du secret ?" data-payment="PVIT" data-operation="renouvellement de secret">Renouveler le secret</button>
                <button type="button" data-question="Que dit la documentation sur les callbacks Airtel Money ?" data-payment="PVIT" data-operation="callback">Callback Airtel</button>
                <button type="button" data-question="Comment un compte de production PVIT est-il relié à GIMAC ?" data-payment="PVIT" data-operation="paiement">Compte GIMAC via PVIT</button>
            </div>
            <form id="askForm">
                <div class="filters">
                    <div class="field">
                        <label for="paymentMethod">Moyen</label>
                        <select id="paymentMethod">
                            <option value="">Sélectionner un moyen</option>
                            <option>PVIT</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="operation">Opération</label>
                        <select id="operation">
                            <option value="">Toutes les opérations</option>
                            <option>authentification</option><option>inscription</option><option>initiation de paiement</option>
                            <option>paiement</option><option>callback</option><option>gestion des erreurs</option>
                            <option>renouvellement de secret</option>
                        </select>
                    </div>
                </div>
                <div class="question-row">
                    <input id="question" name="question" maxlength="8000" placeholder="Posez une question sur le processus d’intégration…" autocomplete="off" required>
                    <button class="send" id="sendButton" type="submit" title="Envoyer" aria-label="Envoyer">↗</button>
                </div>
                <p class="form-note">Aucun secret, identifiant client ou donnée de paiement ne doit être envoyé à l’assistant.</p>
            </form>
        </div>
    </section>
</main>

<script>
    const form = document.getElementById('askForm');
    const messages = document.getElementById('messages');
    const conversation = document.getElementById('conversation');
    const questionInput = document.getElementById('question');
    const paymentSelect = document.getElementById('paymentMethod');
    const operationSelect = document.getElementById('operation');
    const sendButton = document.getElementById('sendButton');
    const statusDot = document.getElementById('statusDot');
    const statusText = document.getElementById('statusText');
    const CONVERSATION_STORAGE_KEY = 'bakoai_conversation_id';
    // The server always issues its own opaque conversation reference (it never trusts a
    // caller-provided one) so memory across turns only works if we track whatever it last
    // returned, not a value generated here.
    let conversationId = window.localStorage.getItem(CONVERSATION_STORAGE_KEY) || null;

    function rememberConversationId(id) {
        if (!id || id === conversationId) return;
        conversationId = id;
        window.localStorage.setItem(CONVERSATION_STORAGE_KEY, id);
    }

    const scrollToLatest = () => {
        conversation.scrollTop = conversation.scrollHeight;
    };

    function addUserMessage(text) {
        const article = document.createElement('article');
        article.className = 'message user';
        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.textContent = 'VOUS';
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        const content = document.createElement('div');
        content.className = 'message-text';
        content.textContent = text;
        bubble.append(content);
        article.append(avatar, bubble);
        messages.append(article);
        scrollToLatest();
    }

    const THINKING_PHRASES = [
        'PVIT réfléchit…',
        'Consultation de la documentation…',
        'Vérification des sources…',
        'Rédaction de la réponse…'
    ];

    function addLoadingMessage() {
        const article = document.createElement('article');
        article.className = 'message assistant';
        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.textContent = 'AI';
        const bubble = document.createElement('div');
        bubble.className = 'bubble loading-bubble';
        bubble.setAttribute('role', 'status');
        bubble.setAttribute('aria-label', 'Réponse en cours de génération…');

        const loader1 = document.createElement('span');
        loader1.className = 'bubble-loader';
        loader1.setAttribute('aria-hidden', 'true');

        const loader2 = document.createElement('span');
        loader2.className = 'bubble-loader';
        loader2.setAttribute('aria-hidden', 'true');

        const loader3 = document.createElement('span');
        loader3.className = 'bubble-loader';
        loader3.setAttribute('aria-hidden', 'true');

        const text = document.createElement('span');
        text.className = 'loading-text';
        text.textContent = THINKING_PHRASES[0];

        bubble.append(loader1, loader2, loader3, text);
        article.append(avatar, bubble);
        messages.append(article);
        scrollToLatest();

        let phraseIndex = 0;
        article._thinkingInterval = window.setInterval(() => {
            phraseIndex = (phraseIndex + 1) % THINKING_PHRASES.length;
            text.style.opacity = '0';
            window.setTimeout(() => {
                text.textContent = THINKING_PHRASES[phraseIndex];
                text.style.opacity = '1';
            }, 250);
        }, 1800);

        return article;
    }

    function removeLoadingMessage(article) {
        window.clearInterval(article._thinkingInterval);
        article.remove();
    }

    function answerWithoutSourceBlock(text) {
        return String(text || '')
            .replace(/\n{2,}Sources consult(?:é|e)es\s*:[\s\S]*$/iu, '')
            .trim();
    }

    function addAssistantMessage(payload) {
        const article = document.createElement('article');
        article.className = 'message assistant';
        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.textContent = 'AI';
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        const content = document.createElement('div');
        content.className = 'message-text';
        content.textContent = answerWithoutSourceBlock(payload.answer) || 'La réponse est indisponible.';
        bubble.append(content);

        const meta = document.createElement('div');
        meta.className = 'answer-meta';
        const row = document.createElement('div');
        row.className = 'meta-row';
        const decision = document.createElement('span');
        const decisions = {
            needs_support: ['warn', 'Support conseillé'],
            needs_clarification: ['warn', 'Périmètre à préciser'],
            refused_code: ['warn', 'Code source refusé'],
            refused_sensitive_data: ['warn', 'Donnée sensible refusée'],
        };
        const [decisionStyle, decisionLabel] = decisions[payload.status]
            || ['ok', payload.reason === 'greeting' ? 'Prêt à aider' : 'Documentation trouvée'];
        decision.className = `pill ${decisionStyle}`;
        decision.textContent = decisionLabel;
        row.append(decision);

        if (typeof payload.confidence === 'number' && (payload.reason === 'grounded_answer' || payload.should_escalate)) {
            const confidence = document.createElement('span');
            confidence.className = 'pill';
            confidence.textContent = `Confiance ${Math.round(payload.confidence * 100)} %`;
            row.append(confidence);
        }
        meta.append(row);

        const sourceList = document.createElement('ul');
        sourceList.className = 'source-list';
        (payload.sources || []).forEach((source) => {
            const item = document.createElement('li');
            item.textContent = `${source.document} / ${source.section}`;
            sourceList.append(item);
        });
        (payload.links || []).forEach((link) => {
            try {
                const url = new URL(link);
                if (url.protocol !== 'https:') return;
                const item = document.createElement('li');
                const anchor = document.createElement('a');
                anchor.href = url.href;
                anchor.target = '_blank';
                anchor.rel = 'noopener noreferrer';
                anchor.textContent = `Documentation officielle — ${url.hostname}`;
                item.append(anchor);
                sourceList.append(item);
            } catch (_) {}
        });
        if (sourceList.childElementCount) meta.append(sourceList);

        if (payload.should_escalate && payload.question_id) {
            const ticket = document.createElement('button');
            ticket.type = 'button';
            ticket.className = 'ticket-button';
            ticket.textContent = 'Ouvrir un ticket de support';
            ticket.addEventListener('click', () => createTicket(ticket, payload.question_id, payload.ticket_token));
            meta.append(ticket);
        }

        bubble.append(meta);
        article.append(avatar, bubble);
        messages.append(article);
        scrollToLatest();
    }

    async function createTicket(button, questionId, ticketToken) {
        button.disabled = true;
        button.textContent = 'Ouverture du ticket…';
        try {
            const response = await fetch('/api/ticket', {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify({question_id: questionId, ticket_token: ticketToken})
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message || 'Ticket indisponible');
            button.textContent = `Ticket #${payload.ticket_id} ouvert`;
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Réessayer d’ouvrir le ticket';
        }
    }

    async function checkStatus() {
        try {
            const response = await fetch('/api/status', {headers: {'Accept': 'application/json'}});
            const payload = await response.json();
            statusDot.classList.toggle('ready', Boolean(payload.connected));
            statusDot.classList.toggle('offline', !payload.connected);
            statusText.textContent = payload.connected
                ? `${payload.documents} document${payload.documents > 1 ? 's' : ''} · LLM ${payload.provider} · Embedding ${payload.embedding_provider || 'n/a'}`
                : 'Configuration incomplète — escalade sécurisée';
        } catch (_) {
            statusDot.classList.add('offline');
            statusText.textContent = 'Service de statut indisponible';
        }
    }

    document.querySelectorAll('[data-question]').forEach((button) => {
        button.addEventListener('click', () => {
            questionInput.value = button.dataset.question || '';
            paymentSelect.value = button.dataset.payment || '';
            operationSelect.value = button.dataset.operation || '';
            questionInput.focus();
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const question = questionInput.value.trim();
        if (!question || sendButton.disabled) return;

        addUserMessage(question);
        questionInput.value = '';
        sendButton.disabled = true;
        sendButton.textContent = '…';
        const loadingMessage = addLoadingMessage();

        try {
            const response = await fetch('/api/ask', {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify({
                    question,
                    moyen_paiement: paymentSelect.value || null,
                    operation: operationSelect.value || null,
                    conversation_id: conversationId
                })
            });
            const payload = await response.json();
            if (!response.ok) {
                const validation = payload.errors ? Object.values(payload.errors).flat().join('\n') : payload.message;
                throw new Error(validation || 'La requête a échoué.');
            }
            rememberConversationId(payload.conversation_id);
            removeLoadingMessage(loadingMessage);
            addAssistantMessage(payload);
        } catch (error) {
            removeLoadingMessage(loadingMessage);
            addAssistantMessage({
                answer: `Je ne peux pas répondre pour le moment. ${error.message || 'Veuillez réessayer.'}`,
                confidence: 0,
                should_escalate: true,
                sources: [],
                links: []
            });
        } finally {
            sendButton.disabled = false;
            sendButton.textContent = '↗';
            questionInput.focus();
        }
    });

    checkStatus();
</script>
</body>
</html>
