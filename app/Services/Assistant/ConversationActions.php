<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

class ConversationActions
{
    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', Str::ascii(mb_strtolower($text))) ?? '') ?? '');
    }

    public function declinesSupport(string $question): bool
    {
        $flat = $this->normalize($question);

        return preg_match('/\b(?:pas de|aucun|sans)\s+(?:nouveau\s+)?(?:ticket|support)\b/u', $flat) === 1
            || preg_match('/\b(?:sans|pas|jamais)\s+(?:ouvrir|ouvre|creer|cree|contacter|contacte|transmettre|transmets)\b.{0,40}\b(?:ticket|support)\b/u', $flat) === 1
            || preg_match('/\b(?:ne|n)\b.{0,35}\b(?:ouvre|ouvrir|cree|creer|transmets|transmettre|contacte|contacter)\b.{0,25}\b(?:pas|aucun|jamais)\b.{0,30}\b(?:ticket|support)\b/u', $flat) === 1;
    }

    public function requestsSupport(string $question): bool
    {
        $flat = preg_replace('/[^a-z0-9\s]/', ' ', Str::ascii(mb_strtolower($question))) ?? '';

        return preg_match('/\b(?:ouvre|ouvrir|creer|cree|contacter|contacte|transmettre|transmets)\b.{0,70}\b(?:ticket|support)\b/u', $flat) === 1
            && ! $this->declinesSupport($question)
            && preg_match('/\b(?:comment|(?:ne|n) .{0,20}pas)\b/u', $flat) !== 1;
    }

    /** Remove a declined action, leaving any information request in the same message. */
    public function withoutSupportDirective(string $question): string
    {
        if (! $this->declinesSupport($question)) {
            return $question;
        }
        $clauses = preg_split('/\s*(?::(?=\s)|;|[.!?](?=\s)|,\s*(?:mais\s+)?|\bet\s+(?=(?:explique|donne|precise|montre|rappelle)))\s*/iu', $question) ?: [];
        $remaining = [];
        foreach ($clauses as $clause) {
            if ($this->declinesSupport($clause)) {
                $clause = preg_replace('/\b(?:(?:sans|pas|jamais)\s+(?:ouvrir|ouvre|cr[ée]er|cr[ée]e|contacter|contacte|transmettre|transmets)|(?:ne|n[’\'])\s*(?:en\s+)?(?:ouvre|cr[ée]e|transmets|contacte)|(?:pas de|aucun))\b[^,;:!?]{0,70}?\b(?:ticket|support)\b(?:\s+(?:pour le moment|maintenant))?[.]?/iu', '', $clause) ?? '';
                if ($this->declinesSupport($clause)) {
                    continue;
                }
            }
            if (preg_match('/^(?:non|oui|merci|pour le moment|maintenant|d accord)?$/u', $this->normalize($clause)) !== 1) {
                $remaining[] = trim($clause);
            }
        }

        return trim(implode(' ', $remaining));
    }

    public function refersToPreviousAnswer(string $question): bool
    {
        $flat = $this->normalize($question);
        if ($this->requestsExpansion($question)) {
            return true;
        }
        if ($this->isPresentationOnly($question)) {
            return true;
        }
        if (preg_match('/\b(?:resum\w*|recapitul\w*|reformul\w*|simplifi\w*|raccourci\w*|rappel\w*|explique\w*)\b(.*)$/u', $flat, $match) !== 1) {
            return false;
        }
        $object = trim(preg_replace('/^(?:de |du |d )/', '', trim($match[1])) ?? $match[1]);
        if (preg_match('/^(?:(?:moi|nous)\s+)?(?:cela|ca|ceci|(?:ta|votre|la|cette)\s+(?:(?:derniere|precedente)\s+)?reponse)\b/u', $object) === 1) {
            return true;
        }
        if (preg_match('/^(?:(?:les|ces|tes|vos) etapes(?= dans l ordre| sans| en |$)|(?:le|ce|notre|votre) (?:parcours|plan)(?= en | sans|$)|(?:la|cette) procedure(?= en | sans|$)|la meme chose|le meme sujet|cette duree)\b/u', $object) === 1) {
            return true;
        }

        // A format alone refers back; a named object ("l API de remboursement") does not.
        $formatOnly = preg_replace('/\ben\s+(?:(?:[1-9]|10|un|une|deux|trois|quatre|cinq|six|sept|huit|neuf|dix)\s+(?:seule?\s+)?(?:points?|phrases?|puces?)|termes simples)\b/u', '', $object) ?? $object;
        $formatOnly = preg_replace('/\b(?:sans|ni)\s+(?:liste|code|puces)|\bplus\s+(?:court|simple)|\bs il (?:te|vous) plait\b|\b(?:moi|nous|svp|stp)\b/u', '', $formatOnly) ?? $formatOnly;

        return trim($formatOnly) === '';
    }

    /** Only subjectless requests: a named new topic must keep its own retrieval. */
    public function requestsExpansion(string $question): bool
    {
        $flat = $this->normalize($question);
        $flat = preg_replace('/^(?:(?:merci|peux tu|pouvez vous|pourrais tu|pourriez vous)\s+)+/u', '', $flat) ?? $flat;
        $flat = preg_replace('/^(?:m|me|nous)\s+/u', '', $flat) ?? $flat;
        $flat = preg_replace('/^(expliquer|detailler|developper)\b/u', match (strtok($flat, ' ')) {
            'detailler' => 'detaille', 'developper' => 'developpe', default => 'explique',
        }, $flat) ?? $flat;
        $flat = trim(preg_replace('/\b(?:stp|svp|s il (?:te|vous) plait)\b/u', '', $flat) ?? $flat);

        return preg_match('/^(?:(?:plus |encore plus )?en details?|(?:explique|expliquez|expliques)(?: moi| nous)? (?:davantage|plus|plus en details?|(?:cette etape|ce point|cela|ca) (?:davantage|plus en details?))|(?:detaille|detaillez|developpe|developpez)(?: moi| nous)?(?: (?:cela|ca|ceci|cette etape|ce point|cette reponse|davantage))?)$/u', $flat) === 1;
    }

    public function isPresentationOnly(string $question): bool
    {
        $flat = $this->normalize($question);

        return $this->protocolExampleRequest($question)
            || $this->jsonStatusRequest($question)
            || $this->fieldNamesRequest($question)
            || $this->parameterSummaryRequest($question)
            || preg_match('/\b(?:mets?|presente|transforme|convertis|affiche)\b.{0,20}\b(?:ca|cela|ceci|cette reponse|ces elements)\b.{0,50}\btableau\b/u', $flat) === 1
            || preg_match('/^(?:sous forme de tableau|un tableau (?:stp|svp)|en tableau)\b/u', $flat) === 1
            || preg_match('/\b(?:expliqu\w*|reformul\w*|simplifi\w*|resum\w*|recapitul\w*|raccourci\w*)\b.{0,25}\bcette etape\b/u', $flat) === 1
            || preg_match('/^si cette information n est pas documentee\b/u', $flat) === 1
            || preg_match('/\b(?:retire|supprime|enleve)\s+(?:le |ce )?tableau\b/u', $flat) === 1
            || preg_match('/\b(?:resum\w*|recapitul\w*)\b.{0,25}\bces parametres\b/u', $flat) === 1
            || preg_match('/\bcompare\b.{0,35}\bcode callback\b.{0,20}\bx secret\b/u', $flat) === 1
            || preg_match('/^(?:en francais (?:plus )?simple|plus simplement|en mots simples)\b/u', $flat) === 1
            || preg_match('/\b(?:present\w*|met\w*|compar\w*)\b.{0,35}\b(?:ces|les) deux (?:elements|notions)\b.{0,50}\btableau\b/u', $flat) === 1;
    }

    public function parameterSummaryRequest(string $question): bool
    {
        return preg_match('/\b(?:resum\w*|recapitul\w*|reformul\w*|present\w*)\b.{0,40}\b(?:parametres|champs|en tetes)\b/u', $this->normalize($question)) === 1;
    }

    public function parameterKind(string $question): ?string
    {
        $question = preg_replace('/\b(?:sans|ni|pas)\s+(?:(?:les|des|de)\s+)?(?:paramètres\s+)?(?:optionnels?|facultatifs?|obligatoires?|requis)\b/iu', '', $question) ?? $question;
        $flat = $this->normalize(preg_split('/[,;]\s*(?:pas|ni|sans)\b/iu', $question)[0]);
        if (preg_match('/\b(?:obligatoires?|requis|necessaires?)\b/u', $flat) === 1 && preg_match('/\b(?:optionnels?|facultatifs?)\b/u', $flat) === 1
            || preg_match('/\b(?:deux groupes|les deux)\b/u', $flat) === 1 && str_contains($flat, 'parametres')) {
            return 'both';
        }
        if (preg_match('/\b(?:obligatoires?|requis|necessaires?)\b/u', $flat) === 1) {
            return 'required';
        }

        return preg_match('/\b(?:optionnels?|facultatifs?)\b/u', $flat) === 1 ? 'optional' : null;
    }

    public function fieldNamesRequest(string $question): bool
    {
        return preg_match('/\b(?:noms? (?:des? |de ces |de )?champs|liste (?:des? |de ces )?champs|quels champs contient)\b/u', $this->normalize($question)) === 1;
    }

    public function declinesExamples(string $question): bool
    {
        return preg_match('/\b(?:sans|pas d|aucun|ni)\s+(?:(?:nouvel?|autre|aucun)\s+)?(?:valeurs? d exemple|exemple|json|code)\b/u', $this->normalize($this->withoutDeclinedApplicationCode($question))) === 1;
    }

    public function withoutDeclinedApplicationCode(string $question): string
    {
        return preg_replace('/\b(?:je ne veux pas|je ne souhaite pas|pas de|sans)\s+(?:de\s+|du\s+)?code\s+(?:source\s+)?(?:php|python|javascript|typescript|java|rust|go)\b/iu', '', $question) ?? $question;
    }

    public function protocolExampleRequest(string $question): bool
    {
        $flat = $this->normalize($this->withoutDeclinedApplicationCode($question));

        return $this->requestsExample($question) && str_contains($flat, 'json')
            && preg_match('/\b(?:montre|donne|affiche|reprends)\b/u', $flat) === 1
            && preg_match('/\b(?:cet accuse|cette reponse|cet exemple|cette notification|l accuse|le meme|ce json)\b/u', $flat) === 1;
    }

    public function requestsExample(string $question): bool
    {
        return ! $this->declinesExamples($question)
            && preg_match('/\b(?:exemple|json|curl)\b/u', $this->normalize($question)) === 1;
    }

    public function jsonStatusRequest(string $question): bool
    {
        return preg_match('/\bquel statut\b.{0,60}\b(?:cet exemple|ce json|cette notification)\b/u', $this->normalize($question)) === 1;
    }

    public function withoutSupportInvitation(string $answer): string
    {
        $answer = preg_replace('/(?:\s*(?:[;,]|\bet\b|\bou\b)\s*)?(?:Pour aller plus loin,\s*)?(?:je recommande d[ ’\']?\s*)?(?:ouvrez|ouvrir|contactez|contacter|cr[ée]ez|transmettez)\b[^\n.!?]*\b(?:ticket|support)\b[^\n.!?]*[.!?]?/iu', '', $answer) ?? $answer;

        return trim($answer, " \t\n\r\0\x0B;,");
    }

    public function stepRequest(string $question): ?string
    {
        $flat = $this->normalize($question);
        // Nominal references still select the parent plan, even after an explanation.
        if (preg_match('/^(?:et\s+)?(?:(?:le|la|l)\s+)?(?:point|etape)(?:\s+(?:numero|n))?\s+(\d+)\b/u', $flat, $nominal) === 1) {
            $flat = 'montre le point '.$nominal[1].substr($flat, strlen($nominal[0]));
        }
        $flat = preg_replace('/^(?:(?:s il (?:te|vous) plait|peux tu|pouvez vous|pourrais tu|pourriez vous)\s+)+/u', '', $flat) ?? $flat;
        $flat = preg_replace('/^(?:me|m|nous)\s+/u', '', $flat) ?? $flat;
        $flat = preg_replace('/^(passer|revenir|retourner|montrer|afficher|expliquer|detailler|simplifier|reprendre|donner)\b/u', match (strtok($flat, ' ')) {
            'passer' => 'passe', 'revenir' => 'reviens', 'retourner' => 'retourne', 'montrer' => 'montre', 'afficher' => 'affiche',
            'detailler' => 'detaille', 'simplifier' => 'simplifie', 'reprendre' => 'reprends', 'donner' => 'donne', default => 'explique',
        }, $flat) ?? $flat;
        $flat = preg_replace('/^(explique|detaille|montre|affiche|donne|simplifie|developpe)(?:s|z)?\b/u', '$1', $flat) ?? $flat;
        $bareReference = '/^(?:explique|detaille|montre|affiche|donne|simplifie|developpe|reprends)(?: moi| nous)?\s+(?:(?:le|la)\s+(?:(?:point|numero|n)\s+)?|(?:point|numero|n)\s+)(\d+)\b/u';
        if (preg_match($bareReference, $flat, $bare) === 1) {
            $tail = trim(substr($flat, strlen($bare[0])));
            $tail = preg_replace('/^(?:(?:plus )?en details?|en termes simples)\b/u', '', $tail) ?? $tail;
            $tail = preg_replace('/\b(?:stp|svp|s il (?:te|vous) plait)\b/u', '', $tail) ?? $tail;
            $invalidNumber = preg_match('/\b(?:le|la|point|[eé]tape|num[eé]ro|n)\s+(?:[\-−–—﹣－]\s*\d+|\d+[.,]\d+)/iu', $question) === 1;

            return trim($tail) === '' && ! $invalidNumber ? 'number:'.(int) $bare[1] : 'number:0';
        }
        $flat = preg_replace('/\bpoints?\b/u', 'etape', $flat) ?? $flat;
        $navigation = preg_match('/^(?:(?:va|passe|reviens|retourne|reprends|affiche|montre|donne|explique|detaille|developpe|simplifie)(?: moi)?\b|etape\b)/u', $flat) === 1;
        if ($navigation && preg_match('/\betape\s+(?:(?:numero|n)\s+)?moins\s+(?:\d+|un|une|deux|trois|quatre|cinq|six|sept|huit|neuf|dix)\b/u', $flat) === 1) {
            return 'number:0';
        }
        if ($navigation && preg_match('/[eé]tape\s+(?:(?:num[eé]ro|n[°º]?)\s*)?(?:[\-−–—﹣－]\s*\d+|\d+[.,]\d+)/iu', $question) === 1) {
            return 'number:0';
        }
        if ($navigation && preg_match('/\b(?:vingt|trente|quarante|cinquante|soixante|cent)\s+(?:[a-z]+\s+)*[a-z]+ieme\s+etape\b/u', $flat) === 1) {
            return 'number:0';
        }
        if ($navigation
            && preg_match('/\betape (?:numero |n )?(\d+|un|une|deux|trois|quatre|cinq|six|sept|huit|neuf|dix)\b/u', $flat, $number) === 1) {
            $numbers = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10];

            return 'number:'.($numbers[$number[1]] ?? (int) $number[1]);
        }
        if ($navigation && preg_match('/\b(premier|premiere|deuxieme|seconde|troisieme|quatrieme|cinquieme|sixieme|septieme|huitieme|neuvieme|dixieme|onzieme|douzieme|treizieme|quatorzieme|quinzieme|seizieme|dix septieme|dix huitieme|dix neuvieme|vingtieme)\s+etape\b/u', $flat, $ordinal) === 1) {
            $ordinals = ['premier' => 1, 'premiere' => 1, 'deuxieme' => 2, 'seconde' => 2, 'troisieme' => 3, 'quatrieme' => 4, 'cinquieme' => 5, 'sixieme' => 6, 'septieme' => 7, 'huitieme' => 8, 'neuvieme' => 9, 'dixieme' => 10, 'onzieme' => 11, 'douzieme' => 12, 'treizieme' => 13, 'quatorzieme' => 14, 'quinzieme' => 15, 'seizieme' => 16, 'dix septieme' => 17, 'dix huitieme' => 18, 'dix neuvieme' => 19, 'vingtieme' => 20];

            return $ordinals[$ordinal[1]] === 1 ? 'first' : 'number:'.$ordinals[$ordinal[1]];
        }
        if ($navigation && preg_match('/\b(\d+)(?:e|eme|er|ere)\s+etape\b/u', $flat, $ordinal) === 1) {
            return 'number:'.$ordinal[1];
        }
        if ($navigation && preg_match('/\betape suivante\b/u', $flat) === 1) {
            return 'next';
        }
        if ($navigation && preg_match('/\betape precedente\b/u', $flat) === 1) {
            return 'previous';
        }
        if ($navigation && preg_match('/\b[a-z]+ieme\s+etape\b/u', $flat) === 1) {
            return 'number:0';
        }
        if ($navigation && preg_match('/\bderniere etape\b/u', $flat) === 1) {
            return 'last';
        }
        if (preg_match('/^(?:(?:donne|montre|rappelle)(?: moi)?\s+(?:uniquement |seulement )?|quelle est |c est quoi )?(?:la )?premiere etape\b/u', $flat) === 1) {
            return 'first';
        }

        if (preg_match('/^(?:reviens|retourne|repasse|retour)\b.{0,20}\betape precedente\b/u', $flat) === 1) {
            return 'previous';
        }

        return preg_match('/^(?:je l ai fait\w*|c est fait|j ai termine|et ensuite|ensuite|et apres)\b/u', $flat) === 1 ? 'next' : null;
    }

    public function requestsStepExpansion(string $question): bool
    {
        $flat = $this->normalize($question);
        if (preg_match('/\b(?:sans(?: entrer)?(?: dans les| en)?|pas de|pas plus de)\s+details?\b/u', $flat) === 1) {
            return false;
        }

        return preg_match('/\b(?:en details?|davantage|detaill\w*|developp\w*)\b/u', $flat) === 1;
    }

    public function withoutNegatedErrors(string $question): string
    {
        return trim(preg_replace('/(?:[,;]\s*)?\b(?:pas|sans|ni)\s+(?:(?:de|du|des|une?|la|le)\s+|[dl][’\']\s*)*(?:l[’\']\s*)?(?:(?:erreur|code)\s+)?(?:HTTP\s*)?[45]\d{2}\b[.]?/iu', '', $question) ?? $question);
    }

    public function requestedHttpError(string $question): ?string
    {
        $active = $this->withoutNegatedErrors($question);

        return preg_match('/\b(?:http|erreur(?:\s+http)?)\s*([45]\d{2})\b/iu', $active, $match) === 1 ? $match[1] : null;
    }

    /** @return array{kind: string, count: int}|null */
    public function formatRequest(string $question): ?array
    {
        $flat = $this->normalize($question);
        if ($this->jsonStatusRequest($question)) {
            return ['kind' => 'json_status', 'count' => 1];
        }
        if ($this->fieldNamesRequest($question)) {
            return ['kind' => 'fields', 'count' => 100];
        }
        if ($this->protocolExampleRequest($question)) {
            return ['kind' => 'json_example', 'count' => 1];
        }
        $withoutTable = preg_match('/\b(?:retire|supprime|enleve|sans|pas de)\s+(?:le |ce )?tableau\b/u', $flat) === 1;
        if (! $withoutTable && preg_match('/\btableau\b/u', $flat) === 1) {
            $numbers = ['deux' => 2, 'trois' => 3, 'quatre' => 4];

            return ['kind' => 'table', 'count' => preg_match('/\b(\d+|deux|trois|quatre)\s+lignes?\b/u', $flat, $rows) === 1 ? ($numbers[$rows[1]] ?? max(1, (int) $rows[1])) : PHP_INT_MAX];
        }
        if (! $this->isPresentationOnly($question) && preg_match('/\b(?:resum\w*|recapitul\w*|reformul\w*|simplifi\w*|raccourci\w*|rappel\w*)\b/u', $flat) !== 1
            && preg_match('/\bexplique\w*\s+(?:la meme chose|cela|ca|cette reponse)\b/u', $flat) !== 1) {
            return null;
        }
        $numbers = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10];
        if (preg_match('/\b(10|[1-9]|un|une|deux|trois|quatre|cinq|six|sept|huit|neuf|dix)\s+(?:seule?\s+)?(phrases?|points?|puces?|etapes?)\b/u', $flat, $match) === 1) {
            return ['kind' => str_starts_with($match[2], 'phrase') ? 'sentences' : 'points', 'count' => $numbers[$match[1]] ?? (int) $match[1]];
        }
        if (preg_match('/\brappel\w*\b/u', $flat) === 1) {
            return ['kind' => 'repeat', 'count' => 10];
        }

        return ['kind' => 'paragraph', 'count' => 3];
    }

    public function summaryPoints(string $question): ?int
    {
        return $this->formatRequest($question)['count'] ?? null;
    }

    private function singleSentence(string $text): string
    {
        $text = preg_replace('/[.!?]+(?=\s|$)/u', ';', trim($text)) ?? $text;

        return rtrim(preg_replace('/(?:\s*;){2,}\s*/u', ' ; ', $text) ?? $text, ' ;').'.';
    }

    public function formatAnswer(string $answer, string $question): string
    {
        $format = $this->formatRequest($question);
        if ($format !== null && $format['kind'] === 'json_status') {
            return (new JsonExamples)->status($answer, $question);
        }
        if ($format !== null && $format['kind'] === 'json_example') {
            return (new JsonExamples)->example($answer, $question);
        }
        if ($format !== null && $format['kind'] === 'fields') {
            return (new JsonExamples)->fields((new JsonExamples)->extract($answer), $question);
        }
        if ($this->declinesExamples($question)) {
            $answer = trim(preg_replace('/```.*?```|~~~.*?~~~/su', '', $answer) ?? $answer);
            if (preg_match('/\bsans valeurs? d exemple\b/u', $this->normalize($question)) === 1) {
                $answer = preg_replace('/\([^)]*(?:\bex(?:emple)?\s*[:.]|[a-z]+_x{2,})[^)]*\)/iu', '', $answer) ?? $answer;
            }
        }
        if ($format === null) {
            return $answer;
        }
        if ($this->parameterKind($question) === 'both' && preg_match('/### Obligatoires\R(.*?)\R+### Optionnels\R(.*)$/su', $answer, $groups) === 1) {
            foreach ([1, 2] as $index) {
                $fields = array_values(array_filter($this->summaryItems($groups[$index]), static fn ($item) => preg_match('/^(?:\*\*)?`[a-zA-Z_][a-zA-Z0-9_-]*`/u', $item) === 1));
                $groups[$index] = $fields !== [] ? implode(' ; ', $fields) : implode(' ', $this->summaryItems($groups[$index]));
            }
            if ($format['kind'] === 'table') {
                return "| Obligatoires | Optionnels |\n| --- | --- |\n| ".str_replace(["\r", "\n", '|'], ['', '<br>', '\\|'], trim($groups[1])).' | '.str_replace(["\r", "\n", '|'], ['', '<br>', '\\|'], trim($groups[2])).' |';
            }
            if ($format['kind'] !== 'points') {
                return $this->singleSentence('**Obligatoires** : '.trim($groups[1]).' ; **Optionnels** : '.trim($groups[2]));
            }

            return '1. **Obligatoires** : '.preg_replace('/\s+/u', ' ', trim($groups[1]))."\n\n2. **Optionnels** : ".preg_replace('/\s+/u', ' ', trim($groups[2]));
        }
        if ($format['kind'] === 'table') {
            $allItems = $this->summaryItems($answer);
            $items = array_slice($allItems, 0, $format['count']);
            if (count($allItems) > $format['count'] && preg_match('/\b(?:sans (?:rien omettre|perte|omettre)|exhaustif\w*|tous les champs|tous les parametres)\b/u', $this->normalize($question)) === 1) {
                $items = array_slice($allItems, 0, $format['count'] - 1);
                $items[] = implode(' ; ', array_slice($allItems, $format['count'] - 1));
            }
            if (preg_match('/code callback.*x secret/u', $this->normalize($question)) === 1) {
                $routing = null;
                $authentication = null;
                foreach ($allItems as $item) {
                    $flat = $this->normalize($item);
                    if ($routing === null && str_contains($flat, 'identifi') && preg_match('/callback|code url/u', $flat) === 1) {
                        $routing = str_starts_with($item, '**Code callback**') ? $item : '**Code callback** : '.$item;
                    }
                    if ($authentication === null && str_contains($flat, 'x secret') && preg_match('/authentifi/u', $flat) === 1 && preg_match('/\b(?:non|ne|pas)\b/u', $flat) !== 1) {
                        $authentication = str_starts_with($item, '**X-Secret**') ? $item : '**X-Secret** : '.$item;
                    }
                }
                if ($routing !== null && $authentication !== null) {
                    $items = [$routing, $authentication];
                }
            }
            if ($items === []) {
                return 'Je ne dispose pas encore de contenu vérifié à présenter dans un tableau.';
            }
            $rows = ['| Élément | Information |', '| --- | --- |'];
            foreach ($items as $index => $item) {
                preg_match('/\*\*([^*]+)\*\*|`([^`]+)`/u', $item, $label);
                $name = ($label[1] ?? '') !== '' ? $label[1] : (($label[2] ?? '') !== '' ? $label[2] : 'Point '.($index + 1));
                $item = preg_replace('/^\*\*'.preg_quote($name, '/').'\*\*\s*:\s*/u', '', $item) ?? $item;
                $rows[] = '| '.str_replace('|', '\\|', $name).' | '.str_replace('|', '\\|', $item).' |';
            }

            return implode("\n", $rows);
        }
        if ($format['kind'] === 'repeat') {
            return trim(preg_replace('/```.*?```|~~~.*?~~~/su', '', $answer) ?? $answer);
        }
        if ($format['kind'] === 'points') {
            if (preg_match('/\b(?:proc[eé]dure|[eé]tapes)\b/iu', $question) === 1) {
                $steps = (new ConversationPlan)->extract($answer);
                if (count($steps) > $format['count']) {
                    $groups = array_slice($steps, 0, $format['count'] - 1);
                    $groups[] = implode(' ', array_slice($steps, $format['count'] - 1));

                    return implode("\n\n", array_map(static fn ($step, $i) => ($i + 1).'. '.trim(preg_replace('/\s+/u', ' ', $step)), $groups, array_keys($groups)));
                }
            }
            if ($this->parameterSummaryRequest($question)) {
                $fields = array_values(array_filter($this->summaryItems($answer), static fn ($item) => preg_match('/^(?:\*\*)?`[a-zA-Z_][a-zA-Z0-9_-]*`/u', $item) === 1));
                if (count($fields) > $format['count']) {
                    $groups = array_slice($fields, 0, $format['count'] - 1);
                    $groups[] = implode(' ', array_slice($fields, $format['count'] - 1));

                    return implode("\n\n", array_map(static fn ($item, $index) => ($index + 1).'. '.$item, $groups, array_keys($groups)));
                }
            }

            return $this->summarize($answer, $format['count']);
        }
        $items = $this->summaryItems($answer);
        if ($this->parameterSummaryRequest($question)) {
            $fields = array_values(array_filter($items, static fn ($item) => preg_match('/^(?:\*\*)?`[a-zA-Z_][a-zA-Z0-9_-]*`/u', $item) === 1));
            $items = $fields !== [] ? $fields : $items;
            if ($format['kind'] === 'sentences' && $items !== []) {
                $groups = array_slice($items, 0, $format['count'] - 1);
                if (count($items) >= $format['count']) {
                    $groups[] = implode(' ; ', array_slice($items, $format['count'] - 1));
                }

                return implode(' ', array_map(fn ($item) => $this->singleSentence($item), $groups));
            }
        } else {
            $items = array_slice($items, 0, $format['count']);
        }
        $summary = implode(' ', array_map(static fn ($item): string => preg_match('/[.!?]\s*$/u', $item) === 1 ? $item : rtrim($item, ':; ').'.', $items));
        if (preg_match('/\b(?:debutant|sans jargon|mots simples)\b/u', $this->normalize($question)) === 1) {
            $summary = preg_replace(['/\bpayload\b/iu', '/\bwebhook\b/iu', '/\bendpoint\b/iu'], ['message reçu', 'notification envoyée par PVIT', 'adresse de l’API'], $summary) ?? $summary;
        }

        return $summary !== '' ? $summary : 'Je ne dispose pas encore d’une réponse à reformuler.';
    }

    /** Extract from the previous verified answer; do not search a new topic or invent filler. */
    public function summarize(string $answer, int $count): string
    {
        $items = array_slice($this->summaryItems($answer), 0, $count);
        if ($items === []) {
            return 'Je ne dispose pas encore d’une réponse à résumer. Précisez votre question sur PVIT.';
        }
        $result = implode("\n\n", array_map(static fn ($item, $index) => ($index + 1).'. '.$item, $items, array_keys($items)));
        if (count($items) < $count) {
            $result .= "\n\nLa réponse précédente contient seulement ".count($items).' point(s) à reprendre sans ajouter d’information.';
        }

        return $result;
    }

    /** @return list<string> */
    private function summaryItems(string $answer): array
    {
        $answer = preg_replace('/```.*?```|~~~.*?~~~/su', '', $answer) ?? '';
        $answer = preg_replace('/^\*(?:Certains points|Les valeurs d.exemple).*$/mu', '', $answer) ?? '';
        $items = [];
        $additional = [];
        $lines = preg_split('/\R/u', $answer) ?: [];
        foreach ($lines as $lineIndex => $line) {
            $line = trim($line);
            if (str_starts_with($line, '|')) {
                $cells = array_map('trim', explode('|', trim($line, '| ')));
                // Headers are followed by a separator; neither contains a fact.
                if (preg_match('/^[\s:|\-]+$/u', $line) === 1 || preg_match('/^\s*\|[\s:|\-]+\|\s*$/u', $lines[$lineIndex + 1] ?? '') === 1) {
                    continue;
                }
                $line = '**'.($cells[0] ?? '').'** : '.implode(' — ', array_slice($cells, 1));
            }
            if ($line === '' || preg_match('/^(?:#|---|\[\d+\])/', $line) === 1) {
                continue;
            }
            // Older conversations stored payloads after removing their code fences.
            if (preg_match('/^(?:["\'][A-Za-z_][A-Za-z0-9_]*["\']\s*:|curl\b|--[a-z-]+\b|-H\s|[{}\[\],]+\s*$)/u', $line) === 1) {
                continue;
            }
            $line = preg_replace('/^(?:\d+[.)]|[-*])\s+/u', '', $line) ?? $line;
            $line = preg_replace('/:?\s*\{\s*((?:\[\d+\]\s*)*)$/u', ' $1', $line) ?? $line;
            $line = preg_replace('/:\s*((?:\[\d+\]\s*)*)$/u', ': $1', $line) ?? $line;
            if (preg_match('/:\s*(?:\[\d+\]\s*)*$/u', $line) === 1
                && preg_match('/\b(?:est|sont|expire|reste|dure|contient|indique|renvoie|doit)\b|\d+\s*(?:secondes?|heures?|jours?)/iu', $line) !== 1) {
                continue;
            }
            $line = preg_replace('/:\s*((?:\[\d+\]\s*)*)$/u', ' $1', $line) ?? $line;
            // A parameter definition is one item: abbreviations such as "ex. PAYMENT"
            // must not split its value from its field name.
            if (preg_match('/^(?:\*\*)?`[a-zA-Z_][a-zA-Z0-9_-]*`/u', $line) === 1) {
                if (! in_array($line, $items, true)) {
                    $items[] = $line;
                }

                continue;
            }
            $first = true;
            foreach (preg_split('/(?<=[.!?])\s+(?=[A-ZÀ-Ÿ])/u', $line) ?: [] as $sentence) {
                if (mb_strlen(strip_tags($sentence)) >= 25 && ! in_array($sentence, $items, true) && ! in_array($sentence, $additional, true)) {
                    if ($first) {
                        $items[] = $sentence;
                        $first = false;
                    } else {
                        $additional[] = $sentence;
                    }
                }
            }
        }

        return array_values(array_merge($items, $additional));
    }
}
