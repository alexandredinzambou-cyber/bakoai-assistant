# Sécurité et frontière de confiance

Ce document décrit les contrôles réellement présents et les limites qui restent à traiter avant une exposition publique.

## Contrôles livrés

- Les routes API reçoivent un `X-Correlation-ID` valide fourni par le client ou un identifiant généré par le serveur, puis le retournent dans la réponse.
- `/api/ask`, `/api/ticket` et `/api/knowledge` sont soumis à des limites de débit.
- Les secrets, tokens, identifiants marchands, données de paiement et données personnelles courantes sont filtrés avant stockage et avant appel externe.
- Les demandes de code et les injections de prompt sont filtrées avant retrieval ; les sources ingérées passent aussi par un contrôle d’instructions hostiles.
- Les réponses LLM sont validées pour les citations, les preuves, les liens et l’absence de code. Une validation échouée mène à `needs_support`.
- Les sources distantes doivent utiliser HTTPS, appartenir à une liste blanche et respecter les préfixes de chemins autorisés. Les redirections ne sont pas suivies.
- Le pipeline OpenAPI compare le JSON canonique local à la ressource officielle avant publication.
- Le client LLM limite les en-têtes d’authentification et interdit les en-têtes supplémentaires sensibles ou liés au transport. Ses exceptions n’incluent ni secret, ni valeur d’en-tête, ni corps distant.
- L’administration de la connaissance refuse de fonctionner si `RAG_ADMIN_KEY` est vide et compare la clé fournie en temps constant.
- Le fallback automatique entre fournisseurs d’embeddings est désactivé par défaut afin d’éviter le mélange d’espaces vectoriels.

## Contrôles transitoires

Les routes suivantes utilisent actuellement une clé partagée `RAG_ADMIN_KEY` :

- `GET /api/knowledge/sources` ;
- `POST /api/knowledge/sources` ;
- `GET|POST /api/knowledge/versions` ;
- les transitions `validate`, `activate` et `rollback` sous `/api/knowledge/versions`.

La clé peut être transmise par `Authorization: Bearer` ou `X-BakoAI-Admin-Key`. Ce mécanisme convient à un environnement interne contrôlé, mais ne fournit ni identité individuelle, ni rôles, ni révocation par utilisateur, ni traçabilité d’autorisation suffisante.

Mesures minimales tant qu’il reste en place :

- exposer les routes uniquement derrière TLS ;
- restreindre leur accès au niveau réseau ou reverse proxy ;
- utiliser une valeur longue, aléatoire et dédiée ;
- stocker la valeur dans un gestionnaire de secrets, jamais dans le dépôt ni dans les logs ;
- faire tourner la clé après toute suspicion d’exposition ;
- ne pas réutiliser les clés ngrok, LLM, NVIDIA ou base de données.

## Fondations présentes, intégration incomplète

Les tables `knowledge_versions`, `indexation_jobs` et `audit_logs`, leurs modèles Eloquent et le service de versionnement des connaissances sont présents. Les transitions de version exposées écrivent un audit avec leur identifiant de corrélation. Les tables `conversations` et `feedbacks` ainsi que les champs enrichis des questions, réponses et tickets sont également migrés.

Ces éléments ne constituent pas encore, à eux seuls :

- une authentification partenaire ;
- un RBAC avec rôles et permissions ;
- un workflow d’approbation lié à une identité et autorisé par rôle pour chaque transition de version ;
- un audit exhaustif, immuable et exportable de toutes les actions ;
- une file d’indexation entièrement orchestrée avec reprise, supervision et alertes.

Il faut donc traiter l’authentification, l’autorisation et la journalisation comme des travaux ouverts, même si leur schéma de données existe.

## Secrets et fournisseurs externes

Le LLM actif est un service OpenAI-compatible derrière un tunnel ngrok. Les variables `NGROK_LLM_API_KEY` et `NGROK_LLM_HEADERS_JSON` sont sensibles si elles contiennent des informations d’accès. NVIDIA reçoit les textes nécessaires au calcul des embeddings via sa propre clé serveur.

Avant production :

- documenter les règles de conservation et de traitement des fournisseurs réellement déployés ;
- limiter les données envoyées au strict contexte documentaire nécessaire ;
- automatiser la rotation du tunnel et des clés sans écrire leurs valeurs dans les sorties CI ;
- surveiller les erreurs de tunnel expiré, d’authentification, de quota et de délai ;
- vérifier que `APP_DEBUG=false` et que les logs de production ne rendent pas les exceptions internes au client.

`GET /api/status` ne contacte pas le LLM. Un état `llm_configured=true` prouve seulement que la base URL, la clé et le modèle sont renseignés ; utilisez `php artisan llm:test --provider=fallback` pour un test réel.

## Données, audit et corrélation

`X-Correlation-ID` facilite le rapprochement d’une requête avec les logs et les futurs enregistrements d’audit. Il ne remplace pas une identité authentifiée. Un identifiant entrant est accepté seulement s’il respecte le format borné par le middleware ; sinon le serveur en génère un.

Pour compléter le dispositif :

- relier chaque action privilégiée à un utilisateur authentifié et à son rôle ;
- rendre les écritures d’audit obligatoires dans les mêmes transactions métier quand c’est pertinent ;
- définir une politique de rétention, d’accès et de purge pour les conversations, questions, feedbacks et tickets ;
- chiffrer les données sensibles au repos avec une gestion de clés séparée ;
- retirer ou pseudonymiser les données avant export de logs et de rapports d’évaluation.

## Risques résiduels

- Un classifieur anti-code ou anti-injection ne peut pas couvrir toutes les formulations adversariales ; les tests doivent évoluer avec les incidents et nouveaux contournements.
- Un document non-OpenAPI uploadé par un administrateur est une entrée de confiance. Son URL est contrôlée, mais son contenu n’est pas comparé intégralement à la réponse distante.
- Le corpus peut être incomplet ou contradictoire. Le comportement attendu est l’escalade, pas l’invention d’une réponse.
- Un tunnel ngrok est éphémère et peut devenir indisponible sans changement applicatif.
- Une activation volontaire du fallback inter-fournisseur d’embeddings peut rendre l’index incohérent si les vecteurs ne partagent pas exactement le même modèle et la même dimension.
- Les rapports d’évaluation historiques peuvent avoir été produits avec une autre configuration ; ils ne valent pas preuve de la configuration active.

Avant exposition publique, une revue de menace, des tests d’autorisation négatifs, une politique de secrets, une stratégie de sauvegarde/restauration et une supervision opérationnelle sont nécessaires.
