# Backend Render et site Vercel

Déploiement réalisé le 3 novembre 2026 :

- Site public : https://bakoai-assistant.vercel.app
- Backend : https://bakoai-backend.onrender.com
- État API : https://bakoai-assistant.vercel.app/api/status
- Branche publiée : `deployment/render`.
- Blueprint : `exs-dbkn5ujncjis73eqanng`.
- Service Render : `srv-dbkn9gfb9u1s73ftirvg`.
- PostgreSQL : `dpg-dbkn8ovb9u1s73ftglo0-a`, PostgreSQL 17, pgvector actif.
- Déploiement Render initial : `dep-dbkn9gnb9u1s73ftiu2g` (commit `70aae0f`).
- Déploiement Vercel publié : `dpl_85aq8Q1b4neSkcjihMEqcXHKqKew`.

Les clés Mistral/NVIDIA et la nouvelle clé Laravel ont été enregistrées dans
Render après autorisation de l'utilisateur. Les clés ne sont pas publiées dans
le dépôt et ne sont pas nécessaires au site statique Vercel.

Le schéma et le corpus sont en place : **37 documents actifs, 76 passages et
76 vecteurs NVIDIA de 768 dimensions**. Les contenus et les vecteurs ont été
comparés après écriture avec la version locale exportée. Aucune conversation
locale ni compte utilisateur local n'a été transféré.

L'import HTTP des pages a été essayé, puis arrêté : ces pages officielles sont
rendues en JavaScript et le téléchargement brut ne contient pas leur contenu.
Le déploiement utilise donc une copie transactionnelle du corpus local déjà
vérifié, avec son modèle vectoriel d'origine, sans ré-embedding ni substitution.
La copie a été refusée par défaut si le corpus de destination n'était pas vide.
La preuve locale est `.tmp/render-knowledge-transfer-proof.json` ; les captures
de conversation sont `.tmp/render-backend-smoke.json` et
`.tmp/vercel-production-smoke.json`. Ces artefacts ne contiennent pas de clés.

La variable Vercel `BACKEND_ORIGIN` vise Render en production et en preview.
L'ancien Root Directory `resources` a été remplacé par la racine du dépôt.
L'affectation automatique des domaines était désactivée dans le projet Vercel :
une promotion explicite a relié le domaine public à la nouvelle version.
Les déploiements suivants nécessitent également de vérifier leur promotion.

## Fichiers préparés

- `Dockerfile.render` : PHP 8.4, Apache, extensions PostgreSQL et Tesseract.
- `.dockerignore` : exclusion du fichier `.env`, des caches, conversations,
  rapports, dépendances locales et fichiers de développement.
- `deployment/render/apache.conf` : document root limité à `public`, port fourni
  par Render et logs vers les flux du conteneur.
- `deployment/render/start.sh` : configuration, migrations non destructives et
  lancement Apache. L'URL HTTPS vient de `RENDER_EXTERNAL_URL`.
- `render.yaml` : service `bakoai-backend` et base `bakoai-postgres` dans la même
  région, avec liaison privée automatique par `DB_URL`.

Le chat actuel contient ses styles et scripts dans sa vue Blade : le backend
Docker n'a pas besoin d'une compilation Vite pour servir cette page.

## Mise en ligne

1. Pour reconstruire ce déploiement, publier les corrections applicatives choisies sur
   le dépôt privé `alexandredinzambou-cyber/bakoai-assistant`. Render construit
   des commits distants, pas les fichiers du poste local. Aucun commit ni push
   n'a été effectué par l'assistant pendant cette préparation Render.
2. Dans Render, choisir **New > Blueprint**, sélectionner ce dépôt et la branche
   contenant `render.yaml`. Vérifier les ressources annoncées avant création.
   Pour modifier le backend existant, utiliser sa branche `deployment/render`
   et son déploiement manuel ; ne pas recréer un Blueprint et une base à chaque changement.
3. Renseigner les trois secrets demandés : `APP_KEY`, `MISTRAL_API_KEY` et
   `NVIDIA_EMBEDDING_API_KEY`. `APP_KEY` doit provenir de
   `php artisan key:generate --show`, pas d'une chaîne aléatoire quelconque.
   Utiliser une nouvelle clé stable propre à ce déploiement. Les clés ne doivent
   jamais apparaître dans Git, Vercel ou un message du chat.
4. Le Blueprint injecte `DB_URL` depuis la base Render. Ce nom est intentionnel :
   la configuration Laravel du projet lit `DB_URL`, pas `DATABASE_URL`.
5. Déployer et vérifier les logs, puis `/up`. La première migration active
   l'extension `vector` prise en charge par Render Postgres.
6. Pour une nouvelle base, initialiser le corpus depuis un export validé ou des
   sources HTML rendues. Le téléchargement brut des 37 URL du manifeste ne
   suffit pas pour ces pages JavaScript. Le script
   `scripts/ingest-production-knowledge.php` reste adapté aux sources dont le
   contenu est disponible par HTTP ; il ne doit pas être relancé aveuglément
   pour ce corpus. L'offre gratuite n'a ni Shell ni tâches ponctuelles : les
   opérations de maintenance ont été effectuées depuis le poste local avec
   l'URL externe Render et TLS, sans modifier le `.env` local. Toute future
   restauration doit préserver les données de production déjà présentes et
   disposer d'une sauvegarde adaptée ; le transfert initial refusait un corpus
   non vide et ne constitue pas une procédure de remplacement en production.
7. Vérifier `/api/status`, puis une question documentaire et une relance réelle.
   `/up` seul ne prouve pas que les clés et le corpus fonctionnent.
8. Dans Vercel, remplacer `BACKEND_ORIGIN` par l'origine HTTPS réelle du backend
   Render, sans `/api`, puis reconstruire le site avec `scripts/build-vercel.mjs`.
   Suivre les étapes Vercel de `DEPLOIEMENT_RAILWAY_VERCEL.md`, notamment le
   remplacement de l'ancien Root Directory `resources` par la racine du dépôt.

Le routage Vercel préparé accepte tout backend HTTPS valide, y compris Render :
aucune modification de la vue du chat n'est nécessaire.

## Limites de l'essai gratuit

Le Blueprint choisit explicitement **Free** pour les deux services afin de
préparer un essai sans souscrire une offre payante. La base gratuite Render
expire le **3 décembre 2026** et n'a pas de sauvegardes gérées. Le service web se met en
veille après 15 minutes sans trafic et son réveil prend environ une minute.
Ces limites doivent être prises en compte pour une démonstration et pour la
pérennité des conversations. Une configuration de production durable nécessite
un choix explicite d'offres adaptées.

Les migrations se font au démarrage, car la commande de pré-déploiement n'est
pas disponible sur l'offre gratuite. Le corpus doit être initialisé séparément.
Les sessions, le cache et les conversations sont conservés en base, et non sur
le disque éphémère du service web.

## Vérifications effectuées

- `render.yaml` validé avec le schéma JSON officiel Render.
- Syntaxe de `start.sh` vérifiée avec Bash.
- Analyse des exclusions et des références de configuration.
- Image Docker construite et démarrée avec succès sur Render.
- `/up` et `/api/status` répondent HTTP 200 ; base, pgvector et 37 documents reconnus.
- Conversation de 7 messages réussie directement sur Render, puis de 7 messages
  via le domaine Vercel public : paramètres obligatoires/facultatifs, résumé,
  changement de sujet, durée de 7 jours et conversion à 168 heures.
- Identifiant de conversation stable et mémoire durable sur les 5 tours
  techniques de chacune de ces conversations.
- Question générative supplémentaire : réponse Mistral et citations obtenues
  depuis Render en 4,52 secondes (HTTP 200).
- Page et logo publics Vercel vérifiés HTTP 200 ; prévisualisation vérifiée avant
  publication. Les essais de conversation ne créent aucun ticket de support.

Références : [Blueprints](https://render.com/docs/blueprint-spec),
[Laravel et Docker](https://render.com/docs/deploy-php-laravel-docker),
[pgvector](https://render.com/docs/postgresql-extensions),
[offres gratuites](https://render.com/docs/free).
