<?php

namespace App\Services\Assistant;

/**
 * The knowledge base keeps the official cURL / JSON examples verbatim, with the documentation's
 * own example values (PAY23102400001, 074111111, ACC_1XXXXXXXX...). When an answer reuses such a
 * value, the reader must know it is a documentation example to replace with their own data, not
 * a value to send as-is. The model is asked to say so, but a long prompt does not make that
 * reliable, so the notice is appended deterministically after generation.
 */
final class ExampleValueNotice
{
    private const MAX_LISTED = 4;

    /**
     * @param  iterable<object|array>  $chunks  the retrieved chunks the answer was built from
     */
    public function append(string $answer, iterable $chunks): string
    {
        $documented = $this->documentedExampleValues($chunks);

        if ($documented === [] || trim($answer) === '') {
            return $answer;
        }

        $used = array_values(array_filter(
            $documented,
            static fn (string $value): bool => preg_match('/(?<![A-Za-z0-9_])'.preg_quote($value, '/').'(?![A-Za-z0-9_])/u', $answer) === 1,
        ));

        if ($used === []) {
            return $answer;
        }

        $listed = implode(', ', array_slice($used, 0, self::MAX_LISTED)).(count($used) > self::MAX_LISTED ? '...' : '');

        return rtrim($answer)."\n\n*Les valeurs d'exemple reprises ici ({$listed}) sont celles de la documentation "
            .'officielle PVIT : remplacez-les par vos propres valeurs.*';
    }

    /**
     * Example values are read only from fenced code blocks of the retrieved chunks: JSON string
     * or numeric values, and query-string values. Placeholders ({{...}}) and plain enum words
     * (SUCCESS, PAYMENT, CUSTOMER...) are API vocabulary, not example data, and are ignored: a
     * value counts when it mixes letters and digits (REF1234567890) or is a long digit run
     * (074111111).
     *
     * @return list<string>
     */
    public function documentedExampleValues(iterable $chunks): array
    {
        $values = [];

        foreach ($chunks as $chunk) {
            $content = (string) data_get($chunk, 'contenu', '');

            if (preg_match_all('/```[^\n]*\n(.*?)```/s', $content, $blocks) === 0) {
                continue;
            }

            foreach ($blocks[1] as $code) {
                preg_match_all('/:\s*"([^"\n{}]{3,80})"/u', $code, $strings);
                preg_match_all('/:\s*(\d{6,})\b/', $code, $numbers);
                preg_match_all('/[?&][A-Za-z_]+=([^&\s"\'{}]{3,80})/', $code, $query);

                foreach ([...$strings[1], ...$numbers[1], ...$query[1]] as $value) {
                    $value = trim($value);
                    $mixed = preg_match('/[A-Za-z]/', $value) === 1 && preg_match('/\d/', $value) === 1;
                    $longDigits = preg_match('/^\d{6,}$/', $value) === 1;

                    if (($mixed || $longDigits) && ! str_contains($value, ' ') && mb_strlen($value) >= 5) {
                        $values[$value] = true;
                    }
                }
            }
        }

        return array_keys($values);
    }
}
