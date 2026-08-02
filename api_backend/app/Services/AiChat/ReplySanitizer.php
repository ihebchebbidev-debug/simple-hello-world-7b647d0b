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
