<?php

declare(strict_types=1);

namespace App\Services\AiChat;

final class ReplySanitizer
{
    /**
     * Strip XML-like tags used by the model for internal tools and reasoning.
     */
    public function sanitize(string $text): string
    {
        $text = preg_replace('/<tool_call>.*?<\/tool_call>/is', '', $text) ?? $text;
        $text = preg_replace('/<function(?:=[^>]*)?>.*?<\/function>/is', '', $text) ?? $text;
        $text = preg_replace('/<parameter(?:=[^>]*)?>.*?<\/parameter>/is', '', $text) ?? $text;
        $text = preg_replace('/<thought>.*?<\/thought>/is', '', $text) ?? $text;
        
        // Strip any trailing unclosed tags
        $text = preg_replace('/<(?:tool_call|function|parameter|thought)[^>]*>.*$/is', '', $text) ?? $text;

        $text = self::stripInternals($text);

        return trim($text);
    }

    /**
     * Remove plumbing that means nothing to a farmer: row uuids, internal
     * tool/field names, and references to the system prompt. These leaked
     * verbatim into real answers ("la requête `cost_per_ha` … UUID a1f1…").
     */
    public static function stripInternals(string $text): string
    {
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

        // "(UUID `a1f1…`)" / "(id: a1f1…)" / "(`a1f1…`)" → gone
        $text = preg_replace('/\s*[\(\[]\s*(?:UUID|uuid|id)?\s*:?\s*`?'.$uuid.'`?\s*[\)\]]/u', '', $text) ?? $text;
        $text = preg_replace('/`?'.$uuid.'`?/u', '', $text) ?? $text;

        // Internal tool / field identifiers and SQL noise. Deleting the word
        // alone leaves mangled sentences ("** = 0**"), so the whole sentence
        // that carries the leak goes — it is plumbing talk, never an answer.
        $names = 'cost_per_ha|get_operations|aggregate_operations|compare_periods|search_catalog|recent_operations'
            .'|resolve_date_range|list_plots|list_campaigns|plot_info|product_usage'
            .'|irrigation_history|fertilization_history|harvest_history|campaign_compare|data_quality|get_overview'
            .'|usage_count|total_matching|returned_rows|irrigation_count|harvest_count|empty_result_diagnostic'
            .'|campaign_scope|tool_failed|SQLSTATE|invalid input syntax|type uuid'
            .'|DONN[EÉ]ES R[EÉ]ELLES|message syst[èe]me|system message|instruction interne';

        $out = [];
        foreach (preg_split('/(?<=[.!?\n])/u', $text) ?: [$text] as $sentence) {
            if (preg_match('/(?:'.$names.')/iu', $sentence)) continue;
            $out[] = $sentence;
        }
        $stripped = trim(implode('', $out));
        // Never blank the answer out: if every sentence leaked, keep the text
        // and let the validator's `leaks_internals` rule force a rewrite.
        if ($stripped !== '') {
            $text = $stripped;
        }

        // Tidy the holes we just punched.
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+([,.])/u', '$1', $text) ?? $text;
        $text = preg_replace('/\(\s*\)|\[\s*\]/u', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function isOnlyInternals(string $original, string $sanitized): bool
    {
        return trim($original) !== '' && trim($sanitized) === '';
    }

    public function leaksReasoning(string $text): bool
    {
        $lower = mb_strtolower($text);
        $leaks = [
            'we need to infer',
            "let's call",
            'lets call',
            'i will call',
            'i need to call',
            'that seems like a tool call',
            'previous draft',
            'i should use the tool',
            'i will check',
        ];
        
        foreach ($leaks as $leak) {
            if (str_contains($lower, $leak)) {
                return true;
            }
        }
        return false;
    }

    public function createStreamFilter(callable $onDelta): StreamSanitizer
    {
        return new StreamSanitizer($onDelta);
    }
}
