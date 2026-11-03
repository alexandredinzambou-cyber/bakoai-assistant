# Points numérotés, demandes de détail et exemples — 3 novembre 2026

## Échange signalé

« comment recevoir les callbacks ? » → « expliques moi le point 3 en détail » → « et le point 2 ».

Trois défauts se cumulaient : la demande de détail ne faisait que sélectionner le texte mémorisé ; les exemples avaient été supprimés lors de l’extraction du plan ; la référence nominale « et le point 2 » n’était pas reconnue et pouvait déclencher une nouvelle génération sur le paiement.

## Corrections

- Les références nominales (`et le point 2`, `le point 3`, `et l’étape 2`) sélectionnent le plan parent. Les numéros négatifs, décimaux, ambigus ou hors limites restent contrôlés.
- Une demande explicite de développement sélectionne d’abord son étape puis utilise le parcours d’explication documentaire. Les réponses détaillées affichent leur numéro d’étape. Les réponses factuelles préfabriquées ne remplacent pas cette explication par une autre procédure.
- L’extraction du plan masque temporairement les blocs de code, puis les remet dans leur étape. Une ligne numérotée dans un exemple ne devient pas une étape. JSON, clôtures de blocs, indentation et fins de ligne Windows sont couverts par les tests.
- Le plan complet vérifié est conservé séparément (`plan_answer`). Les sous-points d’une explication ne remplacent ni ses étapes ni ses exemples.
- Pour un ancien plan, les exemples peuvent être récupérés dans la réponse complète sauvegardée si les titres de toutes les étapes et les sources correspondent. En l’absence de preuve conservée, l’exemple n’est pas inventé et les introductions vides sont retirées.
- Une demande sans exemple masque le JSON à l’affichage tout en le conservant dans le plan.
- Si le modèle fournit un brouillon de code applicatif à une demande d’explication, une seule nouvelle tentative est autorisée avec la même étape, les mêmes sources et les règles de contrôle. Le maximum reste deux appels de génération. Si les deux essais échouent, l’ancien plan reste intact.

## Vérifications

- Suite finale : **974 tests, 5 606 assertions**, tous réussis.
- Tests ciblés sur la reprise d’un brouillon : succès au second essai et échec des deux essais, avec contrôle du nombre d’appels, du maintien de l’étape et de la non-publication du code applicatif.
- Rendu Markdown : **15 cas** réussis.
- Nouveau scénario permanent `point_details` : **12 messages**. Catalogue total : **18 scénarios, 435 questions**. Les 435 questions n’ont pas toutes été rejouées avec le modèle réel lors de cette correction.
- En local, le nouveau scénario a passé ses 12 contrôles. Les 12 réponses du scénario précédent ont également été vérifiées ; son ancienne exigence littérale `Urls` a été remplacée par une expression acceptant `URL` et `Urls`, les deux libellés documentés.
- Première campagne après publication du correctif principal : **11/12**, avec un refus de brouillon applicatif au dernier développement. Ce résultat a déclenché la correction de reprise et les deux nouveaux tests ; la campagne d’échec est conservée.
- Campagne finale via **le site Vercel** : **12/12**, tous HTTP 200, y compris le développement répété, le retour au point 2, le résumé, le masquage du JSON et un numéro invalide.
- Relecture indépendante de la base Render : plan parent et plan complet conservés, **deux blocs JSON** conservés, étape 3 sélectionnée, instantané du sujet conservé, documentation actuelle et mémoire durable.
- Treizième appel de contrôle : sélection du point 3 depuis la mémoire, retour des JSON d’origine, plan inchangé et curseur correct, HTTP 200 et mémoire durable.

Les contrôles de rejeu ciblent les régressions signalées et ne constituent pas une validation exhaustive de chaque affirmation que le modèle peut produire.

Une exécution complète intermédiaire a été interrompue par un manque d’espace sur le disque C: hébergeant PostgreSQL local. Après libération d’espace, la relance complète a réussi. Aucune suppression de fichiers personnels ni modification des paramètres PostgreSQL n’a été effectuée.

## Publication et preuves

- Correctif principal : `49706d04dae140e2f9565f1567a7bbca2a03cd2a`, Render `dep-dbkr3tk9v7es73f531pg`, publié avec succès.
- Version applicative finale : `f91fa8c5b429f4c4b3eaed6c8602e5dfc358b986`, Render **`dep-dbkrais9v7es73f5hvd0`**, statut **Deploy succeeded / Live**.
- Site : https://bakoai-assistant.vercel.app/ ; aucun changement du frontend nécessaire.
- Preuves locales ignorées par Git : `.tmp/point-detail-retry-full-suite-final.log`, `.tmp/point-detail-local.json`, `.tmp/point-callback-local-regraded.json`, `.tmp/point-cloud-final.json` (campagne initiale), `.tmp/point-cloud-retry-release.json`, `.tmp/point-cloud-retry-memory-proof.json`, `.tmp/point-cloud-retry-example-proof.json`.

Les anciens messages affichés dans le navigateur ne sont pas réécrits. Si un échange avait déjà dérivé vers une autre procédure, reposer la question complète permet de reconstruire le plan depuis la documentation actuelle.
