<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\SoldeInitial;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Support\Facades\Schema;

/**
 * Le solde du client, imprimé sur sa facture (26/10/2026).
 *
 * Une option de l'entreprise, éteinte par défaut : tout le monde ne veut pas
 * que la situation du compte d'un client figure sur ses factures. Allumée,
 * une facture adressée à un client connu porte :
 *
 * - le **solde antérieur** : ce qu'il restait à régler sur ses factures
 *   précédentes et sur son solde de départ ;
 * - le **reste de cette facture** ;
 * - le **solde cumulé**, la somme des deux.
 *
 * Le calcul est celui de l'écran des créances : factures à crédit ou avec
 * avance, taxes parafiscales et timbre compris, moins ce que la trésorerie a
 * encaissé sur chacune. Rien de ce que la DGI reçoit n'en dépend.
 */
class SoldeClientService
{
    /** L'option est-elle en place et allumée pour cette entreprise ? */
    public static function actif(?\App\Modules\Admin\Modeles\Entreprise $entreprise): bool
    {
        return $entreprise !== null
            && Schema::hasColumn('entreprises', 'afficher_solde_client_facture')
            && (bool) $entreprise->afficher_solde_client_facture;
    }

    /**
     * @return array{anterieur: float, piece: float, cumule: float}|null  null : rien à imprimer
     */
    public static function pourFacture(Vente $vente): ?array
    {
        $entreprise = $vente->pointDeVente?->entreprise;

        if (! self::actif($entreprise)
            || $vente->etape !== 'Facture'
            || $vente->type_facture === 'avoir'
            || $vente->estAnnulee()
            || ! $vente->client_id
            || preg_match('/passage/i', (string) $vente->client?->nom)) {
            return null;
        }

        $piece = self::reste($vente);

        $precedentes = Vente::query()
            ->where('client_id', $vente->client_id)
            ->where('id', '<', $vente->id)
            ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
            ->where('etape', 'Facture')
            ->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))
            ->whereIn('statut', ['Crédit', 'Avance'])
            ->get();

        $anterieur = $precedentes->sum(fn (Vente $v) => self::reste($v));

        $anterieur += SoldeInitial::where('entreprise_id', $entreprise->id)
            ->where('type', SoldeInitial::CLIENT)
            ->where('tiers_id', $vente->client_id)
            ->get()
            ->sum(fn (SoldeInitial $s) => max(0, (float) $s->montant - $s->regle()));

        $anterieur = round($anterieur, 2);
        $cumule = round($anterieur + $piece, 2);

        // Un client à jour n'a rien à lire.
        if ($cumule <= 0.5) {
            return null;
        }

        return ['anterieur' => $anterieur, 'piece' => $piece, 'cumule' => $cumule];
    }

    /** Ce qui reste à régler sur une facture : le dû moins ce qui est encaissé. */
    private static function reste(Vente $vente): float
    {
        $du = (float) $vente->montant_ttc + (float) $vente->montant_autres_taxes + (float) $vente->timbre_quittance;
        $regle = (float) TresorerieJournal::where('reference_document', $vente->numero_facture)->sum('montant_entree');
        $reste = round($du - $regle, 2);

        return $reste > 0.5 ? $reste : 0.0;
    }
}
