# Développement d’une étape et séparation des webhooks — 3 novembre 2026

## Problème reproduit

Après « comment recevoir les callbacks » puis « expliques moi le 3 », « plus en détail » pouvait démarrer une nouvelle recherche sans sujet résolu. Le modèle mélangeait alors réception du callback, consultation du statut, initiation de paiement et règles de signature du reporting.

## Corrections durables

- Les demandes sans nouveau sujet (« plus en détail », « développe cette étape », « explique-moi davantage », variantes polies et à l’infinitif) reprennent le contexte existant. Sans réponse vérifiée, elles demandent une question complète.
- Une sélection d’étape est enregistrée dans la mémoire de conversation (`plan_selected`). Les anciennes réponses commençant par « Étape N : » restent reconnues lorsqu’elles ne portent pas ce champ.
- La recherche de précisions contient le sujet et le texte de l’étape choisie. Elle ignore les anciennes questions sur d’autres sujets. Les sections citées par cette étape sont privilégiées et les autres sections du même guide sont écartées. La documentation du contrat de notification peut compléter une étape de réception.
- Les sous-points d’une explication ne remplacent plus le plan parent, son curseur, ses sources ou son sujet. Une nouvelle question factuelle libère la sélection précédente ; une reformulation la conserve.
- Les sources du webhook de reporting sont écartées d’une question sur les callbacks de transaction, et réciproquement. Une comparaison explicite permet les deux contrats. La vérification rejette également une citation d’un autre contrat.
- Le vérificateur contrôle l’affirmation « JSON obligatoire » lorsque les formats documentés offrent une alternative. Il distingue l’accusé de réception de la notification entrante.
- Pour le reporting, les en-têtes de signature sont recherchés avec les règles de vérification. Le modèle ne doit pas présenter `X-Secret` comme un en-tête entrant du webhook ; cette erreur est aussi contrôlée après génération.

## Validation

- Suite complète : **955 tests, 5 474 assertions**, tous réussis.
- Rendu Markdown : **15 cas**, tous réussis.
- Nouveau scénario permanent `expansion_followups` : **12 messages**. Le catalogue total contient **17 scénarios et 423 questions** ; les 423 questions n’ont pas toutes été rejouées avec le modèle réel lors de cette correction.
- Tests avec rechargement de la mémoire : callbacks, paiement et remboursement ; sélection, développement répété, résumé, étape suivante et changement de sujet.
- Conversation avec le modèle réel en local : **12/12 contrôles réussis** après resserrement des sources.
- Conversation via le site Vercel après déploiement : **12/12 contrôles réussis**, HTTP 200. L’étape 3 porte bien sur la réception dans ce parcours. Les relances utilisent le type `expansion`, le résumé le type `summary`, puis la navigation reprend à l’étape 4.
- Relecture indépendante de la mémoire PostgreSQL de cette conversation de test : plan parent inchangé, curseur 2 (étape 3), sélection active, instantanés callbacks/reporting conservés, empreinte documentaire actuelle et mémoire durable.

Les assertions de rejeu contrôlent les régressions ciblées ; elles ne constituent pas une validation exhaustive de chaque formulation produite par le modèle. Les essais ont notamment révélé les erreurs de format et de rôle de `X-Secret`, qui ont donné lieu à des tests supplémentaires.

## Publication et preuves

- Commit applicatif : `5d0b18a1893dcffd4a375d137828753a9a991f62`, branche `deployment/render`.
- Render : `dep-dbkqa2tg1s2s73dfvflg`, statut **Deploy succeeded / Live**.
- Site : https://bakoai-assistant.vercel.app/ ; backend : https://bakoai-backend.onrender.com/.
- Aucun changement du frontend n’était nécessaire pour ce correctif serveur.
- Preuves locales ignorées par Git : `.tmp/expansion-full-suite-final-release.log`, `.tmp/expansion-local-scoped.json`, `.tmp/expansion-cloud-final.json`, `.tmp/expansion-cloud-memory-proof.json`.

Les anciens messages affichés ne sont pas réécrits et les conversations de l’utilisateur ne sont pas remplacées par celles des tests. Pour repartir d’un échange dont le sujet avait déjà dérivé, reposer la question complète puis sélectionner à nouveau l’étape concernée.
