<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\ConversationPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConversationPlanExamplesTest extends TestCase
{
    public static function fences(): array
    {
        return [['```', ''], ['~~~', '   '], ['````', '    '], ['```', '   ', "\r\n"]];
    }

    #[DataProvider('fences')]
    public function test_examples_are_preserved_without_creating_steps_from_their_contents(string $fence, string $indent, string $newline = "\n"): void
    {
        $code = $indent.$fence.'text'.$newline.'1. Ligne interne de l’exemple'.$newline.'Exemple interne :'.$newline.$indent.$fence;
        $steps = (new ConversationPlan)->extract("1. Configurer.\n\n2. Traiter le callback.\nExemple de réponse attendue :\n\n".$code."\n\nNe codez pas en dur les valeurs.\n\n3. Vérifier.");
        $this->assertCount(3, $steps);
        $this->assertStringContainsString($code, $steps[1]);
        $this->assertStringContainsString('Exemple de réponse attendue :', $steps[1]);
        $this->assertStringContainsString('Ne codez pas en dur', $steps[1]);
    }

    public function test_legacy_examples_are_recovered_only_from_the_same_parent_plan_and_sources(): void
    {
        $answer = "1. Configurer.\n\n2. Traiter.\nExemple de réponse attendue :\n```json\n{\"responseCode\":200}\n```\n\n3. Vérifier.";
        $memory = ['plan_steps' => ['Configurer.', "Traiter.\nExemple de réponse attendue :", 'Vérifier.'], 'payload_answer' => $answer, 'plan_sources' => [['chunk_id' => 1]], 'payload_sources' => [['chunk_id' => 1]]];
        $plan = new ConversationPlan;
        $this->assertStringContainsString('{"responseCode":200}', $plan->repairSteps($memory)[1]);
        $memory['payload_sources'] = [['chunk_id' => 2]];
        $this->assertSame('Traiter.', $plan->repairSteps($memory)[1]);
        $memory['payload_sources'] = $memory['plan_sources'];
        $memory['payload_answer'] = str_replace('1. Configurer.', '1. Initier un paiement.', $answer);
        $this->assertSame('Traiter.', $plan->repairSteps($memory)[1]);
    }
}
