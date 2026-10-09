<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\EcritureComptable;

class NumerotationService
{
    /**
     * **Le bon de commande fournisseur se numérote `BCF-`** (recette du
     * 08/10/2026). Il partageait `BC-` avec le bon de commande client, et les
     * deux séquences se comptaient chacune dans sa table : la première
     * commande du jour, en vente comme en achat, recevait le même numéro.
     * `BC-` reste celui de la vente — c'est lui que la trésorerie, l'API et
     * la reprise des écritures rattachent aux ventes. Les numéros déjà
     * attribués ne changent pas.
     */
    public const PREFIXE_BC_ACHAT = 'BCF-';

    /**
     * Génère un numéro de document unique et séquentiel scoppé à l'entreprise pour les achats.
     *
     * Convention (validée le 22/07/2026) :
     *   - Facture d'achat définitive : ACH-jjmmaa-xxx   (ex: ACH-220726-001)
     *   - Avoir fournisseur          : AVO-ACH-jjmmaa-xxx
     *   - Demande de prix, Bon de commande, Bapa : conservent l'ancien format
     *     (DP-/BCF-/BA- + dd-mm-yyyy), non concernés par ce changement — ce
     *     sont des documents pré-comptables, pas encore des pièces journalisées.
     */
    public static function genererNumeroAchat(int $entrepriseId, string $etape, ?string $typeFacture = null): string
    {
        if ($etape === 'Demande de prix') {
            return self::genererAncienneConvention($entrepriseId, 'DP-', Achat::class);
        }
        if ($etape === 'Bon de commande') {
            return self::genererAncienneConvention($entrepriseId, self::PREFIXE_BC_ACHAT, Achat::class);
        }
        if ($typeFacture === 'bapa') {
            return self::genererAncienneConvention($entrepriseId, 'BA-', Achat::class);
        }
        if ($typeFacture === 'avoir') {
            return self::genererConventionDatee($entrepriseId, 'AVO-ACH', Achat::class);
        }

        return self::genererConventionDatee($entrepriseId, 'ACH', Achat::class);
    }

    /**
     * Génère un numéro de document unique et séquentiel scoppé à l'entreprise pour les ventes.
     *
     * Convention (validée le 22/07/2026) :
     *   - Facture de vente définitive : VTE-jjmmaa-xxx   (ex: VTE-220726-001)
     *   - Avoir client                : AVO-VTE-jjmmaa-xxx
     *   - Devis, Bon de commande : conservent l'ancien format (DV-/BC- + dd-mm-yyyy)
     */
    public static function genererNumeroVente(int $entrepriseId, string $etape, ?string $typeFacture = null): string
    {
        if ($etape === 'Devis') {
            return self::genererAncienneConvention($entrepriseId, 'DV-', Vente::class);
        }
        if ($etape === 'Bon de commande') {
            return self::genererAncienneConvention($entrepriseId, 'BC-', Vente::class);
        }
        if ($typeFacture === 'avoir') {
            return self::genererConventionDatee($entrepriseId, 'AVO-VTE', Vente::class);
        }

        return self::genererConventionDatee($entrepriseId, 'VTE', Vente::class);
    }

    /**
     * Génère un numéro de Bon de Livraison unique et séquentiel (BL-DD-MM-AAAA-XXXX).
     * Non concerné par le changement de convention (document de logistique,
     * pas une pièce comptable en tant que telle).
     */
    public static function genererNumeroBL(int $entrepriseId): string
    {
        return self::genererAncienneConvention($entrepriseId, 'BL-', BonLivraison::class);
    }

    /**
     * Génère un numéro de pièce pour une opération diverse (OD) manuelle.
     * Convention : OD-jjmmaa-xxx (ex: OD-220726-003)
     */
    public static function genererNumeroOD(int $entrepriseId): string
    {
        $jjmmaa = now()->format('dmy');
        $debut = 'OD-' . $jjmmaa . '-';

        $compte = EcritureComptable::where('entreprise_id', $entrepriseId)
            ->where('reference_document', 'LIKE', $debut . '%')
            ->distinct('reference_document')
            ->count('reference_document');

        $sequence = str_pad((string) ($compte + 1), 3, '0', STR_PAD_LEFT);
        return $debut . $sequence;
    }

    /**
     * Convention datée compacte : {PREFIXE}-{jjmmaa}-{séquence sur 3 chiffres}.
     * La séquence redémarre chaque jour, par entreprise et par préfixe.
     * ex : VTE-220726-001, AVO-ACH-220726-004
     */
    private static function genererConventionDatee(int $entrepriseId, string $prefixe, string $modelClass): string
    {
        $jjmmaa = now()->format('dmy');
        $debut = $prefixe . '-' . $jjmmaa . '-';

        // Sans le filtre de période de la session : il ne laissait compter que
        // les pièces datées dans l'exercice affiché. Un achat saisi aujourd'hui
        // mais daté de l'an dernier recevait ACH-jjmmaa-002, et la facture
        // suivante du jour… ACH-jjmmaa-002 aussi (recette du 08/10/2026).
        $compte = $modelClass::withoutGlobalScope(\App\Modules\Admin\Scopes\PeriodeScope::class)
            ->where('numero_facture', 'LIKE', $debut . '%')
            ->whereHas('pointDeVente', function ($query) use ($entrepriseId) {
                $query->where('entreprise_id', $entrepriseId);
            })
            ->count();

        $sequence = str_pad((string) ($compte + 1), 3, '0', STR_PAD_LEFT);
        return $debut . $sequence;
    }

    /**
     * Ancienne convention (conservée telle quelle pour les documents
     * pré-comptables) : {PREFIXE}{dd-mm-yyyy}-{séquence sur 4 chiffres}.
     * ex : DV-22-07-2026-0001
     */
    private static function genererAncienneConvention(int $entrepriseId, string $prefixe, string $modelClass): string
    {
        $annee = now()->year;

        $compte = $modelClass::withoutGlobalScope(\App\Modules\Admin\Scopes\PeriodeScope::class)
            ->whereYear('created_at', $annee)
            ->whereHas('pointDeVente', function ($query) use ($entrepriseId) {
                $query->where('entreprise_id', $entrepriseId);
            })
            ->where(function ($q) use ($prefixe, $modelClass) {
                // BonLivraison n'a pas de numero_facture : le préfixe suffit à lui seul (comptage global de l'année)
                if ($modelClass === BonLivraison::class) {
                    return;
                }
                $q->where('numero_facture', 'LIKE', $prefixe . '%');
            })
            ->count();

        $sequence = str_pad((string) ($compte + 1), 4, '0', STR_PAD_LEFT);
        return $prefixe . now()->format('d-m-Y') . '-' . $sequence;
    }
}
