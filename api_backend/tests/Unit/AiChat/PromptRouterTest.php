<?php

declare(strict_types=1);

namespace Tests\Unit\AiChat;

use App\Services\AiChat\PromptRouter;
use PHPUnit\Framework\TestCase;

final class PromptRouterTest extends TestCase
{
    public function testMatchesPhytosanitaryAndPlotSectionsForDiseaseTreatmentQueries(): void
    {
        $router = new PromptRouter();

        $result = $router->slim(
            [
                'generated_at' => '2026-07-28T15:00:00+00:00',
                'currency' => 'MAD',
                'units' => ['area' => 'ha'],
                'period' => ['this_month_start' => '2026-07-01'],
                'campaigns' => [],
                'phytosanitary' => [],
                'plot_operations' => [],
            ],
            [
                ['role' => 'user', 'content' => 'Campagnes en cours'],
                ['role' => 'user', 'content' => 'Combien de traitements contre le mildiou a reçu la parcelle P3 ?'],
            ],
        );

        $sections = $result['sections'];

        $this->assertContains('campaigns', $sections);
        $this->assertContains('phytosanitary', $sections);
        $this->assertContains('plot_operations', $sections);
    }

    public function testMatchesPlotContextForFieldLevelMildewTreatmentQuestions(): void
    {
        $router = new PromptRouter();

        $result = $router->slim(
            [
                'generated_at' => '2026-07-28T15:00:00+00:00',
                'currency' => 'MAD',
                'units' => ['area' => 'ha'],
                'period' => ['this_month_start' => '2026-07-01'],
                'phytosanitary' => [],
                'plot_operations' => [],
            ],
            [
                ['role' => 'user', 'content' => 'Quel champ a été traité contre le mildiou ?'],
            ],
        );

        $sections = $result['sections'];

        $this->assertContains('phytosanitary', $sections);
        $this->assertContains('plot_operations', $sections);
    }
}
