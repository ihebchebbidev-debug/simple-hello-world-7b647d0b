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
     * @param  array<int, string>  $evidence  Raw JSON of the tool results the answer was built from.
     * @return array{ok: bool, violations: array<int, string>, detected_lang: string, target_lang: string}
     */
    public function check(string $reply, string $lastUserMessage, string $locale, array $evidence = []): array
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

        // ── Data-integrity rules ────────────────────────────────────────
        // Style is cheap to police; a wrong number is what actually costs
        // the user trust, so check the claims against the tool results.

        // The assistant can only ever see what its filters returned. Any
        // claim of exhaustiveness is unverifiable by construction.
        $exhaustive = '/\b(l\W?ensemble des (enregistrements|donn[ée]es|op[ée]rations)'
            .'|l\W?int[ée]gralit[ée] des'
            .'|tous les enregistrements|toutes les op[ée]rations enregistr[ée]es'
            .'|all (the )?records|every (single )?record|the complete set of)\b/iu';
        if (preg_match($exhaustive, $reply)) {
            $violations[] = 'claims_exhaustiveness';
        }

        if ($evidence !== []) {
            foreach ($this->staleCounts($reply, $evidence) as $stale) {
                $violations[] = "stale_count(said={$stale['said']},total={$stale['total']})";
            }

            $unsupported = $this->unsupportedNumbers($reply, $evidence);
            if ($unsupported !== []) {
                $violations[] = 'unsupported_numbers('.implode(',', array_slice($unsupported, 0, 5)).')';
            }
        }

        return [
            'ok'            => $violations === [],
            'violations'    => $violations,
            'detected_lang' => $actual,
            'target_lang'   => $target,
        ];
    }

    /**
     * Language the reply must be written in for this user message.
     * Public so the prompt builder can pin the same target the validator
     * will later enforce — one source of truth, no drift.
     */
    public function targetLanguage(string $userMessage, string $locale): string
    {
        $lang = $this->detectLang($userMessage, $locale);

        return $lang === 'unknown'
            ? (str_starts_with(strtolower($locale), 'fr') ? 'fr' : 'en')
            : $lang;
    }

    /**
     * Truncated-listing counts quoted as if they were the total.
     *
     * A tool that caps its rows returns `returned_rows` + `total_matching`
     * (or `irrigation_count` / `harvest_count`). When the reply states the
     * capped row count as the answer and never mentions the real total, the
     * user gets a confidently wrong number — the "40 vs 60 irrigations" bug.
     *
     * @param  array<int, string>  $evidence
     * @return array<int, array{said: int, total: int}>
     */
    private function staleCounts(string $reply, array $evidence): array
    {
        $out = [];
        foreach ($evidence as $json) {
            $data = json_decode($json, true);
            if (! is_array($data)) continue;

            foreach ($this->countPairs($data) as [$returned, $total]) {
                if ($total <= $returned) continue;
                if (! $this->mentionsNumber($reply, $returned)) continue;
                if ($this->mentionsNumber($reply, $total)) continue;

                $out[] = ['said' => $returned, 'total' => $total];
            }
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * Every (returned_rows, total) pair found anywhere in a tool result.
     *
     * @param  array<mixed>  $data
     * @return array<int, array{0: int, 1: int}>
     */
    private function countPairs(array $data): array
    {
        $pairs = [];
        $returned = $data['returned_rows'] ?? null;
        if (is_numeric($returned)) {
            foreach (['total_matching', 'irrigation_count', 'harvest_count', 'usage_count', 'total'] as $key) {
                if (isset($data[$key]) && is_numeric($data[$key])) {
                    $pairs[] = [(int) $returned, (int) $data[$key]];
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                foreach ($this->countPairs($value) as $p) $pairs[] = $p;
            }
        }

        return $pairs;
    }

    /** True when the reply states this integer as a standalone number. */
    private function mentionsNumber(string $reply, int $n): bool
    {
        $t = preg_replace('/(\d)[\x{0020}\x{00a0}\x{202f}](\d{3})\b/u', '$1$2', $reply) ?? $reply;

        return (bool) preg_match('/(?<![\d.,])'.$n.'(?![\d.,])/u', $t);
    }

    /**
     * Numbers stated in the reply that appear in no tool result.
     *
     * Only meaningful magnitudes are checked: small integers are dates,
     * counts and list markers, and flagging them would be pure noise.
     *
     * @param  array<int, string>  $evidence
     * @return array<int, string>
     */
    private function unsupportedNumbers(string $reply, array $evidence): array
    {
        $known = [];
        foreach ($this->numbersIn(implode(' ', $evidence)) as $n) {
            $known[] = $n;
            $known[] = round($n, 2);
            $known[] = round($n, 1);
            $known[] = round($n);
        }
        if ($known === []) return [];

        $bad = [];
        foreach ($this->numbersIn($reply) as $n) {
            // Skip integers below 100 (days, months, counts) and years.
            if ($n == floor($n) && ($n < 100 || ($n >= 1900 && $n <= 2200))) continue;

            foreach ($known as $k) {
                if (abs($k - $n) <= 0.011) continue 2;
            }
            $bad[] = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        }

        return array_values(array_unique($bad));
    }

    /** @return array<int, float> */
    private function numbersIn(string $text): array
    {
        // Collapse thousands separators (space, NBSP, narrow NBSP) so
        // "1 234,50" and "1234.5" compare equal.
        $t = preg_replace('/(\d)[\x{0020}\x{00a0}\x{202f}](\d{3})\b/u', '$1$2', $text) ?? $text;

        if (! preg_match_all('/-?\d+(?:[.,]\d+)?/u', $t, $m)) return [];

        return array_map(static fn (string $s): float => (float) str_replace(',', '.', $s), $m[0]);
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
