<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use RuntimeException;

/**
 * Thrown by a farm tool when the question cannot be answered without guessing.
 *
 * The assistant used to resolve ambiguity silently — a 60%-similar plot name
 * became "the" plot, an unparseable date bound was dropped (widening the
 * window to all-time), a bare year picked whichever campaign covered the most
 * days. Every one of those produces a confident answer about the wrong data,
 * which is worse than no answer at all.
 *
 * `AiToolRegistry::dispatch()` turns this into a `needs_clarification` tool
 * result carrying the candidate options, so the model asks the user one short
 * question instead of inventing an interpretation.
 */
final class AiClarificationNeeded extends RuntimeException
{
    /**
     * @param  string                 $reason   machine-readable code, e.g. `ambiguous_plot`
     * @param  string                 $asked    what the user actually wrote
     * @param  array<int, string>     $options  the candidate interpretations
     * @param  string                 $question the question to put to the user
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $asked,
        public readonly array $options,
        public readonly string $question,
    ) {
        parent::__construct($reason.': '.$asked);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'error'    => 'needs_clarification',
            'reason'   => $this->reason,
            'asked'    => $this->asked,
            'options'  => $this->options,
            'question' => $this->question,
            'hint'     => 'Do NOT guess and do NOT answer with data. Ask the user exactly the question in `question`, '
                .'listing `options` verbatim so they can pick one. Never mention tools or this message.',
        ];
    }
}
