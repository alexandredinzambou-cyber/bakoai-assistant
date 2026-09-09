<?php

namespace Tests\Unit;

use App\Services\Assistant\CitationGuard;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CitationGuardTest extends TestCase
{
    public function test_it_accepts_only_retrieved_source_ids_and_renders_server_labels(): void
    {
        $guard = new CitationGuard;
        $chunks = $this->chunks();
        $answer = "Le secret doit etre renouvele avant expiration. [SOURCE:17]\n\nLe nouveau secret remplace le precedent. [SOURCE:18]";

        $result = $guard->validate($answer, $chunks);

        $this->assertTrue($result->valid);
        $this->assertSame([17, 18], $result->chunkIds);
        $this->assertSame(
            "Le secret doit etre renouvele avant expiration. [PVIT renouvellement / Prerequis]\n\nLe nouveau secret remplace le precedent. [PVIT renouvellement / Effet]",
            $guard->render($answer, $chunks),
        );
    }

    public function test_it_rejects_a_paragraph_without_a_citation(): void
    {
        $result = (new CitationGuard)->validate(
            "Premier fait documente. [SOURCE:17]\n\nSecond fait sans preuve.",
            $this->chunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('missing_citation', $result->reason);
    }

    public function test_it_rejects_unknown_or_malformed_citations(): void
    {
        $guard = new CitationGuard;

        $unknown = $guard->validate('Fait invente. [SOURCE:999]', $this->chunks());
        $malformed = $guard->validate('Fait invente. [SOURCE:PVIT / Secret]', $this->chunks());

        $this->assertFalse($unknown->valid);
        $this->assertSame('unknown_citation', $unknown->reason);
        $this->assertFalse($malformed->valid);
        $this->assertSame('malformed_citation', $malformed->reason);
    }

    public function test_it_rejects_an_invented_claim_even_when_the_chunk_id_is_known(): void
    {
        $result = (new CitationGuard)->validate(
            'Le secret est public et ne doit jamais etre renouvele. [SOURCE:17]',
            $this->chunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_rejects_an_extraneous_citation_that_does_not_support_the_paragraph(): void
    {
        $result = (new CitationGuard)->validate(
            'Le secret doit etre renouvele avant expiration. [SOURCE:17] [SOURCE:18]',
            $this->chunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_rejects_a_tiny_fragment_that_only_happens_to_exist_in_a_chunk(): void
    {
        $result = (new CitationGuard)->validate(
            'Le secret. [SOURCE:17]',
            $this->chunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_rejects_a_mid_sentence_fragment_that_drops_a_negation(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => 'Inscription',
                'section' => 'Entreprise',
                'contenu' => 'Il n est pas obligatoire de fournir les informations de l entreprise.',
            ],
        ]);

        $result = (new CitationGuard)->validate(
            'Obligatoire de fournir les informations de l entreprise. [SOURCE:17]',
            $chunks,
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_accepts_a_reordered_paraphrase_of_the_source_sentence(): void
    {
        $result = (new CitationGuard)->validate(
            'Avant expiration le secret doit etre renouvele. [SOURCE:17]',
            $this->chunks(),
        );

        $this->assertTrue($result->valid);
    }

    public function test_it_still_rejects_a_paraphrase_that_flips_the_source_negation(): void
    {
        $result = (new CitationGuard)->validate(
            'Le secret ne doit jamais etre renouvele avant expiration. [SOURCE:17]',
            $this->chunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_still_rejects_a_reordered_paraphrase_that_drops_a_trailing_correction(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => 'Authentification',
                'section' => 'Exemples',
                'contenu' => "Pour tous les marchands le secret est public.\n\nCette affirmation est fausse.",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            'Le secret est public pour tous les marchands. [SOURCE:17]',
            $chunks,
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_assistive_synthesis_still_rejects_a_paraphrase_that_flips_the_source_negation(): void
    {
        $result = (new CitationGuard)->validate(
            'Le secret ne doit jamais etre renouvele avant expiration. [SOURCE:17]',
            $this->chunks(),
            true,
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_accepts_a_claim_that_the_documentation_does_not_cover_a_point(): void
    {
        // The prompt explicitly asks the model to say so plainly when the extracts don't cover a
        // specific point. That statement's own "ne...pas" is a negation cue, but it describes the
        // source's silence rather than reversing a fact the source states -- the source obviously
        // can't itself contain a matching negation about its own gap.
        $chunks = new Collection([
            (object) [
                'chunk_id' => 200,
                'document_titre' => "Codes d'erreur",
                'section' => 'Table',
                'contenu' => '500 | SOMETHING_WENT_WRONG | Something went wrong, please contact support | 500',
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "La documentation ne precise pas d'autre consigne de depannage specifique a ce code d'erreur ; ".
            "contactez le support technique pour signaler le probleme. [SOURCE:200]",
            $chunks,
            true,
        );

        $this->assertTrue($result->valid);
    }

    public function test_it_still_rejects_a_genuinely_reversed_claim_about_the_api_itself(): void
    {
        // Contrast with the gap-acknowledgment case above: this negation is about the API's own
        // behavior, not about documentation coverage, so it must still be checked against the
        // source and rejected when it reverses what the source actually says.
        $chunks = new Collection([
            (object) [
                'chunk_id' => 55,
                'document_titre' => 'Remboursement',
                'section' => 'Regle',
                'contenu' => 'Le remboursement est disponible pour toute transaction reussie.',
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "Le remboursement n'est pas disponible pour cette transaction. [SOURCE:55]",
            $chunks,
            true,
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_accepts_a_prose_explanation_of_an_error_code_table_row(): void
    {
        $result = (new CitationGuard)->validate(
            "L'erreur 401 (AUTHENTICATION_FAILED) survient quand la cle est expiree ou incorrecte. [SOURCE:17]",
            $this->errorCodeChunks(),
        );

        $this->assertTrue($result->valid);
    }

    public function test_it_still_rejects_a_real_code_paired_with_the_wrong_constant(): void
    {
        $result = (new CitationGuard)->validate(
            "L'erreur 401 (SERVICE_NOT_ACTIVE) survient quand la cle est expiree ou incorrecte. [SOURCE:17]",
            $this->errorCodeChunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_still_rejects_a_real_constant_paired_with_the_wrong_code(): void
    {
        $result = (new CitationGuard)->validate(
            "L'erreur 999 (AUTHENTICATION_FAILED) survient quand la cle est expiree ou incorrecte. [SOURCE:17]",
            $this->errorCodeChunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_does_not_treat_a_numbered_code_line_as_a_data_table_row(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => "Guide d'Intégration",
                'section' => 'Exemple de requête',
                'contenu' => "1 | curl --request POST \\\n\n6 | \"amount\": 150,",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            'Le montant est fixe a 6 unites de temps avant expiration. [SOURCE:17]',
            $chunks,
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    private function errorCodeChunks(): Collection
    {
        return new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => "Guide d'Intégration",
                'section' => 'Erreurs fréquentes',
                'contenu' => "HTTP | Code | Cause | Exemple\n\n401 | AUTHENTICATION_FAILED | Clé expirée ou incorrecte | \n\n403 | SECRET_KEY_RECEPTION_URL_NOT_ACTIVE | L'URL de réception n'est pas active | ",
            ],
        ]);
    }

    public function test_it_rejects_a_sentence_taken_from_an_incorrect_example(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => 'Authentification',
                'section' => 'Exemples',
                'contenu' => "Exemple incorrect\nLe secret est public pour tous les marchands.",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            'Le secret est public pour tous les marchands. [SOURCE:17]',
            $chunks,
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_preserves_any_standalone_heading_as_required_context(): void
    {
        foreach (['À éviter', 'Mauvais exemple', "N'utilisez jamais cette approche"] as $heading) {
            $chunks = new Collection([
                (object) [
                    'chunk_id' => 17,
                    'document_titre' => 'Authentification',
                    'section' => 'Exemples',
                    'contenu' => $heading."\nLe secret est public pour tous les marchands.",
                ],
            ]);

            $result = (new CitationGuard)->validate(
                'Le secret est public pour tous les marchands. [SOURCE:17]',
                $chunks,
            );

            $this->assertFalse($result->valid, "Heading context was lost: {$heading}");
            $this->assertSame('unsupported_claim', $result->reason);
        }
    }

    public function test_it_preserves_a_short_warning_sentence_as_context(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => 'Authentification',
                'section' => 'Exemples',
                'contenu' => "Ne suivez jamais cette approche.\n\nLe secret est public pour tous les marchands.",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            'Le secret est public pour tous les marchands. [SOURCE:17]',
            $chunks,
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_preserves_a_correction_placed_after_the_false_statement(): void
    {
        foreach ([
            'Cette affirmation est fausse.',
            'En realite, ne suivez jamais cette approche.',
            'Ceci est faux.',
            'Cette phrase est fausse.',
            'Information incorrecte.',
            'Correction : cette information ne doit pas etre suivie.',
            'La phrase precedente ne doit pas etre suivie.',
            'Ne faites pas cela.',
            'Ceci est errone.',
            'Cette information est erronee.',
            'La declaration precedente est fausse.',
            'Ce texte est incorrect.',
            'La consigne ci-dessus est fausse.',
        ] as $correction) {
            $chunks = new Collection([
                (object) [
                    'chunk_id' => 17,
                    'document_titre' => 'Authentification',
                    'section' => 'Exemples',
                    'contenu' => "Le secret est public pour tous les marchands.\n\n{$correction}",
                ],
            ]);

            $result = (new CitationGuard)->validate(
                'Le secret est public pour tous les marchands. [SOURCE:17]',
                $chunks,
            );

            $this->assertFalse($result->valid, "Trailing correction was lost: {$correction}");
            $this->assertSame('unsupported_claim', $result->reason);
        }
    }

    public function test_it_accepts_complete_document_list_items_and_imperative_prose(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => 'Inscription',
                'section' => 'Informations personnelles',
                'contenu' => "- Nom\n\n- Adresse email professionnelle\n\n- Numero de telephone\n\nCliquez ensuite sur Continuer.",
            ],
        ]);

        $listItem = (new CitationGuard)->validate(
            '- Adresse email professionnelle [SOURCE:17]',
            $chunks,
        );
        $shortListItem = (new CitationGuard)->validate('- Nom [SOURCE:17]', $chunks);
        $imperative = (new CitationGuard)->validate(
            'Cliquez ensuite sur Continuer. [SOURCE:17]',
            $chunks,
        );

        $this->assertTrue($listItem->valid);
        $this->assertTrue($shortListItem->valid);
        $this->assertTrue($imperative->valid);
    }

    public function test_it_shares_a_trailing_citation_across_the_uncited_bullets_it_covers(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 104,
                'document_titre' => "Étapes d'intégration aux APIs MyPVit",
                'section' => "2. Récupérez vos informations d'intégration",
                'contenu' => "Depuis votre espace MyPVit, récupérez :\n\n- Les URLs personnalisées de vos endpoints API\n\n- La clé secrète de votre compte test\n\n- Le code de votre compte d'opération de test",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "Depuis votre espace MyPVit, récupérez :\n- Les URLs personnalisées de vos endpoints API\n- La clé secrète de votre compte test\n- Le code de votre compte d'opération de test [SOURCE:104]",
            $chunks,
        );

        $this->assertTrue($result->valid);
        $this->assertSame([104], $result->chunkIds);
    }

    public function test_it_still_rejects_a_bullet_unsupported_by_the_shared_citation(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 104,
                'document_titre' => "Étapes d'intégration aux APIs MyPVit",
                'section' => "2. Récupérez vos informations d'intégration",
                'contenu' => "Depuis votre espace MyPVit, récupérez :\n\n- Les URLs personnalisées de vos endpoints API\n\n- Le code de votre compte d'opération de test",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "Depuis votre espace MyPVit, récupérez :\n- Les URLs personnalisées de vos endpoints API\n- Un mot de passe administrateur secret [SOURCE:104]",
            $chunks,
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unsupported_claim', $result->reason);
    }

    public function test_it_accepts_a_sentence_ending_with_a_colon_before_a_list(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => "S'inscrire sur MyPVit",
                'section' => 'Informations personnelles',
                'contenu' => "## Informations personnelles\n\nSur la page d'inscription, renseignez les champs suivants :\n\n- Nom\n\n- Prenoms",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "Sur la page d'inscription, renseignez les champs suivants : [SOURCE:17]",
            $chunks,
        );

        $this->assertTrue($result->valid);
    }

    public function test_it_treats_a_bold_heading_with_a_space_before_the_colon_as_a_heading(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 200,
                'document_titre' => "Codes d'erreur",
                'section' => 'Table',
                'contenu' => '1009 | INVALID_PHONE_NUMBER | The provided phone number is invalid. | 400',
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "**Exemple d'interpretation** :\n\n- Code `1009` correspond a `INVALID_PHONE_NUMBER` avec le code HTTP `400`. [SOURCE:200]",
            $chunks,
            true,
        );

        $this->assertTrue($result->valid);
    }

    public function test_it_accepts_a_factless_lead_in_sentence_without_its_own_citation(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 200,
                'document_titre' => "Codes d'erreur",
                'section' => 'Table',
                'contenu' => "Le tableau des codes d'erreur indique le libelle, le message et le code HTTP associe.",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "Pour interpreter un code d'erreur renvoye par l'API PVIT, procedez en trois etapes :\n\n".
            "Consultez le tableau des codes d'erreur qui indique le libelle, le message et le code HTTP associe. [SOURCE:200]",
            $chunks,
            true,
        );

        $this->assertTrue($result->valid);
    }

    public function test_it_still_requires_a_citation_on_a_claim_with_a_fact_ending_in_a_colon(): void
    {
        // Unlike the purely announcing lead-in above, this sentence states a concrete, checkable
        // fact (the code 500) -- salientTokens() finds it, so the lead-in exemption must not
        // apply and a citation is still required.
        $result = (new CitationGuard)->validate(
            "Le code 500 indique une erreur serveur selon la categorie suivante :",
            $this->chunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('missing_citation', $result->reason);
    }

    public function test_it_rejects_model_supplied_links_and_source_blocks(): void
    {
        $guard = new CitationGuard;

        $link = $guard->validate('Consultez https://evil.test. [SOURCE:17]', $this->chunks());
        $sourceBlock = $guard->validate("Explication. [SOURCE:17]\n\nSources consultees : document inconnu", $this->chunks());

        $this->assertSame('model_supplied_link', $link->reason);
        $this->assertSame('model_supplied_source_block', $sourceBlock->reason);
    }

    public function test_it_accepts_a_bare_domain_reference_quoted_verbatim_from_a_source(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => "S'inscrire sur MyPVit",
                'section' => 'Inscription',
                'contenu' => "Accedez au formulaire d'inscription sur mypvit.pro/register",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "Accedez au formulaire d'inscription sur mypvit.pro/register [SOURCE:17]",
            $chunks,
        );

        $this->assertTrue($result->valid);
    }

    public function test_it_accepts_a_verbatim_domain_reference_wrapped_in_backticks(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 227,
                'document_titre' => 'Parcours marchand',
                'section' => 'Etape 1',
                'contenu' => "Rendez-vous sur mypvit.pro/register puis renseignez les informations demandees.",
            ],
        ]);

        $result = (new CitationGuard)->validate(
            "Rendez-vous sur la page d'inscription `mypvit.pro/register`. [SOURCE:227]",
            $chunks,
            true,
        );

        $this->assertTrue($result->valid);
    }

    public function test_it_still_rejects_a_bare_domain_reference_absent_from_every_source(): void
    {
        $result = (new CitationGuard)->validate(
            'Rendez-vous sur evil-phishing.com pour continuer. [SOURCE:17]',
            $this->chunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('model_supplied_link', $result->reason);
    }

    public function test_server_rendering_sanitizes_untrusted_document_metadata(): void
    {
        $chunks = new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => '[Documentation](https://evil.example)',
                'section' => '<script>alert(1)</script> Inscription',
                'contenu' => 'Le secret doit etre renouvele avant expiration.',
            ],
        ]);

        $rendered = (new CitationGuard)->render(
            'Le secret doit etre renouvele avant expiration. [SOURCE:17]',
            $chunks,
        );

        $this->assertStringContainsString('[Documentation / Inscription]', $rendered);
        $this->assertStringNotContainsString('https://', $rendered);
        $this->assertStringNotContainsString('<script>', $rendered);
    }

    public function test_it_rejects_hidden_markup_instead_of_stripping_it_during_validation(): void
    {
        $result = (new CitationGuard)->validate(
            'Le secret doit etre renouvele avant expiration. <% Le remboursement est automatique. %> [SOURCE:17]',
            $this->chunks(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame('model_supplied_markup', $result->reason);
    }

    private function chunks(): Collection
    {
        return new Collection([
            (object) [
                'chunk_id' => 17,
                'document_titre' => 'PVIT renouvellement',
                'section' => 'Prerequis',
                'contenu' => 'Le secret doit etre renouvele avant expiration.',
            ],
            (object) [
                'chunk_id' => 18,
                'document_titre' => 'PVIT renouvellement',
                'section' => 'Effet',
                'contenu' => 'Le nouveau secret remplace le precedent.',
            ],
        ]);
    }
}
