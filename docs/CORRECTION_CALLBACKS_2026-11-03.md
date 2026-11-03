# Correction des relances sur les callbacks — 3 novembre 2026

Le défaut transmis a été reproduit sur le site public : après « comment recevoir
les callbacks », « expliques moi le 1 en détail » générait une procédure de paiement.
Le correctif est publié sur Render et Vercel.

## Causes et corrections

- La navigation exigeait le mot « étape » et ne reconnaissait ni « le 1 » ni la
  conjugaison « expliques ». Les références courtes et les variantes avec « point »
  sélectionnent maintenant le plan vérifié, sans recherche ni appel au modèle.
- Le pluriel « callbacks » n'identifiait pas le sujet callback : les instantanés
  n'étaient pas conservés sous le bon sujet. Le singulier, le pluriel, les webhooks
  et les notifications de paiement sont désormais rattachés au bon sujet.
- La suppression des commentaires JSON déplaçait la clôture des exemples indentés,
  puis le rendu des citations collait des références à cette clôture. Le serveur
  préserve l'indentation et déplace citations/prose hors du bloc. Le navigateur
  répare aussi les délimiteurs des anciennes réponses déjà dans l'historique.
  Les champs et valeurs des exemples ne sont pas réécrits.
- Le test élargi a détecté que « Résume cette étape » relançait le modèle. Cette
  demande reformule maintenant l'étape sélectionnée et préserve le plan et le curseur.

## Vérifications

- Suite locale complète : **934 tests, 5331 assertions**, tous réussis.
- **15 cas JavaScript** sur le normaliseur réellement utilisé dans la page ;
  export Vercel et syntaxe du JavaScript inline vérifiés.
- **12 messages consécutifs en production**, tous HTTP 200 et sans échec : absence
  de plan, procédure callback, relance exacte, deuxième point, numéro décimal,
  retour au premier point, changement vers le reporting, retour aux callbacks,
  étape suivante, résumé sans génération et clôture de conversation.
- L'ancien historique a été vérifié visuellement dans Edge : les étapes après le
  JSON et le texte après cURL redeviennent des listes et paragraphes normaux.
- **1 mémoires anciennes réparées** après sauvegarde, uniquement lorsque
  leur contenu était strictement identique à la sauvegarde et présentait le défaut
  reproduit. Le plan a été restauré depuis une nouvelle conversation vérifiée et
  ses sources actuelles. Les autres sujets mémorisés ont été conservés.
- Le jeu permanent comporte **16 scénarios et 411 messages** ; seuls les 12 messages
  du nouveau scénario ont été rejoués en production lors de cette correction.

## Publication

- Site : https://bakoai-assistant.vercel.app
- Backend : https://bakoai-backend.onrender.com
- Branche : `deployment/render`.
- Backend corrigé : commit `e2cbfa2`, déploiement `dep-dbkpikqjnfac739k5ra0`.
- Site corrigé : déploiement Vercel `dpl_BNXLVJ5fhPjUfeXtVXwtzhyc9vkB`.
- Actualiser le site charge le normaliseur du rendu pour les anciennes réponses.

## Preuves locales

- `.tmp/callback-before.json` : reproduction initiale.
- `.tmp/callback-cloud-first-pass.json` : premier passage, ayant révélé le problème
  supplémentaire du résumé d'étape (type `procedure` à l'époque).
- `.tmp/callback-cloud-final.json` : 12 messages du contrôle final.
- `.tmp/callback-full-suite-release.log` : suite complète finale.
- `.tmp/callback-original-memories.json` : sauvegarde des mémoires avant réparation.
- `.tmp/callback-memory-repair-proof.json` : résultat de la réparation ciblée.

Les exemples documentaires restent ceux des sources ; les tests de rendu ne
constituent pas une validation exhaustive de chaque réponse future du modèle.
