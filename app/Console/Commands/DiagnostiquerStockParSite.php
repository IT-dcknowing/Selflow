<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Console\Command;

/**
 * Les entrées de stock sont-elles restées sur leur point de vente ? —
 * question du 19/10/2026, après le lot 114.
 *
 * Lecture seule. Pour une entreprise : ce que chaque site a reçu, et par qui ;
 * puis les mouvements passés par un membre affecté à un site **sur un autre
 * site** que le sien — ce que le lot 114 empêche désormais, et qui a pu se
 * produire avant lui. Rien n'est déplacé : un mouvement de stock ne se
 * corrige que par un transfert, que le gérant décide.
 *
 *   php artisan selflow:diagnostic-stock-sites "poissonnerie EDEN"
 */
class DiagnostiquerStockParSite extends Command
{
    protected $signature = 'selflow:diagnostic-stock-sites {entreprise : identifiant ou nom}';

    protected $description = 'Entrées de stock par point de vente, et mouvements passés hors du site de leur auteur (lecture seule).';

    public function handle(): int
    {
        $cle = (string) $this->argument('entreprise');
        $entreprise = ctype_digit($cle) ? Entreprise::find((int) $cle) : Entreprise::where('nom', $cle)->first();
        if (!$entreprise) {
            $this->error("Entreprise introuvable : {$cle}");

            return self::FAILURE;
        }

        $sites = PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get()->keyBy('id');
        $this->info("{$entreprise->nom} — {$sites->count()} point(s) de vente");

        $mouvements = MouvementStock::whereIn('point_de_vente_id', $sites->keys())->get(['id', 'produit_id', 'point_de_vente_id', 'type_mouvement', 'sous_type', 'utilisateur_id', 'quantite', 'created_at']);
        $auteurs = Utilisateur::whereIn('id', $mouvements->pluck('utilisateur_id')->filter()->unique())->get()->keyBy('id');

        $lignes = [];
        foreach ($sites as $site) {
            $entrees = $mouvements->where('point_de_vente_id', $site->id)->where('type_mouvement', MouvementStock::ENTREE);
            $lignes[] = [
                $site->nom,
                $entrees->count(),
                rtrim(rtrim(number_format((float) $entrees->sum('quantite'), 3, ',', ' '), '0'), ','),
                $entrees->pluck('utilisateur_id')->filter()->unique()->map(fn ($id) => $auteurs[$id]->email ?? '?')->implode(', ') ?: '—',
            ];
        }
        $this->table(['Point de vente', 'Entrées', 'Quantité reçue', 'Saisies par'], $lignes);

        // Les mouvements d'un membre affecté, passés sur un autre site que le sien.
        $horsSite = $mouvements->filter(function ($m) use ($auteurs) {
            $auteur = $auteurs[$m->utilisateur_id] ?? null;

            // Un transfert depuis son site écrit l'entrée sur le site d'arrivée :
            // c'est voulu, et il n'a pas à être signalé.
            return $auteur && $auteur->estAffecteAUnSite()
                && $m->sous_type !== MouvementStock::TRANSFERT
                && (int) $auteur->point_de_vente_id !== (int) $m->point_de_vente_id;
        });

        if ($horsSite->isEmpty()) {
            $this->info('Aucun mouvement passé par un membre affecté hors de son site : chaque entrée est restée sur son point de vente.');

            return self::SUCCESS;
        }

        $this->warn($horsSite->count() . ' mouvement(s) passé(s) par un membre affecté sur un autre site que le sien :');
        $this->table(
            ['N°', 'Date', 'Type', 'Article', 'Qté', 'Site du mouvement', 'Auteur', 'Son site'],
            $horsSite->map(fn ($m) => [
                $m->id,
                $m->created_at?->format('d/m/Y H:i'),
                $m->type_mouvement . ($m->sous_type ? ' / ' . $m->sous_type : ''),
                $m->produit_id,
                (float) $m->quantite,
                $sites[$m->point_de_vente_id]->nom ?? '?',
                $auteurs[$m->utilisateur_id]->email ?? '?',
                $sites[$auteurs[$m->utilisateur_id]->point_de_vente_id]->nom ?? '?',
            ])->values()->all()
        );
        $this->line('Pour les ramener, faites un transfert de stock depuis l\'écran Stock : rien n\'est déplacé automatiquement.');

        return self::SUCCESS;
    }
}
