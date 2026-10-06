<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La facture d'achat reçue de la DGI passe en écriture.
 *
 * ## La règle, et pourquoi elle a changé
 *
 * Jusqu'au 05/10/2026, une facture relevée sur le portail n'était qu'un objet
 * de rapprochement : l'écriture venait de l'achat saisi dans Selflow, et une
 * facture non rapprochée ne produisait rien. Le propriétaire l'a renversé le
 * 06/10/2026 : la plupart des factures fournisseur arrivent **par la DGI**, et
 * c'est elles que la comptabilité doit porter en priorité. Les achats saisis à
 * la main sont surtout les charges qu'aucun fournisseur ne normalise.
 *
 * Trois sources, donc, et chacune passe en écriture **une fois** :
 *
 * | Pièce | Qui porte l'écriture |
 * |---|---|
 * | Facture reçue du portail, sans achat en face | **ce service** |
 * | Facture reçue rattachée à un achat saisi | l'achat — il l'avait déjà portée |
 * | BAPA (émis par nous, certifié par la DGI) | l'achat — le relevé qui le ramène est ignoré |
 * | Achat saisi | `ComptabiliteService::genererEcrituresAchat()`, comme avant |
 *
 * ## Ne jamais compter deux fois
 *
 * `synchroniser()` ne pose rien : il **constate** ce qui devrait être, et
 * aligne. Une facture qui doit être portée et ne l'est pas est passée ; une
 * facture portée qui ne doit plus l'être — rattachée après coup à un achat,
 * écartée, reconnue comme notre propre BAPA — est **contre-passée** plutôt
 * qu'effacée. L'opération a pu partir chez Comptaflow : la supprimer ici ne la
 * supprimerait pas là-bas, et les deux livres divergeraient sans que rien ne le
 * dise. Une contre-passation part comme le reste.
 *
 * Le relevé redépose les mêmes factures chaque heure : `operation_id` dit
 * qu'elle est déjà passée, et rien ne se répète.
 */
class EcritureFactureRecueService
{
    public const TYPE_OPERATION  = 'FactureAchatRecue';
    public const TYPE_ANNULATION = 'AnnulationFactureAchatRecue';

    /**
     * Aligner toutes les factures reçues d'une entreprise.
     *
     * @return array{passees: int, contre_passees: int}
     */
    public static function pourEntreprise(int $entrepriseId): array
    {
        $bilan = ['passees' => 0, 'contre_passees' => 0];

        PortailFneFactureRecue::where('entreprise_id', $entrepriseId)
            ->with('lignes')
            ->orderBy('id')
            ->each(function (PortailFneFactureRecue $facture) use (&$bilan) {
                $fait = self::synchroniser($facture);

                if ($fait === 'passee') {
                    $bilan['passees']++;
                } elseif ($fait === 'contre_passee') {
                    $bilan['contre_passees']++;
                }
            });

        return $bilan;
    }

    /**
     * Aligner une facture : la passer, la contre-passer, ou ne rien faire.
     *
     * @return string|null `passee`, `contre_passee`, ou null si rien n'a bougé.
     */
    public static function synchroniser(PortailFneFactureRecue $facture): ?string
    {
        $doitEtrePortee = self::motifDeNePasPorter($facture) === null;
        $estPortee      = $facture->operation_id !== null;

        if ($doitEtrePortee && !$estPortee) {
            return self::passer($facture) ? 'passee' : null;
        }

        if (!$doitEtrePortee && $estPortee) {
            self::contrePasser($facture);

            return 'contre_passee';
        }

        return null;
    }

    /**
     * Pourquoi cette facture ne doit pas être portée par ce service — ou null.
     *
     * Exposé pour les écrans et les épreuves : une facture qui ne passe pas en
     * écriture doit pouvoir dire pourquoi.
     */
    public static function motifDeNePasPorter(PortailFneFactureRecue $facture): ?string
    {
        if (!$facture->entreprise_id) {
            return "aucune entreprise ne porte le NCC du relevé";
        }

        if ($facture->subtype === 'proforma') {
            return 'une proforma n’est pas une pièce comptable';
        }

        if ($facture->statut_rapprochement === PortailFneFactureRecue::ECARTEE) {
            return 'écartée par un utilisateur';
        }

        if ($facture->achat_id) {
            return 'rattachée à un achat, qui porte déjà l’écriture';
        }

        if (self::estNotrePropreBapa($facture)) {
            return 'bordereau émis par l’entreprise : son achat porte déjà l’écriture';
        }

        if (self::montantDu($facture) <= 0) {
            return 'montant nul';
        }

        return null;
    }

    /**
     * Le relevé ramène-t-il une pièce que nous avons nous-mêmes émise ?
     *
     * Le BAPA est un achat que Selflow émet et que la DGI certifie : il porte
     * déjà son écriture, par l'achat. S'il revient par le relevé — le portail
     * le classe parmi les pièces où notre NCC figure —, le passer une seconde
     * fois doublerait la charge et la dette fournisseur.
     *
     * Deux signes, chacun suffisant : l'émetteur est notre propre NCC, ou la
     * référence est le numéro FNE d'un de nos achats. Le second couvre le
     * relevé où l'émetteur serait mal renseigné.
     */
    public static function estNotrePropreBapa(PortailFneFactureRecue $facture): bool
    {
        $entreprise = $facture->entreprise;
        $nccEmetteur = self::ncc($facture->emetteur_ncc);

        if ($entreprise && $nccEmetteur !== '' && $nccEmetteur === self::ncc($entreprise->ncc)) {
            return true;
        }

        if (!$facture->reference) {
            return false;
        }

        return Achat::where('numero_fne', $facture->reference)
            ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $facture->entreprise_id))
            ->exists();
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Passe la facture au journal des achats.
     *
     * Charges au débit, TVA déductible au débit, droit de timbre et autres
     * taxes au débit, le fournisseur au crédit du net à payer. Un avoir reçu
     * (`refund`) inverse les sens.
     */
    private static function passer(PortailFneFactureRecue $facture): bool
    {
        $entreprise = $facture->entreprise;
        $mouvements = self::mouvements($facture);

        if ($mouvements === null) {
            Log::warning('Facture reçue non passée en écriture : montants incohérents', [
                'facture' => $facture->reference,
            ]);

            return false;
        }

        $fournisseur = self::fournisseur($facture, $entreprise);
        $estAvoir    = $facture->subtype === 'refund';
        $journal     = CodeJournal::where('entreprise_id', $entreprise->id)
            ->where('type', 'Achat')->value('code') ?? 'ACH';
        $date        = ($facture->date_facture ?? $facture->date_scraping ?? now())->toDateString();
        $libelle     = trim(($estAvoir ? 'Avoir reçu ' : 'Facture reçue ') . $facture->reference
            . ' — ' . ($fournisseur->nom ?? $facture->emetteur_nom ?? 'Fournisseur'));

        DB::transaction(function () use ($facture, $entreprise, $mouvements, $fournisseur, $estAvoir, $journal, $date, $libelle) {
            $operation = Operation::creer(
                $entreprise->id, $facture->point_de_vente_id, $date, self::TYPE_OPERATION,
                $journal, $facture->reference, mb_substr($libelle, 0, 255)
            );

            foreach ($mouvements['debits'] as [$compte, $montant, $intitule]) {
                self::ligne($operation, $facture, $journal, $date, $intitule,
                    $estAvoir ? null : $compte, $estAvoir ? $compte : null, null,
                    $estAvoir ? 0 : $montant, $estAvoir ? $montant : 0);
            }

            $compteFournisseur = $fournisseur->compte_comptable
                ?: config('selflow.plan_comptable_defaut.fournisseur_collectif');

            self::ligne($operation, $facture, $journal, $date, 'Fournisseur ' . ($fournisseur->nom ?? ''),
                $estAvoir ? $compteFournisseur : null, $estAvoir ? null : $compteFournisseur,
                $fournisseur->numero_tiers,
                $estAvoir ? $mouvements['total'] : 0, $estAvoir ? 0 : $mouvements['total']);

            $facture->forceFill(['operation_id' => $operation->id])->saveQuietly();

            $operation->cloturerEquilibre();
        });

        return true;
    }

    /**
     * Contre-passe l'opération qui portait la facture.
     *
     * Chaque ligne repart avec débit et crédit inversés, sous la même
     * référence : le solde de la pièce revient à zéro chez Selflow comme chez
     * Comptaflow, et l'historique dit ce qui s'est passé.
     */
    private static function contrePasser(PortailFneFactureRecue $facture): void
    {
        $origine = Operation::with('ecritures')->find($facture->operation_id);

        DB::transaction(function () use ($facture, $origine) {
            if ($origine) {
                $annulation = Operation::creer(
                    $origine->entreprise_id, $origine->point_de_vente_id, now()->toDateString(),
                    self::TYPE_ANNULATION, $origine->code_journal, $origine->reference_document,
                    mb_substr('Contre-passation — ' . $origine->libelle_general, 0, 255)
                );

                foreach ($origine->ecritures as $ecriture) {
                    EcritureComptable::create([
                        'operation_id'       => $annulation->id,
                        'entreprise_id'      => $ecriture->entreprise_id,
                        'point_de_vente_id'  => $ecriture->point_de_vente_id,
                        'date_ecriture'      => $annulation->date_operation,
                        'libelle'            => mb_substr('Contre-passation ' . $ecriture->libelle, 0, 255),
                        'reference_document' => $ecriture->reference_document,
                        'code_journal'       => $ecriture->code_journal,
                        'compte_debit'       => $ecriture->compte_credit,
                        'compte_credit'      => $ecriture->compte_debit,
                        'compte_tiers'       => $ecriture->compte_tiers,
                        'debit'              => $ecriture->credit,
                        'credit'             => $ecriture->debit,
                    ]);
                }

                $annulation->cloturerEquilibre();
            }

            $facture->forceFill(['operation_id' => null])->saveQuietly();
        });
    }

    /**
     * Ce que la facture met au débit, compte par compte, et le total dû.
     *
     * Le total au crédit est le **net à payer** du relevé — ce que l'on doit
     * réellement au fournisseur. La TVA, le timbre et les autres taxes sont
     * pris tels que la DGI les a certifiés ; la charge est le reste. Calculée
     * ainsi, l'opération tombe juste par construction : un arrondi du portail
     * ne peut pas la déséquilibrer, et un écart ne se perd pas dans une ligne
     * de régularisation.
     *
     * @return array{debits: array<int, array{0:string,1:float,2:string}>, total: float}|null
     */
    private static function mouvements(PortailFneFactureRecue $facture): ?array
    {
        $total  = self::montantDu($facture);
        $tva    = round((float) $facture->montant_tva, 2);
        $timbre = round((float) $facture->timbre_fiscal, 2);
        $taxes  = round((float) $facture->autres_taxes, 2);
        $charge = round($total - $tva - $timbre - $taxes, 2);

        if ($charge <= 0 || $tva < 0 || $timbre < 0 || $taxes < 0) {
            return null;
        }

        $debits = [];
        $tvaParCompte = [];

        $repartition = self::repartitionDesCharges($facture, $charge, $tva);

        foreach ($repartition as $compte => [$montant, $tvaDuCompte]) {
            $debits[] = [$compte, $montant, 'Achat suivant facture ' . $facture->reference];

            if ($tvaDuCompte > 0) {
                $compteTva = self::compteTvaDeductible($compte);
                $tvaParCompte[$compteTva] = round(($tvaParCompte[$compteTva] ?? 0) + $tvaDuCompte, 2);
            }
        }

        foreach ($tvaParCompte as $compteTva => $montant) {
            $debits[] = [$compteTva, $montant, 'TVA déductible ' . $facture->reference];
        }

        if ($timbre > 0) {
            $debits[] = [config('selflow.plan_comptable_defaut.timbre_achat', '646200'), $timbre, 'Droit de timbre ' . $facture->reference];
        }

        if ($taxes > 0) {
            $debits[] = [config('selflow.plan_comptable_defaut.taxes_achat', '648000'), $taxes, 'Autres taxes ' . $facture->reference];
        }

        return ['debits' => $debits, 'total' => $total];
    }

    /**
     * La charge et la TVA, réparties sur les comptes que les articles désignent.
     *
     * Une ligne de facture dont la référence ou la désignation est celle d'un
     * article du catalogue prend **le compte d'achat paramétré** pour cet
     * article — article, puis rayon, puis défaut : la chaîne de
     * `ImputationService`. Les autres tombent sur le compte d'achat par défaut.
     *
     * Le dernier compte absorbe l'arrondi : la somme des parts est exactement la
     * charge, et la somme des TVA exactement la TVA certifiée.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private static function repartitionDesCharges(PortailFneFactureRecue $facture, float $charge, float $tva): array
    {
        $defaut = ImputationService::compteAchat(null, $facture->entreprise_id);
        $poids  = [];

        foreach ($facture->lignes as $ligne) {
            $valeur = max(0, (float) $ligne->quantite * (float) $ligne->prix_unitaire - (float) $ligne->remise);

            if ($valeur <= 0) {
                continue;
            }

            $compte = ImputationService::compteAchat(self::article($facture->entreprise_id, $ligne->reference_article, $ligne->designation), $facture->entreprise_id);
            $poids[$compte] = ($poids[$compte] ?? 0) + $valeur;
        }

        if ($poids === []) {
            return [$defaut => [$charge, $tva]];
        }

        $somme = array_sum($poids);
        $parts = [];
        $resteCharge = $charge;
        $resteTva    = $tva;
        $comptes     = array_keys($poids);
        $dernier     = end($comptes);

        foreach ($poids as $compte => $valeur) {
            if ($compte === $dernier) {
                $parts[$compte] = [round($resteCharge, 2), round($resteTva, 2)];
                break;
            }

            $partCharge = round($charge * $valeur / $somme, 2);
            $partTva    = round($tva * $valeur / $somme, 2);
            $parts[$compte] = [$partCharge, $partTva];
            $resteCharge -= $partCharge;
            $resteTva    -= $partTva;
        }

        return array_filter($parts, fn ($p) => $p[0] > 0 || $p[1] > 0);
    }

    /** L'article du catalogue que désigne une ligne de facture, s'il existe. */
    private static function article(int $entrepriseId, ?string $reference, ?string $designation): ?Produit
    {
        $reference   = trim((string) $reference);
        $designation = trim((string) $designation);

        if ($reference === '' && $designation === '') {
            return null;
        }

        return Produit::with('categorieRelation')
            ->where('entreprise_id', $entrepriseId)
            ->where(function ($q) use ($reference, $designation) {
                if ($reference !== '') {
                    $q->orWhere('reference', $reference);
                }
                if ($designation !== '') {
                    $q->orWhere('nom', $designation);
                }
            })
            ->first();
    }

    /**
     * Le fournisseur de Selflow qui porte le NCC de l'émetteur — créé s'il manque.
     *
     * Le créer n'invente rien : nom, NCC et RCCM sont ceux que la DGI a
     * certifiés. Sans fiche, toute facture reçue retomberait sur le
     * fournisseur divers, et Comptaflow verrait tous les fournisseurs du
     * portail sous un seul numéro de tiers. Sans NCC, c'est le fournisseur
     * divers — il n'y a rien de sûr à quoi rattacher la dette.
     */
    private static function fournisseur(PortailFneFactureRecue $facture, Entreprise $entreprise): Fournisseur
    {
        $ncc = self::ncc($facture->emetteur_ncc);

        if ($ncc === '') {
            return Fournisseur::divers($entreprise);
        }

        $existant = Fournisseur::where('entreprise_id', $entreprise->id)
            ->whereRaw('UPPER(TRIM(ncc)) = ?', [$ncc])
            ->first();

        if ($existant) {
            return $existant;
        }

        $collectif = config('selflow.plan_comptable_defaut.fournisseur_collectif');
        $nom = $facture->emetteur_nom ?: "Fournisseur {$ncc}";

        return Fournisseur::create([
            'entreprise_id'    => $entreprise->id,
            'nom'              => $nom,
            'type_facturation' => 'B2B',
            'ncc'              => $ncc,
            'rccm'             => $facture->emetteur_rccm,
            'compte_comptable' => $collectif,
            'numero_tiers'     => NumerotationTiersService::pourFournisseur($entreprise, $collectif, $nom),
            'source'           => 'portail_fne',
        ]);
    }

    private static function ligne(
        Operation $operation,
        PortailFneFactureRecue $facture,
        string $journal,
        string $date,
        string $libelle,
        ?string $compteDebit,
        ?string $compteCredit,
        ?string $compteTiers,
        float $debit,
        float $credit
    ): void {
        EcritureComptable::create([
            'operation_id'       => $operation->id,
            'entreprise_id'      => $operation->entreprise_id,
            'point_de_vente_id'  => $facture->point_de_vente_id,
            'date_ecriture'      => $date,
            'libelle'            => mb_substr(trim($libelle), 0, 255),
            'reference_document' => $facture->reference,
            'code_journal'       => $journal,
            'compte_debit'       => $compteDebit,
            'compte_credit'      => $compteCredit,
            'compte_tiers'       => $compteTiers,
            'debit'              => round($debit, 2),
            'credit'             => round($credit, 2),
        ]);
    }

    /** Ce que l'on doit au fournisseur. */
    private static function montantDu(PortailFneFactureRecue $facture): float
    {
        $net = round((float) $facture->net_a_payer, 2);

        if ($net > 0) {
            return $net;
        }

        return round((float) $facture->montant_ttc + (float) $facture->timbre_fiscal + (float) $facture->autres_taxes, 2);
    }

    /** Même ventilation que les achats saisis — voir `ComptabiliteService`. */
    private static function compteTvaDeductible(string $compteCharge): string
    {
        return match (substr($compteCharge, 0, 2)) {
            '60'    => config('selflow.plan_comptable_defaut.tva_deductible'),
            '61'    => config('selflow.plan_comptable_defaut.tva_deductible_transport'),
            '20', '21', '22', '23', '24' => config('selflow.plan_comptable_defaut.tva_deductible_immobilisations'),
            default => config('selflow.plan_comptable_defaut.tva_deductible_services'),
        };
    }

    private static function ncc(?string $ncc): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $ncc));
    }
}
