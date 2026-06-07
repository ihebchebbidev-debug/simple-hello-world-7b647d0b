<?php

/**
 * Shared filter validation for all /reports/* endpoints.
 *
 * Accepts both the plural array form used by the dashboard
 * (plot_ids[] + date_from/date_to) and the singular aliases documented
 * in the spec/milestones (plot_id + from/to). Singular inputs are
 * normalised into the plural shape before validation so the controllers
 * only ever read one canonical key set.
 */

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

final class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise singular aliases (plot_id, from, to) into the canonical
     * plural/underscored keys (plot_ids[], date_from, date_to) so the
     * rest of the stack has exactly one shape to deal with.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        // plot_id  →  plot_ids[]
        if (! $this->has('plot_ids') && $this->filled('plot_id')) {
            $plotId = $this->input('plot_id');
            $merge['plot_ids'] = is_array($plotId) ? array_values($plotId) : [$plotId];
        }

        // from  →  date_from
        if (! $this->has('date_from') && $this->filled('from')) {
            $merge['date_from'] = $this->input('from');
        }

        // to  →  date_to
        if (! $this->has('date_to') && $this->filled('to')) {
            $merge['date_to'] = $this->input('to');
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plot_ids'    => ['sometimes', 'array'],
            'plot_ids.*'  => ['uuid', 'exists:plots,id'],
            'campaign_id' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,id'],
            'date_from'   => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d'],
            'date_to'     => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'q'           => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
