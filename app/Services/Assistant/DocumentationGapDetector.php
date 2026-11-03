<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/**
 * Retrieval always returns the closest passages, so a question the documentation does not cover
 * still reaches the model with a "confident" context; the model then answers that the
 * documentation does not say. Nothing upstream flags that answer, so the developer was never
 * offered the support ticket the escalation path exists for. This detector reads the final answer
 * and says whether its main point is such a gap.
 *
 * Only the main point counts, read as the first substantive sentence: an answer that gives the
 * documented procedure and adds that one detail is not specified stays a normal answer, otherwise
 * every honest caveat would escalate.
 */
final class DocumentationGapDetector
{
    public function isGapAnswer(string $answer): bool
    {
        $sentences = $this->substantiveSentences($answer);

        // A comparison can reject an unsupported equality, then supply both documented values.
        // That resolves the user's question rather than requiring support for the equality.
        if ($sentences !== [] && $this->isGapStatement($sentences[0])
            && preg_match('/\b(?:identique|meme|egale?)\b/u', $this->flat($sentences[0])) === 1
            && preg_match('/\bexport\b.{0,100}\d+\s*jours?/u', $this->flat($answer)) === 1
            && preg_match('/\b(?:cle|secret|token)\b.{0,100}\d+\s*(?:secondes?|heures?)/u', $this->flat($answer)) === 1) {
            return false;
        }

        return $sentences !== [] && $this->isGapStatement($sentences[0]);
    }

    public function isGapStatement(string $sentence): bool
    {
        $flat = $this->flat($sentence);

        // "n'est pas documente": about the documentation by construction.
        if (preg_match('/ n (?:est|sont) pas (?:documente|mentionne|couvert|precise|decrit|indique|detaille)/', $flat) === 1) {
            return true;
        }

        // "ne propose pas ... dans son corpus documentaire": a negation scoped to the documentation.
        if (preg_match('/ ne? (?:\w+ ){1,2}pas\b.{0,160}? dans (?:la |sa |son |le |les |cette |ces )?(?:documentation|corpus|doc|docs|sources?|extraits?) /', $flat) === 1) {
            return true;
        }

        // "D'apres le contexte documentaire fourni, PVit ne propose pas ...": the same scoping put
        // first. Only existence / offer verbs count, so a documented prohibition ("selon la
        // documentation, il ne faut pas exposer la cle") stays a regular answer.
        if (preg_match(
            '/ (?:d apres|selon|au vu d(?:e|es)) (?:le |la |les |l |ces |cette )?(?:contexte|documentation|doc|docs|extraits?|sources?|corpus|elements?|informations?)\b.{0,120}?'
            .' (?:ne? (?:(?:le|la|les|l|en|y) )?(?:propose|prevoi|prevo|offre|existe|supporte|gere|prend|accepte|mentionne|documente|precise|indique|couvre|decrit|fournit|contient|dispose)\w* (?:pas|aucun)|n (?:existe|y a) (?:pas|aucun)|aucune? (?:api|service|option|moyen|information|mention))/',
            $flat,
        ) === 1) {
            return true;
        }

        // "la documentation ne precise pas", "aucune information": only when the subject is the
        // documentation, so "la notification ne contient pas le fichier" stays a claim about the API.
        return preg_match('/ (?:documentation|doc|docs|extraits?|sources?|pages?|corpus) /', $flat) === 1
            && preg_match(
                '/ ne? (?:(?:le|la|les|l|en) )?(?:precise|mentionne|documente|couvre|decrit|indique|detaille|traite|contient|fournit|donne|aborde|specifie)\w* (?:pas|aucun|rien)'
                .'| aucune? (?:information|mention|precision|indication|detail) /',
                $flat,
            ) === 1;
    }

    /**
     * The answer's sentences, without headings, separators, list markers, rendered source labels
     * or the notices the pipeline appends itself.
     *
     * @return list<string>
     */
    private function substantiveSentences(string $answer): array
    {
        $sentences = [];

        foreach (preg_split('/\R/u', $answer) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^(?:#|-{3,}|\*{3,}|_{3,}|\*(?:Certains points|Les valeurs d.exemple)|Sources? consult)/u', $line) === 1) {
                continue;
            }

            $line = (string) preg_replace('/^(?:[-*+]|\d+[.)])\s+/u', '', $line);
            $line = trim((string) preg_replace('/\[[^\]]*\]/u', ' ', $line));

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                if (preg_match('/\p{L}{3,}/u', $sentence) === 1) {
                    $sentences[] = $sentence;
                }
            }
        }

        return $sentences;
    }

    // Lowercase ASCII with punctuation folded to single spaces, padded so words match whole.
    private function flat(string $text): string
    {
        $text = Str::ascii(mb_strtolower(strip_tags($text)));

        return ' '.trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text)).' ';
    }
}
