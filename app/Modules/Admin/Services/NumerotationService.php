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
    public static function genererNumeroAchat(int $entrepriseId, string $etape, ?string $typeFacture = null, ?int $pointDeVenteId = null): string
    {
        $site = self::siteNumerote($entrepriseId, $pointDeVenteId);
        if ($etape === 'Demande de prix') {
            return self::genererAncienneConvention($entrepriseId, 'DP-', Achat::class, $site);
        }
        if ($etape === 'Bon de commande') {
            return self::genererAncienneConvention($entrepriseId, self::PREFIXE_BC_ACHAT, Achat::class, $site);
        }
        if ($typeFacture === 'bapa') {
            return self::genererAncienneConvention($entrepriseId, 'BA-', Achat::class, $site);
        }
        if ($typeFacture === 'avoir') {
            return self::genererConventionDatee($entrepriseId, 'AVO-ACH', Achat::class, $site);
        }

        return self::genererConventionDatee($entrepriseId, 'ACH', Achat::class, $site);
    }

    /**
     * Génère un numéro de document unique et séquentiel scoppé à l'entreprise pour les ventes.
     *
     * Convention (validée le 22/07/2026) :
     *   - Facture de vente définitive : VTE-jjmmaa-xxx   (ex: VTE-220726-001)
     *   - Avoir client                : AVO-VTE-jjmmaa-xxx
     *   - Devis, Bon de commande : conservent l'ancien format (DV-/BC- + dd-mm-yyyy)
     */
    public static function genererNumeroVente(int $entrepriseId, string $etape, ?string $typeFacture = null, ?int $pointDeVenteId = null): string
    {
        $site = self::siteNumerote($entrepriseId, $pointDeVenteId);
        if ($etape === 'Devis') {
            return self::genererAncienneConvention($entrepriseId, 'DV-', Vente::class, $site);
        }
        if ($etape === 'Bon de commande') {
            return self::genererAncienneConvention($entrepriseId, 'BC-', Vente::class, $site);
        }
        if ($typeFacture === 'avoir') {
            return self::genererConventionDatee($entrepriseId, 'AVO-VTE', Vente::class, $site);
        }

        return self::genererConventionDatee($entrepriseId, 'VTE', Vente::class, $site);
    }

    /**
     * Le site dont le code entre dans le numéro — seulement quand l'entreprise
     * en a plusieurs : une entreprise d'un seul site garde ses numéros
     * d'aujourd'hui. Sans site désigné, le site actif de la session.
     */
    private static function siteNumerote(int $entrepriseId, ?int $pointDeVenteId): ?\App\Modules\Admin\Modeles\PointDeVente
    {
        $sites = \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', $entrepriseId);
        if ((clone $sites)->count() < 2) {
            return null;
        }

        $pointDeVenteId = $pointDeVenteId ?: (int) session('point_de_vente_actif_id');
        $site = $pointDeVenteId ? (clone $sites)->find($pointDeVenteId) : null;
        if ($site && blank($site->code_piece)) {
            $site->code_piece = \App\Modules\Admin\Modeles\PointDeVente::codeLibre($entrepriseId, (string) $site->nom, $site->id);
            $site->saveQuietly();
        }

        return $site;
    }

    /**
     * Génère un numéro de Bon de Livraison unique et séquentiel (BL-DD-MM-AAAA-XXXX).
     * Non concerné par le changement de convention (document de logistique,
     * pas une pièce comptable en tant que telle).
     */
    public static function genererNumeroBL(int $entrepriseId, ?int $pointDeVenteId = null): string
    {
        return self::genererAncienneConvention($entrepriseId, 'BL-', BonLivraison::class, self::siteNumerote($entrepriseId, $pointDeVenteId));
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
    private static function genererConventionDatee(int $entrepriseId, string $prefixe, string $modelClass, ?\App\Modules\Admin\Modeles\PointDeVente $site = null): string
    {
        $jjmmaa = now()->format('dmy');
        // Plusieurs sites : le code du site entre dans le numéro, et chacun
        // compte à partir de 1 (20/10/2026). VTE-BON-201026-001.
        $debut = $prefixe . '-' . ($site ? $site->code_piece . '-' : '') . $jjmmaa . '-';

        // Sans le filtre de période de la session : il ne laissait compter que
        // les pièces datées dans l'exercice affiché. Un achat saisi aujourd'hui
        // mais daté de l'an dernier recevait ACH-jjmmaa-002, et la facture
        // suivante du jour… ACH-jjmmaa-002 aussi (recette du 08/10/2026).
        return self::prochainNumero($entrepriseId, $modelClass, $debut, $debut, 3);
    }

    /**
     * Ancienne convention (conservée telle quelle pour les documents
     * pré-comptables) : {PREFIXE}{dd-mm-yyyy}-{séquence sur 4 chiffres}.
     * ex : DV-22-07-2026-0001
     */
    private static function genererAncienneConvention(int $entrepriseId, string $prefixe, string $modelClass, ?\App\Modules\Admin\Modeles\PointDeVente $site = null): string
    {
        if ($site) {
            $prefixe .= $site->code_piece . '-';
        }
        // La séquence court sur l'année, quel que soit le jour : on cherche
        // donc le plus grand numéro de l'année sous ce préfixe.
        return self::prochainNumero($entrepriseId, $modelClass, $prefixe, $prefixe . now()->format('d-m-Y') . '-', 4, now()->year);
    }

    /**
     * Le numéro suivant, sans doublon possible (urgence du 26/10/2026).
     *
     * Le numéro valait « nombre de pièces + 1 », compté sans verrou ni
     * contrainte d'unicité en base. Deux défauts en sortaient :
     *
     * - deux ventes enregistrées au même instant (deux caisses, un double
     *   envoi) comptaient le même nombre et recevaient le **même numéro** ;
     * - une pièce supprimée (archive effacée) faisait baisser le compte : la
     *   suivante reprenait le numéro de la dernière **encore en base**.
     *
     * Désormais :
     *
     * 1. la ligne de l'entreprise est verrouillée jusqu'à la fin de la
     *    transaction qui enregistre la pièce : deux numérotations de la même
     *    entreprise passent l'une après l'autre ;
     * 2. la séquence part du **plus grand** numéro attribué, et non du compte ;
     * 3. le numéro retenu est vérifié absent avant d'être rendu.
     *
     * Le format ne change pas.
     *
     * @param  string  $prefixeRecherche  ce que partagent les numéros de la séquence
     * @param  string  $debut             ce qui précède le compteur dans le nouveau numéro
     */
    private static function prochainNumero(int $entrepriseId, string $modelClass, string $prefixeRecherche, string $debut, int $chiffres, ?int $annee = null): string
    {
        \App\Modules\Admin\Modeles\Entreprise::whereKey($entrepriseId)->lockForUpdate()->value('id');

        $colonne = $modelClass === BonLivraison::class ? 'numero_bl' : 'numero_facture';
        $requete = fn () => $modelClass::withoutGlobalScope(\App\Modules\Admin\Scopes\PeriodeScope::class)
            ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entrepriseId));

        $plusGrand = 0;
        $requete()
            ->where($colonne, 'LIKE', $prefixeRecherche . '%')
            ->when($annee, fn ($q) => $q->where($colonne, 'LIKE', '%-' . $annee . '-%'))
            ->pluck($colonne)
            ->each(function ($numero) use (&$plusGrand) {
                if (preg_match('/-(\d+)$/', (string) $numero, $m)) {
                    $plusGrand = max($plusGrand, (int) $m[1]);
                }
            });

        $sequence = $plusGrand + 1;
        do {
            $numero = $debut . str_pad((string) $sequence, $chiffres, '0', STR_PAD_LEFT);
            $sequence++;
        } while ($requete()->where($colonne, $numero)->exists());

        return $numero;
    }
}
