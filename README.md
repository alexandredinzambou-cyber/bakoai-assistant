# BakoAI Assistant API

BakoAI Assistant est une API Laravel de type RAG destinée aux développeurs partenaires. Elle répond à partir de la documentation officielle d’intégration des paiements, cite ses sources et bascule vers le support quand l’information est absente, contradictoire ou insuffisamment fiable. Elle ne doit jamais générer, corriger, compléter ni traduire du code source dans ses réponses finales.

PVIT est l’unique corpus documentaire. Airtel Money, Moov Money, Visa, Mastercard et GIMAC sont des contextes de paiement routés vers ce corpus commun, pas des corpus indépendants.

Les volumes de documents, de chunks et de tests évoluent avec l’index et la suite. Ce README ne fige donc aucun total : l’état courant doit être obtenu avec `/api/status`, la base active et l’exécution de la suite de tests.

## État du projet

| Capacité | État actuel |
|---|---|
| Question/réponse RAG, citations, historique et escalade | Implémenté |
| Garde-fous sur les secrets, le code, les injections de prompt et les sorties non étayées | Implémenté et couvert par des tests ciblés |
| Ingestion documentaire et OpenAPI, vérification des sources et ré-embedding atomique | Implémenté |
| LLM actif | Service OpenAI-compatible exposé derrière un tunnel HTTPS ngrok |
| Embeddings actifs | NVIDIA NIM, 768 dimensions |
| Fallback automatique entre fournisseurs d’embeddings | Désactivé par défaut |
| Identifiant de corrélation API | Implémenté via `X-Correlation-ID` |
| Modèle de gouvernance des connaissances, conversations, feedbacks et audit | Migrations, modèles et service de versionnement disponibles |
| Authentification partenaire et RBAC complet | Pas encore livré |
| API d’administration de la connaissance | Sources et transitions de versions protégées par une clé partagée ; pas encore de RBAC |

Les transitions de versions sont exposées par API et auditées. La clé partagée reste toutefois une protection transitoire : elle ne remplace pas une identité administrative ni des autorisations par rôle.

## Architecture

- Laravel 13 et PHP 8.4.1 ou supérieur ;
- PostgreSQL 15 ou supérieur avec pgvector 0.5.0 ou supérieur ;
- vecteurs `vector(768)` et index HNSW ;
- recherche hybride sémantique/lexicale fusionnée par Reciprocal Rank Fusion ;
- métadonnées de chunks pour le moyen de paiement, l’opération, l’environnement, la méthode HTTP, l’endpoint et les codes d’erreur ;
- LLM OpenAI-compatible via ngrok ;
- embeddings NVIDIA `nvidia/llama-nemotron-embed-vl-1b-v2` ;
- vues Blade sans build frontend obligatoire ;
- PHPUnit avec SQLite en mémoire pour les tests applicatifs portables.

Le flux principal est :

`question → filtres de sécurité → contexte de paiement → corpus PVIT → retrieval hybride → seuil/conflit → LLM ngrok → validation des citations et du code → réponse ou escalade`

## Fournisseurs actifs

### LLM ngrok OpenAI-compatible

Le fournisseur par défaut est `ngrok`. Il appelle en HTTPS un serveur exposant le contrat OpenAI-compatible suivant :

- `POST {NGROK_LLM_BASE_URL}/{NGROK_LLM_ENDPOINT}` ;
- corps JSON avec `model`, `messages` et `temperature` ;
- texte attendu dans `choices[0].message.content` ;
- authentification serveur configurable par `Authorization`, `X-API-Key` ou `X-Auth-Token` ;
- redirections désactivées, délais bornés et retries limités aux erreurs transitoires.

Un tunnel expiré, un service indisponible et une réponse invalide produisent des erreurs typées sans journaliser le secret ni le corps distant. Le détail du contrat et des en-têtes autorisés est dans [docs/LLM_NGROK.md](docs/LLM_NGROK.md).

APIFreeLLM et Gemini ne sont pas des fournisseurs actifs de cette configuration.

### Embeddings NVIDIA

Le fournisseur actif est `nvidia` avec un espace vectoriel de 768 dimensions. La configuration par défaut garde `EMBEDDING_FALLBACK_PROVIDERS=nvidia` et `EMBEDDING_ALLOW_PROVIDER_FALLBACK=false` : une panne NVIDIA doit échouer proprement, jamais produire silencieusement des vecteurs d’un autre espace.

Tout changement de fournisseur, de modèle ou de dimension exige une réindexation contrôlée. `local-hash-v1` reste réservé aux tests ou à un usage hors ligne explicitement choisi ; il ne doit pas être mélangé à l’index NVIDIA.

## Garde-fous exécutables

Le comportement de sécurité ne repose pas uniquement sur le prompt système :

1. Les entrées ressemblant à une clé API, un mot de passe, un Bearer token, un identifiant marchand, une donnée de carte ou une donnée personnelle courante sont refusées avant stockage et avant appel externe.
2. Les demandes explicites de génération, correction, complétion ou traduction de code sont refusées avant le retrieval.
3. Les instructions de type prompt injection sont détectées dans les questions et dans les sources ingérées.
4. Le contexte de paiement demandé est résolu vers le corpus PVIT. Une portée absente, contradictoire ou une comparaison technique non documentée est clarifiée ou escaladée.
5. Le LLM doit fournir des preuves textuelles et des citations internes `[SOURCE:id]`. Une citation absente, inconnue, mal formée ou non étayée provoque un rejet déterministe.
6. Les liens publics sont construits côté serveur depuis les sources enregistrées et limités aux domaines HTTPS autorisés.
7. Une sortie contenant du code, un lien inventé, une source contradictoire ou une confiance insuffisante devient `needs_support`.
8. Une indisponibilité du retrieval ou du LLM ne déclenche jamais une réponse de mémoire.
9. Le pipeline OpenAPI valide la spécification, compare le JSON canonique avec la ressource officielle et exclut exemples, payloads, schémas et références externes de l’index explicatif.

Les réponses exposent notamment `status`, `reason`, `should_escalate`, `sources`, `links`, `contains_code`, `llm_attempted`, `requested_payment_method` et `documentation_corpus_payment_method`. Les champs `confidence`, `confidence_level` et `sources[].confidence_score` sont additionnels et contrôlés par `RAG_EXPOSE_CONFIDENCE` (désactivés par défaut dans cet environnement) ; les seuils internes `RAG_MIN_CONFIDENCE` et `RAG_LLM_CONFIDENCE_FLOOR` continuent de piloter l’escalade et le repli extractif quelle que soit cette option.

## Modèle de données et gouvernance

Les migrations historiques créent le socle RAG : développeurs, responsables, moyens de paiement, documents, chunks, embeddings, questions, réponses, tickets et spécifications OpenAPI.

Les migrations du 5 août 2026 ajoutent :

| Migration | Apport |
|---|---|
| `2026_08_05_000000_create_knowledge_governance_tables.php` | `knowledge_versions`, `indexation_jobs`, `audit_logs` et contrainte d’une seule version active |
| `2026_08_05_010000_create_conversation_feedback_tables.php` | `conversations`, `feedbacks` et leurs relations |
| `2026_08_05_020000_enrich_bakoai_domain_tables.php` | rattachement aux versions, métadonnées de chunks/questions/réponses, enrichissement des tickets et index métier |
| `2026_08_05_030000_enforce_unique_source_per_knowledge_version.php` | unicité transactionnelle d’une source officielle dans chaque version de connaissance |

`KnowledgeVersionService` sait créer une version de staging, la valider, l’activer de manière atomique, l’échouer et revenir à une version validée. Avant toute publication ou restauration, un préflight verrouillé refuse un corpus vide ou partiel, une source non vérifiée, un document sans chunk actif, un embedding manquant et un modèle vectoriel différent de celui du retrieval. La publication active simultanément les documents et spécifications OpenAPI vérifiés de la version, tout en conservant les versions précédentes pour un rollback. Les imports et transitions sont audités dans leurs transactions métier. Les transitions publiques restent protégées par `RAG_ADMIN_KEY`, sans RBAC.

## Installation

Prérequis :

- PHP 8.4.1+ avec `curl`, `dom`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_pgsql`, `pgsql`, `sodium`, `xml` et `xmlwriter` ;
- `pdo_sqlite` et `sqlite3` pour la suite de tests locale ;
- Composer ;
- PostgreSQL 15+ avec pgvector 0.5.0+ ;
- un endpoint LLM HTTPS OpenAI-compatible joignable via ngrok ;
- une clé NVIDIA pour les embeddings.

Installation standard sous Linux ou macOS :

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan rag:ingest --moyen=PVIT
php artisan serve
```

Créer la base `bakoai_assistant` et activer pgvector avec un rôle PostgreSQL autorisé :

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

### Windows dans ce workspace

Le binaire validé est `E:\IA\.php85-nts\php.exe`. Il charge le `php.ini` et les extensions compatibles livrés dans `E:\IA\.php85-nts`. Utilisez-le explicitement : un autre `php.exe` trouvé dans le `PATH` peut charger un `php.ini` incompatible.

```powershell
composer install
Copy-Item .env.example .env
E:\IA\.php85-nts\php.exe artisan key:generate
E:\IA\.php85-nts\php.exe artisan migrate
E:\IA\.php85-nts\php.exe artisan db:seed
E:\IA\.php85-nts\php.exe artisan rag:ingest --moyen=PVIT
E:\IA\.php85-nts\php.exe artisan serve
```

Dans les exemples suivants, remplacez `php` par `E:\IA\.php85-nts\php.exe` sous Windows. Les scripts `scripts/create-database.php`, `scripts/create-vector-extension.php` et `scripts/postgres-info.php` sont des aides pour l’environnement local, pas un mécanisme de déploiement.

## Configuration minimale

Copiez `.env.example` vers `.env`, puis renseignez au minimum les valeurs suivantes sans commiter les secrets :

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=bakoai_assistant
DB_USERNAME=postgres
DB_PASSWORD=

LLM_DEFAULT_PROVIDER=ngrok
LLM_FALLBACK_PROVIDERS=ngrok
NGROK_LLM_BASE_URL=https://your-tunnel.ngrok-free.app/api/v1
NGROK_LLM_ENDPOINT=chat/completions
NGROK_LLM_API_KEY=replace-with-server-token
NGROK_LLM_MODEL=replace-with-served-model
NGROK_LLM_AUTH_HEADER=Authorization
NGROK_LLM_AUTH_SCHEME=Bearer
NGROK_LLM_HEADERS_JSON='{"ngrok-skip-browser-warning":"bakoai-assistant"}'

EMBEDDING_DEFAULT_PROVIDER=nvidia
EMBEDDING_FALLBACK_PROVIDERS=nvidia
EMBEDDING_ALLOW_PROVIDER_FALLBACK=false
EMBEDDING_DIMENSIONS=768
NVIDIA_EMBEDDING_BASE_URL=https://integrate.api.nvidia.com/v1
NVIDIA_EMBEDDING_API_KEY=replace-with-nvidia-key
NVIDIA_EMBEDDING_MODEL=nvidia/llama-nemotron-embed-vl-1b-v2
NVIDIA_EMBEDDING_TRUNCATE=END

RAG_EMBEDDING_DIMENSIONS=768
RAG_EMBEDDING_MODEL=nvidia/llama-nemotron-embed-vl-1b-v2
RAG_MAX_CHUNKS_PER_DOCUMENT=1000
RAG_VERIFY_SOURCE_URLS=true
RAG_ALLOWED_SOURCE_HOSTS=docs.mypvit.pro
RAG_ALLOWED_SOURCE_PATH_PREFIXES=/fr/,/openapi/
RAG_ADMIN_KEY=replace-with-a-long-random-secret
```

Après une rotation de tunnel ou une modification de `.env`, videz le cache de configuration puis testez le fournisseur :

```bash
php artisan config:clear
php artisan llm:test --provider=fallback
```

La commande utilise `fallback` parce que la liste configurée ne contient que `ngrok`. La configuration détaillée est dans [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## API

Interface de test : `http://127.0.0.1:8000/assistant`

Question :

```http
POST /api/ask
Content-Type: application/json
X-Correlation-ID: integration-run-20260805

{
  "question": "Comment l’e-mail est-il vérifié pendant l’inscription MyPVit ?",
  "moyen_paiement": "PVIT",
  "operation": "inscription"
}
```

Le middleware conserve un `X-Correlation-ID` entrant valide ou en génère un, puis le retourne dans la réponse.

Création idempotente d’un ticket :

```http
POST /api/ticket
Content-Type: application/json

{
  "question_id": 1,
  "ticket_token": "jeton opaque retourné par /api/ask"
}
```

Le jeton lie le ticket à la question créée. Le champ public `developpeur_id` reste interdit tant que l’authentification partenaire n’est pas en place.

`GET /api/status` vérifie sans appel distant la base, pgvector, la configuration du LLM et l’existence de documents actifs. Il ne valide donc pas à lui seul que le tunnel répond.

Les limites de débit actuelles sont de 30 requêtes par minute sur `/api/ask`, 10 sur `/api/ticket` et 10 sur le groupe `/api/knowledge`.

Administration des versions de connaissance, avec `Authorization: Bearer <RAG_ADMIN_KEY>` :

- `GET /api/knowledge/versions` ;
- `POST /api/knowledge/versions` ;
- `POST /api/knowledge/versions/{id}/validate` ;
- `POST /api/knowledge/versions/{id}/activate` ;
- `POST /api/knowledge/versions/rollback`.

## Mise à jour des connaissances

Réindexation des sources présentes dans `storage/app/sources/` :

```bash
php artisan rag:ingest --moyen=PVIT
```

Test NVIDIA puis remplacement atomique des embeddings des documents actifs :

```bash
php artisan embedding:test --provider=nvidia
php artisan rag:reembed --provider=nvidia
```

Après la migration qui ajoute les filtres structurés, enrichissez les anciens chunks sans recalculer leurs embeddings :

```bash
php artisan rag:backfill-metadata --dry-run
php artisan rag:backfill-metadata
```

Ajoutez `--all` à `rag:reembed` uniquement pour inclure aussi les documents historiques inactifs.

Ingestion d’un fichier local associé à son URL officielle :

```bash
php artisan rag:ingest \
  --path=storage/app/sources/pvit-complement.md \
  --source-url=https://docs.mypvit.pro/fr/chemin/officiel \
  --moyen=PVIT
```

Le pipeline générique accepte `.md`, `.html`, `.htm` et `.txt`. Une URL doit utiliser HTTPS et respecter `RAG_ALLOWED_SOURCE_HOSTS` ainsi que `RAG_ALLOWED_SOURCE_PATH_PREFIXES`.

Pour préparer un corpus sans modifier le corpus actif, créez d’abord une version en staging par API, puis associez les imports à son identifiant :

```bash
php artisan rag:ingest \
  --path=storage/app/sources/pvit-complement.md \
  --source-url=https://docs.mypvit.pro/fr/chemin/officiel \
  --knowledge-version=12 \
  --moyen=PVIT
```

La source reste inactive jusqu’aux transitions `validate`, puis `activate`. L’activation est transactionnelle et un rollback peut republier la dernière version précédemment active.

Téléchargement et ingestion de pages autorisées :

```bash
php artisan rag:ingest \
  --url=https://docs.mypvit.pro/fr/tutoriels/register \
  --url=https://docs.mypvit.pro/fr/intro/getting-started \
  --moyen=PVIT
```

L’administration existante exige `Authorization: Bearer <RAG_ADMIN_KEY>` ou `X-BakoAI-Admin-Key` :

- `GET /api/knowledge/sources` ;
- `POST /api/knowledge/sources` en multipart avec `source`, `moyen_paiement`, `source_url` et éventuellement `version`.

Cette clé partagée est un contrôle transitoire, pas un remplacement de l’authentification et du RBAC.

### Spécifications OpenAPI

Le JSON brut passe uniquement par la commande dédiée :

```bash
php artisan rag:ingest-openapi storage/app/sources/pvit-openapi.json \
  --source-url=https://docs.mypvit.pro/openapi/chemin-officiel.json \
  --moyen=PVIT
```

Remplacez l’URL d’exemple par la ressource officielle réelle. Le pipeline accepte OpenAPI 3.x et Swagger 2.0, résout uniquement les références JSON Pointer internes et exige une égalité JSON canonique avec la ressource HTTPS officielle. L’API d’upload générique n’accepte pas le JSON.

## Tests et évaluation

Suite applicative :

```bash
composer test
php artisan test
php artisan test --testsuite=Unit
php artisan test tests/Feature/AssistantApiTest.php
```

Sous Windows, les commandes de référence sont :

```powershell
E:\IA\.php85-nts\php.exe artisan test
E:\IA\.php85-nts\php.exe artisan test --testsuite=Unit
E:\IA\.php85-nts\php.exe artisan test tests/Feature/AssistantApiTest.php
```

Tests des fournisseurs et évaluation RAG :

```bash
php artisan llm:test --provider=fallback
php artisan embedding:test --provider=nvidia
php artisan assistant:eval --routing-only --format=json
php artisan assistant:eval
php artisan assistant:eval --format=json
php artisan assistant:eval --fail-on-target
```

`rag:eval` reste un alias de compatibilité, mais `assistant:eval` est le nom principal. `--routing-only` vérifie le routage contexte → PVIT sans appeler le LLM. `--fail-on-target` renvoie un code non nul si les objectifs configurés ne sont pas atteints.

Ne recopiez pas un ancien nombre de tests ou d’assertions dans une livraison : la sortie de la suite complète exécutée avec le bon binaire PHP est la source de vérité. Les rapports déjà présents dans `storage/app/eval-reports/` sont historiques ; un rapport associé à un ancien fournisseur ne valide pas la configuration ngrok/NVIDIA actuelle.

## Limites avant production

- L’authentification partenaire, la gestion de session ou de jetons et le RBAC complet ne sont pas encore livrés.
- Les routes de connaissance utilisent une clé d’administration partagée ; elles doivent être placées derrière TLS et un contrôle d’accès réseau jusqu’au remplacement par une identité forte.
- Les tables d’audit et de jobs existent, mais la journalisation exhaustive de chaque action d’administration et l’orchestration asynchrone complète restent à intégrer.
- Un tunnel ngrok peut expirer ou changer d’URL ; sa rotation doit inclure `config:clear` et un test réel du LLM.
- L’index dépend de NVIDIA. Le fallback inter-fournisseur est volontairement désactivé pour éviter de mélanger des espaces vectoriels.
- Le corpus PVIT ne documente pas nécessairement un comportement technique propre à chaque rail ni toutes les opérations ; ces questions doivent rester escaladées.
- Le pipeline OpenAPI est limité au JSON, sans références externes et sans exemples ou schémas exécutables.
- Un document non-OpenAPI uploadé par un administrateur reste une entrée de confiance : son URL est vérifiée, mais son contenu n’est pas comparé octet par octet à la ressource distante.
- La défense anti-code et anti-injection combine plusieurs contrôles, mais doit continuer à être testée avec des cas adversariaux.
- Files de jobs, chiffrement métier, stockage de secrets, supervision et alerting doivent être finalisés avant exposition publique.

Voir [docs/SECURITY.md](docs/SECURITY.md) pour la frontière de sécurité actuelle.

## Documentation complémentaire

- [Configuration](docs/CONFIGURATION.md)
- [LLM ngrok OpenAI-compatible](docs/LLM_NGROK.md)
- [Sécurité et limites](docs/SECURITY.md)
