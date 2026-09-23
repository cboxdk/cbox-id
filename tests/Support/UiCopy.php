<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * THE WORDS A PERSON READS, pulled out of source code for a test to check.
 *
 * A console page is mostly identifiers — `customer.name`, `tenantAssignable`,
 * `'environment.keys'` — and a vocabulary rule matched against the raw file would report
 * every one of them, which is how a rule collects exemptions until it means nothing. So
 * this keeps only what reaches the screen:
 *
 *  - JSX text, between a closing `>` and the next `<` or `{`;
 *  - string literals that read like copy — anything with whitespace in it, or starting
 *    with a capital. A bare lowercase word is an identifier (`'customer'` is an enum value,
 *    `'keys'` a route name) far more often than it is a sentence;
 *  - template literals, minus their `${…}` expressions, which are code.
 *
 * Comments are skipped: the history of why a word was retired is worth keeping in the
 * source, and a reader of the code is not a reader of the UI.
 */
final class UiCopy
{
    /**
     * @return list<string>
     */
    public static function fromTsx(string $source): array
    {
        $copy = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if (str_starts_with(substr($source, $i, 2), '//') && ($i === 0 || $source[$i - 1] !== ':')) {
                $end = strpos($source, "\n", $i);
                $i = $end === false ? $length : $end;

                continue;
            }

            if (str_starts_with(substr($source, $i, 2), '/*')) {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $j = $i + 1;

                while ($j < $length && $source[$j] !== $char && $source[$j] !== "\n") {
                    $j += $source[$j] === '\\' ? 2 : 1;
                }

                $literal = substr($source, $i + 1, $j - $i - 1);
                $lineStart = strrpos(substr($source, 0, $i), "\n");
                $before = substr($source, $lineStart === false ? 0 : $lineStart + 1, $i - ($lineStart === false ? 0 : $lineStart + 1));

                // A module path is not copy.
                if (! preg_match('/^\s*(import|export)\b|\bfrom\s*$/', $before) && self::readsLikeCopy($literal)) {
                    $copy[] = $literal;
                }

                $i = $j + 1;

                continue;
            }

            if ($char === '`') {
                $j = $i + 1;
                $text = '';

                while ($j < $length && $source[$j] !== '`') {
                    if ($source[$j] === '\\') {
                        $text .= substr($source, $j, 2);
                        $j += 2;

                        continue;
                    }

                    if (substr($source, $j, 2) === '${') {
                        $depth = 0;

                        for ($k = $j; $k < $length; $k++) {
                            $depth += $source[$k] === '{' ? 1 : ($source[$k] === '}' ? -1 : 0);

                            if ($depth === 0 && $source[$k] === '}') {
                                break;
                            }
                        }

                        // The expression is code; what surrounds it is one sentence.
                        $text .= ' … ';
                        $j = $k + 1;

                        continue;
                    }

                    $text .= $source[$j];
                    $j++;
                }

                if (self::readsLikeCopy($text)) {
                    $copy[] = trim((string) preg_replace('/\s+/', ' ', $text));
                }

                $i = $j + 1;

                continue;
            }

            if ($char === '>') {
                $j = $i + 1;

                while ($j < $length && ! in_array($source[$j], ['<', '{', '}', '>'], true)) {
                    $j++;
                }

                $text = substr($source, $i + 1, $j - $i - 1);

                if ($j < $length && in_array($source[$j], ['<', '{'], true)
                    && preg_match('/[A-Za-z]{2}/', $text) === 1
                    && preg_match('/^\s*[=&|)(;,.?:]/', $text) !== 1) {
                    $copy[] = trim((string) preg_replace('/\s+/', ' ', $text));
                    $i = $j;

                    continue;
                }
            }

            $i++;
        }

        return $copy;
    }

    /**
     * Every string literal in a PHP file that reads like copy — page titles, flash messages,
     * nav labels, help text — read with PHP's own tokenizer, so comments never count.
     *
     * @return list<string>
     */
    public static function fromPhp(string $source): array
    {
        $copy = [];

        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $literal = $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];

            if (self::readsLikeCopy($literal)) {
                $copy[] = $literal;
            }
        }

        return $copy;
    }

    private static function readsLikeCopy(string $text): bool
    {
        return preg_match('/\s/', trim($text)) === 1 || preg_match('/^[A-Z][a-z]/', $text) === 1;
    }
}
