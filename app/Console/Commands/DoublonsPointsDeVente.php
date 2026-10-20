<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\SuppressionEntrepriseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Les points de vente en double (20/10/2026) — « GEUDE AYA JAQUELINE » et
 * « GUEDE AYA JACQUELINE ».
 *
 * Sans option : la liste des paires au nom identique ou très proche, avec ce
 * que chacun porte. Rien n'est modifié.
 *
 * `--fusionner=<doublon> --vers=<cible>` : tout ce que porte le doublon passe
 * sur la cible — ventes, achats, trésorerie, personnel, mouvements — et les
 * fiches de stock s'additionnent (CUMP pondéré). Puis le doublon disparaît.
 * Refusé si le doublon porte une pièce **normalisée** : elle a été certifiée
 * sous son nom, la DGI la connaît ainsi. Sans `--appliquer`, on montre.
 *
 *   php artisan selflow:doublons-points-de-vente "poissonnerie LA GRACE"
 *   php artisan selflow:doublons-points-de-vente "poissonnerie LA GRACE" --fusionner=12 --vers=15 --appliquer
 */
class DoublonsPointsDeVente extends Command
{
    protected $signature = 'selflow:doublons-points-de-vente {entreprise? : identifiant ou nom ; toutes si absent}
        {--fusionner= : identifiant du point de vente en double}
        {--vers= : identifiant du point de vente à garder}
        {--appliquer : écrire la fusion}';

    protected $description = 'Repère les points de vente en double, et fusionne un doublon dans le bon site.';

    public function handle(): int
    {
        if ($this->option('fusionner')) {
            return $this->fusionner((int) $this->option('fusionner'), (int) $this->option('vers'));
        }

        $cle = $this->argument('entreprise');
        $sites = PointDeVente::query()
            ->when($cle !== null, fn ($q) => ctype_digit((string) $cle)
                ? $q->where('entreprise_id', (int) $cle)
                : $q->whereHas('entreprise', fn ($e) => $e->where('nom', $cle)))
            ->orderBy('entreprise_id')->orderBy('id')->get();

        $paires = [];
        foreach ($sites->groupBy('entreprise_id') as $deLEntreprise) {
            foreach ($deLEntreprise as $site) {
                $proche = PointDeVente::procheDe((int) $site->entreprise_id, (string) $site->nom, (int) $site->id);
                if ($proche && $proche->id > $site->id) {
                    $paires[] = [$site, $proche];
                }
            }
        }

        if ($paires === []) {
            $this->info('Aucun point de vente en double.');

            return self::SUCCESS;
        }

        $lignes = [];
        foreach ($paires as [$a, $b]) {
            foreach ([$a, $b] as $s) {
                $lignes[] = [$s->id, $s->entreprise?->nom, $s->nom, $s->etablissement_fne_id ? 'oui' : 'non',
                    $this->compter(Vente::class, $s->id), $this->compter(Vente::class, $s->id, true),
                    $this->compter(Achat::class, $s->id), Stock::where('point_de_vente_id', $s->id)->where('quantite_disponible', '!=', 0)->count()];
            }
            $lignes[] = ['', '', '', '', '', '', '', ''];
        }
        $this->table(['Id', 'Entreprise', 'Point de vente', 'Déclaré au portail', 'Ventes', 'dont normalisées', 'Achats', 'Articles en stock'], $lignes);
        $this->line('Pour fusionner : --fusionner=<id du doublon> --vers=<id à garder> (de préférence celui déclaré au portail), puis --appliquer.');

        return self::SUCCESS;
    }

    private function compter(string $modele, int $site, bool $normalisees = false): int
    {
        return $modele::withoutGlobalScopes()->where('point_de_vente_id', $site)
            ->when($normalisees, fn ($q) => $q->where('normalise', true))->count();
    }

    private function fusionner(int $doublonId, int $cibleId): int
    {
        $doublon = PointDeVente::find($doublonId);
        $cible = PointDeVente::find($cibleId);

        if (!$doublon || !$cible || $doublon->id === $cible->id || $doublon->entreprise_id !== $cible->entreprise_id) {
            $this->error('Deux points de vente distincts de la même entreprise sont attendus (--fusionner et --vers).');

            return self::FAILURE;
        }

        $certifiees = $this->compter(Vente::class, $doublon->id, true) + $this->compter(Achat::class, $doublon->id, true);
        if ($certifiees > 0) {
            $this->error("« {$doublon->nom} » porte {$certifiees} pièce(s) normalisée(s) : certifiées sous ce nom, elles ne changent pas de point de vente. Fusion refusée.");

            return self::FAILURE;
        }

        $colonnes = array_values(array_filter(
            SuppressionEntrepriseService::colonnesVers('points_de_vente'),
            fn ($c) => $c[0] !== 'stocks' && $c[0] !== 'points_de_vente'
        ));

        $this->info("« {$doublon->nom} » (#{$doublon->id}) → « {$cible->nom} » (#{$cible->id})");
        $plan = [];
        foreach ($colonnes as [$table, $colonne]) {
            $n = DB::table($table)->where($colonne, $doublon->id)->count();
            if ($n > 0) {
                $plan[] = [$table . '.' . $colonne, $n];
            }
        }
        $fiches = Stock::where('point_de_vente_id', $doublon->id)->count();
        $plan[] = ['stocks (fiches additionnées)', $fiches];
        $this->table(['Colonne', 'Lignes'], $plan);

        if (!$this->option('appliquer')) {
            $this->line('Rien n\'est écrit : relancez avec --appliquer.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($doublon, $cible, $colonnes) {
            foreach (Stock::where('point_de_vente_id', $doublon->id)->get() as $fiche) {
                $garde = Stock::where('point_de_vente_id', $cible->id)->where('produit_id', $fiche->produit_id)->lockForUpdate()->first();
                if (!$garde) {
                    $fiche->forceFill(['point_de_vente_id' => $cible->id])->save();
                    continue;
                }
                $qa = (float) $garde->quantite_disponible;
                $qb = (float) $fiche->quantite_disponible;
                $total = $qa + $qb;
                $garde->forceFill([
                    'quantite_disponible' => $total,
                    'cump' => $total > 0 ? round(($qa * (float) $garde->cump + $qb * (float) $fiche->cump) / $total, 4) : $garde->cump,
                    'reference' => $garde->reference || $fiche->reference,
                ])->save();
                $fiche->delete();
            }

            foreach ($colonnes as [$table, $colonne]) {
                DB::table($table)->where($colonne, $doublon->id)->update([$colonne => $cible->id]);
            }

            $doublon->delete();
        });

        \App\Modules\Admin\Services\CacheService::invaliderPointsDeVente((int) $cible->entreprise_id);
        $this->info('Fusion faite.');

        return self::SUCCESS;
    }
}
