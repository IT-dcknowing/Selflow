<?php

namespace App\Console\Commands;

use App\Jobs\EffacerEntreprise;
use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Console\Command;

/**
 * Effacer les entreprises en corbeille depuis plus de 30 jours (16/10/2026).
 *
 * L'effacement part en file, une entreprise à la fois : même travail que
 * « Vider la corbeille », sans bloquer, et rejouable si une suppression échoue.
 */
class PurgerCorbeille extends Command
{
    protected $signature = 'selflow:purger-corbeille';

    protected $description = 'Efface définitivement les entreprises en corbeille depuis plus de ' . Entreprise::JOURS_EN_CORBEILLE . ' jours.';

    public function handle(): int
    {
        $limite = now()->subDays(Entreprise::JOURS_EN_CORBEILLE);

        $ids = Entreprise::enCorbeille()
            ->where('corbeille_le', '<=', $limite)
            ->pluck('id');

        foreach ($ids as $id) {
            EffacerEntreprise::dispatch($id);
        }

        $this->info($ids->count() . ' entreprise(s) de la corbeille mises en file pour effacement définitif.');

        return self::SUCCESS;
    }
}
