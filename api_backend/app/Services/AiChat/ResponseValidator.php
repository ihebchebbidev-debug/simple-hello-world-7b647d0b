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

        // Internal plumbing must never reach the user: row uuids, tool names,
        // SQL errors, references to the system prompt. Seen verbatim in
        // production answers, so it is a hard rule, not a style nit.
        $internal = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
            .'|\bcost_per_ha\b|\bget_operations\b|\bsearch_catalog\b|\bplot_info\b|\bproduct_usage\b'
            .'|\blist_plots\b|\blist_campaigns\b|\busage_count\b|\btotal_matching\b|\breturned_rows\b'
            .'|\birrigation_count\b|\bharvest_count\b|\bSQLSTATE\b|invalid input syntax'
            .'|DONN[EÉ]ES R[EÉ]ELLES|message syst[èe]me|outil attend|tool_failed)/iu';
        if (preg_match($internal, $reply)) {
            $violations[] = 'leaks_internals';
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

            $unsupported = $this->unsupportedNumbers($reply, $evidence, $lastUserMessage);
            if ($unsupported !== []) {
                $violations[] = 'unsupported_numbers('.implode(',', array_slice($unsupported, 0, 5)).')';
            }

            foreach ($evidence as $json) {
                $data = json_decode($json, true);
                if (! is_array($data)) continue;

                if ($this->listingAnsweredAsSummary($reply, $data)) {
                    $violations[] = 'listing_not_listed';
                }
                if ($this->unqualifiedAbsence($reply, $data)) {
                    $violations[] = 'unqualified_absence';
                }
            }

            // "There is no data" is only credible once the farm-wide search
            // has run. Without it, an empty answer is a failed lookup being
            // reported as a fact about the farm.
            if ($this->claimsNothingFound($reply) && ! $this->searchedFarmWide($evidence)) {
                $violations[] = 'absence_without_search';
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
     * A listing tool returned rows, but the answer shows none of them.
     *
     * "Traitements contre la cératite sur la parcelle B12" was answered with a
     * cost total and no dates — the user asked WHAT was applied and got money
     * instead. When a result is marked `answer_shape: listing` and carries
     * dated rows, at least one of those dates must appear in the reply.
     *
     * @param  array<mixed>  $data
     */
    private function listingAnsweredAsSummary(string $reply, array $data): bool
    {
        if (($data['answer_shape'] ?? null) !== 'listing') {
            return false;
        }
        $rows = $data['rows'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return false;
        }

        foreach ($rows as $row) {
            $date = is_array($row) ? (string) ($row['date'] ?? '') : '';
            if ($date === '') continue;
            $ymd = substr($date, 0, 10);
            [$y, $m, $d] = array_pad(explode('-', $ymd), 3, '');
            // Accept the ISO form and the common FR forms (29/07/2026, 29 juillet 2026).
            if (str_contains($reply, $ymd)
                || ($d !== '' && preg_match('/\b'.((int) $d).'\D{1,12}'.$y.'\b/u', $reply) === 1)
                || preg_match('#\b'.$d.'[/.-]'.$m.'[/.-]'.$y.'\b#u', $reply) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * "Aucun traitement" written while the plot demonstrably WAS treated.
     *
     * The tool attaches `filter_context.unfiltered_treatment_count` whenever a
     * pest/product filter matched nothing. A bare absence claim that never
     * names what WAS applied is misleading, even though it is literally true
     * for the filter.
     *
     * @param  array<mixed>  $data
     */
    private function unqualifiedAbsence(string $reply, array $data): bool
    {
        $ctx = $data['filter_context'] ?? null;
        if (! is_array($ctx) || (int) ($ctx['unfiltered_treatment_count'] ?? 0) <= 0) {
            return false;
        }

        $claimsAbsence = preg_match(
            '/\b(aucun|aucune|pas de|n\W?est enregistr|non enregistr|no treatment|none recorded)\b/iu',
            $reply,
        ) === 1;
        if (! $claimsAbsence) {
            return false;
        }

        $known = array_merge(
            array_map('strval', (array) ($ctx['recorded_target_pests'] ?? [])),
            array_map('strval', (array) ($ctx['recorded_products'] ?? [])),
        );
        foreach ($known as $label) {
            $label = trim($label);
            if ($label !== '' && mb_stripos($reply, $label) !== false) {
                return false;
            }
        }

        return true;
    }

    /**

     * The reply tells the user nothing is recorded / nothing was found.
     */
    private function claimsNothingFound(string $reply): bool
    {
        return preg_match(
            '/(aucun(e)? (enregistrement|donn[ée]e|op[ée]ration|trace|information)'
            .'|aucune donn[ée]e'
            .'|pas d\W?(enregistrement|op[ée]ration|donn[ée]e)'
            .'|rien n\W?est enregistr'
            .'|je ne trouve (pas|aucun)'
            .'|not (recorded|found) (in|anywhere)'
            .'|no (data|record|records|operations) (are |is |were )?(recorded|found|available))/iu',
            $reply,
        ) === 1;
    }

    /**
     * Did the turn actually run the cross-table discovery lookup?
     *
     * `locate_data` results are recognisable by their verdict + all-time count:
     * they are the only payload that proves the search was not limited to one
     * table, one plot or one period.
     *
     * @param  array<int, string>  $evidence
     */
    private function searchedFarmWide(array $evidence): bool
    {
        foreach ($evidence as $json) {
            $data = json_decode($json, true);
            if (is_array($data) && array_key_exists('total_all_time', $data) && array_key_exists('verdict', $data)) {
                return true;
            }
        }
        return false;
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
     * Small integers used to be exempt, which meant a hallucinated count
     * ("3 irrigations" when the data says 5) was never caught — exactly the
     * class of error that matters most on a farm-data assistant. They are now
     * checked too. To keep that from flagging legitimate arithmetic, a figure
     * also counts as supported when it is derivable from two evidence values
     * (sum, difference, or percentage ratio), and when the user themselves put
     * it in the question. Ordered-list markers are stripped before scanning.
     *
     * @param  array<int, string>  $evidence
     * @return array<int, string>
     */
    private function unsupportedNumbers(string $reply, array $evidence, string $lastUserMessage = ''): array
    {
        $base = $this->numbersIn(implode(' ', $evidence));
        if ($base === []) return [];

        $known = [];
        foreach (array_merge($base, $this->numbersIn($lastUserMessage)) as $n) {
            $known[] = $n;
            $known[] = round($n, 2);
            $known[] = round($n, 1);
            $known[] = round($n);
        }
        // Deterministic figures every answer may legitimately contain.
        $known = array_merge($known, [0.0, 1.0, 100.0]);

        // Derived values the model is allowed to compute from the evidence:
        // totals, deltas and percentage variations. Bounded so the check
        // stays cheap on a long tool payload.
        $seed = array_slice(array_values(array_unique($base)), 0, 60);
        foreach ($seed as $a) {
            foreach ($seed as $b) {
                $known[] = round($a + $b, 2);
                $known[] = round($a - $b, 2);
                if (abs($b) > 0.0001) {
                    $known[] = round($a / $b * 100, 2);
                    $known[] = round(($a - $b) / abs($b) * 100, 2);
                }
            }
        }

        $bad = [];
        foreach ($this->numbersIn($this->stripListMarkers($reply)) as $n) {
            // Years are calendar labels, never a measured quantity.
            if ($n == floor($n) && $n >= 1900 && $n <= 2200) continue;

            foreach ($known as $k) {
                if (abs($k - $n) <= 0.011) continue 2;
            }
            $bad[] = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        }

        return array_values(array_unique($bad));
    }

    /**
     * Drop "1." / "2)" ordered-list prefixes and bullet numbering so list
     * scaffolding is never mistaken for a stated figure.
     */
    private function stripListMarkers(string $reply): string
    {
        return preg_replace('/^\s*\d{1,2}[.)]\s+/m', '', $reply) ?? $reply;
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
