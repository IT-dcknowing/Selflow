<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\IntegrationPortailEnArrierePlan;
use Illuminate\Console\Command;

/** Les « Tout intégrer » demandés depuis l'écran, exécutés par le planificateur (23/11/2026). */
class IntegrerPortailEnAttente extends Command
{
    protected $signature = 'portail-fne:integrer-demandes {--secondes=50}';

    protected $description = 'Intègre, par lots, les factures du portail FNE dont l\'intégration a été demandée depuis l\'écran.';

    public function handle(IntegrationPortailEnArrierePlan $arrierePlan): int
    {
        $arrierePlan->traiterLesDemandes((int) $this->option('secondes'));

        return self::SUCCESS;
    }
}
