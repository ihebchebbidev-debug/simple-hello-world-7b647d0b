<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Parse bilingual (FR/EN) natural-language date expressions into ISO ranges.
 *
 * Returns null when the input cannot be interpreted. Never throws.
 * Result shape:
 *   [
 *     'from'        => 'YYYY-MM-DD',
 *     'to'          => 'YYYY-MM-DD',
 *     'label'       => string,      // canonical human label
 *     'granularity' => 'day'|'week'|'month'|'quarter'|'year'|'season'|'custom',
 *   ]
 */
final class NaturalDateParser
{
    /** English + French month names → 1..12 */
    private const MONTHS = [
        // English (long + short)
        'january' => 1, 'jan' => 1,
        'february' => 2, 'feb' => 2,
        'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'may' => 5,
        'june' => 6, 'jun' => 6,
        'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
        // French
        'janvier' => 1,
        'fevrier' => 2, 'février' => 2, 'fev' => 2, 'fév' => 2,
        'mars' => 3,
        'avril' => 4, 'avr' => 4,
        'mai' => 5,
        'juin' => 6,
        'juillet' => 7, 'juil' => 7,
        'aout' => 8, 'août' => 8,
        'septembre' => 9,
        'octobre' => 10,
        'novembre' => 11, 'nov.' => 11,
        'decembre' => 12, 'décembre' => 12, 'dec.' => 12, 'déc' => 12,
    ];

    /** Unit words → canonical bucket */
    private const UNITS = [
        'day' => 'day', 'days' => 'day', 'jour' => 'day', 'jours' => 'day', 'j' => 'day',
        'week' => 'week', 'weeks' => 'week', 'semaine' => 'week', 'semaines' => 'week',
        'month' => 'month', 'months' => 'month', 'mois' => 'month',
        'quarter' => 'quarter', 'quarters' => 'quarter', 'trimestre' => 'quarter', 'trimestres' => 'quarter',
        'year' => 'year', 'years' => 'year', 'an' => 'year', 'ans' => 'year', 'annee' => 'year', 'année' => 'year', 'annees' => 'year', 'années' => 'year',
    ];

    public function parse(string $raw, ?Carbon $reference = null): ?array
    {
        $ref = ($reference ?? Carbon::now())->copy();
        $s = $this->normalize($raw);
        if ($s === '') return null;

        // 1. ISO date "YYYY-MM-DD" (single day) or "YYYY-MM"
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            try {
                $d = Carbon::create((int)$m[1], (int)$m[2], (int)$m[3])->startOfDay();
                return $this->pack($d, $d, $d->toDateString(), 'day');
            } catch (Throwable) { return null; }
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $s, $m)) {
            try {
                $d = Carbon::create((int)$m[1], (int)$m[2], 1);
                return $this->pack($d->copy()->startOfMonth(), $d->copy()->endOfMonth(), $d->format('F Y'), 'month');
            } catch (Throwable) { return null; }
        }

        // 2. Simple keywords
        if (in_array($s, ['today', "aujourd'hui", 'aujourdhui', 'ce jour'], true)) {
            return $this->pack($ref->copy()->startOfDay(), $ref->copy()->endOfDay(), 'today', 'day');
        }
        if (in_array($s, ['yesterday', 'hier'], true)) {
            $d = $ref->copy()->subDay();
            return $this->pack($d->copy()->startOfDay(), $d->copy()->endOfDay(), 'yesterday', 'day');
        }
        if (in_array($s, ['tomorrow', 'demain'], true)) {
            $d = $ref->copy()->addDay();
            return $this->pack($d->copy()->startOfDay(), $d->copy()->endOfDay(), 'tomorrow', 'day');
        }
        if (in_array($s, ['ytd', 'year to date', 'depuis le debut de l annee', "depuis le début de l'année", 'cette annee jusqu a aujourd hui'], true)) {
            return $this->pack($ref->copy()->startOfYear(), $ref->copy(), 'year-to-date', 'custom');
        }
        if (in_array($s, ['mtd', 'month to date', 'depuis le debut du mois', 'ce mois jusqu a aujourd hui'], true)) {
            return $this->pack($ref->copy()->startOfMonth(), $ref->copy(), 'month-to-date', 'custom');
        }

        // 3. this/last/next + unit
        if (preg_match('/^(this|current|cette?|ce|ce dernier)\s+(week|semaine|month|mois|quarter|trimestre|year|an|annee|année)$/u', $s, $m)) {
            return $this->relativeUnit($ref, self::UNITS[$m[2]] ?? 'month', 0, 'this');
        }
        if (preg_match('/^(last|previous|dernier|dernière|derniere|precedent|précédent|passe|passé)\s+(week|semaine|month|mois|quarter|trimestre|year|an|annee|année)$/u', $s, $m)) {
            return $this->relativeUnit($ref, self::UNITS[$m[2]] ?? 'month', -1, 'last');
        }
        if (preg_match('/^(la|le)\s+(week|semaine|month|mois|quarter|trimestre|year|an|annee|année)\s+(dernier|dernière|derniere|passe|passé|precedente|précédente)$/u', $s, $m)) {
            return $this->relativeUnit($ref, self::UNITS[$m[2]] ?? 'month', -1, 'last');
        }
        if (preg_match('/^(next|prochain|prochaine)\s+(week|semaine|month|mois|quarter|trimestre|year|an|annee|année)$/u', $s, $m)) {
            return $this->relativeUnit($ref, self::UNITS[$m[2]] ?? 'month', +1, 'next');
        }

        // 4. "last N units" / "N derniers/dernières units" / "past N units"
        if (preg_match('/^(?:last|past|derniers?|dernières?|last past)\s+(\d{1,3})\s+([a-zéûàôùïö]+)$/u', $s, $m)
            || preg_match('/^(\d{1,3})\s+(?:derniers?|dernières?|last|past)\s+([a-zéûàôùïö]+)$/u', $s, $m)
            || preg_match('/^(?:il y a)\s+(\d{1,3})\s+([a-zéûàôùïö]+)$/u', $s, $m)) {
            $n = max(1, (int)$m[1]);
            $unit = self::UNITS[$m[2]] ?? null;
            if ($unit !== null) {
                return $this->lastN($ref, $unit, $n);
            }
        }

        // 5. Quarter: "Q2 2024", "2e trimestre 2024", "trimestre 2 2024"
        if (preg_match('/^q([1-4])(?:\s+(\d{4}))?$/', $s, $m)
            || preg_match('/^([1-4])(?:er|e|ère|eme|ème)?\s+trimestre(?:\s+(\d{4}))?$/', $s, $m)
            || preg_match('/^trimestre\s+([1-4])(?:\s+(\d{4}))?$/', $s, $m)) {
            $q = (int)$m[1];
            $year = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : (int)$ref->year;
            $start = Carbon::create($year, ($q - 1) * 3 + 1, 1)->startOfMonth();
            $end = $start->copy()->addMonths(2)->endOfMonth();
            return $this->pack($start, $end, "Q$q $year", 'quarter');
        }

        // 6. Year alone: "2024"
        if (preg_match('/^(\d{4})$/', $s, $m)) {
            $y = (int)$m[1];
            if ($y >= 1970 && $y <= 2100) {
                $start = Carbon::create($y, 1, 1)->startOfYear();
                return $this->pack($start, $start->copy()->endOfYear(), (string)$y, 'year');
            }
        }

        // 7. Month name with optional year / "last <month>" / "<month> dernier"
        //    Examples: "july", "juillet", "july 2024", "juillet 2024",
        //              "last july", "juillet dernier", "en juillet 2024"
        $stripped = preg_replace('/^(en|in|au mois de|le mois de|month of)\s+/u', '', $s) ?? $s;

        if (preg_match('/^(last|dernier)\s+([a-zéûàôùïö.]+)$/u', $stripped, $m)) {
            $month = self::MONTHS[$m[2]] ?? null;
            if ($month !== null) {
                $year = (int)$ref->year - ((int)$ref->month > $month ? 0 : 1);
                return $this->monthRange($year, $month);
            }
        }
        if (preg_match('/^([a-zéûàôùïö.]+)\s+(dernier|dernière|derniere|passe|passé)$/u', $stripped, $m)) {
            $month = self::MONTHS[$m[1]] ?? null;
            if ($month !== null) {
                $year = (int)$ref->year - ((int)$ref->month > $month ? 0 : 1);
                return $this->monthRange($year, $month);
            }
        }
        if (preg_match('/^([a-zéûàôùïö.]+)(?:\s+(\d{4}))?$/u', $stripped, $m)) {
            $month = self::MONTHS[$m[1]] ?? null;
            if ($month !== null) {
                $year = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : (int)$ref->year;
                return $this->monthRange($year, $month);
            }
        }

        // 8. Season / campaign
        if (preg_match('/^(this|current|cette|la)?\s*(season|saison|campagne)(\s+(en cours|active|actuelle|current))?$/u', $s)) {
            return $this->activeCampaignRange('current season') ?? $this->packYear($ref, 'this year (no active campaign)');
        }
        if (preg_match('/^(last|dernière|derniere|precedente|précédente|passee|passée)\s+(season|saison|campagne)$/u', $s)
            || preg_match('/^(saison|campagne)\s+(derniere|dernière|passee|passée|precedente|précédente)$/u', $s)) {
            return $this->previousCampaignRange() ?? $this->packYear($ref->copy()->subYear(), 'last year (no previous campaign)');
        }

        return null;
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function normalize(string $raw): string
    {
        $s = trim(mb_strtolower($raw));
        // strip surrounding quotes/punctuation
        $s = trim($s, " \t\n\r\0\x0B.,;:!?\"'()[]");
        // collapse whitespace
        $s = (string) preg_replace('/\s+/', ' ', $s);
        return $s;
    }

    /** @return array{from:string,to:string,label:string,granularity:string} */
    private function pack(Carbon $from, Carbon $to, string $label, string $granularity): array
    {
        return [
            'from'        => $from->toDateString(),
            'to'          => $to->toDateString(),
            'label'       => $label,
            'granularity' => $granularity,
        ];
    }

    private function monthRange(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        return $this->pack($start, $start->copy()->endOfMonth(), $start->format('F Y'), 'month');
    }

    private function packYear(Carbon $ref, string $label): array
    {
        return $this->pack($ref->copy()->startOfYear(), $ref->copy()->endOfYear(), $label, 'year');
    }

    private function relativeUnit(Carbon $ref, string $unit, int $offset, string $direction): array
    {
        $labelDir = ['this' => '', 'last' => 'last ', 'next' => 'next '][$direction] ?? '';
        return match ($unit) {
            'week' => (function () use ($ref, $offset, $labelDir) {
                $d = $ref->copy()->addWeeks($offset);
                return $this->pack($d->copy()->startOfWeek(), $d->copy()->endOfWeek(), $labelDir.'week', 'week');
            })(),
            'month' => (function () use ($ref, $offset, $labelDir) {
                $d = $ref->copy()->addMonths($offset);
                return $this->pack($d->copy()->startOfMonth(), $d->copy()->endOfMonth(), $labelDir.$d->format('F Y'), 'month');
            })(),
            'quarter' => (function () use ($ref, $offset, $labelDir) {
                $d = $ref->copy()->addQuarters($offset);
                return $this->pack($d->copy()->startOfQuarter(), $d->copy()->endOfQuarter(), $labelDir.'Q'.$d->quarter.' '.$d->year, 'quarter');
            })(),
            'year' => (function () use ($ref, $offset, $labelDir) {
                $d = $ref->copy()->addYears($offset);
                return $this->pack($d->copy()->startOfYear(), $d->copy()->endOfYear(), $labelDir.$d->year, 'year');
            })(),
            default => $this->pack($ref->copy()->startOfMonth(), $ref->copy()->endOfMonth(), 'this month', 'month'),
        };
    }

    private function lastN(Carbon $ref, string $unit, int $n): array
    {
        $to = $ref->copy();
        $from = match ($unit) {
            'day'     => $to->copy()->subDays($n - 1)->startOfDay(),
            'week'    => $to->copy()->subWeeks($n)->startOfDay(),
            'month'   => $to->copy()->subMonths($n)->startOfDay(),
            'quarter' => $to->copy()->subQuarters($n)->startOfDay(),
            'year'    => $to->copy()->subYears($n)->startOfDay(),
            default   => $to->copy()->subDays($n - 1)->startOfDay(),
        };
        return $this->pack($from, $to, "last $n $unit".($n > 1 ? 's' : ''), 'custom');
    }

    /** @return array{from:string,to:string,label:string,granularity:string}|null */
    private function activeCampaignRange(string $label): ?array
    {
        try {
            if (! Schema::hasTable('campaigns')) return null;
            $row = DB::table('campaigns')->where('is_active', true)->select('name', 'start_date', 'end_date')->first();
            if ($row === null || ! $row->start_date || ! $row->end_date) return null;
            return $this->pack(
                Carbon::parse((string) $row->start_date),
                Carbon::parse((string) $row->end_date),
                trim(($row->name ?? $label).' (campaign)'),
                'season',
            );
        } catch (Throwable) { return null; }
    }

    private function previousCampaignRange(): ?array
    {
        try {
            if (! Schema::hasTable('campaigns')) return null;
            $row = DB::table('campaigns')
                ->where('is_active', false)
                ->orderByDesc('start_date')
                ->select('name', 'start_date', 'end_date')
                ->first();
            if ($row === null || ! $row->start_date || ! $row->end_date) return null;
            return $this->pack(
                Carbon::parse((string) $row->start_date),
                Carbon::parse((string) $row->end_date),
                trim(($row->name ?? 'previous').' (campaign)'),
                'season',
            );
        } catch (Throwable) { return null; }
    }
}