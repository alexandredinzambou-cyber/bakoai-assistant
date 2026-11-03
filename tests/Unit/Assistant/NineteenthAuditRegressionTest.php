<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationActions;
use App\Services\Assistant\DurationConversion;
use App\Services\Assistant\FollowUpDetector;
use App\Services\Assistant\JsonExamples;
use App\Services\Assistant\ParameterScope;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NineteenthAuditRegressionTest extends TestCase
{
    public static function negativeSteps(): array
    {
        $cases = [];
        foreach (['', 'numéro ', 'n° '] as $label) {
            foreach (['-', '−', '–', '—', '－'] as $sign) {
                foreach (['Va à', 'Montre', 'Peux-tu expliquer'] as $verb) {
                    $cases[] = [$verb.' l’étape '.$label.$sign.'1 du plan Sandbox.'];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('negativeSteps')]
    public function test_sign_and_label_combinations_cannot_become_positive_steps(string $question): void
    {
        $this->assertSame('number:0', (new ConversationActions)->stepRequest($question));
    }

    public static function lastSteps(): array
    {
        $cases = [];
        foreach (['montre' => 'montrer', 'explique' => 'expliquer', 'détaille' => 'détailler', 'simplifie' => 'simplifier', 'affiche' => 'afficher', 'reprends' => 'reprendre'] as $verb => $infinitive) {
            foreach (['', 'S’il te plaît, ', 'Peux-tu '] as $prefix) {
                $cases[] = [$prefix.($prefix === 'Peux-tu ' ? $infinitive : $verb).' la dernière étape du plan Sandbox.'];
            }
        }

        return $cases;
    }

    #[DataProvider('lastSteps')]
    public function test_the_same_navigation_verbs_select_the_last_step(string $question): void
    {
        $this->assertSame('last', (new ConversationActions)->stepRequest($question));
    }

    public static function scopes(): array
    {
        $cases = [];
        foreach (['', 'Sans exemple, ', 'Sans code, ', 'Pas de ticket : '] as $prefix) {
            foreach (['du corps' => 'body', 'de chemin' => 'path', 'de requête' => 'query', 'des en-têtes' => 'headers'] as $words => $scope) {
                $cases[] = [$prefix.'liste uniquement les paramètres obligatoires '.$words.' pour cet appel.', [$scope]];
            }
        }

        return $cases;
    }

    #[DataProvider('scopes')]
    public function test_unrelated_negative_directives_do_not_hide_a_parameter_scope(string $question, array $expected): void
    {
        $this->assertSame($expected, (new ParameterScope)->requested($question));
    }

    public static function units(): array
    {
        $cases = [];
        foreach (['En ', 'Et en '] as $prefix) {
            foreach ([' et en ', ' et ', ', puis en ', ' puis en '] as $join) {
                $cases[] = [$prefix.'heures'.$join.'minutes.', ['heure', 'minute']];
                $cases[] = [$prefix.'jours'.$join.'heures uniquement.', ['jour', 'heure']];
            }
        }

        return $cases;
    }

    #[DataProvider('units')]
    public function test_unit_sequences_share_one_parser_with_or_without_et(string $question, array $expected): void
    {
        $this->assertSame($expected, (new DurationConversion)->requestedUnits($question));
        $this->assertTrue((new FollowUpDetector)->isFollowUp($question, 'Disponibilité du fichier d’export'));
    }

    public static function exclusions(): array
    {
        return array_map(static fn ($verb) => [$verb.' le statut READY. Montre cet exemple JSON.'], ['Évite', 'Évitez', 'Exclus', 'Excluez', 'Ignore', 'Ignorez']);
    }

    #[DataProvider('exclusions')]
    public function test_exclusion_verbs_select_another_verified_status(string $question): void
    {
        $source = "Notification reçue\n```json\n{\"job_id\":\"A\",\"status\":\"READY\"}\n```\nNotification reçue\n```json\n{\"job_id\":\"B\",\"status\":\"FAILED\"}\n```";
        $answer = (new JsonExamples)->example($source, $question);
        $this->assertStringContainsString('FAILED', $answer);
        $this->assertStringNotContainsString('READY', $answer);
        $this->assertStringContainsString('"B"', $answer);
    }

    public function test_unsupported_or_mixed_units_are_not_partially_converted(): void
    {
        $service = new DurationConversion;
        foreach (['En semaines.', 'En mois.', 'En heures et en semaines.'] as $question) {
            $this->assertTrue($service->isConversionFollowUp($question));
            $this->assertSame([], $service->requestedUnits($question));
        }
        $this->assertFalse($service->isConversionFollowUp('En production, comment initier un paiement ?'));
    }

    public function test_positive_scope_and_actual_scope_exclusions_are_preserved(): void
    {
        $this->assertSame(['body'], (new ParameterScope)->requested('Sans exemple, liste les paramètres du corps, sans les en-têtes ni les paramètres de chemin.'));
        $this->assertSame(['query'], (new ParameterScope)->requested('Sans code, liste les paramètres de requête, évite les en-têtes.'));
    }

    public function test_parameter_paragraphs_preserve_every_field_without_a_requested_limit(): void
    {
        $answer = (new ConversationActions)->formatAnswer("- `accountOperationCode` : Code du compte documenté.\n- `transactionType` : Type de transaction documenté.\n- `amount` : Montant de la transaction documenté.\n- `operatorCode` : Code de l’opérateur documenté.", 'Sans code, présente uniquement les paramètres de requête obligatoires des frais.');
        foreach (['accountOperationCode', 'transactionType', 'amount', 'operatorCode'] as $field) {
            $this->assertStringContainsString($field, $answer);
        }
    }

    public static function sentences(): array
    {
        return [['une', 1], ['deux', 2]];
    }

    #[DataProvider('sentences')]
    public function test_sentence_limits_group_fields_instead_of_omitting_them(string $word, int $count): void
    {
        $answer = (new ConversationActions)->formatAnswer("- `accountOperationCode` : Code du compte documenté.\n- `transactionType` : Type de transaction documenté.\n- `amount` : Montant de la transaction documenté.\n- `operatorCode` : Code de l’opérateur documenté.", 'Résume les paramètres de requête obligatoires en '.$word.' phrase'.($count > 1 ? 's' : '').'.');
        foreach (['accountOperationCode', 'transactionType', 'amount', 'operatorCode'] as $field) {
            $this->assertStringContainsString($field, $answer);
        }
        $this->assertSame($count, substr_count($answer, '.'));
    }
}
