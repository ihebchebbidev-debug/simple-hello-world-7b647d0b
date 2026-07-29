<?php

declare(strict_types=1);

namespace App\Services\AiChat;

/**
 * Keyword-based context slimmer.
 *
 * The full context JSON built by AiContextBuilder is expensive to send
 * every turn. This router inspects the recent user turns and keeps only
 * the sections whose keywords match — plus a small baseline that must
 * always be present so the assistant can reason about scope.
 */
final class PromptRouter
{
    /** Always-included sections (kept small on purpose). */
    private const BASELINE = ['generated_at', 'currency', 'units', 'period', 'plots'];

    /** Section → keyword list (matched case-insensitively, EN + FR). */
    private const MAP = [
        'dashboard'         => ['dashboard', 'tableau', 'kpi', 'overview', 'résumé', 'resume', 'summary'],
        'water'             => ['water', 'irrigation', 'eau', 'm3', 'm³', 'arrosage'],
        'fertilization'     => ['fertil', 'engrais', 'npk', 'urea', 'ureé', 'nitrog', 'phospho', 'potass'],
        'phytosanitary'     => ['pesticid', 'phyto', 'pest', 'ravageur', 'bioagresseur', 'fongic', 'insectic', 'herbic', 'traitement', 'traitements', 'mildiou', 'mildew', 'maladie', 'disease', 'treatment', 'treat'],
        'harvest'           => ['harvest', 'récolte', 'recolte', 'yield', 'rendement', 'production'],
        'costs'             => ['cost', 'coût', 'cout', 'price', 'prix', 'mad', 'dh', 'dépense', 'depense', 'budget', 'facture'],
        'labor'             => ['labor', 'labour', 'ouvrier', 'main-d', "main d'", 'salaire', 'wage', 'worker'],
        'prices'            => ['price', 'prix', 'tarif', 'rate'],
        'campaigns'         => ['campaign', 'campagne', 'campagnes', 'saison', 'season', 'active', 'en cours', 'ongoing', 'current'],
        'plot_operations'   => ['operation', 'opération', 'operations', 'parcelle', 'parcelles', 'plot', 'field', 'champ', 'terrain', 'parcel', 'bloc'],
        'recent_operations' => ['recent', 'récent', 'dernier', 'latest', 'today', 'aujourd'],
        'catalog'           => ['catalog', 'catalogue', 'produit', 'product', 'inventory', 'stock'],
        'catalog_items'     => ['catalog', 'catalogue', 'produit', 'product', 'inventory', 'stock', 'sku', 'scientific', 'scientifique'],
        'pests'             => ['pest', 'bioagresseur', 'bioagresseurs', 'stress', 'infestation', 'insecte', 'insectes', 'champignon', 'mildiou', 'parasite', 'parasites', 'scientific', 'scientifique'],
        'users'             => ['user', 'utilisateur', 'role', 'admin', 'technicien', 'manager', 'équipe', 'equipe', 'team'],
        'notifications'     => ['notif', 'alerte', 'alert', 'message'],
        'postings'          => ['sync', 'synchro', 'offline', 'posting', 'queue'],
    ];

    /**
     * @param  array<string, mixed>                                    $fullContext
     * @param  array<int, array{role: string, content: string}>        $messages
     * @return array{context: array<string, mixed>, sections: array<int, string>}
     */
    public function slim(array $fullContext, array $messages): array
    {
        $needle = $this->recentUserText($messages);
        if ($needle === '') {
            return ['context' => $fullContext, 'sections' => array_keys($fullContext)];
        }

        $needleLc = mb_strtolower($needle);
        $keep = array_fill_keys(self::BASELINE, true);

        foreach (self::MAP as $section => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($needleLc, mb_strtolower($kw))) {
                    $keep[$section] = true;
                    break;
                }
            }
        }

        if ($this->looksLikeScientificName($needleLc)) {
            $keep['pests'] = true;
            $keep['phytosanitary'] = true;
        }

        // If the router matched nothing beyond baseline, keep the previous
        // behaviour of sending the full context — safer than answering blind.
        $matched = array_diff(array_keys($keep), self::BASELINE);
        if ($matched === []) {
            return ['context' => $fullContext, 'sections' => array_keys($fullContext)];
        }

        $slim = [];
        foreach ($fullContext as $key => $value) {
            if (isset($keep[$key])) {
                $slim[$key] = $value;
            }
        }

        return ['context' => $slim, 'sections' => array_keys($slim)];
    }

    /** @param array<int, array{role: string, content: string}> $messages */
    private function looksLikeScientificName(string $needle): bool
    {
        return preg_match('/\b[A-Z][a-z]+ [a-z]{2,}\b/', $needle) === 1;
    }

    /** @param array<int, array{role: string, content: string}> $messages */
    private function recentUserText(array $messages): string
    {
        // Look at the last 2 user turns — enough to catch follow-ups
        // like "and for that plot?" whose subject lives in the prior turn.
        $collected = [];
        for ($i = count($messages) - 1; $i >= 0 && count($collected) < 2; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $collected[] = (string) ($messages[$i]['content'] ?? '');
            }
        }
        return trim(implode(' ', $collected));
    }
}
