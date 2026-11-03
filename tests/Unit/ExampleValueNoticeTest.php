<?php

namespace Tests\Unit;

use App\Services\Assistant\ExampleValueNotice;
use PHPUnit\Framework\TestCase;

class ExampleValueNoticeTest extends TestCase
{
    private function chunks(): array
    {
        return [(object) ['contenu' => "## Exemple de notification reçue\n\n```json\n{\n  \"transactionId\": \"PAY23102400001\",\n"
            ."  \"status\": \"SUCCESS\",\n  \"customerID\": \"074111111\",\n  \"accountOperationCode\": \"ACC_1XXXXXXXX\",\n"
            ."  \"code\": 200\n}\n```\n\n```bash\ncurl -X GET \"https://api.mypvit.pro/v2/{{code_url_status}}/status?transactionId=PAY23102400001&accountOperationCode={{operation_account_code}}\"\n```"]];
    }

    public function test_it_names_the_documentation_example_values_reused_in_the_answer(): void
    {
        $answer = "Votre serveur répond :\n\n```json\n{\n  \"transactionId\": \"PAY23102400001\",\n  \"responseCode\": 200\n}\n```";

        $result = (new ExampleValueNotice)->append($answer, $this->chunks());

        $this->assertStringStartsWith($answer, $result);
        $this->assertStringContainsString("Les valeurs d'exemple reprises ici (PAY23102400001) sont celles de la documentation officielle PVIT", $result);
    }

    public function test_placeholders_enums_and_unrelated_answers_get_no_notice(): void
    {
        $notice = new ExampleValueNotice;

        $this->assertSame(['PAY23102400001', '074111111', 'ACC_1XXXXXXXX'], $notice->documentedExampleValues($this->chunks()));

        foreach ([
            'Le statut vaut SUCCESS ou FAILED et le code URL {{code_url_status}} est propre à votre compte.',
            'Le champ transactionId identifie la transaction.',
            'La référence PAY23102400001X est différente.',
        ] as $answer) {
            $this->assertSame($answer, $notice->append($answer, $this->chunks()));
        }
    }
}
