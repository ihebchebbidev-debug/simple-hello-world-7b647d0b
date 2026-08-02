<?php

declare(strict_types=1);

namespace App\Services\AiChat;

final class StreamSanitizer
{
    private string $buffer = '';

    /**
     * @var callable
     */
    private $onDelta;

    public function __construct(callable $onDelta)
    {
        $this->onDelta = $onDelta;
    }

    public function __invoke(string $delta): void
    {
        $this->buffer .= $delta;
        
        // Remove completed tags from buffer
        $this->buffer = preg_replace('/<tool_call>.*?<\/tool_call>/is', '', $this->buffer) ?? $this->buffer;
        $this->buffer = preg_replace('/<function(?:=[^>]*)?>.*?<\/function>/is', '', $this->buffer) ?? $this->buffer;
        $this->buffer = preg_replace('/<parameter(?:=[^>]*)?>.*?<\/parameter>/is', '', $this->buffer) ?? $this->buffer;
        $this->buffer = preg_replace('/<thought>.*?<\/thought>/is', '', $this->buffer) ?? $this->buffer;
        
        // Find if we have an unclosed potential tag
        $lastOpen = strrpos($this->buffer, '<');
        
        if ($lastOpen !== false) {
            $holdIndex = false;
            
            // Check for unclosed tag blocks that have already opened their tag fully
            if (preg_match('/<(?:tool_call|function|parameter|thought)[^>]*>.*$/is', $this->buffer, $matches, PREG_OFFSET_CAPTURE)) {
                $holdIndex = $matches[0][1];
            } else {
                // Check for partial opening tags
                if (preg_match('/<(?:t(?:o(?:o(?:l(?:_(?:c(?:a(?:l(?:l)?)?)?)?)?)?)?)?|f(?:u(?:n(?:c(?:t(?:i(?:o(?:n)?)?)?)?)?)?)?|p(?:a(?:r(?:a(?:m(?:e(?:t(?:e(?:r)?)?)?)?)?)?)?)?|t(?:h(?:o(?:u(?:g(?:h(?:t)?)?)?)?)?)?)?$/i', $this->buffer, $matches, PREG_OFFSET_CAPTURE)) {
                    $holdIndex = $matches[0][1];
                }
            }
            
            if ($holdIndex !== false) {
                // Emit up to holdIndex
                $safe = substr($this->buffer, 0, (int)$holdIndex);
                if ($safe !== '') {
                    ($this->onDelta)($safe);
                }
                $this->buffer = substr($this->buffer, (int)$holdIndex);
                return;
            }
        }
        
        // No unclosed tags, emit everything
        if ($this->buffer !== '') {
            ($this->onDelta)($this->buffer);
            $this->buffer = '';
        }
    }

    public function flush(): void
    {
        if ($this->buffer !== '') {
            // Strip any incomplete tags just in case
            $this->buffer = preg_replace('/<(?:tool_call|function|parameter|thought)[^>]*>.*$/is', '', $this->buffer) ?? $this->buffer;
            
            if ($this->buffer !== '') {
                ($this->onDelta)($this->buffer);
                $this->buffer = '';
            }
        }
    }
}
