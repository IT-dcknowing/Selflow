<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ramener les encaissements passés au montant dû — retour du 16/10/2026.
 *
 * Avant le plafond posé dans `TresorerieJournal`, le montant tendu par le
 * client entrait tel quel en trésorerie. Cette commande ramène chaque
 * encaissement d'une facture à ce qui restait dû au moment où il a été fait,
 * dans l'ordre où ils ont été passés, puis recalcule le solde cumulé de chaque
 * point de vente. Sans `--appliquer`, elle montre seulement ce qu'elle ferait.
 *
 *   php artisan selflow:corriger-tresorerie "DC-KNOWING CGA"
 *   php artisan selflow:corriger-tresorerie "DC-KNOWING CGA" --appliquer
 */
class CorrigerTresorerie extends Command
{
    protected $signature = 'selflow:corriger-tresorerie {entreprise : identifiant ou nom} {--appliquer : écrire les corrections}';

    protected $description = 'Ramène les encaissements au montant dû des factures et recalcule les soldes (aperçu par défaut).';

    public function handle(): int
    {
        $cle = (string) $this->argument('entreprise');
        $entreprise = ctype_digit($cle) ? Entreprise::find((int) $cle) : Entreprise::where('nom', $cle)->first();
        if (!$entreprise) {
            $this->error("Entreprise introuvable : {$cle}");

            return self::FAILURE;
        }

        $f = fn ($n) => number_format((float) $n, 0, ',', ' ');
        $sites = $entreprise->pointsDeVente()->pluck('id');

        $dus = Vente::withoutGlobalScopes()->whereIn('point_de_vente_id', $sites)
            ->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))
            ->get()
            ->mapWithKeys(fn ($v) => [$v->numero_facture => (float) $v->montant_ttc + (float) $v->montant_autres_taxes + (float) $v->timbre_quittance]);

        $mouvements = TresorerieJournal::withoutGlobalScopes()->whereIn('point_de_vente_id', $sites)
            ->orderBy('created_at')->orderBy('id')->get();

        $corrections = [];
        $cumul = [];
        foreach ($mouvements as $m) {
            $ref = (string) $m->reference_document;
            $entree = (float) $m->montant_entree;
            if ($entree <= 0 || !$dus->has($ref)) {
                continue;
            }
            $reste = max(0, $dus[$ref] - ($cumul[$ref] ?? 0));
            $retenu = round(min($entree, $reste), 2);
            $cumul[$ref] = ($cumul[$ref] ?? 0) + $retenu;
            if ($retenu < $entree) {
                $corrections[$m->id] = [$ref, $entree, $retenu];
            }
        }

        $this->info("{$entreprise->nom} : " . count($corrections) . ' encaissement(s) au-delà du dû.');
        $this->table(['Pièce', 'Encaissé', 'Ramené à', 'Écart'], array_map(
            fn ($c) => [$c[0], $f($c[1]), $f($c[2]), $f($c[1] - $c[2])], array_values($corrections)
        ));
        $this->line('Écart total : ' . $f(array_sum(array_map(fn ($c) => $c[1] - $c[2], $corrections))) . ' F');

        if (!$this->option('appliquer')) {
            $this->warn('Aperçu seulement. Relancez avec --appliquer pour écrire les corrections.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($corrections, $mouvements) {
            foreach ($corrections as $id => [, , $retenu]) {
                TresorerieJournal::withoutGlobalScopes()->whereKey($id)->update(['montant_entree' => $retenu]);
            }

            // Le solde cumulé, recalculé point de vente par point de vente.
            foreach ($mouvements->groupBy('point_de_vente_id') as $lignes) {
                $solde = 0.0;
                foreach ($lignes as $m) {
                    $entree = isset($corrections[$m->id]) ? $corrections[$m->id][2] : (float) $m->montant_entree;
                    $solde += $entree - (float) $m->montant_sortie;
                    TresorerieJournal::withoutGlobalScopes()->whereKey($m->id)->update(['solde_resultat' => round($solde, 2)]);
                }
            }
        });

        $this->info('Corrections écrites ; soldes recalculés.');

        return self::SUCCESS;
    }
}
