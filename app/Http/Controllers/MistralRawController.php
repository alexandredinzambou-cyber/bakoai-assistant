<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Question;
use App\Services\Assistant\AnswerVerifier;
use App\Services\Assistant\ContextAssembler;
use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\ConversationEvidence;
use App\Services\Assistant\ConversationMemory;
use App\Services\Assistant\ConversationMemoryStore;
use App\Services\Assistant\ConversationPlan;
use App\Services\Assistant\DocumentationGapDetector;
use App\Services\Assistant\DocumentedFactAnswer;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\ExampleValueNotice;
use App\Services\Assistant\FollowUpDetector;
use App\Services\Assistant\GenerationPolicy;
use App\Services\Assistant\GreetingDetector;
use App\Services\Assistant\JsonExamples;
use App\Services\Assistant\LocalImageTextExtractor;
use App\Services\Assistant\ParameterMemory;
use App\Services\Assistant\ParameterScope;
use App\Services\Assistant\ProcedureContext;
use App\Services\Assistant\PromptInjectionGuard;
use App\Services\Assistant\RequestContractAnswer;
use App\Services\Assistant\ResponseGuard;
use App\Services\Llm\OpenAiCompatibleLlmClient;
use App\Services\Rag\RetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Serves the /mistral-raw page. Originally a raw probe of the Mistral provider, this endpoint is
 * now a real RAG assistant in its own right: it reuses AssistantService's conversation-memory
 * machinery (Conversation model + cache, follow-up rewriting, remembered scope/operation). The
 * context is chosen by ContextAssembler, the prompt, length rule and token cap by
 * GenerationPolicy (fact / procedure / diagnosis). AnswerVerifier checks the answer against the
 * extracts and removes only what is demonstrably wrong (an invented identifier or figure, a
 * contradiction, a guess, a fabricated example), with one informed retry when the opening claim
 * or most of the draft is wrong; citations are optional and only number the sources. Only an
 * answer with nothing left, or whose opening claim is still wrong after the retry, becomes a
 * refusal. Since the /assistant page was removed this is the only chat page: it also refuses
 * code requests and questions carrying a secret, as /assistant did, and offers a support ticket
 * when it cannot answer from the documentation. ResponseGuard's code policy (strip-then-refuse)
 * still applies. A screenshot is read by the Mistral vision model under the same diagnosis
 * prompt, and its answer is verified like any other.
 */
class MistralRawController extends Controller
{
    // Added to the diagnosis prompt when a screenshot is attached: the image gives the symptom,
    // the extracts the answer, as for a pasted error message.
    private const VISION_RULES = <<<'PROMPT'

Capture d ecran
9. Le developpeur joint une capture d ecran : lis-y le code d erreur, le message et le contexte, puis explique-les avec les extraits uniquement, comme s il les avait colles dans sa question. L image n est pas une source a citer.
10. Si l image ne concerne pas l integration PVIT, refuse poliment en une phrase et rappelle que ton role se limite a l integration PVIT.
PROMPT;

    private const GREETING_REPLY = <<<'REPLY'
Bonjour ! Je suis l assistant d integration BakoAI. PVIT est une API unique qui connecte Airtel Money, Moov Money, Visa, Mastercard et GIMAC, avec plusieurs methodes d integration : backend, lien de paiement ou module e-commerce. Posez-moi une question technique sur les flux documentes et je vous repondrai uniquement a partir de la documentation officielle PVIT disponible.
REPLY;

    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const MAX_LOG_CHARS = 12000;

    public function ask(
        Request $request,
        RetrievalService $retrieval,
        OpenAiCompatibleLlmClient $llm,
        PromptInjectionGuard $promptInjectionGuard,
        ResponseGuard $responseGuard,
        GreetingDetector $greetingDetector,
        AnswerVerifier $verifier,
        LocalImageTextExtractor $imageTextExtractor,
    ): JsonResponse {
        set_time_limit(120);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'moyen_paiement' => ['nullable', 'string', Rule::in(config('rag.payment_methods', []))],
            'operation' => ['nullable', 'string', Rule::in(config('rag.operations', []))],
            'image' => ['nullable', 'file', 'max:8192'],
            'conversation_id' => ['nullable', 'string', 'max:80', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            // An edited message restarts the conversation from the question that preceded it.
            'context_question' => ['nullable', 'string', 'max:2000'],
        ]);

        $question = $validated['question'];
        $contextQuestion = trim((string) ($validated['context_question'] ?? ''));

        // Inspect every caller-controlled text before retrieval, persistence or external calls.
        if ($responseGuard->containsSensitiveData($question)
            || $responseGuard->containsSensitiveData($contextQuestion)) {
            return $this->staticAnswer($responseGuard->sensitiveDataRefusal(), null);
        }

        $attachment = $request->file('image');
        $imageDataUrl = null;
        $logText = null;

        if ($attachment) {
            $mime = (string) $attachment->getMimeType();

            if (in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
                try {
                    $imageText = $imageTextExtractor->extract($attachment);
                } catch (Throwable) {
                    return response()->json([
                        'error' => 'La verification locale de la capture est indisponible. Aucun fichier n a ete transmis au fournisseur IA. Copiez le message d erreur en retirant les donnees sensibles.',
                    ], 422);
                }

                if ($responseGuard->containsSensitiveData($imageText)) {
                    return $this->staticAnswer($responseGuard->sensitiveDataRefusal(), null);
                }

                if ($promptInjectionGuard->containsPromptInjection($imageText)) {
                    return response()->json(['error' => $promptInjectionGuard->contextRefusal()], 422);
                }

                $imageDataUrl = 'data:'.$mime.';base64,'.base64_encode($attachment->get());
            } elseif (str_starts_with($mime, 'text/') || in_array(strtolower((string) $attachment->getClientOriginalExtension()), ['log', 'txt'], true)) {
                $logText = mb_convert_encoding($attachment->get(), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');

                // Inspect the entire file, including the part omitted from the model context.
                if ($responseGuard->containsSensitiveData($logText)) {
                    return $this->staticAnswer($responseGuard->sensitiveDataRefusal(), null);
                }

                if ($promptInjectionGuard->containsPromptInjection($logText)) {
                    return response()->json(['error' => $promptInjectionGuard->contextRefusal()], 422);
                }

                if (mb_strlen($logText) > self::MAX_LOG_CHARS) {
                    $logText = '[... log tronque, seuls les '.self::MAX_LOG_CHARS." derniers caracteres sont affiches ...]\n"
                        .mb_substr($logText, -self::MAX_LOG_CHARS);
                }
            } else {
                return response()->json([
                    'error' => 'Format de fichier non supporte. Joignez une image (jpg, png, webp, gif) ou un fichier texte/log (.txt, .log).',
                ], 422);
            }
        }

        $acknowledgement = $greetingDetector->isAcknowledgement($question);

        if ($acknowledgement || $greetingDetector->isGreeting($question) || $greetingDetector->isFarewell($question)) {
            // A static greeting, or an "ok je vois" after an answer, creates no conversation:
            // memory is only for technical turns, and the conversation goes on where it was.
            return response()->json([
                'answer' => $greetingDetector->isFarewell($question) ? GreetingDetector::FAREWELL_REPLY
                    : ($acknowledgement ? GreetingDetector::ACKNOWLEDGEMENT_REPLY : self::GREETING_REPLY),
                'should_escalate' => false,
                'code_filtered' => false,
                'model' => (string) config('llm.providers.mistral.model'),
                'sources' => [],
                'conversation_id' => $validated['conversation_id'] ?? null,
            ]);
        }

        // Mirror AssistantService: adversarial instructions in the question are refused
        // deterministically, before any retrieval or model call -- they are never persisted,
        // never searched and never sent to Mistral.
        if ($promptInjectionGuard->containsPromptInjection($question)
            || $promptInjectionGuard->containsPromptInjection($contextQuestion)) {
            return response()->json([
                'error' => $promptInjectionGuard->questionRefusal(),
            ], 422);
        }

        // The two business rules /assistant enforced before it was removed, answered the same way:
        // a secret or card number is never searched, stored or sent to Mistral, and no code is
        // written for the developer.
        if ($responseGuard->requestsSourceCode($question)) {
            return $this->staticAnswer($responseGuard->refusal(), $validated['conversation_id'] ?? null, codeRefusal: true);
        }

        [$conversation, $conversationReference] = $this->conversationState(
            $validated['conversation_id'] ?? null,
        );

        $memory = $this->conversationMemory($conversation, $conversationReference);

        if ($memory === [] && $contextQuestion !== '') {
            $memory = ['last_question' => Str::limit($contextQuestion, 500, '')];
        }

        $actions = new ConversationActions;
        $technicalQuestion = $actions->withoutSupportDirective($actions->withoutNegatedErrors($question));
        $repaired = (new ParameterMemory)->repair($memory);
        if ($repaired !== $memory) {
            app(ConversationMemoryStore::class)->write($conversation, $conversationReference, $repaired);
            $memory = $repaired;
        }
        $memory = (new ConversationMemory)->forQuestion($memory, $technicalQuestion);
        if (! (new ConversationEvidence)->isCurrent($memory['evidence_stamp'] ?? null)) {
            $memory = (new ConversationMemory)->invalidate($memory);
            app(ConversationMemoryStore::class)->write($conversation, $conversationReference, $memory);

            return $this->staticAnswer('La documentation utilisée dans cette conversation a changé. Les anciennes étapes et exemples ont été écartés. Reposez votre question complète pour repartir des sources actuelles.', $conversation?->reference);
        }
        $isFollowUp = (new FollowUpDetector)->isFollowUp($technicalQuestion, $memory['last_question'] ?? null);
        $askedSubject = (new ConversationPlan)->subject($technicalQuestion);
        $knownSubject = (new ConversationPlan)->subject($memory['topic_question'] ?? $memory['resolved_question'] ?? $memory['last_question'] ?? '');
        if ($actions->formatRequest($technicalQuestion) !== null && $askedSubject !== null && $knownSubject !== null && $askedSubject !== $knownSubject) {
            $isFollowUp = false;
        }
        if ($actions->declinesSupport($question) && $technicalQuestion === '') {
            return $this->staticAnswer('D’accord, aucun nouveau ticket ne sera ouvert pour le moment.', $validated['conversation_id'] ?? null);
        }
        if ($technicalQuestion === '') {
            return $this->staticAnswer('D’accord. Précisez le sujet PVIT sur lequel vous souhaitez de l’aide.', $validated['conversation_id'] ?? null);
        }
        if ($actions->requestsSupport($question)) {
            if ($conversation === null || empty($memory['last_question'])) {
                return $this->staticAnswer('Décrivez le problème PVIT à transmettre au support, sans donnée confidentielle.', $validated['conversation_id'] ?? null);
            }
            $record = Question::where('conversation_id', $conversation->id)
                ->where('texte', $memory['last_question'])->latest('id')->first();
            $support = $record !== null
                ? ['should_escalate' => true, 'question_id' => $record->id, 'ticket_token' => Crypt::encryptString((string) $record->id)]
                : $this->escalation($memory['last_question'], $conversation, $conversationReference);

            return response()->json([
                'answer' => 'Utilisez le bouton « Ouvrir un ticket de support » ci-dessous pour transmettre votre problème précédent au support PVIT.',
                ...$support,
                'conversation_id' => $conversationReference,
                'code_filtered' => false,
                'sources' => [],
                'model' => $this->modelName(),
            ]);
        }

        if (($stepRequest = $actions->stepRequest($technicalQuestion)) !== null && empty($attachment)) {
            $requestedProcedure = (new ConversationPlan)->procedureSubject($technicalQuestion);
            $planProcedure = $memory['plan_subject'] ?? (new ConversationPlan)->procedureSubject($memory['topic_question'] ?? $memory['resolved_question'] ?? $memory['last_question'] ?? '');
            if ($requestedProcedure !== null && $planProcedure !== null && $requestedProcedure !== $planProcedure) {
                return $this->staticAnswer('Cette demande concerne une autre procédure. Je n’ai pas encore de plan vérifié pour cette procédure. Demandez son parcours complet avant de sélectionner une étape.', $memory !== [] ? $conversationReference : null);
            }
            $steps = $memory['plan_steps'] ?? (new ConversationPlan)->extract($memory['last_answer'] ?? '');
            if ($steps !== []) {
                $index = match ($stepRequest) {
                    'first' => 0,
                    'last' => count($steps) - 1,
                    'previous' => max(0, (int) ($memory['plan_index'] ?? 0) - 1),
                    default => str_starts_with($stepRequest, 'number:') ? (int) substr($stepRequest, 7) - 1 : (int) ($memory['plan_index'] ?? 0) + 1,
                };
                if (str_starts_with($stepRequest, 'number:') && ! isset($steps[$index])) {
                    return $this->staticAnswer('Ce plan comporte '.count($steps).' étapes. Choisissez un numéro entre 1 et '.count($steps).'.', $conversationReference);
                }
                $answer = isset($steps[$index]) ? 'Étape '.($index + 1).' : '.$steps[$index]
                    : 'Vous avez parcouru toutes les étapes de ce plan. Précisez le point sur lequel vous souhaitez continuer.';
                $sources = $memory['plan_sources'] ?? $memory['last_sources'] ?? [];
                $this->rememberConversationTurn($conversation, $conversationReference, $question, $answer,
                    $memory['last_payment_method'] ?? 'PVIT', $memory['last_operation'] ?? null,
                    $memory['topic_question'] ?? $memory['resolved_question'] ?? $memory['last_question'], $sources,
                    ['plan_steps' => $steps, 'plan_index' => min($index, count($steps)), 'plan_sources' => $sources]);

                return response()->json([
                    'answer' => $answer, 'sources' => $sources, 'conversation_id' => $conversationReference,
                    'should_escalate' => false, 'code_filtered' => false, 'model' => $this->modelName(), 'answer_type' => 'step',
                ]);
            }

            return $this->staticAnswer('Je n’ai pas encore de plan vérifié pour ce sujet. Précisez la procédure à parcourir avant de choisir une étape.', $memory !== [] ? $conversationReference : null);
        }

        $conversion = new DurationConversion;
        $targetUnits = $conversion->requestedUnits($technicalQuestion);
        if ($conversion->isConversionFollowUp($technicalQuestion) && $targetUnits === []) {
            $answer = 'Précisez les unités à convertir : secondes, minutes, heures ou jours.';
            $answer .= isset($memory['duration_fact']) ? ' La durée précédente a été conservée.' : ' Précisez aussi le délai concerné.';

            return $this->staticAnswer($answer, $memory !== [] ? $conversationReference : null);
        }
        $targetUnit = $targetUnits[0] ?? null;
        $jsonMetadata = $askedSubject !== null && $knownSubject !== null && $askedSubject !== $knownSubject ? []
            : ($memory['payload_examples'] ?? (new JsonExamples)->extract($memory['payload_answer'] ?? $memory['last_answer'] ?? ''));
        $jsonContractMismatch = ($askedSubject ?? $knownSubject) === 'reporting'
            && collect($jsonMetadata)->contains(static fn ($example) => in_array('transactionId', $example['keys'] ?? [], true));
        $needsJsonSource = $actions->fieldNamesRequest($technicalQuestion) && ($jsonContractMismatch || $jsonMetadata !== [] && (new JsonExamples)->select($jsonMetadata, $technicalQuestion) === []);
        $parameterKind = $actions->parameterKind($technicalQuestion) ?? ($memory['active_parameter_kind'] ?? null);
        $scopeService = new ParameterScope;
        $requestedScope = $scopeService->requested($technicalQuestion);
        $scopeKey = $requestedScope !== null ? $scopeService->key($requestedScope)
            : ($actions->parameterSummaryRequest($technicalQuestion) ? ($memory['active_parameter_scope'] ?? null) : null);
        $selectSet = static function (string $kind) use ($memory, $scopeKey) {
            if ($scopeKey === null) {
                return $memory['parameter_sets'][$kind] ?? null;
            }
            $set = $memory['parameter_sets'][$kind.':'.$scopeKey] ?? $memory['parameter_sets'][$kind] ?? null;

            return ($set['scope'] ?? null) === $scopeKey ? $set : null;
        };
        $parameterSet = $parameterKind !== null ? $selectSet($parameterKind) : null;
        $requiredSet = $selectSet('required');
        $optionalSet = $selectSet('optional');
        if ($parameterKind === 'both' && $requiredSet !== null && $optionalSet !== null) {
            $combinedAnswer = [];
            $combinedSources = [];
            foreach (['required' => 'Obligatoires', 'optional' => 'Optionnels'] as $kind => $label) {
                $set = $kind === 'required' ? $requiredSet : $optionalSet;
                $mapping = [];
                foreach ($set['sources'] as $index => $source) {
                    $originalNumber = $source['number'] ?? ($index + 1);
                    $key = json_encode(array_diff_key($source, ['number' => true]));
                    if (! isset($combinedSources[$key])) {
                        $source['number'] = count($combinedSources) + 1;
                        $combinedSources[$key] = $source;
                    }
                    $mapping[$originalNumber] = $combinedSources[$key]['number'];
                }
                $combinedAnswer[] = '### '.$label."\n".preg_replace_callback('/\[(\d+)\]/u', static fn ($match) => '['.($mapping[(int) $match[1]] ?? $match[1]).']', $set['answer']);
            }
            $parameterSet = ['answer' => implode("\n\n", $combinedAnswer), 'sources' => array_values($combinedSources)];
        }
        $legacyParameterQuestion = ($memory['parameter_question'] ?? '') !== '' ? $memory['parameter_question'] : ($memory['last_question'] ?? '');
        $legacyParameterKind = $actions->parameterKind($legacyParameterQuestion) ?? $actions->parameterKind($memory['parameter_answer'] ?? '');
        $needsParameterSource = $actions->parameterSummaryRequest($technicalQuestion) && $parameterKind !== null && $parameterSet === null
            && ($scopeKey !== null || ! empty($memory['parameter_memory_invalidated']) || $parameterKind === 'both' || $legacyParameterKind !== null && $legacyParameterKind !== $parameterKind);
        if ($needsParameterSource && $requestedScope === null && $scopeKey !== null) {
            $technicalQuestion = $scopeService->directive($scopeKey).' '.$technicalQuestion;
        }
        if ($needsParameterSource && $actions->parameterKind($technicalQuestion) === null && $parameterKind !== null) {
            $technicalQuestion .= match ($parameterKind) {
                'required' => ' Paramètres obligatoires.',
                'optional' => ' Paramètres optionnels.',
                default => ' Paramètres obligatoires et optionnels.',
            };
        }
        if (($summaryPoints = $actions->summaryPoints($technicalQuestion)) !== null && empty($attachment) && $isFollowUp && ! $needsJsonSource && ! $needsParameterSource && $targetUnit === null) {
            $summaryAnswer = $memory['last_answer'] ?? '';
            $summarySources = $memory['last_sources'] ?? [];
            if ($actions->parameterSummaryRequest($technicalQuestion)) {
                $summaryAnswer = $parameterSet['answer'] ?? (($memory['parameter_answer'] ?? '') !== '' ? $memory['parameter_answer'] : ($memory['last_verified_answer'] ?? $summaryAnswer));
                $summarySources = $parameterSet['sources'] ?? (($memory['parameter_answer'] ?? '') !== '' ? ($memory['parameter_sources'] ?? []) : ($memory['last_verified_sources'] ?? $summarySources));
            }
            if ($actions->fieldNamesRequest($technicalQuestion) || $actions->protocolExampleRequest($technicalQuestion) || $actions->jsonStatusRequest($technicalQuestion)) {
                $summaryAnswer = ($memory['payload_answer'] ?? '') !== '' ? $memory['payload_answer'] : $summaryAnswer;
                $summarySources = ($memory['payload_answer'] ?? '') !== '' ? ($memory['payload_sources'] ?? []) : $summarySources;
            }
            if (preg_match('/\b(?:proc[eé]dure|[eé]tapes)\b/iu', $question) === 1 && ! empty($memory['plan_steps'])) {
                $summaryAnswer = implode("\n\n", array_map(static fn ($step, $index) => ($index + 1).'. '.$step, $memory['plan_steps'], array_keys($memory['plan_steps'])));
                $summarySources = $memory['plan_sources'] ?? $summarySources;
            }
            $answer = $actions->formatAnswer($summaryAnswer, $technicalQuestion);
            if ($actions->fieldNamesRequest($technicalQuestion) && ! empty($memory['payload_examples'])) {
                $answer = (new JsonExamples)->fields($memory['payload_examples'], $technicalQuestion);
            }
            if ($actions->fieldNamesRequest($technicalQuestion) && str_starts_with($answer, '`') && count($summarySources) === 1) {
                $answer .= ' [1]';
            }
            $this->rememberConversationTurn($conversation, $conversationReference, $question, $answer,
                $memory['last_payment_method'] ?? 'PVIT', $memory['last_operation'] ?? null,
                $memory['topic_question'] ?? $memory['resolved_question'] ?? $memory['last_question'] ?? $technicalQuestion, $summarySources,
                ['presentation' => true]);

            return response()->json([
                'answer' => $answer,
                'conversation_id' => $conversationReference,
                'should_escalate' => false,
                'code_filtered' => false,
                'sources' => $summarySources,
                'model' => $this->modelName(),
                'answer_type' => 'summary',
            ]);
        }

        $effectiveQuestion = $this->questionWithConversationMemory($technicalQuestion, $memory, $isFollowUp);

        if ($targetUnit !== null && ($askedSubject === null || $askedSubject === $knownSubject)) {
            $duration = $memory['duration_fact'] ?? $conversion->extract($memory['last_verified_answer'] ?? $memory['last_answer'] ?? '');
            if ($duration === null) {
                return $this->staticAnswer('Je ne dispose pas d’une durée unique vérifiée à convertir. Précisez le délai concerné.', $memory !== [] ? $conversationReference : null);
            }
            $answer = $conversion->convertMany($duration, $targetUnits);
            $sources = $memory['last_verified_sources'] ?? $memory['last_sources'] ?? [];
            $answer .= $sources !== [] ? ' [1]' : '';
            $this->rememberConversationTurn($conversation, $conversationReference, $question, $answer,
                $memory['last_payment_method'] ?? 'PVIT', $memory['last_operation'] ?? null, $effectiveQuestion, $sources, ['presentation' => true]);

            return response()->json(['answer' => $answer, 'sources' => $sources, 'conversation_id' => $conversationReference, 'should_escalate' => false, 'code_filtered' => false, 'model' => $this->modelName(), 'answer_type' => 'conversion']);
        }

        $paymentMethod = $validated['moyen_paiement']
            ?? ($isFollowUp ? ($memory['last_payment_method'] ?? 'PVIT') : 'PVIT');
        $operation = $validated['operation']
            ?? ($isFollowUp ? ($memory['last_operation'] ?? null) : null);

        // Mirror AssistantService: an embedding/retrieval outage is an infrastructure failure,
        // not a 500. Fail closed (no grounded context -> no answer) with a friendly 503 and the
        // correlation id so the operator can match the response to the warning log entry.
        try {
            $chunks = $retrieval->search($effectiveQuestion, $paymentMethod, $operation);
            if ($actions->fieldNamesRequest($technicalQuestion)
                && preg_match('/callback|notification|accus[eé]/iu', $effectiveQuestion) === 1) {
                $jsonQuery = (new ConversationPlan)->subject($effectiveQuestion) === 'reporting'
                    ? 'Webhook de reporting export Corps de la notification Ce que votre endpoint doit répondre received 2xx'
                    : 'Webhook de notification de statut : exemple de notification reçue et accusé de réception attendu du serveur JSON';
                $jsonSources = $retrieval->search($jsonQuery, $paymentMethod, $operation);
                $chunks = $chunks->concat($jsonSources)->unique('chunk_id')->values();
            }
            if ((new ConversationPlan)->subject($effectiveQuestion) === 'reporting'
                && preg_match('/accus\w*|renvoyer|r[eé]pondre/iu', $technicalQuestion) === 1) {
                $reportingSources = $retrieval->search('Webhook de reporting Ce que votre endpoint doit répondre 2xx received', $paymentMethod, $operation);
                $chunks = $chunks->concat($reportingSources)->unique('chunk_id')->values();
            }
            if (str_contains(mb_strtolower($technicalQuestion), 'https') && preg_match('/obligatoire|recommand/iu', $technicalQuestion) === 1) {
                $httpsSources = $retrieval->search('Configurer une URL de callback HTTPS recommandée bonnes pratiques', $paymentMethod, $operation);
                $chunks = $chunks->concat($httpsSources)->unique('chunk_id')->values();
            }
            if (str_contains(Str::ascii(mb_strtolower($technicalQuestion)), 'sandbox')
                && preg_match('/\b(?:[eé]tapes?|pr[eé]requis|obligatoire|valider)\b/iu', $technicalQuestion) === 1) {
                $chunks = (new ProcedureContext)->sandbox($chunks);
                if ((new DocumentedFactAnswer)->sandboxRequirements($technicalQuestion, $chunks) === null) {
                    $requirements = $retrieval->search('Guide Sandbox APIs complémentaires requises KYC Check Status obligatoires pour valider intégration', $paymentMethod, $operation, 20);
                    $chunks = $chunks->concat($requirements)->unique('chunk_id')->values();
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Mistral raw retrieval failed.', [
                'reason' => 'retrieval_unavailable',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'correlation_id' => $this->correlationId(),
            ]);

            return response()->json([
                'error' => "La base documentaire n'est pas disponible pour le moment. Je ne peux pas "
                    .'repondre sans extraits verifies et je recommande d ouvrir un ticket de support.',
                'correlation_id' => $this->correlationId(),
            ], 503);
        }

        // The model only sees the chunks within reach of the best one, under a character
        // budget: what it receives, it restates.
        $contextChunks = (new ContextAssembler)->select($chunks, $effectiveQuestion);
        $sandboxRequirement = (new DocumentedFactAnswer)->sandboxRequirements($technicalQuestion, $chunks->reject(static fn ($chunk): bool => $promptInjectionGuard->containsPromptInjection((string) data_get($chunk, 'contenu', ''))));
        if ($sandboxRequirement !== null) {
            $contextChunks = $contextChunks->push($sandboxRequirement['chunk'])->unique('chunk_id')->values();
        }
        $context = $this->context($contextChunks, $promptInjectionGuard);

        if ($logText !== null) {
            $context = "[EXTRAIT DE LOG FOURNI PAR LE DEVELOPPEUR]\n{$logText}\n\n---\n\n".$context;
        }

        if ($context === '') {
            // Retrieval found nothing usable for this question: fail the same way /assistant
            // does (no grounded context -> no answer) instead of letting the model improvise.
            return response()->json([
                'error' => "Je n'ai pas trouve de passage documentaire PVIT pertinent pour cette question. Reformulez en precisant le moyen de paiement ou l'operation concernee, ou ouvrez un ticket de support.",
                ...$this->escalation($question, $conversation),
            ], 422);
        }

        // An undocumented HTTP error must not turn into a generic API diagnosis.
        if (($httpError = $actions->requestedHttpError($question)) !== null
            && ! $contextChunks->contains(static fn ($chunk): bool => preg_match('/\b'.preg_quote($httpError, '/').'\b/u', (string) data_get($chunk, 'contenu', '')) === 1)) {
            $answer = 'La documentation PVIT fournie ne decrit pas le code HTTP '.$httpError
                .' pour cet appel. Je ne peux pas en deduire la cause ; ouvrez un ticket de support avec le message d erreur, sans cle ni donnee confidentielle.';
            if ($actions->declinesSupport($question)) {
                $answer = $actions->withoutSupportInvitation($answer);
            }
            $this->rememberConversationTurn($conversation, $conversationReference, $question, $answer, $paymentMethod, $operation, $effectiveQuestion);

            return response()->json([
                'answer' => $answer,
                ...$this->escalation($question, $conversation, $conversationReference),
                'conversation_id' => $conversationReference,
                'code_filtered' => false,
                'citation_filtered' => false,
                'sources' => $this->sources($contextChunks),
                'answer_type' => GenerationPolicy::DIAGNOSTIC,
                'model' => $this->modelName(),
            ]);
        }

        $factChunks = $chunks->reject(static fn ($chunk): bool => $promptInjectionGuard->containsPromptInjection((string) data_get($chunk, 'contenu', '')));
        $facts = new DocumentedFactAnswer;
        $fact = $facts->sandboxPlan($technicalQuestion, $factChunks)
            ?? $facts->jsonRoleFields($technicalQuestion, $effectiveQuestion, $factChunks, $jsonMetadata)
            ?? $facts->reportingAcknowledgement($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? $facts->statusCapability($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? (new RequestContractAnswer)->refundProcedure($technicalQuestion, $factChunks)
            ?? (new RequestContractAnswer)->combinedFields($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? (new RequestContractAnswer)->requiredFields($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? (new RequestContractAnswer)->optionalFields($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? $facts->httpsPolicy($technicalQuestion, $factChunks)
            ?? $facts->callbackConfiguration($technicalQuestion, $factChunks)
            ?? $facts->callbackRoles($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? $facts->callbackAcknowledgement($technicalQuestion, $factChunks)
            ?? $facts->feesQueryExample($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? $facts->expiredExport($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? $facts->exportRenewal($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? $facts->endpoint($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? $facts->credentialDuration($technicalQuestion, $effectiveQuestion, $factChunks)
            ?? $facts->exportAvailability($technicalQuestion, $effectiveQuestion, $factChunks);
        if ($fact !== null) {
            if (isset($fact['parameter_kind']) && ($actions->parameterKind($technicalQuestion) ?? $parameterKind) !== $fact['parameter_kind']) {
                return $this->staticAnswer('Précisez le groupe de paramètres souhaité : obligatoires, optionnels ou les deux. Les informations précédentes ont été conservées.', $memory !== [] ? $conversationReference : null);
            }
            $rendered = $verifier->renderNumbered($fact['answer'], $fact['chunks'] ?? collect([$fact['chunk']]));
            $answer = $rendered['answer'];
            $canonicalAnswer = $answer;
            $sources = $this->sources(collect($rendered['sources']), numbered: true);
            if ($targetUnit !== null && ($factDuration = $conversion->extract($canonicalAnswer)) !== null) {
                if ($targetUnits !== [$factDuration['unit']]) {
                    $answer = $canonicalAnswer."\n\n".$conversion->convertMany($factDuration, $targetUnits).($sources !== [] ? ' [1]' : '');
                }
            }
            if (! isset($fact['json_examples'])) {
                $answer = $actions->formatAnswer($answer, $technicalQuestion);
            }
            $state = ['canonical_answer' => $canonicalAnswer];
            if (isset($fact['parameter_kind'])) {
                $state['parameter_kind'] = $fact['parameter_kind'];
            }
            if ($scopeKey !== null && preg_match('/param[eè]tres|champs|en[- ]t[eê]tes/iu', $technicalQuestion) === 1) {
                $state['parameter_scope'] = $scopeKey;
            }
            if (isset($fact['json_examples'])) {
                $state += ['payload_examples' => $fact['json_examples'], 'payload_answer' => Str::limit($fact['json_answer'], 8000, ''), 'payload_sources' => $sources];
            }
            $this->rememberConversationTurn($conversation, $conversationReference, $question, $answer, $paymentMethod, $operation, $effectiveQuestion, $sources, $state);

            return response()->json([
                'answer' => $answer, 'sources' => $sources,
                'conversation_id' => $conversationReference, 'should_escalate' => false,
                'code_filtered' => false, 'citation_filtered' => false,
                'answer_type' => GenerationPolicy::FACT, 'model' => $this->modelName(),
            ]);
        }

        // Every call gets the compact synthesis prompt of GenerationPolicy, whose single length
        // rule and token cap follow the kind of question (a fact, a procedure, a diagnosis),
        // decided here rather than left to the model. A screenshot shows a symptom: it is a
        // diagnosis whatever the wording.
        $policy = new GenerationPolicy;
        $answerType = $imageDataUrl !== null ? GenerationPolicy::DIAGNOSTIC : $policy->classify($technicalQuestion);
        $generationOptions = ['max_tokens' => $policy->maxTokens($answerType)];
        $systemPrompt = $policy->systemPrompt($answerType).($imageDataUrl !== null ? self::VISION_RULES : '');
        if ($sandboxRequirement !== null) {
            $systemPrompt .= "\nDistingue les tests unitaires de paiement de la validation definitive de l integration : KYC et Check Status sont obligatoires pour cette validation, selon l extrait fourni. Ne les presente jamais comme optionnels.";
        }
        $generationQuestion = $isFollowUp
            ? "Sujet de la conversation : {$effectiveQuestion}\nQuestion actuelle (repondre uniquement a celle-ci) : {$technicalQuestion}"
            : $technicalQuestion;

        try {
            $answer = $imageDataUrl !== null
                ? $llm->completeWithImage(
                    'mistral',
                    $systemPrompt,
                    $context,
                    $imageDataUrl,
                    $generationQuestion,
                    $generationOptions,
                )
                : $llm->completeWithProvider(
                    'mistral',
                    $systemPrompt,
                    $context,
                    $generationQuestion,
                    $generationOptions,
                );
        } catch (Throwable $exception) {
            Log::warning('Mistral raw call failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'correlation_id' => $this->correlationId(),
            ]);

            return response()->json([
                'error' => $exception->getMessage(),
                'correlation_id' => $this->correlationId(),
            ], 502);
        }

        // Application code is never shown; a documented cURL/JSON example survives stripping.
        $guardCode = static function (string $text) use ($responseGuard): array {
            if (! $responseGuard->containsSourceCode($text)) {
                return [$text, false, false];
            }

            $stripped = $responseGuard->stripCodeLikeSegments($text);

            return mb_strlen($stripped) >= 20 && ! $responseGuard->containsSourceCode($stripped)
                ? [$stripped, true, false]
                : [$responseGuard->refusal(), true, true];
        };
        [$answer, $codeFiltered, $answerRefused] = $guardCode($answer);

        if ($answerRefused) {
            // The user did not ask for code: an unusable model draft must not replace the
            // previous verified answer or its subject, nor masquerade as a policy refusal.
            return response()->json([
                'answer' => 'Je n’ai pas pu produire une réponse documentaire fiable pour cette demande. Précisez votre question ou reprenez la réponse précédente.',
                ...$this->escalation($question, $conversation, $conversationReference),
                'code_filtered' => true,
                'citation_filtered' => true,
                'model' => $this->modelName($imageDataUrl !== null),
                'sources' => $this->sources($contextChunks),
                'conversation_id' => $conversation?->reference,
            ]);
        }

        // Each paragraph, list item and example is checked against the extracts: what is
        // demonstrably wrong is removed and reported, never rewritten; the rest is kept, cited or
        // not. A diagnosis from a screenshot is checked the same way.
        if ($sandboxRequirement !== null) {
            $answer .= "\n\n".$sandboxRequirement['answer'];
        }
        $graded = $verifier->verify($answer, $contextChunks, $effectiveQuestion);

        // The retry is a text completion: it cannot see the screenshot again.
        if (! $graded->acceptable && $imageDataUrl === null) {
            // The opening claim or most of the draft is wrong: one informed retry, which sees its
            // own draft and the exact passages that were removed, with the reason for each.
            Log::info('Mistral raw draft partly wrong; retrying.', [
                'reason' => $graded->reason,
                'removed' => array_map(static fn (array $unit): string => $unit['reason'], $graded->removed),
                'question' => $effectiveQuestion,
            ]);

            try {
                $retry = $llm->completeWithProvider(
                    'mistral',
                    $policy->retryPrompt($answerType, $answer, $graded->removed),
                    $context,
                    $generationQuestion,
                    $generationOptions,
                );
                [$retry, $retryCodeFiltered, $retryRefused] = $guardCode($retry);

                if (! $retryRefused) {
                    if ($sandboxRequirement !== null) {
                        $retry .= "\n\n".$sandboxRequirement['answer'];
                    }
                    $retryGraded = $verifier->verify($retry, $contextChunks, $effectiveQuestion);

                    if ($retryGraded->isBetterThan($graded)) {
                        $graded = $retryGraded;
                        $codeFiltered = $codeFiltered || $retryCodeFiltered;
                    }
                }
            } catch (Throwable $retryException) {
                Log::warning('Mistral raw verification retry failed.', [
                    'exception' => $retryException::class,
                    'message' => $retryException->getMessage(),
                ]);
            }
        }

        // Nothing left once the errors are removed, or an opening claim that is still wrong after
        // the retry: the answer is refused rather than shown around its main error. Otherwise it
        // is shown without what was removed, and says so.
        $citationFiltered = ! $graded->acceptable;
        $sources = $this->sources($contextChunks);

        if ($citationFiltered) {
            Log::warning('Mistral raw answer refused after verification.', [
                'reason' => $graded->reason,
                'question' => $effectiveQuestion,
            ]);

            // A bare refusal wastes the documents retrieval did find: list them as clickable
            // pointers so the refusal guides instead of dead-ends.
            $pointers = $chunks->take(4)->map(function ($chunk): string {
                $document = trim((string) $chunk->document_titre);
                $section = trim((string) ($chunk->section ?: ''));
                $label = $document !== '' ? $document : ($section !== '' ? $section : 'Documentation PVIT');
                $url = trim((string) $chunk->lien_officiel);
                $isOfficial = preg_match('/\Ahttps:\/\/[^\s]*\z/u', $url) === 1
                    && in_array((string) (parse_url($url, PHP_URL_HOST) ?: ''),
                        array_map('strtolower', (array) config('rag.allowed_source_hosts', [])), true);

                $line = $section !== '' && $section !== $document
                    ? '- '.$label.' - '.$section
                    : '- '.$label;

                return $isOfficial ? $line.' : '.$url : $line;
            })->implode("\n");

            $answer = "Je n'ai pas pu produire une reponse suffisamment adossee a la documentation "
                .'PVIT pour cette question (la reponse generee contredisait ou depassait les extraits). '
                ."Reformulez en precisant le moyen de paiement ou l'operation concernee, ou "
                ."consultez ces passages les plus proches :\n\n".$pointers
                ."\n\nPour aller plus loin, ouvrez un ticket de support.";
            if ($actions->declinesSupport($question)) {
                $answer = str_replace("\n\nPour aller plus loin, ouvrez un ticket de support.", '', $answer);
            }
            $sources = $this->sources($chunks);
        } else {
            $rendered = $verifier->renderNumbered($graded->answer, $contextChunks);
            $answer = $rendered['answer'];

            if ($graded->hasRemovals()) {
                $answer .= "\n\n*Certains points de la reponse generee n'ont pas pu etre verifies dans la "
                    .'documentation PVIT et ont ete retires.*';
            }

            $answer = (new ExampleValueNotice)->append($answer, $contextChunks);
            $answer = $actions->formatAnswer($answer, $question);
            $sources = $this->sources(collect($rendered['sources']), numbered: true);
        }

        $this->rememberConversationTurn(
            $conversation,
            $conversationReference,
            $question,
            $answer,
            $paymentMethod,
            $operation,
            $effectiveQuestion,
            $sources,
            ['verified' => ! $citationFiltered],
        );

        // A refused answer, or one whose main point is that the documentation does not cover the
        // question, sends the developer to the support: the page then offers a ticket.
        $shouldEscalate = $citationFiltered || (new DocumentationGapDetector)->isGapAnswer($answer);

        return response()->json([
            'answer' => $answer,
            ...($shouldEscalate ? $this->escalation($question, $conversation, $conversationReference) : ['should_escalate' => false]),
            'code_filtered' => $codeFiltered,
            'citation_filtered' => $citationFiltered,
            'removed_claims' => count($graded->removed),
            'removed_reasons' => $graded->removedReasons(),
            'answer_type' => $answerType,
            'model' => $this->modelName($imageDataUrl !== null),
            'sources' => $sources,
            'conversation_id' => $conversationReference,
        ]);
    }

    private function modelName(bool $vision = false): string
    {
        return (string) config($vision ? 'llm.providers.mistral.vision_model' : 'llm.providers.mistral.model');
    }

    // code_filtered marks a code refusal, as the strip-then-refuse path does.
    private function staticAnswer(string $answer, ?string $conversationReference, bool $codeRefusal = false): JsonResponse
    {
        return response()->json([
            'answer' => $answer,
            'should_escalate' => false,
            'code_filtered' => $codeRefusal,
            'model' => (string) config('llm.providers.mistral.model'),
            'sources' => [],
            'conversation_id' => $conversationReference,
        ]);
    }

    /**
     * The question is recorded so that /api/ticket can open a ticket on it; the signed token
     * proves the caller asked it. Without a database the answer still recommends the support,
     * only the ticket button is missing.
     *
     * @return array{should_escalate: bool, question_id: ?int, ticket_token: ?string}
     */
    private function escalation(string $question, ?Conversation $conversation, ?string $conversationReference = null): array
    {
        if ((new ConversationActions)->declinesSupport($question)) {
            return ['should_escalate' => false, 'question_id' => null, 'ticket_token' => null];
        }
        try {
            // A new conversation is created by rememberConversationTurn, after the request started.
            $conversation ??= $conversationReference !== null
                ? Conversation::query()->whereNull('developpeur_id')->where('reference', $conversationReference)->first()
                : null;
            $record = Question::create([
                'conversation_id' => $conversation?->id,
                'texte' => $question,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Mistral raw escalation could not be recorded.', [
                'exception' => $exception::class,
                'correlation_id' => $this->correlationId(),
            ]);

            return ['should_escalate' => true, 'question_id' => null, 'ticket_token' => null];
        }

        return [
            'should_escalate' => true,
            'question_id' => $record->id,
            'ticket_token' => Crypt::encryptString((string) $record->id),
        ];
    }

    private function conversationState(?string $requestedReference): array
    {
        $reference = is_string($requestedReference) ? trim($requestedReference) : null;
        $reference = ($reference !== '' && preg_match('/\A[A-Za-z0-9._:-]{1,80}\z/', $reference) === 1)
            ? $reference
            : null;

        if ($reference !== null) {
            // Anonymous-only scope, mirroring AssistantService's lookup for an unauthenticated
            // caller: a reference can never attach to (or read) a developer-owned conversation.
            $conversation = Conversation::query()
                ->where('reference', $reference)
                ->where('statut', 'active')
                ->whereNull('date_cloture')
                ->whereNull('developpeur_id')
                ->first();

            if ($conversation !== null) {
                return [$conversation, (string) $conversation->reference];
            }
            if (preg_match('/\Aconv_[a-z0-9]{40}\z/', $reference) === 1
                && ! Conversation::where('reference', $reference)->exists()
                && app(ConversationMemoryStore::class)->hasTemporary($reference)) {
                return [null, $reference];
            }
        }

        // Mirror AssistantService: a caller-selected reference is only a cache/lookup key for a
        // conversation that actually exists; when none does, the persistent conversation gets a
        // server-generated opaque reference so no caller can claim (or collide with) a reference
        // they did not receive from this endpoint.
        return [null, $this->newConversationReference()];
    }

    private function newConversationReference(): string
    {
        // Same entropy argument as AssistantService: random_bytes-backed, 40 base-62 characters,
        // with the database unique constraint as the final guard.
        return 'conv_'.Str::lower(Str::random(40));
    }

    /**
     * @return array{last_question?: string, last_answer?: string, last_payment_method?: string, last_operation?: ?string}
     */
    private function conversationMemory(?Conversation $conversation, ?string $conversationReference): array
    {
        return app(ConversationMemoryStore::class)->read($conversation, $conversationReference);
    }

    private function questionWithConversationMemory(string $questionText, array $memory, bool $isFollowUp): string
    {
        if (! $isFollowUp || empty($memory['last_question'])) {
            return $questionText;
        }

        // "Et pour Moov Money ?" must retrieve for the full previous intent, not for two tokens.
        return (new FollowUpDetector)->resolve($questionText, $memory);
    }

    private function rememberConversationTurn(
        ?Conversation $conversation,
        ?string $conversationReference,
        string $questionText,
        string $answer,
        string $paymentMethod,
        ?string $operation,
        ?string $resolvedQuestion = null,
        array $sources = [],
        array $state = [],
    ): void {
        if ($conversationReference === null) {
            return;
        }

        $previous = (new ConversationMemory)->forQuestion($this->conversationMemory($conversation, $conversationReference), $questionText);
        $followUp = (new FollowUpDetector)->isFollowUp($questionText, $previous['last_question'] ?? null);
        $askedSubject = (new ConversationPlan)->subject($questionText);
        $knownSubject = (new ConversationPlan)->subject($previous['topic_question'] ?? $previous['resolved_question'] ?? $previous['last_question'] ?? '');
        if ((new ConversationActions)->formatRequest($questionText) !== null && $askedSubject !== null && $knownSubject !== null && $askedSubject !== $knownSubject) {
            $followUp = false;
        }
        $topic = $followUp ? ($previous['topic_question'] ?? $previous['resolved_question'] ?? $previous['last_question'] ?? $questionText) : ($resolvedQuestion ?? $questionText);
        $recent = $followUp ? array_slice((array) ($previous['recent_questions'] ?? []), -3) : [];
        $recent[] = $questionText;
        $plan = new ConversationPlan;
        $sameSubject = $plan->subject($topic) !== null && $plan->subject($topic) === $plan->subject($previous['topic_question'] ?? $previous['resolved_question'] ?? '');
        $presentation = (bool) ($state['presentation'] ?? false);
        $verified = (bool) ($state['verified'] ?? true);
        $canonicalAnswer = $state['canonical_answer'] ?? $answer;
        $stateParameterScope = $state['parameter_scope'] ?? null;
        $stateParameterKind = $state['parameter_kind'] ?? null;
        unset($state['presentation'], $state['verified'],$state['canonical_answer'], $state['parameter_scope'], $state['parameter_kind']);
        $newSteps = $presentation ? [] : $plan->extract($canonicalAnswer);
        $steps = $newSteps !== [] ? $newSteps : (($followUp || $sameSubject) ? ($previous['plan_steps'] ?? []) : []);
        $actions = new ConversationActions;
        $parameterTurn = preg_match('/\b(?:param[eè]tres|champs|en[- ]t[eê]tes)\b/iu', $questionText) === 1 && ! $presentation;
        $parameterSets = ($followUp || $sameSubject) ? ($previous['parameter_sets'] ?? []) : [];
        $parameterKind = $stateParameterKind ?? $actions->parameterKind($questionText);
        if ($parameterTurn && $parameterKind !== null && (new ParameterMemory)->contradicts($parameterKind, $canonicalAnswer)) {
            $verified = false;
        }
        if ($parameterTurn && $parameterKind !== null && ($stateParameterKind === null || $stateParameterKind === $parameterKind)
            && ! (new ParameterMemory)->contradicts($parameterKind, $canonicalAnswer)) {
            $parameterScope = $stateParameterScope ?? (new ParameterScope)->key((new ParameterScope)->requested($questionText));
            $set = ['answer' => Str::limit($canonicalAnswer, 8000, ''), 'sources' => $sources, 'scope' => $parameterScope];
            if ($stateParameterKind !== null) {
                $set['contract_kind'] = $stateParameterKind;
            }
            $parameterSets[$parameterKind] = $set;
            $parameterSets[$parameterKind.':'.$parameterScope] = $set;
            $state['parameter_memory_invalidated'] = false;
        }
        $activeParameterKind = ($followUp || $sameSubject) ? ($previous['active_parameter_kind'] ?? null) : null;
        $activeParameterScope = ($followUp || $sameSubject) ? ($previous['active_parameter_scope'] ?? null) : null;
        if ($parameterTurn) {
            $activeParameterScope = $parameterScope ?? null;
        } elseif ($actions->parameterSummaryRequest($questionText) && ($requestedScope = (new ParameterScope)->requested($questionText)) !== null) {
            $activeParameterScope = (new ParameterScope)->key($requestedScope);
        }
        if (($parameterTurn || $actions->parameterSummaryRequest($questionText)) && $parameterKind !== null) {
            $activeParameterKind = $parameterKind;
        }
        $memory = array_merge([
            'topic_question' => Str::limit($topic, 1000, ''),
            'recent_questions' => $recent,
            'plan_steps' => $steps,
            'plan_index' => $followUp ? ($previous['plan_index'] ?? 0) : 0,
            'plan_sources' => $newSteps !== [] ? $sources : (($followUp || $sameSubject) ? ($previous['plan_sources'] ?? []) : []),
            'plan_subject' => $newSteps !== [] ? $plan->procedureSubject($questionText) : (($followUp || $sameSubject) ? ($previous['plan_subject'] ?? $plan->procedureSubject($previous['topic_question'] ?? '')) : null),
            'last_question' => Str::limit($questionText, 500, ''),
            'resolved_question' => Str::limit($resolvedQuestion ?? $questionText, 2000, ''),
            // Keep fences: stripping formatting before storing made example payloads look
            // like prose to the summary extractor.
            'last_answer' => Str::limit($answer, 8000, ''),
            'last_sources' => $sources,
            'last_payment_method' => $paymentMethod,
            'last_operation' => $operation,
            'parameter_answer' => preg_match('/\b(?:param[eè]tres|champs)\b/iu', $questionText) === 1 && ! $presentation ? Str::limit($answer, 8000, '') : (($followUp || $sameSubject) ? ($previous['parameter_answer'] ?? '') : ''),
            'parameter_sources' => preg_match('/\b(?:param[eè]tres|champs)\b/iu', $questionText) === 1 && ! $presentation ? $sources : (($followUp || $sameSubject) ? ($previous['parameter_sources'] ?? []) : []),
            'parameter_question' => $parameterTurn ? $questionText : (($followUp || $sameSubject) ? ($previous['parameter_question'] ?? '') : ''),
            'parameter_sets' => $parameterSets,
            'active_parameter_scope' => $activeParameterScope,
            'active_parameter_kind' => $activeParameterKind,
            'payload_answer' => str_contains($answer, '```json') && ! $presentation ? Str::limit($answer, 8000, '') : (($followUp || $sameSubject) ? ($previous['payload_answer'] ?? '') : ''),
            'payload_sources' => str_contains($answer, '```json') && ! $presentation ? $sources : (($followUp || $sameSubject) ? ($previous['payload_sources'] ?? []) : []),
            'payload_examples' => str_contains($answer, '```json') && ! $presentation ? (new JsonExamples)->extract($answer) : (($followUp || $sameSubject) ? ($previous['payload_examples'] ?? []) : []),
            'duration_fact' => $presentation ? ($previous['duration_fact'] ?? null) : ((new DurationConversion)->extract($canonicalAnswer) ?? (($followUp || $sameSubject) ? ($previous['duration_fact'] ?? null) : null)),
        ], $state);
        $memory = (new ConversationMemory)->retain($previous, $memory, $verified, $presentation);
        if ($verified && ! $presentation) {
            $memory['last_verified_answer'] = Str::limit($canonicalAnswer, 8000, '');
        }
        if ($verified) {
            $evidenceSources = array_merge($memory['last_sources'] ?? [], $memory['plan_sources'] ?? [], $memory['parameter_sources'] ?? [], $memory['payload_sources'] ?? []);
            foreach ($memory['parameter_sets'] ?? [] as $set) {
                $evidenceSources = array_merge($evidenceSources, $set['sources'] ?? []);
            }
            $memory['evidence_stamp'] = (new ConversationEvidence)->capture($evidenceSources);
            $subject = (new ConversationPlan)->subject($memory['topic_question'] ?? '');
            if ($subject !== null && isset($memory['subjects'][$subject])) {
                $memory['subjects'][$subject]['evidence_stamp'] = $memory['evidence_stamp'];
                $memory['subjects'][$subject]['last_verified_answer'] = $memory['last_verified_answer'];
            }
        }

        app(ConversationMemoryStore::class)->write($conversation, $conversationReference, $memory);
    }

    // Same request-attribute lookup as AssistantService: the id assigned by the
    // AssignCorrelationId middleware, echoed back to the caller on infrastructure failures.
    private function correlationId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');
        $value = $request->attributes->get('correlation_id')
            ?? $request->headers->get('X-Correlation-ID');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function cleanDisplayText(string $text): string
    {
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text);
        $text = (string) preg_replace('/[\p{So}\p{Sk}\x{FE0F}\x{200D}]+/u', ' ', $text);
        $text = (string) preg_replace('/\p{Cf}+|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
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

    // With $numbered, the sources are the cited chunks in citation order, and 'number' is the
    // [n] marker the answer uses for each.
    private function sources(Collection $chunks, bool $numbered = false): array
    {
        return $chunks->values()->map(fn ($chunk, int $index): array => array_filter([
            'number' => $numbered ? $index + 1 : null,
            'chunk_id' => (int) $chunk->chunk_id,
            'document' => (string) $chunk->document_titre,
            'section' => (string) $chunk->section,
            'url' => (string) $chunk->lien_officiel,
        ], static fn (mixed $value): bool => $value !== null))->all();
    }
}
