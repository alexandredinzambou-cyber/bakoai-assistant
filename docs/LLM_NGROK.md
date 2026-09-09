# Fournisseur LLM via ngrok

Le fournisseur `ngrok` appelle un service exposant un contrat OpenAI-compatible. Il ne dépend pas d'APIFreeLLM.

## Contrat HTTP

- méthode : `POST` ;
- URL : `NGROK_LLM_BASE_URL` suivie de `NGROK_LLM_ENDPOINT` ;
- valeur par défaut conseillée : une base HTTPS terminant par `/api/v1` et l'endpoint relatif `chat/completions` ;
- requête JSON : `model`, `messages` et `temperature` ;
- réponse attendue : texte non vide dans `choices[0].message.content` ;
- redirections désactivées.

Exemple sans secret :

```env
LLM_DEFAULT_PROVIDER=ngrok
LLM_FALLBACK_PROVIDERS=ngrok
NGROK_LLM_BASE_URL=https://your-tunnel.ngrok-free.app/api/v1
NGROK_LLM_ENDPOINT=chat/completions
NGROK_LLM_API_KEY=replace-with-server-token
NGROK_LLM_MODEL=replace-with-served-model
```

La base URL, la clé et le modèle doivent être non vides. L'URL d'un tunnel doit utiliser HTTPS. L'endpoint doit rester un chemin relatif, sans URL absolue, query string ni fragment.

## Authentification et en-têtes

`NGROK_LLM_AUTH_HEADER` accepte `Authorization`, `X-API-Key` ou `X-Auth-Token`. `NGROK_LLM_AUTH_SCHEME` vaut `Bearer` par défaut et peut être vide si le serveur attend directement la clé.

Les en-têtes supplémentaires sont un objet JSON dans `NGROK_LLM_HEADERS_JSON`. L'exemple active uniquement le contournement de la page d'avertissement navigateur de ngrok :

```env
NGROK_LLM_HEADERS_JSON='{"ngrok-skip-browser-warning":"bakoai-assistant"}'
```

Le client rejette les retours à la ligne, les noms invalides et les en-têtes contrôlant le transport, l'authentification, les cookies ou les informations de proxy. Il ne journalise ni la clé, ni les valeurs d'en-têtes, ni le corps d'une réponse en erreur.

## Disponibilité

Les délais de connexion et de réponse ainsi que le nombre de tentatives sont configurables par les variables `NGROK_LLM_CONNECT_TIMEOUT`, `NGROK_LLM_TIMEOUT`, `NGROK_LLM_RETRY_ATTEMPTS` et `NGROK_LLM_RETRY_DELAY_MS`. Le client borne ces valeurs et réessaie uniquement les erreurs réseau ou HTTP transitoires.

Les appelants peuvent distinguer :

- `LlmTunnelExpiredException` : tunnel ngrok expiré ou hors ligne ;
- `LlmUnavailableException` : connexion, quota ou service temporairement indisponible ;
- `InvalidLlmResponseException` : réponse réussie ne respectant pas le contrat OpenAI-compatible.

Les messages de ces exceptions sont volontairement génériques et n'incluent jamais le corps renvoyé par le service.

## Vérification opérationnelle

Après une rotation du tunnel, vider le cache de configuration puis appeler la liste de fallback, qui ne contient que `ngrok` dans la configuration active :

```bash
php artisan config:clear
php artisan llm:test --provider=fallback
```

Sous Windows dans ce workspace :

```powershell
E:\IA\.php85-nts\php.exe artisan config:clear
E:\IA\.php85-nts\php.exe artisan llm:test --provider=fallback
```

`GET /api/status` vérifie uniquement que la configuration obligatoire est renseignée. Il n'effectue pas d'appel distant et ne remplace pas ce test.
