<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Confronter chaque fiche de stock à son journal — en lecture seule.
 *
 * ## Pourquoi cette commande
 *
 * Jusqu'au lot 43 (06/10/2026), l'écran Articles & stock ÉCRIVAIT en base :
 * afficher « Tous les sites » posait la somme des sites dans la fiche du site
 * actif, sans mouvement au journal. Chaque coup d'œil a pu gonfler un stock.
 *
 * Le journal, lui, n'a pas été touché : chaque mouvement porte `stock_apres`,
 * la quantité de la fiche juste après lui. Une fiche saine vaut le
 * `stock_apres` de son dernier mouvement. Celles qui s'en écartent sont
 * listées ici.
 *
 * ## Ce qu'elle ne fait pas
 *
 * Elle ne corrige rien. Une correction passe par l'inventaire physique, qui
 * écrit l'écart au journal, le date et le signe — et recalcule le CUMP (Coût
 * Unitaire Moyen Pondéré). Réécrire la fiche d'ici reproduirait exactement le
 * défaut qu'elle sert à trouver.
 */
class VerifierFichesDeStock extends Command
{
    protected $signature = 'selflow:verifier-stocks {--entreprise= : Limiter à une entreprise}';

    protected $description = "Liste les fiches de stock qui s'écartent de leur journal de mouvements (lecture seule)";

    public function handle(): int
    {
        $derniers = MouvementStock::query()
            ->select('produit_id', 'point_de_vente_id', DB::raw('MAX(id) as dernier_id'))
            ->groupBy('produit_id', 'point_de_vente_id');

        $ecarts = Stock::query()
            ->joinSub($derniers, 'd', fn ($j) => $j->on('d.produit_id', '=', 'stocks.produit_id')
                ->on('d.point_de_vente_id', '=', 'stocks.point_de_vente_id'))
            ->join('mouvements_stock as m', 'm.id', '=', 'd.dernier_id')
            ->join('produits as p', 'p.id', '=', 'stocks.produit_id')
            ->join('points_de_vente as s', 's.id', '=', 'stocks.point_de_vente_id')
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('p.entreprise_id', $id))
            ->whereRaw('ABS(stocks.quantite_disponible - m.stock_apres) > 0.0005')
            ->orderBy('p.entreprise_id')
            ->get([
                'p.entreprise_id', 'p.reference', 'p.nom', 's.nom as site',
                'stocks.quantite_disponible as fiche', 'm.stock_apres as journal',
            ]);

        if ($ecarts->isEmpty()) {
            $this->info('Aucune fiche ne s’écarte de son journal.');

            return self::SUCCESS;
        }

        $this->warn($ecarts->count() . ' fiche(s) s’écartent de leur journal. À corriger par un inventaire physique, jamais à la main :');
        $this->table(
            ['Entreprise', 'Référence', 'Article', 'Site', 'Fiche', 'Journal', 'Écart'],
            $ecarts->map(fn ($e) => [
                $e->entreprise_id, $e->reference, $e->nom, $e->site,
                (float) $e->fiche, (float) $e->journal, round((float) $e->fiche - (float) $e->journal, 3),
            ])->all()
        );

        return self::FAILURE;
    }
}
