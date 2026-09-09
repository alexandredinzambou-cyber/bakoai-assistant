# Configuration de BakoAI Assistant

`.env.example` est le modèle de configuration versionné. Copiez-le vers `.env`, renseignez les secrets localement et ne commitez jamais le fichier réel. Après toute modification, exécutez `php artisan config:clear` avant de diagnostiquer le comportement de l’application.

Sous Windows dans ce workspace, remplacez systématiquement `php` par `E:\IA\.php85-nts\php.exe`. Ce binaire charge les extensions compatibles livrées avec le projet ; un binaire trouvé ailleurs dans le `PATH` peut utiliser un autre `php.ini`.

## LLM ngrok

La configuration active utilise uniquement le fournisseur `ngrok` :

```env
LLM_DEFAULT_PROVIDER=ngrok
LLM_FALLBACK_PROVIDERS=ngrok
LLM_TEMPERATURE=0.1
LLM_TIMEOUT=45
LLM_RETRY_ATTEMPTS=2
LLM_RETRY_DELAY_MS=1000

NGROK_LLM_BASE_URL=https://your-tunnel.ngrok-free.app/api/v1
NGROK_LLM_ENDPOINT=chat/completions
NGROK_LLM_API_KEY=replace-with-server-token
NGROK_LLM_MODEL=replace-with-served-model
NGROK_LLM_TIMEOUT=45
NGROK_LLM_CONNECT_TIMEOUT=10
NGROK_LLM_RETRY_ATTEMPTS=2
NGROK_LLM_RETRY_DELAY_MS=1000
NGROK_LLM_AUTH_HEADER=Authorization
NGROK_LLM_AUTH_SCHEME=Bearer
NGROK_LLM_HEADERS_JSON='{"ngrok-skip-browser-warning":"bakoai-assistant"}'
```

`NGROK_LLM_BASE_URL`, `NGROK_LLM_API_KEY` et `NGROK_LLM_MODEL` doivent être non vides. La base d’un tunnel doit être une URL HTTPS sans identifiants, query string ni fragment. `NGROK_LLM_ENDPOINT` doit être un chemin relatif sans URL absolue, query string ni fragment.

Le client borne les valeurs effectives :

- timeout total entre 1 et 120 secondes ;
- timeout de connexion entre 1 et 30 secondes, sans dépasser le timeout total ;
- 1 à 5 tentatives ;
- délai de retry entre 0 et 10 000 ms.

Les retries concernent les erreurs réseau, HTTP 408, 425, 429 et 5xx. Les redirections sont désactivées. Consultez [LLM_NGROK.md](LLM_NGROK.md) pour les règles d’authentification et d’en-têtes.

Après création ou rotation du tunnel :

```bash
php artisan config:clear
php artisan llm:test --provider=fallback
```

`llm:test --provider=fallback` parcourt `LLM_FALLBACK_PROVIDERS`, qui ne contient que `ngrok` dans la configuration active. `GET /api/status` vérifie que les trois valeurs obligatoires sont présentes, mais ne réalise pas d’appel distant et ne remplace donc pas ce test.

## Embeddings NVIDIA

La configuration active conserve un seul espace vectoriel :

```env
RAG_EMBEDDING_DIMENSIONS=768
RAG_EMBEDDING_MODEL=nvidia/llama-nemotron-embed-vl-1b-v2

EMBEDDING_DEFAULT_PROVIDER=nvidia
EMBEDDING_FALLBACK_PROVIDERS=nvidia
EMBEDDING_ALLOW_PROVIDER_FALLBACK=false
EMBEDDING_DIMENSIONS=768
EMBEDDING_TIMEOUT=45
EMBEDDING_RETRY_ATTEMPTS=2
EMBEDDING_RETRY_DELAY_MS=1000

NVIDIA_EMBEDDING_BASE_URL=https://integrate.api.nvidia.com/v1
NVIDIA_EMBEDDING_API_KEY=replace-with-nvidia-key
NVIDIA_EMBEDDING_MODEL=nvidia/llama-nemotron-embed-vl-1b-v2
NVIDIA_EMBEDDING_TIMEOUT=45
NVIDIA_EMBEDDING_TRUNCATE=END
```

`EMBEDDING_ALLOW_PROVIDER_FALLBACK=false` interdit le passage automatique vers un autre fournisseur. `EMBEDDING_FALLBACK_PROVIDERS=nvidia` évite aussi qu’une liste historique réintroduise un autre espace. Le fournisseur local est réservé aux tests explicitement hors ligne.

Vérification et réindexation :

```bash
php artisan embedding:test --provider=nvidia
php artisan rag:reembed --provider=nvidia
```

La commande `rag:reembed` traite les documents actifs et remplace les vecteurs atomiquement. Ajoutez `--all` seulement si les versions historiques inactives doivent aussi être recalculées. Ne changez jamais le modèle ou la dimension sans cette opération et sans vérifier la compatibilité de `vector(768)`.

## PostgreSQL et pgvector

Configuration minimale :

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=bakoai_assistant
DB_USERNAME=postgres
DB_PASSWORD=
```

L’extension doit être disponible dans la base :

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Appliquez ensuite toutes les migrations, y compris les migrations de gouvernance, conversations, feedbacks et enrichissement métier :

```bash
php artisan migrate
```

SQLite en mémoire est utilisé par la suite de tests applicatifs. Le binaire PHP de test doit donc charger `pdo_sqlite` et `sqlite3` en plus des extensions de production.

## Retrieval et sources

Valeurs principales :

```env
RAG_CHUNK_SIZE=1400
RAG_CHUNK_OVERLAP=180
RAG_TOP_K=5
RAG_RRF_RANK_CONSTANT=60
RAG_MIN_CONFIDENCE=0.55
RAG_LLM_CONFIDENCE_FLOOR=0.50
RAG_MAX_SOURCE_BYTES=5242880
RAG_MAX_CHUNKS_PER_DOCUMENT=1000
RAG_VERIFY_SOURCE_URLS=true
RAG_SOURCE_CONNECT_TIMEOUT=20
RAG_SOURCE_TIMEOUT=35
RAG_ALLOWED_SOURCE_HOSTS=docs.mypvit.pro
RAG_ALLOWED_SOURCE_PATH_PREFIXES=/fr/,/openapi/
RAG_ADMIN_KEY=replace-with-a-long-random-secret
```

`RAG_VERIFY_SOURCE_URLS=true` est la valeur attendue pour publier une source. Les téléchargements utilisent HTTPS, n’acceptent que les hôtes et préfixes configurés et ne suivent pas de redirection. `RAG_MAX_CHUNKS_PER_DOCUMENT` interrompt une source anormalement volumineuse avant tout appel d’embedding. `RAG_ADMIN_KEY` doit être long, aléatoire, distinct des clés LLM/embedding et transmis uniquement sur TLS.

## Commandes Windows de référence

Depuis `E:\IA\bakoai-assistant` :

```powershell
E:\IA\.php85-nts\php.exe artisan about
E:\IA\.php85-nts\php.exe artisan config:clear
E:\IA\.php85-nts\php.exe artisan migrate
E:\IA\.php85-nts\php.exe artisan llm:test --provider=fallback
E:\IA\.php85-nts\php.exe artisan embedding:test --provider=nvidia
E:\IA\.php85-nts\php.exe artisan test
```

Si `composer test` est utilisé, vérifiez que Composer lui-même s’exécute avec ce PHP 8.4.1+ ; les scripts Composer utilisent le binaire PHP du processus Composer.
