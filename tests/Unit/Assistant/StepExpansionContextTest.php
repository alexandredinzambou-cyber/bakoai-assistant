<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\StepExpansionContext;
use Tests\TestCase;

class StepExpansionContextTest extends TestCase
{
    public function test_a_cited_step_does_not_import_sibling_sections_of_its_guide(): void
    {
        $guide = 'https://docs.mypvit.pro/fr/intro/integration-guide';
        $memory = ['plan_subject' => 'callback', 'plan_index' => 2, 'plan_steps' => ['Configuration', 'POST', 'Recevoir la notification [4].'], 'plan_sources' => [['number' => 4, 'url' => $guide, 'section' => '3. Réception du Webhook (Callback)']]];
        $chunks = collect([
            (object) ['chunk_id' => 1, 'lien_officiel' => $guide, 'section' => '3. Réception du Webhook (Callback)'],
            (object) ['chunk_id' => 2, 'lien_officiel' => $guide, 'section' => '3. Réception du Webhook (Callback) > Accusé'],
            (object) ['chunk_id' => 3, 'lien_officiel' => $guide, 'section' => '2. Simulation > Bonnes pratiques'],
            (object) ['chunk_id' => 4, 'lien_officiel' => 'https://docs.mypvit.pro/fr/api/status', 'section' => 'Consultation'],
            (object) ['chunk_id' => 5, 'lien_officiel' => 'https://docs.mypvit.pro/fr/webhooks/status', 'section' => 'Notification'],
        ]);
        $this->assertSame([1, 2, 5], (new StepExpansionContext)->select($chunks, $memory)->pluck('chunk_id')->all());
    }
}
