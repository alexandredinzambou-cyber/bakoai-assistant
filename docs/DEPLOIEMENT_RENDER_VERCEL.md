# Backend Render et site Vercel

Préparation du 3 novembre 2026. Aucun service Render n'a été créé depuis cette
session : le contrôle du navigateur a échoué avant son ouverture, avec l'erreur
`failed to write kernel assets: Le chemin d’accès spécifié est introuvable. (os error 3)`.
La réinitialisation de l'outil n'a pas résolu cette erreur.

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

1. Publier les fichiers préparés et les corrections applicatives choisies sur
   le dépôt privé `alexandredinzambou-cyber/bakoai-assistant`. Render construit
   des commits distants, pas les fichiers du poste local. Aucun commit ni push
   n'a été effectué par l'assistant pendant cette préparation Render.
2. Dans Render, choisir **New > Blueprint**, sélectionner ce dépôt et la branche
   contenant `render.yaml`. Vérifier les ressources annoncées avant création.
3. Renseigner les trois secrets demandés : `APP_KEY`, `MISTRAL_API_KEY` et
   `NVIDIA_EMBEDDING_API_KEY`. `APP_KEY` doit provenir de
   `php artisan key:generate --show`, pas d'une chaîne aléatoire quelconque.
   Utiliser une nouvelle clé stable propre à ce déploiement. Les clés ne doivent
   jamais apparaître dans Git, Vercel ou un message du chat.
4. Le Blueprint injecte `DB_URL` depuis la base Render. Ce nom est intentionnel :
   la configuration Laravel du projet lit `DB_URL`, pas `DATABASE_URL`.
5. Déployer et vérifier les logs, puis `/up`. La première migration active
   l'extension `vector` prise en charge par Render Postgres.
6. Initialiser les 37 sources de `deployment/knowledge-sources.json` par le script
   existant `scripts/ingest-production-knowledge.php`, en ciblant la base Render.
   Le script n'est pas exécuté durant le build et aucune conversation locale
   n'est importée. Sur une offre payante, il peut être lancé depuis le Shell du
   service. L'offre gratuite n'a ni Shell ni tâches ponctuelles : l'initialisation
   doit alors être effectuée depuis le poste local avec l'URL externe de la base
   Render et les mêmes fournisseurs, en imposant `APP_ENV=production`,
   `DB_URL=<URL externe Render>` et `DB_SSLMODE=require` au processus uniquement.
   Ne pas modifier le `.env` local et ne pas afficher l'URL contenant le mot de
   passe. Cette étape reste à réaliser après accès au compte.
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
expire après 30 jours et n'a pas de sauvegardes gérées. Le service web se met en
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
- Le moteur Docker n'est pas installé sur ce poste : l'image n'a pas été
  construite ni exécutée ici. La construction et le test HTTP sur Render
  restent à faire après rétablissement de l'accès au compte.

Références : [Blueprints](https://render.com/docs/blueprint-spec),
[Laravel et Docker](https://render.com/docs/deploy-php-laravel-docker),
[pgvector](https://render.com/docs/postgresql-extensions),
[offres gratuites](https://render.com/docs/free).
