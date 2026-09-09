<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CitationGuard
{
    private ResponseGuard $responseGuard;

    public function __construct(?ResponseGuard $responseGuard = null)
    {
        $this->responseGuard = $responseGuard ?? new ResponseGuard;
    }

    private const EXACT_CITATION_PATTERN = '/\[\s*SOURCE\s*:\s*(\d+)\s*\]/iu';

    private const ANY_CITATION_PATTERN = '/\[\s*(?:SOURCE|DOCUMENT|CITATION)\b[^\]]*\]/iu';

    // A genuine paraphrase (reordered or lightly reworded) keeps essentially the same words on
    // both sides. A claim that drops a trailing correction/warning ("...fausse.") or a leading
    // heading ("A eviter") instead looks like a *subset* of a longer source block: one-directional
    // overlap alone can't tell these apart (the claim's own words are still 100% covered), so both
    // directions must clear this bar -- high enough to reject a dropped qualifier, low enough to
    // tolerate a couple of connective words shifting.
    private const PARAPHRASE_WORD_COVERAGE_THRESHOLD = 0.85;

    public function validate(string $answer, Collection $retrievedChunks, bool $allowAssistiveSynthesis = false): CitationValidationResult
    {
        $answer = trim($this->normalizeCitationFormat($answer));

        if ($answer === '') {
            return CitationValidationResult::invalid('empty_answer');
        }

        if (preg_match('/<(?:[!?%\/]|[A-Za-z][^>\r\n]*>)|(?:%|\?)>/u', $answer) === 1) {
            return CitationValidationResult::invalid('model_supplied_markup');
        }

        if (preg_match('/\b(?:mailto|javascript|data):|\[[^\]\r\n]+\]\([^\)\r\n]+\)/iu', $answer) === 1) {
            return CitationValidationResult::invalid('model_supplied_link');
        }

        if ($this->containsFabricatedDomainReference($answer, $retrievedChunks)) {
            return CitationValidationResult::invalid('model_supplied_link');
        }

        if (preg_match('/\b(?:sources?\s+consult(?:e|é)es?|liens?\s+officiels?)\b/iu', $answer) === 1) {
            return CitationValidationResult::invalid('model_supplied_source_block');
        }

        preg_match_all(self::ANY_CITATION_PATTERN, $answer, $citationTokens);
        preg_match_all(self::EXACT_CITATION_PATTERN, $answer, $exactTokens);

        if (count($citationTokens[0]) !== count($exactTokens[0])) {
            return CitationValidationResult::invalid('malformed_citation');
        }

        $chunksById = $retrievedChunks->keyBy(static fn ($chunk): int => (int) $chunk->chunk_id);
        $knownIds = $retrievedChunks
            ->pluck('chunk_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $knownIdLookup = array_fill_keys($knownIds, true);
        $citedIds = [];
        $hasExplanation = false;

        $paragraphs = $this->paragraphs($answer);

        // Validate the citation structure for the whole answer first so a missing or
        // unknown citation cannot be hidden by an earlier unsupported claim.
        foreach ($paragraphs as $index => $paragraph) {
            preg_match_all(self::EXACT_CITATION_PATTERN, $paragraph, $paragraphCitations);
            $visibleText = trim((string) preg_replace(self::EXACT_CITATION_PATTERN, '', $paragraph));

            if ($visibleText === '' || $this->isHeadingOnly($visibleText)) {
                continue;
            }

            // Only exempt a factless lead-in when it has no citation to check in the first
            // place -- one that does carry a citation (e.g. a genuine documented instruction that
            // happens to end in ":") is a real, verifiable claim and must still be checked below,
            // not waved through unexamined.
            if ($paragraphCitations[1] === []) {
                if ($this->isFactlessLeadIn($visibleText, $index === 0)) {
                    continue;
                }

                return CitationValidationResult::invalid('missing_citation');
            }

            $hasExplanation = true;

            foreach ($paragraphCitations[1] as $chunkId) {
                $chunkId = (int) $chunkId;

                if (! isset($knownIdLookup[$chunkId])) {
                    return CitationValidationResult::invalid('unknown_citation');
                }

                $citedIds[] = $chunkId;
            }
        }

        foreach ($paragraphs as $index => $paragraph) {
            preg_match_all(self::EXACT_CITATION_PATTERN, $paragraph, $paragraphCitations);
            $visibleText = trim((string) preg_replace(self::EXACT_CITATION_PATTERN, '', $paragraph));

            if ($visibleText === '' || $this->isHeadingOnly($visibleText)) {
                continue;
            }

            // Same restriction as the first pass above: a lead-in shape only excuses a paragraph
            // that has no citation to verify. One that does carry a citation already survived the
            // first pass as a real cited claim and must still go through extractive verification
            // below -- skipping it here would let its content fidelity go unchecked.
            if ($paragraphCitations[1] === [] && $this->isFactlessLeadIn($visibleText, $index === 0)) {
                continue;
            }

            $normalizedClaim = $this->normalizeEvidence($visibleText);
            $claimWords = preg_split('/\s+/u', $normalizedClaim, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $claimSentences = $this->completeSentences($visibleText);
            $isListClaim = preg_match('/^\s*(?:[-*+]\s+|\d+[.)]\s+)/u', $visibleText) === 1;
            $isSubstantive = mb_strlen($normalizedClaim) >= ($isListClaim ? 3 : 12)
                && count($claimWords) >= ($isListClaim ? 1 : 3)
                && $this->isProseEvidence($visibleText)
                && $claimSentences !== [];
            if ($allowAssistiveSynthesis) {
                // A synthesized answer is explicitly allowed to reword and even translate the
                // source (the corpus mixes English error names with French prose), so holding it
                // to a verbatim sentence match would reject the paraphrase the prompt asked for.
                // Instead, require every checkable fact it states -- numbers, `backtick`
                // identifiers, SNAKE_CASE/ALL-CAPS constants -- to trace back to the sources cited
                // for it. A claim citing several sources together (a shared wrap-up sentence) is
                // checked against their combined text, not each one individually: the model
                // bundled them as jointly supporting one statement, not as redundant copies.
                $combinedSourceSentences = collect($paragraphCitations[1])
                    ->flatMap(fn ($chunkId): array => $this->completeSentences(
                        (string) data_get($chunksById->get((int) $chunkId), 'contenu', ''),
                    ))
                    ->all();

                $isExtractive = $isSubstantive && $this->isSemanticallySupported($visibleText, $combinedSourceSentences);
            } else {
                $isExtractive = $isSubstantive && collect($paragraphCitations[1])->every(function ($chunkId) use ($chunksById, $claimSentences): bool {
                    $chunk = $chunksById->get((int) $chunkId);
                    $sourceSentences = $this->completeSentences((string) data_get($chunk, 'contenu', ''));

                    return collect($claimSentences)->every(
                        fn (string $claim): bool => $this->matchesSourceContent($claim, $sourceSentences),
                    );
                });
            }

            if (! $isExtractive) {
                return CitationValidationResult::invalid('unsupported_claim');
            }
        }

        if (! $hasExplanation) {
            return CitationValidationResult::invalid('empty_answer');
        }

        $citedIds = array_values(array_unique($citedIds));

        if ($citedIds === []) {
            return CitationValidationResult::invalid('missing_citation');
        }

        return CitationValidationResult::valid($citedIds);
    }

    public function render(string $answer, Collection $retrievedChunks): string
    {
        $answer = $this->normalizeCitationFormat($answer);
        $labels = $retrievedChunks
            ->keyBy(static fn ($chunk): int => (int) $chunk->chunk_id)
            ->map(fn ($chunk): string => $this->citationLabel(
                (string) $chunk->document_titre,
                (string) ($chunk->section ?: 'Section non précisée'),
            ));

        return trim((string) preg_replace_callback(
            self::EXACT_CITATION_PATTERN,
            static function (array $matches) use ($labels): string {
                $chunkId = (int) $matches[1];

                return '['.($labels->get($chunkId) ?? 'Source documentaire validée').']';
            },
            $answer,
        ));
    }

    // Any bracket that opens with SOURCE, DOCUMENT or CITATION is an unambiguous citation
    // attempt, even when its exact punctuation drifts from the imposed format: several ids
    // bundled together ("[ SOURCE:518 ; SOURCE:517 ]", "[SOURCE:518, 517]"), a missing colon
    // ("[SOURCE 518]"), or a different keyword ("[DOCUMENT:518]"). Rewrite every id found inside
    // such a bracket into its own canonical [SOURCE:n], so the rest of this class -- which only
    // ever parses one id per bracket -- sees the citations it already knows how to handle,
    // instead of failing the whole answer over a cosmetic formatting slip. A bracket with no
    // digit at all (e.g. "[SOURCE: abc]") is left untouched and still correctly rejected below as
    // malformed: there is no id to recover.
    private function normalizeCitationFormat(string $text): string
    {
        return (string) preg_replace_callback(
            self::ANY_CITATION_PATTERN,
            static function (array $matches): string {
                preg_match_all('/\d+/', $matches[0], $ids);

                if ($ids[0] === []) {
                    return $matches[0];
                }

                return implode('', array_map(
                    static fn (string $id): string => "[SOURCE:{$id}]",
                    array_unique($ids[0]),
                ));
            },
            $text,
        );
    }

    // Links and domain mentions (with or without a scheme) are common inside the documentation
    // itself, e.g. "mypvit.pro/register" or "https://api.mypvit.pro" in an IP-whitelisting
    // instruction. Only reject one that the model introduced on its own, i.e. that does not
    // appear verbatim in the retrieved source text.
    private function containsFabricatedDomainReference(string $answer, Collection $retrievedChunks): bool
    {
        preg_match_all('/\b(?:https?|ftp):\/\/\S+|\bwww\.\S+|\b(?:[a-z0-9-]+\.)+(?:com|net|org|io|pro|dev|fr|ga|cm|africa)(?:\/\S*)?/iu', $answer, $matches);

        if ($matches[0] === []) {
            return false;
        }

        $normalizedSource = Str::ascii(mb_strtolower($retrievedChunks
            ->map(static fn ($chunk): string => (string) data_get($chunk, 'contenu', ''))
            ->implode("\n")));

        foreach ($matches[0] as $domainReference) {
            // The path segment above is matched with `\S*`, which also swallows a closing
            // Markdown delimiter the model wrapped the URL in (`` `mypvit.pro/register` ``,
            // *mypvit.pro/register*, _mypvit.pro/register_) since none of those characters are
            // whitespace. Left uncleaned, a perfectly legitimate, verbatim-from-the-source URL
            // never matches because the stray delimiter is still glued to it.
            $normalizedReference = Str::ascii(mb_strtolower(rtrim($domainReference, '.,;:!?)`*_')));

            if (! str_contains($normalizedSource, $normalizedReference)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function paragraphs(string $answer): array
    {
        $blocks = preg_split('/(?:\R[ \t]*){2,}/u', trim($answer)) ?: [];

        // A citation the model set off on its own line, separated by a blank line from the list
        // or paragraph it plainly supports, must not become its own disconnected block -- that
        // would both starve the preceding content of its citation and get silently skipped here
        // as an empty claim. Fold it back into the previous block instead.
        $mergedBlocks = [];

        foreach ($blocks as $block) {
            $visibleText = trim((string) preg_replace(self::EXACT_CITATION_PATTERN, '', $block));

            if ($visibleText === '' && $mergedBlocks !== []) {
                $last = array_key_last($mergedBlocks);
                $mergedBlocks[$last] = rtrim($mergedBlocks[$last])."\n".trim($block);

                continue;
            }

            $mergedBlocks[] = $block;
        }

        $paragraphs = [];

        foreach ($mergedBlocks as $block) {
            $items = preg_split('/\R(?=\s*(?:[-*+]|\d+[.)])\s+)/u', trim($block)) ?: [];

            // A model that cites a whole intro-plus-bullets run once, on the last line, still
            // attributes every line in that run to the same extract. Share the citations found
            // anywhere in the run across all of its lines so an uncited bullet is not mistaken
            // for an unsupported claim; each line still has to pass extractive verification.
            preg_match_all(self::EXACT_CITATION_PATTERN, implode(' ', $items), $sharedCitations);
            $sharedCitations = implode('', array_unique($sharedCitations[0]));

            foreach ($items as $item) {
                if ($sharedCitations !== '' && preg_match(self::EXACT_CITATION_PATTERN, $item) !== 1) {
                    $item = rtrim($item).' '.$sharedCitations;
                }

                $paragraphs[] = $item;
            }
        }

        return array_values(array_filter(array_map('trim', $paragraphs), static fn (string $paragraph): bool => $paragraph !== ''));
    }

    // The source keeps a heading and each short paragraph as separate blank-line-delimited
    // blocks, but the model commonly reflows two or more adjacent ones into a single flowing
    // paragraph when it copies them -- dropping a blank line is not a content change. Matching
    // only a single whole block would reject that as unsupported even though every word is still
    // a verbatim, unmodified, in-order copy. This still requires whole blocks end to end (no
    // partial-sentence slice), preserving the anti-cherry-picking guarantee completeSentences()
    // is built on -- it only widens what counts as "one block" to "a run of consecutive ones".
    //
    // A block (or run of blocks) that isn't matched verbatim gets one more chance as a close
    // paraphrase -- reworded or reordered, but not a different claim -- via isCloseParaphrase().
    // That check is gated on matching negation and shared risk tokens, so it can loosen wording
    // without opening the door back up to an invented or meaning-inverted claim.
    private function matchesSourceContent(string $claim, array $sourceSentences): bool
    {
        if (in_array($claim, $sourceSentences, true)) {
            return true;
        }

        // A data-table row is always its own standalone block in this corpus (blank-line
        // delimited, one row per block) -- checked here, against single blocks only, before the
        // window ever grows. Growing the window by concatenating blocks (below) is safe for
        // prose, but two unrelated single-"|" lines (e.g. adjacent code-sample lines) glued
        // together would otherwise accumulate two "|" between them and be misread as one row.
        foreach ($sourceSentences as $sentence) {
            if ($this->isSupportedTableRow($claim, $sentence)) {
                return true;
            }
        }

        $total = count($sourceSentences);

        for ($start = 0; $start < $total; $start++) {
            $joined = '';

            for ($end = $start; $end < $total; $end++) {
                $joined = trim($joined.' '.$sourceSentences[$end]);

                if ($joined === $claim || $this->isCloseParaphrase($claim, $joined)) {
                    return true;
                }

                if (mb_strlen($joined) >= mb_strlen($claim) * 2 + 40) {
                    break;
                }
            }
        }

        return false;
    }

    // Same content, different words: a genuine paraphrase still (a) asserts or denies the same
    // thing -- so a negation cue ("pas", "jamais", ...) present on only one side means the claim
    // flipped the source's meaning, not reworded it -- (b) never introduces a number or
    // `backtick` identifier the source doesn't have, and (c) covers essentially the same
    // vocabulary as the source block in both directions -- not just the claim's words being a
    // subset of a longer source (that's a dropped qualifier, not a paraphrase).
    private function isCloseParaphrase(string $claim, string $source): bool
    {
        // A single data-table row is matched structurally by matchesSourceContent() before this
        // ever runs. Reaching here with a "|" in $source means it's a *concatenation* of several
        // blocks (the growing-window loop above) -- e.g. two unrelated single-"|" code-sample
        // lines glued together, which would otherwise be misread as one row. Prose word-coverage
        // matching doesn't apply to table syntax either, so treat this as unsupported rather than
        // risk either misreading.
        if (str_contains($source, '|')) {
            return false;
        }

        if ($this->hasNegationCue($claim) !== $this->hasNegationCue($source)) {
            return false;
        }

        foreach ($this->salientTokens($claim) as $token) {
            if (! str_contains($source, $token)) {
                return false;
            }
        }

        $claimWords = $this->wordSet($claim);
        $sourceWords = $this->wordSet($source);

        if ($claimWords === [] || $sourceWords === []) {
            return false;
        }

        $shared = count(array_intersect($claimWords, $sourceWords));

        return ($shared / count($claimWords)) >= self::PARAPHRASE_WORD_COVERAGE_THRESHOLD
            && ($shared / count($sourceWords)) >= self::PARAPHRASE_WORD_COVERAGE_THRESHOLD;
    }

    // A row like "500 | SOMETHING_WENT_WRONG | Something went wrong, please contact support |
    // 500" packs its identity into short identifier-shaped fields -- a code, a snake_case
    // constant -- and its explanation into a free-text field the model is free to translate or
    // reword (this corpus explains English error identifiers in French prose). Requiring every
    // field's own words to carry over, the way a prose paraphrase must, would reject every
    // correct citation of a translated row. Instead, every identifier-shaped field (no spaces, or
    // an underscored constant) must be reproduced verbatim -- that's the part a hallucination
    // would get wrong, e.g. pairing a real code with the wrong constant -- while free-text fields
    // are never checked. A row with no identifier-shaped field at all (a plain list that merely
    // contains a stray "|") never matches here; exact-match already covers that case. Requiring at
    // least 2 separators (3+ columns) excludes a "line-number | content" code-sample line, which
    // has only one and isn't a data row at all.
    private function isSupportedTableRow(string $claim, string $row): bool
    {
        if (substr_count($row, '|') < 2) {
            return false;
        }

        $fields = array_values(array_filter(
            array_map('trim', explode('|', $row)),
            static fn (string $field): bool => $field !== '',
        ));

        $identifierFields = array_filter($fields, fn (string $field): bool => $this->isIdentifierField($field));

        if ($identifierFields === []) {
            return false;
        }

        foreach ($identifierFields as $field) {
            if (! str_contains($claim, $field)) {
                return false;
            }
        }

        return true;
    }

    private function isIdentifierField(string $field): bool
    {
        return ! str_contains($field, ' ') || str_contains($field, '_');
    }

    // Reordering a sentence moves its terminal punctuation onto a different word (the source's
    // final "expiration." becomes a mid-sentence "expiration" once something else ends the
    // claim) -- without stripping that punctuation first, a purely reordered, meaning-identical
    // sentence would spuriously fail to match itself.
    private function wordSet(string $text): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn (string $word): string => trim($word, ".,;:!?…\"'()"),
            $words,
        ))));
    }

    private function hasNegationCue(string $normalizedText): bool
    {
        return preg_match('/\b(?:ne|pas|jamais|aucune?|sans|non|interdit|ni)\b/u', $normalizedText) === 1;
    }

    // A bare section label -- a whole paragraph that is just "**Title**" (optionally with a
    // trailing colon) or a Markdown ATX heading -- is structure, not a factual claim, and must
    // not be held to the same citation requirement as a sentence making an assertion. French
    // typographic convention puts a space (often a non-breaking one) before a colon -- the model
    // answers in French and reliably writes "**Titre** :", not "**Titre**:" -- so the space must
    // be tolerated or every such heading is misread as an uncited claim.
    private function isHeadingOnly(string $text): bool
    {
        $text = trim($text);

        return preg_match('/^\*\*[^\n*]+\*\*[ \t\x{00A0}]*:?$/u', $text) === 1
            || preg_match('/^#{1,6}\s+\S.*$/u', $text) === 1
            // A bare Markdown horizontal rule ("---", "***", "___") used to separate sections is
            // pure layout, not a claim -- Mistral in particular reaches for it often between the
            // steps of a synthesized answer. Left unrecognized it became its own citation-less
            // "paragraph" (paragraphs() splits on blank lines) and rejected an otherwise
            // well-cited answer purely over the model's choice of section divider.
            || preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/u', $text) === 1;
    }

    // A lead-in sentence ("Pour interpreter un code d'erreur, procedez en trois etapes :") only
    // announces the structure that follows -- it asserts nothing checkable itself, the same way a
    // bare heading doesn't. The prompt explicitly asks the model for a framing intro and a closing
    // "next useful action" line, both of which regularly take this shape. Requiring a citation on
    // it anyway rejects an otherwise faithful, well-cited answer over a sentence with no fact to
    // attribute. Narrowly scoped so an actual claim that happens to end in ":" isn't swept in: it
    // must carry zero salientTokens() (no number, no `backtick` id, no SNAKE_CASE constant -- the
    // same signal isSemanticallySupported() already treats as "a checkable fact is being stated"),
    // and stay short enough that a real multi-clause explanation can't qualify by accident.
    //
    // The very first paragraph of an answer is often this kind of framing sentence by prompt
    // design ("Commence par reformuler ou clarifier le probleme pose..."), but the model doesn't
    // always end it with ":" -- e.g. "D'apres le contexte documentaire, voici comment proceder
    // pour renouveler le secret [...]." reads identically to a colon-led intro and asserts nothing
    // checkable, yet was rejected outright for ending in "." instead. Accepting a period/!/? close
    // is gated on two things, not position alone: $isOpeningParagraph (index 0), AND the text
    // containing "voici"/"voila" -- the self-referential "here is what follows" marker French
    // reliably uses only to announce content, never to state a fact itself. That second condition
    // is what keeps this narrow: an opening paragraph that IS the answer ("Il faut creer un compte
    // marchand puis tester en sandbox.") has zero salientTokens() too but contains no such marker,
    // so it still correctly requires a citation below. A later paragraph ending in "." is ordinary
    // prose making a claim regardless of marker, and must still carry a citation. Its length bar is
    // also relaxed a bit further than the general 180 (up to 260): the prompt explicitly asks this
    // opening sentence to both reformulate the question AND frame the context in one go, which
    // regularly runs a little longer than a short colon-led "here are the steps :" lead-in.
    private function isFactlessLeadIn(string $text, bool $isOpeningParagraph = false): bool
    {
        $text = trim($text);
        $isSelfReferentialFraming = preg_match('/\bvoil[aà]\b|\bvoici\b/iu', $text) === 1;
        $canEndInSentencePunctuation = $isOpeningParagraph && $isSelfReferentialFraming;
        $pattern = $canEndInSentencePunctuation ? '/[ \t\x{00A0}]*[:.!?]$/u' : '/[ \t\x{00A0}]*:$/u';
        $maxLength = $canEndInSentencePunctuation ? 260 : 180;

        return mb_strlen($text) <= $maxLength
            && preg_match($pattern, $text) === 1
            && $this->salientTokens($text) === [];
    }

    private function citationLabel(string $document, string $section): string
    {
        $document = $this->safeMetadataLabel($document) ?: 'Document officiel';
        $section = $this->safeMetadataLabel($section) ?: 'Section non précisée';

        return $document.' / '.$section;
    }

    private function safeMetadataLabel(string $value): string
    {
        $value = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $value);
        $value = (string) preg_replace('/(?:\b(?:https?|ftp):\/\/|\bwww\.|\b(?:javascript|data|mailto):)\S*/iu', ' ', $value);
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}\[\]{}<>`|]+/u', ' ', $value);

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $value)), 180, '');
    }

    private function normalizeEvidence(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/^\s*(?:[-*+]\s+|\d+[.)]\s+)/u', '', $text);
        // Str::ascii() already folds en/em dashes to "-", but this narrower family of
        // hyphen-like codepoints (non-breaking hyphen, figure dash, minus sign, soft hyphen) has
        // no ASCII transliteration table entry and gets silently dropped instead -- fusing the
        // words on either side ("One-Time" -> "OneTime"). The model reaches for these
        // interchangeably with a plain hyphen; a citation must not fail over which one it picked.
        $text = preg_replace('/[\x{2010}\x{2011}\x{2012}\x{2212}\x{00AD}]/u', '-', $text);
        $text = Str::ascii(mb_strtolower($text));

        return trim((string) preg_replace('/\s+/u', ' ', $text), " \t\n\r\0\x0B\"'");
    }

    /**
     * A block is a whole line or blank-line-delimited unit of text, never a truncated slice of
     * one — the source is chunked that way, and the model's own paragraph boundaries define the
     * claim's blocks the same way. So completeness doesn't hinge on trailing punctuation; it's
     * enforced downstream by requiring an exact match against a full block on the other side.
     *
     * @return list<string>
     */
    private function completeSentences(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($this->hasUnsafeExampleContext($text)) {
            return [];
        }

        $blocks = preg_split('/(?:\R[ \t]*){2,}/u', trim($text)) ?: [];
        $entries = [];
        $pendingQualifier = '';

        foreach ($blocks as $block) {
            $isListItem = preg_match('/^\s*(?:[-*+]\s+|\d+[.)]\s+)/u', $block) === 1;
            $block = trim((string) preg_replace('/^\s*(?:[-*+]\s+|\d+[.)]\s+|#{1,6}\s*)/u', '', $block));

            if ($block === '') {
                continue;
            }

            // An unpunctuated qualifier (a heading like "Attention") has nothing of its own to
            // stand as a claim, so it's prepended onto the block that follows. A punctuated one
            // (a full sentence like "Cette affirmation est fausse.") becomes its own entry and is
            // merged into its neighbor below by the qualifier flag instead.
            if (preg_match('/[.!?…]\s*$/u', $block) !== 1 && ! $isListItem
                && mb_strlen($block) <= 90 && $this->isContextQualifier($block)) {
                $pendingQualifier = trim($pendingQualifier.' '.$block);

                continue;
            }

            $entries[] = [
                'text' => trim($pendingQualifier.' '.$block),
                'qualifier' => $pendingQualifier !== '' || $this->isContextQualifier($block),
            ];
            $pendingQualifier = '';
        }

        // A correction or warning semantically qualifies the statement next to it. Group both
        // directions so a model cannot quote only a false statement while dropping an adjacent
        // warning placed immediately before or after it.
        $groups = [];
        $qualifierCarriesForward = false;

        foreach ($entries as $entry) {
            if ($groups === [] || (! $entry['qualifier'] && ! $qualifierCarriesForward)) {
                $groups[] = $entry['text'];
            } else {
                $last = array_key_last($groups);
                $groups[$last] = trim($groups[$last].' '.$entry['text']);
            }

            $qualifierCarriesForward = $entry['qualifier'];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $unit): string => $this->normalizeEvidence($unit),
            $groups,
        ))));
    }

    // A synthesized claim may reword or translate the source freely -- the corpus itself mixes
    // English error identifiers with French explanatory prose -- but every checkable fact it
    // states must still trace back to the cited source, verbatim. This is a looser but still
    // meaningful substitute for exact-sentence matching when paraphrase is explicitly allowed.
    // Free rewording must not extend to reversing the source's polarity: a negation cue present
    // in the claim means the source must also support this negation -- unless the claim is itself
    // about the documentation's own silence (see isDocumentationGapAcknowledgment()), which the
    // source can never "support" by definition.
    private function isSemanticallySupported(string $claimText, array $sourceSentences): bool
    {
        $sourceText = implode(' ', $sourceSentences);

        if ($this->hasNegationCue($this->normalizeEvidence($claimText))
            && ! $this->isDocumentationGapAcknowledgment($claimText)) {
            if (! $this->hasNegationCue($sourceText)) {
                return false;
            }
        }

        foreach ($this->salientTokens($claimText) as $token) {
            if (! str_contains($sourceText, $token)) {
                return false;
            }
        }

        return true;
    }

    // The prompt explicitly instructs the assistant to say so plainly when the retrieved extracts
    // don't cover a specific point ("si le contexte ne permet pas... dis-le clairement"), which
    // routinely produces a claim like "la documentation ne precise pas de consigne specifique". Its
    // "ne...pas" is a negation cue by the generic check above, but it isn't reversing a fact the
    // source states -- it's describing the source's silence, which the source obviously cannot
    // itself contain a matching negation for. Scoped to the documentation/context as the subject of
    // the negation so an ordinary negated claim about the API's own behavior ("le paiement n est
    // pas rembourse automatiquement") still gets checked against the source normally.
    private function isDocumentationGapAcknowledgment(string $text): bool
    {
        $normalized = Str::ascii(mb_strtolower($text));

        return preg_match(
            '/\b(?:la\s+documentation|le\s+contexte|les\s+extraits?|la\s+source|aucun\s+extrait)\b[^.!?\r\n]{0,80}\bne\b[^.!?\r\n]{0,60}\bpas\b/u',
            $normalized,
        ) === 1;
    }

    /**
     * Numbers, `backtick`-quoted identifiers and snake_case/SNAKE_CASE constants (error codes
     * like SOMETHING_WENT_WRONG, field names) are the highest-risk hallucination surface in a
     * paraphrase -- free rewording of connective prose is fine, but every one of these the model
     * states must be real, not invented. Matched case-insensitively: callers may pass text that
     * has already been through normalizeEvidence() and lost its original casing, and an
     * underscore-joined identifier is just as distinctive lowercased -- ordinary prose never
     * contains one either way.
     *
     * @return list<string>
     */
    private function salientTokens(string $text): array
    {
        preg_match_all(
            '/`[^`\r\n]+`|\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b|\b\d{2,}(?:[.,]\d+)*\b/iu',
            $text,
            $matches,
        );

        return array_values(array_unique(array_map(
            fn (string $token): string => $this->normalizeEvidence(trim($token, '`')),
            $matches[0] ?? [],
        )));
    }

    private function isProseEvidence(string $text): bool
    {
        $isListItem = preg_match('/^\s*(?:[-*+]\s+|\d+[.)]\s+)/u', $text) === 1;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/u', $text) ?: [])));
        $sentenceLine = (string) ($lines[array_key_last($lines)] ?? '');

        // A French list item often continues a sentence via elision or a conjunction (e.g.
        // "d'une clé...", "et d'un compte...") and can be purely numeric (an IP address, an
        // amount); only a full sentence is held to the capitalized-start requirement.
        if (! $isListItem) {
            if (preg_match('/\p{L}/u', $sentenceLine, $firstLetter) !== 1) {
                return false;
            }

            $letter = $firstLetter[0];

            if (mb_strtoupper($letter) !== $letter || mb_strtolower($letter) === $letter) {
                return false;
            }
        }

        $wordCount = count(preg_split('/\s+/u', $this->normalizeEvidence($sentenceLine), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return $wordCount >= ($isListItem ? 1 : 3) && ! $this->responseGuard->containsSourceCode($text);
    }

    private function isContextQualifier(string $text): bool
    {
        $text = Str::ascii(mb_strtolower($text));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        $explicitMarker = preg_match(
            '/^(?:a eviter|attention|avertissement|cependant|contrairement|correction\b|do not|en realite|erratum\b|exemple incorrect|faux\b|fausse\b|however|ignorez\b.{0,80}\b(?:phrase|precedent|ceci|cela)|important|in fact|information\s+(?:incorrecte?|fausse?|erronee?)\b|mais\b|mauvais exemple|ne\b.{0,80}\b(?:ceci|cela)\b|ne\b.+\bjamais|never\b|neanmoins|n[\' ]en\s+tenez\s+pas\s+compte|n[\' ]utilisez|note\b|rectification\b|toutefois|warning)/u',
            $text,
        ) === 1;
        $anaphoricCorrection = preg_match(
            '/^(?:ceci|cela|ce\s+(?:texte|qui\s+precede)|cette\s+(?:phrase|information|declaration|consigne)|la\s+(?:phrase|information|declaration|consigne)(?:\s+(?:precedente|ci-dessus))?|l[\' ]enonce\s+precedent|this\s+(?:statement|text|information))\b.{0,120}\b(?:faux|fausse|false|incorrecte?|wrong|erronee?|inexacte?|obsolete|ne\b.{0,50}\bpas)\b/u',
            $text,
        ) === 1;
        $namedAssertionCorrection = preg_match(
            '/\b(?:affirmation|assertion|statement)\b.{0,60}\b(?:false|faux|fausse|incorrecte?|wrong|erronee?|inexacte?)\b/u',
            $text,
        ) === 1;

        return $explicitMarker || $anaphoricCorrection || $namedAssertionCorrection;
    }

    private function hasUnsafeExampleContext(string $text): bool
    {
        $text = Str::ascii(mb_strtolower($text));

        return preg_match(
            '/\b(?:exemple|example|reponse|response|valeur|value)\s+(?:incorrecte?|invalide?|erronee?|fausse?|wrong|invalid)|\b(?:incorrecte?|erronee?|wrong|invalid)\s+(?:exemple|example|reponse|response|valeur|value)|\b(?:contre-exemple|anti-example|do\s+not\s+use|a\s+ne\s+pas\s+(?:faire|utiliser|suivre)|deprecated|obsolete)\b/u',
            $text,
        ) === 1;
    }
}
