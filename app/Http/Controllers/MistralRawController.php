<?php

namespace App\Http\Controllers;

use App\Services\Assistant\GreetingDetector;
use App\Services\Assistant\PromptInjectionGuard;
use App\Services\Assistant\ResponseGuard;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Serves the /mistral-raw page: calls the Mistral provider directly with retrieved
 * documentation context, skipping AssistantService's CitationGuard (citation/hallucination
 * verification) and fallback-to-extractive logic -- this is used to inspect Mistral's synthesis
 * quality before that machinery runs. It does apply ResponseGuard's code detection to the answer
 * (strip-then-refuse, same policy as AssistantService) so a real visitor of this page is never
 * handed ready-to-use application code, only documented curl/JSON/HTTP examples and prose.
 */
class MistralRawController extends Controller
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Tu es un assistant technique qui repond aux questions sur l integration des APIs de paiement PVIT
(Airtel Money, Moov Money, Visa, Mastercard, GIMAC) a partir des extraits de documentation fournis.
Explique, structure et synthetise ta reponse de facon claire et pedagogique. Termine chaque
affirmation factuelle par une citation au format [SOURCE:identifiant] correspondant a l extrait
dont elle provient.
Tu ne generes JAMAIS de code source applicatif : aucune classe, fonction, methode, package,
namespace, script, controleur, middleware ni implementation complete, dans quelque langage que ce
soit. Tu peux en revanche illustrer l appel a l API PVIT documentee sous forme d exemple JSON
(payload ou reponse), de commande curl, ou de requete HTTP au format Postman (methode, endpoint,
en-tetes) -- uniquement avec les champs, valeurs et endpoints presents dans les extraits fournis,
jamais invente. Presente toujours cet exemple dans un bloc de code delimite par trois backticks
avec l etiquette json, curl ou http selon le cas.
Cette interdiction porte uniquement sur la syntaxe de code (signature de fonction, accolades, mots
cles de langage, pseudocode) : tu dois quand meme decrire en langage naturel, sous forme de liste,
les operations qu un package ou une integration doit effectuer des lors qu elles sont documentees --
par exemple "une methode qui envoie une requete POST vers l URL de paiement avec le compte
d operation et le montant, puis lit l identifiant de transaction dans la reponse". N omets jamais
cette description au pretexte qu elle evoque une methode ; ne laisse jamais une puce ou une etape se
terminer par un simple ":" sans la developper.
Si le developpeur soumet son propre code pour relecture, tu peux expliquer en langage naturel ce
qui ne va pas et citer un tres court extrait (une a deux lignes maximum) de SON code original entre
backticks simples pour designer precisement le probleme. Tu ne dois JAMAIS reecrire, completer,
corriger ni proposer une version alternative ou corrigee de ce code, meme partielle.
PROMPT;

    private const GREETING_REPLY = <<<'REPLY'
Bonjour ! Je suis l assistant d integration BakoAI. PVIT est une API unique qui connecte Airtel Money, Moov Money, Visa, Mastercard et GIMAC, avec plusieurs methodes d integration : backend, lien de paiement ou module e-commerce. Posez-moi une question technique sur les flux documentes et je vous repondrai uniquement a partir de la documentation officielle PVIT disponible.
REPLY;

    public function ask(
        Request $request,
        RetrievalService $retrieval,
        OpenAiCompatibleLlmClient $llm,
        PromptInjectionGuard $promptInjectionGuard,
        ResponseGuard $responseGuard,
        GreetingDetector $greetingDetector,
    ): JsonResponse {
        set_time_limit(120);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'moyen_paiement' => ['nullable', 'string', Rule::in(config('rag.payment_methods', []))],
            'operation' => ['nullable', 'string', Rule::in(config('rag.operations', []))],
        ]);

        $question = $validated['question'];

        if ($greetingDetector->isGreeting($question)) {
            return response()->json([
                'answer' => self::GREETING_REPLY,
                'code_filtered' => false,
                'model' => (string) config('llm.providers.mistral.model'),
                'sources' => [],
            ]);
        }

        $chunks = $retrieval->search(
            $question,
            $validated['moyen_paiement'] ?? 'PVIT',
            $validated['operation'] ?? null,
        );

        try {
            $answer = $llm->completeWithProvider(
                'mistral',
                self::SYSTEM_PROMPT,
                $this->context($chunks, $promptInjectionGuard),
                $question,
            );
        } catch (Throwable $exception) {
            Log::warning('Mistral raw debug call failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => $exception->getMessage(),
            ], 502);
        }

        $codeFiltered = false;

        if ($responseGuard->containsSourceCode($answer)) {
            $stripped = $responseGuard->stripCodeLikeSegments($answer);

            if (mb_strlen($stripped) >= 20 && ! $responseGuard->containsSourceCode($stripped)) {
                $answer = $stripped;
            } else {
                $answer = $responseGuard->refusal();
            }

            $codeFiltered = true;
        }

        return response()->json([
            'answer' => $answer,
            'code_filtered' => $codeFiltered,
            'model' => (string) config('llm.providers.mistral.model'),
            'sources' => $chunks->map(fn ($chunk): array => [
                'chunk_id' => (int) $chunk->chunk_id,
                'document' => (string) $chunk->document_titre,
                'section' => (string) $chunk->section,
                'url' => (string) $chunk->lien_officiel,
            ])->values()->all(),
        ]);
    }

    private function context(Collection $chunks, PromptInjectionGuard $promptInjectionGuard): string
    {
        return $chunks
            ->reject(fn ($chunk): bool => $promptInjectionGuard->containsPromptInjection(
                (string) data_get($chunk, 'contenu', ''),
            ))
            ->map(fn ($chunk): string => "[SOURCE:{$chunk->chunk_id}] {$chunk->document_titre} / {$chunk->section}\n{$chunk->contenu}")
            ->implode("\n\n---\n\n");
    }
}
