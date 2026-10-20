<?php

namespace App\Console\Commands;

use App\Jobs\EffacerEntreprise;
use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Console\Command;

/**
 * Effacer tout de suite les entreprises de la corbeille, depuis le terminal
 * (20/10/2026) : aucune limite de temps de navigateur ni de proxy. Le premier
 * passage met aussi en cache le graphe de la base, ce qui rend les
 * effacements suivants depuis l'écran presque instantanés.
 *
 *   php artisan selflow:vider-corbeille            # toute la corbeille
 *   php artisan selflow:vider-corbeille 42         # une entreprise
 */
class ViderCorbeille extends Command
{
    protected $signature = 'selflow:vider-corbeille {entreprise? : identifiant ou nom ; toute la corbeille si absent}';

    protected $description = 'Efface définitivement, tout de suite, les entreprises de la corbeille.';

    public function handle(): int
    {
        $cle = $this->argument('entreprise');
        $requete = Entreprise::enCorbeille();
        if ($cle !== null) {
            $requete->where(fn ($q) => ctype_digit((string) $cle) ? $q->whereKey((int) $cle) : $q->where('nom', $cle));
        }
        $entreprises = $requete->get(['id', 'nom']);

        if ($entreprises->isEmpty()) {
            $this->info('Rien à effacer : la corbeille est vide' . ($cle !== null ? ' pour « ' . $cle . ' »' : '') . '.');

            return self::SUCCESS;
        }

        foreach ($entreprises as $e) {
            $debut = microtime(true);
            (new EffacerEntreprise((int) $e->id))->handle();
            $this->line(sprintf('« %s » effacée en %.1f s.', $e->nom, microtime(true) - $debut));
        }

        return self::SUCCESS;
    }
}
