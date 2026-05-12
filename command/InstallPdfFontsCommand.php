<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\FontMetrics;
use Illuminate\Console\Command;

class InstallPdfFontsCommand extends Command
{
    protected $signature   = 'pdf:fonts:install {--force : Re-register even if .ufm metrics already exist}';
    protected $description = 'Download and register Unicode fonts (Kalpurush, Hind Siliguri) with DomPDF';

    private array $fonts = [
        'HindSiliguri' => [
            ['weight' => 'normal', 'style' => 'normal', 'url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/hindsiliguri/HindSiliguri-Regular.ttf'],
            ['weight' => 'bold',   'style' => 'normal', 'url' => 'https://raw.githubusercontent.com/google/fonts/main/ofl/hindsiliguri/HindSiliguri-Bold.ttf'],
        ],
        // Kalpurush has no separate bold/italic TTFs — register the same file for all
        // variants so DomPDF never falls back to a Latin-only font inside <strong>/<b>/<em>.
        'kalpurush' => [
            ['weight' => 'normal', 'style' => 'normal', 'url' => 'https://raw.githubusercontent.com/potasiyam/Kalpurush/master/release/Kalpurush0.258ship.ttf'],
            ['weight' => 'bold',   'style' => 'normal', 'url' => 'https://raw.githubusercontent.com/potasiyam/Kalpurush/master/release/Kalpurush0.258ship.ttf'],
            ['weight' => 'normal', 'style' => 'italic', 'url' => 'https://raw.githubusercontent.com/potasiyam/Kalpurush/master/release/Kalpurush0.258ship.ttf'],
            ['weight' => 'bold',   'style' => 'italic', 'url' => 'https://raw.githubusercontent.com/potasiyam/Kalpurush/master/release/Kalpurush0.258ship.ttf'],
        ],
    ];

    public function handle(): int
    {
        $fontDir = storage_path('fonts');
        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);
            $this->info("Created directory: {$fontDir}");
        }

        $dompdf = Pdf::getDomPDF();
        $opts   = $dompdf->getOptions();

        $wasRemote = $opts->isRemoteEnabled();
        $opts->set('isRemoteEnabled', true);

        $fontMetrics = new FontMetrics($dompdf->getCanvas(), $opts);

        foreach ($this->fonts as $family => $variants) {
            foreach ($variants as $variant) {
                $this->registerVariant($fontMetrics, $family, $variant, $fontDir);
            }
        }

        $opts->set('isRemoteEnabled', $wasRemote);

        $this->newLine();
        $this->info('Done. Bangla fonts (Kalpurush, Hind Siliguri) are now available for PDF generation.');

        return self::SUCCESS;
    }

    /**
     * Map weight+style → the filename prefix DomPDF uses.
     * Mirrors Dompdf\FontMetrics::getType() logic.
     */
    private function dompdfTypeString(string $weight, string $style): string
    {
        $bold   = $weight === 'bold';
        $italic = in_array($style, ['italic', 'oblique'], true);

        if ($bold && $italic) return 'bold_italic';
        if ($bold)            return 'bold';
        if ($italic)          return 'italic';
        return 'normal';
    }

    private function registerVariant(FontMetrics $fontMetrics, string $family, array $variant, string $fontDir): void
    {
        $typeStr = $this->dompdfTypeString($variant['weight'], $variant['style']);
        $ufmGlob = $fontDir . '/' . strtolower($family) . '_' . $typeStr . '_*.ufm';
        $hasUfm  = ! empty(glob($ufmGlob));

        if ($hasUfm && ! $this->option('force')) {
            $this->line("  <comment>Skipped</comment> {$family} {$variant['weight']}/{$variant['style']} (already registered — use --force to redo)");
            return;
        }

        $this->line("  Registering <info>{$family} {$variant['weight']}/{$variant['style']}</info>...");

        $ok = $fontMetrics->registerFont(
            ['family' => $family, 'weight' => $variant['weight'], 'style' => $variant['style']],
            $variant['url']
        );

        if ($ok) {
            $this->info("  ✓ Registered");
        } else {
            $this->error("  ✗ Registration failed for {$family} {$variant['weight']}/{$variant['style']}");
        }
    }
}
