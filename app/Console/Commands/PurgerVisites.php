<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Visite;
use Illuminate\Console\Command;

/** Les visites de plus de 30 jours ne servent plus (16/10/2026). */
class PurgerVisites extends Command
{
    protected $signature = 'selflow:purger-visites {--jours=30}';
    protected $description = 'Supprime les visites de traçabilité plus vieilles que N jours (30 par défaut).';

    public function handle(): int
    {
        $jours = max(1, (int) $this->option('jours'));
        $efface = Visite::where('cree_le', '<', now()->subDays($jours))->delete();
        $this->info("{$efface} visite(s) purgée(s).");

        return self::SUCCESS;
    }
}
