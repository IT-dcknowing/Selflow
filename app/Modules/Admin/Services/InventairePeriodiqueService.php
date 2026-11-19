<?php

namespace App\Modules\Admin\Services;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\Produit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Regroupe les mouvements de stock en écritures comptables à la périodicité
 * choisie par l'entreprise.
 *
 * - réglage désactivé : aucun mouvement de stock ne crée d'écriture comptable ;
 * - quotidien : une opération agrégée par site et par journée ;
 * - périodique : une opération agrégée par site à chaque échéance de N mois.
 *
 * Les quantités et coûts restent ceux du journal de stock. Le service ne
 * modifie jamais les mouvements, les quantités, le CUMP ou les montants.
 */
class InventairePeriodiqueService
{
    /**
     * Traite les périodes arrivées à échéance pour les entreprises configurées.
     *
     * @return array{entreprises:int, periodes:int, operations:int, erreurs:array<int,string>}
     */
    public static function traiter(int $maximumPeriodesParEntreprise = 12): array
    {
        $bilan = ['entreprises' => 0, 'periodes' => 0, 'operations' => 0, 'erreurs' => []];

        Entreprise::query()
            ->where('comptabilite_stock_active', true)
            ->whereNotNull('comptabilite_stock_date_debut')
            ->whereNotNull('comptabilite_stock_date_fin')
            ->orderBy('id')
            ->chunkById(100, function ($entreprises) use (&$bilan, $maximumPeriodesParEntreprise) {
                foreach ($entreprises as $entreprise) {
                    $bilan['entreprises']++;

                    try {
                        $fait = self::traiterEntreprise($entreprise, $maximumPeriodesParEntreprise);
                        $bilan['periodes'] += $fait['periodes'];
                        $bilan['operations'] += $fait['operations'];
                    } catch (\Throwable $e) {
                        $bilan['erreurs'][] = "Entreprise #{$entreprise->id} : " . $e->getMessage();
                        Log::error('Comptabilisation périodique du stock impossible', [
                            'entreprise_id' => $entreprise->id,
                            'erreur' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $bilan;
    }

    /**
     * Traite les périodes échues d'une entreprise, sans jamais les comptabiliser deux fois.
     *
     * @return array{periodes:int,operations:int}
     */
    public static function traiterEntreprise(Entreprise $entreprise, int $maximum = 12): array
    {
        if (!$entreprise->comptabilite_stock_active
            || !$entreprise->comptabilite_stock_date_debut
            || !$entreprise->comptabilite_stock_date_fin) {
            return ['periodes' => 0, 'operations' => 0];
        }

        $debutConfiguration = Carbon::parse($entreprise->comptabilite_stock_date_debut)->startOfDay();
        $finConfiguration = Carbon::parse($entreprise->comptabilite_stock_date_fin)->startOfDay();
        $quotidien = (bool) $entreprise->comptabilite_stock_quotidienne;
        $mois = max(1, min(12, (int) ($entreprise->comptabilite_stock_periode_mois ?: 1)));
        $fait = ['periodes' => 0, 'operations' => 0];

        for ($i = 0; $i < $maximum; $i++) {
            $derniereFin = DB::table('stock_comptabilites_periodiques')
                ->where('entreprise_id', $entreprise->id)
                ->max('date_fin');

            $debut = $derniereFin
                ? Carbon::parse($derniereFin)->addDay()->startOfDay()
                : $debutConfiguration->copy();

            if ($debut->lt($debutConfiguration)) {
                $debut = $debutConfiguration->copy();
            }

            if ($debut->gt($finConfiguration) || $debut->gt(now()->startOfDay())) {
                break;
            }

            $fin = $quotidien
                ? $debut->copy()
                : $debut->copy()->addMonthsNoOverflow($mois)->subDay()->startOfDay();

            if ($fin->gt($finConfiguration)) {
                $fin = $finConfiguration->copy();
            }

            // Une période n'est comptabilisée qu'une fois qu'elle est terminée.
            if ($fin->gt(now()->startOfDay())) {
                break;
            }

            $operations = self::comptabiliserPeriode($entreprise, $debut, $fin);
            $fait['periodes']++;
            $fait['operations'] += $operations;
        }

        return $fait;
    }

    private static function comptabiliserPeriode(Entreprise $entreprise, Carbon $debut, Carbon $fin): int
    {
        return DB::transaction(function () use ($entreprise, $debut, $fin) {
            // Verrouiller l'entreprise évite que deux passages du planificateur
            // ne créent simultanément la même période.
            Entreprise::query()->whereKey($entreprise->id)->lockForUpdate()->firstOrFail();

            $existe = DB::table('stock_comptabilites_periodiques')
                ->where('entreprise_id', $entreprise->id)
                ->whereDate('date_debut', $debut->toDateString())
                ->whereDate('date_fin', $fin->toDateString())
                ->exists();

            if ($existe) {
                return 0;
            }

            $mouvements = MouvementStock::query()
                ->with('produit')
                ->whereHas('produit', fn ($q) => $q->where('entreprise_id', $entreprise->id))
                ->whereDate('created_at', '>=', $debut->toDateString())
                ->whereDate('created_at', '<=', $fin->toDateString())
                ->orderBy('id')
                ->get();

            // Une variation nette par site et par paire de comptes. Les entrées
            // augmentent le stock ; les sorties le diminuent.
            $groupes = [];

            foreach ($mouvements as $mouvement) {
                $produit = $mouvement->produit;

                if (!$produit || !$produit->estStockable()) {
                    continue;
                }

                if (!in_array($mouvement->type_mouvement, [MouvementStock::ENTREE, MouvementStock::SORTIE], true)) {
                    continue;
                }

                $compteStock = ImputationService::compteStock($produit);
                $compteVariation = ImputationService::compteVariation($produit);

                if (!$compteStock || !$compteVariation) {
                    throw new RuntimeException(
                        "Comptes de stock ou de variation manquants pour l'article {$produit->reference} ({$produit->nom})."
                    );
                }

                $valeur = round((float) $mouvement->quantite * (float) ($mouvement->cout_unitaire ?? 0), 2);
                if ($valeur <= 0) {
                    continue;
                }

                $siteId = (int) $mouvement->point_de_vente_id;
                $cle = $siteId . '|' . $compteStock . '|' . $compteVariation;

                if (!isset($groupes[$cle])) {
                    $groupes[$cle] = [
                        'site_id' => $siteId,
                        'compte_stock' => $compteStock,
                        'compte_variation' => $compteVariation,
                        'variation_nette' => 0.0,
                        'produits' => [],
                    ];
                }

                $sens = $mouvement->type_mouvement === MouvementStock::ENTREE ? 1 : -1;
                $groupes[$cle]['variation_nette'] += $sens * $valeur;
                $groupes[$cle]['produits'][$produit->id] = $produit->nom;
            }

            // Dans Selflow, le journal OD est le journal de type « Général ».
            // Préférer les journaux reçus de COMPTAFLOW lorsqu'ils existent.
            $journal = CodeJournal::obtenirJournauxPrioritaires($entreprise->id)
                ->where('type', 'Général')
                ->whereNull('archive_le')
                ->sortBy('code')
                ->first()?->code ?? 'OD';

            $operationsCreees = 0;
            $parSite = [];

            foreach ($groupes as $groupe) {
                $net = round((float) $groupe['variation_nette'], 2);
                if (abs($net) < 0.01) {
                    continue;
                }
                $parSite[$groupe['site_id']][] = $groupe;
            }

            foreach ($parSite as $siteId => $lignes) {
                $reference = 'STK-' . $debut->format('Ymd') . '-' . $fin->format('Ymd') . '-PDV' . $siteId;
                $operation = Operation::creer(
                    $entreprise->id,
                    (int) $siteId,
                    $fin->toDateString(),
                    'stock_periodique',
                    $journal,
                    $reference,
                    'Variation de stock du ' . $debut->format('d/m/Y') . ' au ' . $fin->format('d/m/Y')
                );

                $totalDebit = 0.0;
                $totalCredit = 0.0;

                foreach ($lignes as $groupe) {
                    $net = round((float) $groupe['variation_nette'], 2);
                    $montant = abs($net);
                    $entreeNette = $net > 0;
                    $compteDebit = $entreeNette ? $groupe['compte_stock'] : $groupe['compte_variation'];
                    $compteCredit = $entreeNette ? $groupe['compte_variation'] : $groupe['compte_stock'];
                    $libelle = 'Variation de stock — ' . count($groupe['produits']) . ' article(s)';

                    EcritureComptable::create([
                        'operation_id' => $operation->id,
                        'entreprise_id' => $entreprise->id,
                        'point_de_vente_id' => (int) $siteId,
                        'date_ecriture' => $fin->toDateString(),
                        'libelle' => $libelle,
                        'reference_document' => $reference,
                        'code_journal' => $journal,
                        'compte_debit' => $compteDebit,
                        'compte_credit' => null,
                        'debit' => $montant,
                        'credit' => 0,
                    ]);

                    EcritureComptable::create([
                        'operation_id' => $operation->id,
                        'entreprise_id' => $entreprise->id,
                        'point_de_vente_id' => (int) $siteId,
                        'date_ecriture' => $fin->toDateString(),
                        'libelle' => $libelle,
                        'reference_document' => $reference,
                        'code_journal' => $journal,
                        'compte_debit' => null,
                        'compte_credit' => $compteCredit,
                        'debit' => 0,
                        'credit' => $montant,
                    ]);

                    $totalDebit += $montant;
                    $totalCredit += $montant;
                }

                $solde = round($totalDebit - $totalCredit, 2);
                Operation::withoutGlobalScopes()->whereKey($operation->id)->update([
                    'solde_equilibre' => $solde,
                    'est_equilibree' => abs($solde) < 0.01,
                ]);

                if ($solde != 0.0) {
                    throw new RuntimeException("L'opération de stock {$reference} n'est pas équilibrée.");
                }

                if (($entreprise->comptaflow_mode_deversement ?? 'manuel') === 'automatique') {
                    DeverserOperationComptaflow::dispatch($operation->id)->afterCommit();
                }

                $operationsCreees++;
            }

            DB::table('stock_comptabilites_periodiques')->insert([
                'entreprise_id' => $entreprise->id,
                'date_debut' => $debut->toDateString(),
                'date_fin' => $fin->toDateString(),
                'operations_creees' => $operationsCreees,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $operationsCreees;
        }, 5);
    }
}
