<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\PortailFneDemande;
use Illuminate\Console\Command;

/**
 * La file des relevés que le scraper doit aller chercher.
 *
 * C'est **tout le contrat** entre Selflow et le scraper. Selflow ne sait pas
 * comment le portail est consulté — script lancé à la main, tâche planifiée,
 * navigateur piloté — et n'a pas à le savoir. Il dit quels logins ont besoin
 * d'un relevé frais ; le scraper vient le lui demander.
 *
 * Usage :
 *   php artisan portail-fne:demandes            (tableau lisible)
 *   php artisan portail-fne:demandes --json     (pour le scraper)
 *
 * Le `--json` rend un tableau de logins et rien d'autre :
 *
 *   ["1864699A", "2201455B"]
 *
 * Une file vide rend `[]`, pas une erreur : « rien à relever » est une réponse.
 *
 * La commande **ne ferme aucune demande**. Une demande n'est servie que lorsque
 * `ImportPortailFneService` range un fichier portant ce login. Un scraper qui
 * dit avoir travaillé sans rien déposer laisse donc sa demande ouverte — c'est
 * voulu, et c'est le seul endroit où l'on verra qu'il ne fonctionne plus.
 */
class DemandesPortailFne extends Command
{
    protected $signature = 'portail-fne:demandes
                            {--json : Rendre la seule liste des logins, pour un script}';

    protected $description = 'Liste les relevés du portail FNE attendus par Selflow.';

    public function handle(): int
    {
        $demandes = PortailFneDemande::where('statut', PortailFneDemande::STATUT_EN_ATTENTE)
            ->orderBy('created_at')
            ->get();

        if ($this->option('json')) {
            $this->line(json_encode(
                $demandes->pluck('login')->unique()->values()->all(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        if ($demandes->isEmpty()) {
            $this->info('Aucun relevé en attente.');

            return self::SUCCESS;
        }

        $this->table(
            ['Login', 'Entreprise', 'Motif', 'Demandé le', 'Attend depuis'],
            $demandes->map(fn (PortailFneDemande $d) => [
                $d->login,
                $d->entreprise?->nom ?? '—',
                $d->motif ?? '—',
                $d->created_at?->format('d/m/Y H:i') ?? '—',
                // L'âge et non la seule date : « 03/07 » ne dit rien à qui lit,
                // « 52 jours » dit qu'il y a un problème.
                $d->attenteLisible() . ($d->estEnRetard() ? '  <fg=red>(en retard)</>' : ''),
            ])->all()
        );

        if ($demandes->filter(fn (PortailFneDemande $d) => $d->estEnRetard())->isNotEmpty()) {
            $heures = config('selflow.portail_fne.delai_alerte_heures', 24);
            $this->warn("Des demandes attendent depuis plus de {$heures} h : "
                . 'le scraper ne tourne pas, il dépose ailleurs, ou le login est faux.');
        }

        return self::SUCCESS;
    }
}
