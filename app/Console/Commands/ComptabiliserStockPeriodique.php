<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\InventairePeriodiqueService;
use Illuminate\Console\Command;

class ComptabiliserStockPeriodique extends Command
{
    protected $signature = 'selflow:comptabiliser-stock-periodique {--max-periodes=12}';

    protected $description = 'Comptabilise les variations de stock selon la périodicité configurée par entreprise';

    public function handle(): int
    {
        $bilan = InventairePeriodiqueService::traiter((int) $this->option('max-periodes'));

        $this->info(sprintf(
            '%d entreprise(s), %d période(s) traitée(s), %d opération(s) comptable(s) créée(s).',
            $bilan['entreprises'],
            $bilan['periodes'],
            $bilan['operations']
        ));

        foreach ($bilan['erreurs'] as $erreur) {
            $this->error($erreur);
        }

        return $bilan['erreurs'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
