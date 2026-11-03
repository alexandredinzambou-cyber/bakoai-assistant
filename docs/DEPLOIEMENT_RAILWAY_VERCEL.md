# Déploiement de PVIT Assistant — Railway et Vercel

État au 3 novembre 2026 : préparation locale effectuée. La création du projet
Railway a été refusée avec le message « Trials are temporarily paused for new
accounts. Upgrade to a paid plan to start deploying. ». Aucun backend Railway
n'a donc été créé. Le backend a ensuite été déployé sur Render et le site Vercel
publié : consulter `DEPLOIEMENT_RENDER_VERCEL.md` pour l'état réellement en ligne.
La suite de ce document décrit l'alternative Railway préparée initialement.

## Organisation

- Railway : API Laravel, Mistral, embeddings NVIDIA et PostgreSQL avec pgvector.
- Vercel : HTML du chat, image et routage des appels API publics vers Railway.
- La vue `resources/views/mistral-raw.blade.php` reste la source de l'interface.
  `scripts/build-vercel.mjs` en produit une version statique à chaque build.
- Le navigateur appelle `/api/mistral-raw/ask` sur son propre domaine Vercel.
  Vercel relaie la requête à Railway. Aucun réglage CORS n'est nécessaire pour
  ce parcours. Les clés des fournisseurs restent dans le service Railway.

## 1. Activer Railway

Activer une offre permettant les déploiements dans l'espace Railway connecté,
puis créer un projet privé `bakoai-assistant`.

Ajouter un service nommé **Postgres** utilisant l'image officielle
`pgvector/pgvector:pg17`. Une image PostgreSQL sans pgvector ne suffit pas.
Avant son premier démarrage, configurer :

```text
POSTGRES_DB=bakoai_assistant
POSTGRES_USER=postgres
POSTGRES_PASSWORD=<mot de passe aléatoire>
PGDATA=/var/lib/postgresql/data/pgdata
```

Attacher un volume persistant à `/var/lib/postgresql/data`.
La base communique avec Laravel par le réseau privé Railway ; son port n'a
pas besoin d'être exposé sur Internet pour ce déploiement.

Créer ensuite un service vide nommé **backend** dans le même projet.

## 2. Configurer Laravel

Utiliser `deployment/railway.env.example` comme liste des variables du service
**backend**. Remplacer chaque `CHANGE_ME`, conserver les références à Postgres
et générer une nouvelle clé d'application, stable lors des futurs déploiements :

```powershell
& 'E:\IA\.php85-nts\php.exe' artisan key:generate --show
```

Copier cette clé uniquement dans `APP_KEY` de Railway. Renseigner les clés
Mistral et NVIDIA dans Railway, sans les publier dans le dépôt ni dans Vercel.
Le modèle Mistral local au moment de la préparation est `open-mistral-nemo`.
Vérifier son accessibilité avec la clé du compte utilisé en production.

Le chat actuel utilise directement Mistral : configurer seulement ngrok dans
`LLM_DEFAULT_PROVIDER` ne le rendrait pas fonctionnel. Le modèle d'embedding,
ses dimensions et les vecteurs indexés doivent correspondre.

`railway.toml` exécute `php artisan migrate --force` en pré-déploiement.
`RAILPACK_SKIP_MIGRATIONS=true` évite de répéter les migrations au démarrage.
Ne pas utiliser `migrate:fresh` ou `migrate:reset` sur la base de production.

Les sessions, le cache et les conversations utilisent PostgreSQL. Les logs
partent vers stderr. `railpack.json` installe Tesseract pour l'inspection locale
des captures d'écran ; `php.ini` permet les pièces jointes acceptées par le chat.

## 3. Déployer le code local du backend

Le dépôt distant connu est `alexandredinzambou-cyber/bakoai-assistant`, mais le
workspace contient de nombreuses corrections locales non publiées. Pour
déployer ces corrections sans dépendre d'un ancien commit GitHub :

```powershell
Set-Location E:\IA\bakoai-assistant
railway link --project <ID_PROJET> --service backend --environment production
railway up --service backend --environment production --detach
railway deployment list --json
railway logs --lines 100
```

`.railwayignore` exclut notamment `.env`, les variantes temporaires contenant
des secrets, les rapports, les dépendances locales et les fichiers de tests.
Railpack installe les dépendances depuis Composer et construit les assets.
Les paramètres de construction doivent utiliser la racine du projet Laravel.

Générer un domaine public dans Railway, puis régler `APP_URL` sur ce domaine
HTTPS. Vérifier `/up` avant de poursuivre. `/up` vérifie le démarrage Laravel ;
il ne prouve pas à lui seul que le corpus et les fournisseurs sont opérationnels.

## 4. Initialiser le corpus documentaire

`deployment/knowledge-sources.json` contient les **37 URL documentaires actives**
relevées dans la base locale pendant cette préparation. Il ne contient ni
conversations, ni comptes utilisateurs, ni clés API, ni copie de base locale.

Dans le conteneur du service **backend**, après les migrations :

```powershell
railway ssh --service backend -- php scripts/ingest-production-knowledge.php
```

Pour le corpus actuel, les pages sont rendues en JavaScript : cet import HTTP
direct a échoué sur Render. Un transfert du corpus déjà indexé a été utilisé,
comme décrit dans le guide Render. Ne pas supposer que cette commande suffit
pour reconstruire toutes les sources actuelles.

Le script refuse de fonctionner hors de l'environnement `production`, initialise
les moyens de paiement puis télécharge et indexe les URL par le pipeline existant.
L'indexation appelle le fournisseur d'embeddings et peut prendre plusieurs minutes.
Elle s'arrête au premier échec et n'est pas exécutée automatiquement à chaque build.
Le corpus distant peut évoluer : cette opération reconstruit les vecteurs depuis
les pages officielles disponibles, elle ne copie pas exactement le corpus local.

Vérifier ensuite `https://<backend>/api/status` : la base, pgvector, la
configuration LLM et le nombre de documents doivent être corrects. Tester aussi
une vraie question documentaire : le statut seul ne teste pas les clés à distance.

## 5. Déployer le site sur Vercel

Projet existant : `bakoai-assistant`, ID `prj_dexdKL10rdyhQdWFEppYV9qfAIG8`,
dans `alexandredinzambou-4011s-projects`.
Son ancien **Root Directory est `resources`**. Le remplacer par la racine du
dépôt (champ vide). Garder le framework **Other**. Le fichier `vercel.json`
définit le build ; il n'a pas besoin d'une installation npm pour l'export statique.
Laisser Output Directory sans surcharge : le script utilise la Build Output API.

Ajouter la variable **non secrète** suivante aux environnements Vercel souhaités :

```text
BACKEND_ORIGIN=https://<domaine-backend>.up.railway.app
```

Utiliser uniquement l'origine, sans `/api`, chemin, paramètres ni identifiants.
Ne jamais copier `APP_KEY`, les clés Mistral/NVIDIA ou le mot de passe PostgreSQL
dans Vercel. Le build ne lit pas le fichier `.env` de Laravel.

Pour un envoi local limité aux fichiers publics préconstruits :

```powershell
Set-Location E:\IA\bakoai-assistant
vercel link --project bakoai-assistant --scope alexandredinzambou-4011s-projects --yes
$env:BACKEND_ORIGIN='https://<domaine-backend>.up.railway.app'
node scripts/build-vercel.mjs
Remove-Item Env:\BACKEND_ORIGIN
vercel deploy --prebuilt --yes
```

Tester d'abord cette prévisualisation avec une vraie question, une relance,
un rafraîchissement de page et une pièce jointe. Ensuite, reconstruire avec
`BACKEND_ORIGIN` puis publier :

```powershell
vercel deploy --prebuilt --prod --yes
```

La publication doit être faite une fois le backend opérationnel. Les futures
publications par GitHub nécessitent de committer et pousser les fichiers préparés
ainsi que les corrections applicatives souhaitées ; rien n'a été poussé ici.

## Limites à vérifier

- Vercel limite les requêtes relayées à 120 secondes. Mesurer une question réelle
  et ses éventuelles nouvelles tentatives ; les timeout locaux des fournisseurs
  ne constituent pas une limite globale du parcours. Le relais doit répondre
  avant cette durée, sinon prévoir un traitement asynchrone ou un accès direct
  à l'API avec une configuration CORS adaptée.
- Les limites API doivent distinguer les visiteurs derrière les proxys : tester
  HTTPS et les adresses clients en production. `TRUSTED_PROXIES=*` est destiné
  au backend derrière le proxy géré Railway.
- L'historique du navigateur est propre à chaque domaine. Les anciens chats
  locaux ne sont pas automatiquement transférés vers le domaine Vercel.

Références : [Laravel sur Railway](https://railway.com/guides/laravel),
[PHP et Railpack](https://railpack.com/languages/php/),
[envoi du code local](https://docs.railway.com/cli/up),
[Build Output API Vercel](https://vercel.com/docs/build-output-api),
[limites du relais Vercel](https://vercel.com/docs/limits).

Vérifications locales disponibles :

```powershell
node scripts/test-vercel-build.mjs
& 'E:\IA\.php85-nts\php.exe' vendor/phpunit/phpunit/phpunit tests/Feature/DeploymentProxyTest.php tests/Feature/ExampleTest.php --no-progress
```

Le test d'export retire sa configuration de routage factice après vérification :
reconstruire avec le vrai domaine Railway avant `vercel deploy --prebuilt`.
