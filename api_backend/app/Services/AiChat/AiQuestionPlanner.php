<?php

declare(strict_types=1);

namespace App\Services\AiChat;

/**
 * Deterministic question → tool-call planner.
 *
 * Free-tier models are unreliable at picking the right function, and when they
 * skip tool-calling entirely the assistant falls back to a data-free answer
 * ("Cette information n'est pas dans l'instantané actuel"). This planner reads
 * the user's last question with plain regexes and returns the tool calls that
 * WILL answer it, so the data is fetched server-side and injected into the
 * prompt as evidence before the model writes a single token.
 *
 * It never replaces the agent loop — the model may still call more tools. It
 * only guarantees that the obvious lookup already happened.
 *
 * Purely read-only: it emits {name, args} pairs for AiToolRegistry.
 */
final class AiQuestionPlanner
{
    public function __construct(
        private readonly NaturalDateParser $dates = new NaturalDateParser(),
    ) {}

    /** Hard cap so a single turn never fans out into a scan of the whole farm. */
    private const MAX_CALLS = 3;

    /**
     * @param  array<int, array{role?: string, content?: string}>  $messages
     * @return array<int, array{name: string, args: array<string, mixed>}>
     */
    public function plan(array $messages): array
    {
        $question = $this->lastUserQuestion($messages);
        if ($question === '') {
            return [];
        }

        $q = $this->normalise($question);
        if ($this->isSmallTalk($q)) {
            return [];
        }

        // Plot / crop / period / product context, resolved once and shared by
        // every call below. The plot may live in an earlier turn ("et le coût ?").
        // The exclusion clause is stripped first, so "vigne sauf P1" does not
        // read P1 as the plot the user is asking about.
        $exclude = $this->extractExclusions($question);
        $stripped = $this->stripExclusionClause($question);
        $plot = $this->extractPlot($stripped) ?? $this->extractPlotFromHistory($messages);
        $crop = $this->extractCrop($q);
        [$from, $to] = $this->extractWindow($question, $q);


        $scope = [];
        if ($plot !== null && $plot !== '') {
            $scope['plot'] = $plot;
        } elseif ($crop !== null) {
            $scope['crop'] = $crop;
        }
        if ($exclude !== []) {
            $scope['exclude_plots'] = $exclude;
        }
        $window = array_filter(
            ['from' => $from, 'to' => $to],
            static fn ($v) => $v !== null,
        );

        $calls = [];
        $add = static function (string $name, array $args) use (&$calls): void {
            foreach ($calls as $existing) {
                if ($existing['name'] === $name) {
                    return;
                }
            }
            $calls[] = ['name' => $name, 'args' => $args];
        };

        // ── Catalog lookups (price / composition). Plot-free by nature. ──
        if ($product = $this->extractCatalogProduct($question, $q)) {
            $add('product_info', ['query' => $product, 'kind' => 'any']);
        }

        // ── Water / irrigation ──
        $wantsWater = $this->has($q, ['eau', 'irrigation', 'irrigations', 'arrosage', 'water', 'm3', 'm³']);
        if ($wantsWater) {
            if ($this->wantsRows($q) || $this->has($q, ['date', 'dates', 'dernieres irrigations', 'derniers arrosages'])) {
                $add('irrigation_history', array_merge($scope, $window, [
                    'order' => $this->wantsLatest($q) ? 'desc' : 'asc',
                    'limit' => $this->extractCount($q) ?? 10,
                ]));
            }
            $add('water_per_ha', array_merge($scope, $window));
        }

        // ── Fertilization / nutrients ──
        $wantsNutrient = $this->has($q, ['unite', 'unites', 'azote', 'nitrogen', 'npk', 'n p k', 'phosphore', 'potasse', 'potassium', 'magnesium', ' mg ']);
        $wantsAminoAcids = $this->has($q, [
            'acides amines', 'acide amine', 'amino', 'aminoacide', 'aa libres',
            'naturamin', 'biostimulant', 'biostimulants', 'hydrolysat', 'peptide',
        ]);
        $wantsFertilization = $this->has($q, ['fertilisation', 'fertilization', 'engrais', 'fertilisant']);
        if ($wantsNutrient) {
            $add('nutrient_per_ha', array_merge($scope, $window, [
                'nutrient' => $this->extractNutrient($q),
            ]));
        }
        // "Acides aminés" describes a product family/ingredient, not an
        // operation type. Naturamin may be catalogued as either a fertilizer
        // or a phytosanitary product, so querying fertilizations alone can
        // truthfully return zero while applications exist in the other log.
        if ($wantsAminoAcids) {
            // Scope-agnostic: with no plot named the tool covers every plot,
            // and returns a per-plot breakdown either way.
            $add('product_usage', array_merge($scope, $window, [
                'query' => $this->extractNamedProduct($question) ?? 'acides amines',
                'order' => $this->wantsLatest($q) ? 'desc' : 'asc',
                'limit' => $this->extractCount($q) ?? 40,
            ]));
        } elseif ($wantsFertilization) {
            $add('fertilization_history', array_merge($scope, $window, array_filter([
                'product' => $this->extractFertilizerProduct($question, $q),
                'order'   => $this->wantsLatest($q) ? 'desc' : 'asc',
                'limit'   => $this->extractCount($q) ?? 15,
            ], static fn ($v) => $v !== null)));
        }

        // ── Phytosanitary treatments ──
        $pest = $this->extractPest($question);
        $wantsTreatment = $pest !== null || $this->has($q, [
            'traitement', 'traitements', 'treatment', 'phytosanitaire', 'pulverisation', 'fongicide', 'insecticide',
        ]);
        if ($wantsTreatment) {
            $add('treatments', array_merge($scope, $window, array_filter([
                'pest'    => $pest,
                'product' => $this->extractPesticideProduct($question, $q),
                'order'   => $this->wantsLatest($q) ? 'desc' : 'asc',
                'limit'   => $this->extractCount($q) ?? 20,
            ], static fn ($v) => $v !== null)));
        }

        // ── Harvest ──
        if ($this->has($q, ['recolte', 'recoltee', 'recoltes', 'harvest', 'vendange', 'rendement', 'yield'])) {
            $add('harvest_history', array_merge($scope, $window, ['limit' => 20]));
        }

        // ── Cost ──
        if ($this->has($q, ['cout', 'couts', 'cost', 'depense', 'depenses', 'charge', 'budget', 'tnd', 'dinar'])) {
            $add('cost_per_ha', array_merge($scope, $window, [
                'type' => $this->extractCostType($q),
            ]));
        }

        // ── Plot identity (surface, culture, cépage) ──
        if ($this->has($q, ['surface', 'superficie', 'hectare', 'combien de ha', 'area', 'cepage', 'variete', 'variety', 'culture'])) {
            $add('plot_info', $scope);
        }

        // Nothing matched but the user clearly named a plot → give the model its
        // identity card rather than letting it answer from thin air.
        if ($calls === [] && isset($scope['plot'])) {
            $add('plot_info', $scope);
        }

        return array_slice($calls, 0, self::MAX_CALLS);
    }

    // ─── Question extraction ────────────────────────────────────────────

    /** @param array<int, array{role?: string, content?: string}> $messages */
    private function lastUserQuestion(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return trim((string) ($messages[$i]['content'] ?? ''));
            }
        }
        return '';
    }

    /** @param array<int, array{role?: string, content?: string}> $messages */
    private function extractPlotFromHistory(array $messages): ?string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $plot = $this->extractPlot($this->stripExclusionClause((string) ($messages[$i]['content'] ?? '')));
            if ($plot !== null && $plot !== '') {
                return $plot;
            }
        }
        return null;
    }

    /** Lowercase, accent-free copy used for keyword matching. */
    private function normalise(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', '’' => "'",
        ]);

        return ' '.preg_replace('/\s+/u', ' ', $s).' ';
    }

    /** @param array<int, string> $needles */
    private function has(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }

    private function isSmallTalk(string $q): bool
    {
        $trimmed = trim($q);
        if (mb_strlen($trimmed) <= 3) {
            return true;
        }

        return (bool) preg_match(
            '/^(bonjour|salut|bonsoir|coucou|hello|hi|hey|merci|thanks|ok|d\'accord|au revoir|bye)\b[\s!.,]*$/u',
            $trimmed,
        );
    }

    private function wantsRows(string $q): bool
    {
        return $this->has($q, ['detail', 'details', 'liste', 'lister', 'releve', 'historique', 'chronolog', 'list', 'breakdown', 'log']);
    }

    private function wantsLatest(string $q): bool
    {
        return $this->has($q, ['dernier', 'derniere', 'derniers', 'dernieres', 'last', 'latest', 'recent', 'plus recent']);
    }

    /** "les 3 dernières irrigations", "les 2 derniers traitements" → 3 / 2. */
    private function extractCount(string $q): ?int
    {
        if (preg_match('/\b(\d{1,2})\s+(?:dernier|derniere|derniers|dernieres|last|premier|premiers)/u', $q, $m) === 1) {
            return max(1, min(40, (int) $m[1]));
        }
        $words = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];
        foreach ($words as $word => $n) {
            if (preg_match('/\b'.$word.'\s+(?:dernier|derniere|derniers|dernieres|last)/u', $q) === 1) {
                return $n;
            }
        }
        if ($this->wantsLatest($q) && ! $this->wantsRows($q)) {
            return preg_match('/\b(derniers|dernieres)\b/u', $q) === 1 ? null : 1;
        }
        return null;
    }

    /**
     * Words that can follow "parcelle" without naming one: "par parcelle ce
     * mois-ci", "quelle parcelle a…", "chaque parcelle". Treating them as a
     * plot name is what turned a farm-wide question into a lookup for a plot
     * called "ce".
     */
    private const PLOT_STOPWORDS = [
        'x', 'y', 'z', 'ce', 'cet', 'cette', 'ces', 'le', 'la', 'les', 'l', 'du', 'de', 'des', 'un', 'une',
        'quelle', 'quelles', 'quel', 'quels', 'chaque', 'toutes', 'tous', 'toute', 'tout', 'et', 'ou', 'a',
        'au', 'aux', 'en', 'par', 'pour', 'sur', 'dans', 'avec', 'sans', 'depuis', 'entre', 'donnee',
        'the', 'each', 'every', 'all', 'this', 'that', 'which', 'what', 'is', 'are', 'has', 'have',
    ];

    private function extractPlot(string $question): ?string
    {
        if (preg_match('/\b(?:parcelle|parcel|plot|bloc|block)s?\s+(?:n[°o]\s*)?([\p{L}\p{N}][\p{L}\p{N}_-]{0,20})/iu', $question, $m) === 1) {
            $candidate = trim($m[1]);
            $norm = strtr(mb_strtolower($candidate), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ù' => 'u', 'ô' => 'o', 'î' => 'i', 'ç' => 'c']);
            if ($candidate !== '' && ! in_array($norm, self::PLOT_STOPWORDS, true)) {
                return $candidate;
            }
            // fall through: maybe the sentence carries a code-shaped name instead
        }
        if (preg_match('/\b([A-Z]{1,3}\s*-?\s*\d{1,4})\b/u', $question, $m) === 1) {
            return preg_replace('/\s+/', '', trim($m[1])) ?: trim($m[1]);
        }
        return null;
    }


    private function extractCrop(string $q): ?string
    {
        foreach (['vigne', 'olivier', 'agrume', 'oranger', 'citron', 'pommier', 'amandier', 'grenadier', 'tomate', 'ble', 'orge'] as $crop) {
            if (str_contains($q, $crop)) {
                return $crop;
            }
        }
        return null;
    }

    /**
     * Regex for an exclusion clause. The name list stops at the first word
     * that cannot be a plot name, so "sauf P1 cette année" excludes P1 and
     * leaves "cette année" to the date parser.
     */
    private const EXCLUSION_RE =
        '/\b(?:sauf|hormis|excepte|excepté|except|exclu(?:re|ant)?|without)\s+(?:la\s+|les\s+|le\s+)?(?:parcelles?\s+)?'
        .'((?:[\p{L}\p{N}][\p{L}\p{N}_-]{0,20})(?:\s*(?:,|\bet\b|\band\b)\s*[\p{L}\p{N}][\p{L}\p{N}_-]{0,20})*)/iu';

    /** "toutes les parcelles de vigne sauf P1" → ["P1"]. */
    private function extractExclusions(string $question): array
    {
        if (preg_match(self::EXCLUSION_RE, $question, $m) !== 1) {
            return [];
        }
        $parts = preg_split('/\s*(?:,|\bet\b|\band\b)\s*/iu', trim($m[1])) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            $norm = mb_strtolower($p);
            if ($p !== '' && mb_strlen($p) <= 24 && ! in_array($norm, self::PLOT_STOPWORDS, true)) {
                $out[] = $p;
            }
        }
        return array_slice($out, 0, 10);
    }

    /** Remove the "sauf …" clause so plot detection ignores excluded names. */
    private function stripExclusionClause(string $question): string
    {
        return (string) preg_replace(self::EXCLUSION_RE, ' ', $question);
    }


    /**
     * Resolve the period the user mentioned into a concrete window.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function extractWindow(string $question, string $q): array
    {
        // Explicit range: "entre le 15/06/2026 et le 30/06/2026", "du X au Y".
        if (preg_match(
            '/\b(?:entre|between|du|de|from)\s+(?:le\s+)?([0-9]{1,4}[\/.\-][0-9]{1,2}[\/.\-][0-9]{2,4})\s*(?:et|au|to|and|jusqu\'au)\s+(?:le\s+)?([0-9]{1,4}[\/.\-][0-9]{1,2}[\/.\-][0-9]{2,4})/iu',
            $question,
            $m,
        ) === 1) {
            $a = $this->toIso($m[1]);
            $b = $this->toIso($m[2]);
            if ($a !== null && $b !== null) {
                return $a <= $b ? [$a, $b] : [$b, $a];
            }
        }

        // "jusqu'à ce jour" / "à ce jour" / "to date" → open start, today end.
        if ($this->has($q, ["jusqu'a ce jour", 'a ce jour', 'jusqu a ce jour', 'to date', 'so far', 'cumul'])) {
            return [null, now()->toDateString()];
        }

        // Single explicit date: "à la date du 12/07/2026".
        if (preg_match('/\b([0-9]{1,2}[\/.\-][0-9]{1,2}[\/.\-][0-9]{2,4})\b/u', $question, $m) === 1) {
            $d = $this->toIso($m[1]);
            if ($d !== null) {
                return [$d, $d];
            }
        }

        // Natural phrases handled by the shared parser (FR + EN).
        foreach ([
            "aujourd'hui", 'aujourd hui', 'today', 'hier', 'yesterday',
            'ce mois-ci', 'ce mois ci', 'ce mois', 'this month', 'mois dernier', 'last month',
            'cette semaine', 'this week', 'semaine derniere', 'last week',
            'cette annee', 'this year', 'annee derniere', 'last year',
            'cette saison', 'this season', 'saison derniere', 'last season',
            'ytd', 'depuis le debut de l\'annee',
        ] as $phrase) {
            if (str_contains($q, $phrase)) {
                $parsed = $this->safeParse($phrase);
                if ($parsed !== null) {
                    return [$parsed['from'] ?? null, $parsed['to'] ?? null];
                }
            }
        }

        // "juillet 2026", "en juin", "Q2 2026".
        if (preg_match(
            '/\b((?:janvier|fevrier|mars|avril|mai|juin|juillet|aout|septembre|octobre|novembre|decembre|january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+\d{4})?)\b/u',
            $q,
            $m,
        ) === 1) {
            $parsed = $this->safeParse(trim($m[1]));
            if ($parsed !== null) {
                return [$parsed['from'] ?? null, $parsed['to'] ?? null];
            }
        }

        return [null, null];
    }

    /** @return array{from?: ?string, to?: ?string}|null */
    private function safeParse(string $phrase): ?array
    {
        try {
            /** @var array{from?: ?string, to?: ?string}|null $parsed */
            $parsed = $this->dates->parse($phrase);
            return $parsed;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Accepts dd/mm/yyyy, dd-mm-yyyy and yyyy-mm-dd. */
    private function toIso(string $raw): ?string
    {
        $parts = preg_split('/[\/.\-]/', trim($raw)) ?: [];
        if (count($parts) !== 3) {
            return null;
        }
        [$a, $b, $c] = array_map('intval', $parts);
        if (strlen($parts[0]) === 4) {
            [$y, $m, $d] = [$a, $b, $c];
        } else {
            [$d, $m, $y] = [$a, $b, $c];
            if ($y < 100) {
                $y += 2000;
            }
        }
        if ($m < 1 || $m > 12 || $d < 1 || $d > 31 || $y < 2000 || $y > 2100) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private function extractNutrient(string $q): string
    {
        $found = [];
        if ($this->has($q, ['azote', 'nitrogen', ' n '])) $found[] = 'n';
        if ($this->has($q, ['phosphore', 'phosphorus'])) $found[] = 'p';
        if ($this->has($q, ['potasse', 'potassium'])) $found[] = 'k';
        if ($this->has($q, ['magnesium', 'magnesie', ' mg '])) $found[] = 'mg';
        if ($this->has($q, ['calcium', ' ca '])) $found[] = 'ca';
        if ($this->has($q, ['soufre', 'sulfur', 'sulphur'])) $found[] = 's';

        return count($found) === 1 ? $found[0] : 'all';

    }

    private function extractCostType(string $q): string
    {
        if ($this->has($q, ['traitement', 'phytosanitaire', 'treatment', 'pesticide'])) {
            return 'phytosanitary';
        }
        if ($this->has($q, ['irrigation', 'eau', 'water'])) {
            return 'irrigation';
        }
        if ($this->has($q, ['engrais', 'fertilisation', 'fertilization'])) {
            return 'fertilization';
        }
        if ($this->has($q, ['recolte', 'harvest', 'main d oeuvre', "main d'oeuvre"])) {
            return 'harvest';
        }
        return 'all';
    }

    private function extractPest(string $question): ?string
    {
        if (preg_match(
            '/\b(mildiou|o[ïi]dium|cicadelle|botrytis|cochenille|acarien|puceron|thrips|tuta|c[ée]ratite|ceratitis|pyrale|carpocapse|black[- ]rot|excoriose|mouche)\b/iu',
            $question,
            $m,
        ) === 1) {
            return mb_strtolower(trim($m[1]));
        }
        return null;
    }

    /**
     * Product name for a price/composition question:
     * "quel est le prix de l'Antéor Flash", "la composition du Biomate".
     */
    private function extractCatalogProduct(string $question, string $q): ?string
    {
        if (! $this->has($q, ['prix', 'price', 'tarif', 'coute', 'composition', 'compose', 'matiere active', 'dosage du produit'])) {
            return null;
        }
        // A composition/price question scoped to a plot is a treatment question.
        if ($this->has($q, ['parcelle', 'plot', 'bloc'])) {
            return null;
        }

        if (preg_match(
            '/\b(?:prix|price|tarif|composition)\s+(?:de\s+la|de\s+l\'|du|de|des|of|for|d\')\s*([\p{L}\p{N}][\p{L}\p{N}\s\'\-\.]{1,40}?)\s*(?:[?.!,]|$)/iu',
            $question,
            $m,
        ) === 1) {
            $name = trim($m[1]);
            return mb_strlen($name) >= 2 ? $name : null;
        }
        return null;
    }

    private function extractFertilizerProduct(string $question, string $q): ?string
    {
        return $this->extractQuotedProduct($question);
    }

    /** "le produit Naturamin contient…" → Naturamin. */
    private function extractNamedProduct(string $question): ?string
    {
        if (preg_match(
            '/\bproduit\s+[«"“\']?([\p{L}\p{N}][\p{L}\p{N}\-\.]{1,60})[»"”\']?/iu',
            $question,
            $m,
        ) === 1) {
            $name = trim($m[1]);
            return mb_strlen($name) >= 2 ? $name : null;
        }

        return $this->extractQuotedProduct($question);
    }

    private function extractPesticideProduct(string $question, string $q): ?string
    {
        return $this->extractQuotedProduct($question);
    }

    /** «Biomate», "Antéor Flash", 'produit X' → the quoted name. */
    private function extractQuotedProduct(string $question): ?string
    {
        if (preg_match('/[«"“\']\s*([\p{L}\p{N}][\p{L}\p{N}\s\'\-\.]{1,40}?)\s*[»"”\']/u', $question, $m) === 1) {
            $name = trim($m[1]);
            if (mb_strlen($name) >= 2 && mb_strtolower($name) !== 'x') {
                return $name;
            }
        }
        return null;
    }
}
