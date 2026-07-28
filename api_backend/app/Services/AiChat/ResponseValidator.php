<?php

declare(strict_types=1);

namespace App\Services\AiChat;

/**
 * Deterministic post-generation self-check.
 * Runs cheap regex/heuristic checks on the assistant reply and returns a
 * list of violated rules. When any hard rule is broken, the service triggers
 * a single repair pass through the model.
 */
final class ResponseValidator
{
    /**
     * @return array{ok: bool, violations: array<int, string>, detected_lang: string, target_lang: string}
     */
    public function check(string $reply, string $lastUserMessage, string $locale): array
    {
        $violations = [];
        $target = $this->detectLang($lastUserMessage, $locale);
        $actual = $this->detectLang($reply, $target);

        if ($target !== 'unknown' && $actual !== 'unknown' && $actual !== $target) {
            $violations[] = "language_mismatch(expected={$target}, got={$actual})";
        }

        $trimmed = ltrim($reply);

        // Formatting rules
        if (preg_match('/^#{1,6}\s/', $trimmed)) {
            $violations[] = 'opens_with_heading';
        }
        if (preg_match('/^\s*\*\s+/m', $reply)) {
            $violations[] = 'uses_asterisk_bullets';
        }
        if (preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $reply)) {
            $violations[] = 'contains_html';
        }
        if (substr_count($reply, '```') % 2 !== 0) {
            $violations[] = 'unbalanced_code_fence';
        }

        // Forbidden preambles / filler
        $forbidden = [
            '/^\s*(sure|of course|certainly|absolutely)[,! ]/i',
            '/^\s*(voici|bien sûr|bien sur|d\'accord)[,! ]/i',
            '/\bas an ai\b/i',
            '/\ben tant qu\'?\s*ia\b/i',
            '/\b(i hope this helps|let me know if|n\'hésitez pas|n\'hesitez pas)\b/i',
        ];
        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $reply)) {
                $violations[] = 'forbidden_phrase';
                break;
            }
        }

        // Length cap — very generous, only flag runaway replies
        $wordCount = str_word_count(strip_tags($reply));
        if ($wordCount > 320) {
            $violations[] = "too_long({$wordCount}w)";
        }

        return [
            'ok'            => $violations === [],
            'violations'    => $violations,
            'detected_lang' => $actual,
            'target_lang'   => $target,
        ];
    }

    /**
     * Cheap language sniffer: French vs English. Falls back to locale hint.
     * Enough to catch obvious "user wrote FR, reply is EN" regressions.
     */
    private function detectLang(string $text, string $fallback): string
    {
        $t = mb_strtolower($text);
        if ($t === '') {
            return 'unknown';
        }

        // French markers: accented chars + common function words
        $frHits = preg_match_all('/[àâäçéèêëîïôöùûüÿœæ]/u', $t)
            + preg_match_all('/\b(le|la|les|un|une|des|est|pour|avec|dans|combien|quel|quelle|parcelle|eau|coût|cout|mois|année|annee|aujourd\'hui|hier|bonjour)\b/u', $t);

        $enHits = preg_match_all('/\b(the|and|is|are|for|with|how|what|which|plot|water|cost|month|year|today|yesterday|hello)\b/u', $t);

        if ($frHits >= 2 && $frHits > $enHits) {
            return 'fr';
        }
        if ($enHits >= 2 && $enHits > $frHits) {
            return 'en';
        }

        $fb = strtolower(substr($fallback, 0, 2));
        return in_array($fb, ['fr', 'en'], true) ? $fb : 'unknown';
    }
}
