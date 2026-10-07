<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\AvoirBapa;
use App\Modules\Admin\Modeles\AvoirBapaLigne;
use App\Modules\Admin\Modeles\MouvementStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'avoir interne d'un BAPA (chantier 8.4 du plan).
 *
 * Le propriétaire a confirmé le 07/10/2026 que la DGI ne normalise toujours
 * pas l'avoir d'un bordereau d'achat. L'avoir est donc **interne** : il n'est
 * jamais transmis à la plateforme, ne porte ni numéro fiscal ni code QR, et
 * se dit tel sur le document. Il sert ce que la plateforme ne sert pas : la
 * comptabilité (le fournisseur est débité, la charge recréditée) et le stock
 * (la marchandise rendue sort).
 *
 * Le plafond est celui des avoirs de vente (chantier 8.1), en quantité ligne
 * à ligne **et** en montant, contrôlé au serveur sous verrou du bordereau :
 * on ne rend pas plus que ce qui a été acheté.
 *
 * Seul le BAPA s'avoire (précision du 05/10/2026) : un achat ordinaire est
 * une pièce du fournisseur, et c'est à lui d'émettre son avoir.
 */
class AvoirBapaService
{
    /**
     * Ce qui a déjà été rendu, par ligne du bordereau.
     *
     * @return array<int, float>
     */
    public static function dejaRendu(Achat $bapa): array
    {
        return AvoirBapaLigne::query()
            ->whereIn('achat_detail_id', $bapa->details()->pluck('id'))
            ->groupBy('achat_detail_id')
            ->selectRaw('achat_detail_id, SUM(quantite) as total')
            ->pluck('total', 'achat_detail_id')
            ->map(fn ($t) => (float) $t)
            ->all();
    }

    public static function montantDejaAvoire(Achat $bapa): float
    {
        return (float) AvoirBapa::where('achat_id', $bapa->id)->sum('montant_ttc');
    }

    public static function resteAAvoirer(Achat $bapa): float
    {
        return max(0.0, round((float) $bapa->montant_ttc - self::montantDejaAvoire($bapa), 2));
    }

    public static function estAvoirable(Achat $bapa): bool
    {
        return $bapa->estBapa()
            && $bapa->type_facture !== 'avoir'
            && $bapa->etape === 'Facture';
    }

    /**
     * Établir l'avoir.
     *
     * @param  array<int|string, array{quantite?: mixed, retour_stock?: mixed}>  $lignes  par id de ligne du bordereau
     */
    public static function etablir(Achat $bapa, array $lignes, string $motif, ?int $utilisateurId): AvoirBapa
    {
        if (!self::estAvoirable($bapa)) {
            throw ValidationException::withMessages(['lignes' =>
                "Seul un bordereau d'achat (BAPA) finalisé s'avoire. Un achat ordinaire est une pièce de votre "
                . "fournisseur : c'est à lui d'émettre son avoir."]);
        }

        return DB::transaction(function () use ($bapa, $lignes, $motif, $utilisateurId) {
            // Le bordereau relu verrouillé : deux avoirs saisis en même temps
            // sur le même reste ne passent pas tous les deux.
            $bapa = Achat::withoutGlobalScopes()->lockForUpdate()->findOrFail($bapa->id);
            $details = $bapa->details()->with('produit')->get()->keyBy('id');
            $deja = self::dejaRendu($bapa);

            $retenues = [];
            $total = 0.0;

            foreach ($lignes as $detailId => $saisie) {
                $quantite = round((float) ($saisie['quantite'] ?? 0), 3);
                if ($quantite <= 0) {
                    continue;
                }

                /** @var AchatDetail|null $detail */
                $detail = $details->get((int) $detailId);
                if (!$detail) {
                    continue; // une ligne d'une autre pièce, postée à la main
                }

                $reste = (float) $detail->quantite - ($deja[$detail->id] ?? 0.0);
                if ($quantite > $reste + 0.0005) {
                    throw ValidationException::withMessages(['lignes' => sprintf(
                        '%s : %s demandé(s), il n\'en reste que %s à rendre.',
                        $detail->produit?->nom ?? $detail->libelle_virtuel ?? 'Article',
                        self::nombre($quantite), self::nombre(max(0, $reste))
                    )]);
                }

                // Le prix est celui du bordereau, net de sa remise : il ne se
                // saisit pas, sans quoi on rendrait au prix qu'on veut.
                $prix = (float) $detail->prix_unitaire * (1 - (float) ($detail->remise_taux ?? 0) / 100);
                $montant = round($quantite * $prix, 2);
                $total += $montant;

                $retenues[] = [$detail, $quantite, $prix, $montant, filter_var($saisie['retour_stock'] ?? false, FILTER_VALIDATE_BOOLEAN)];
            }

            if ($retenues === []) {
                throw ValidationException::withMessages(['lignes' => 'Indiquez au moins une quantité à rendre.']);
            }

            $reste = self::resteAAvoirer($bapa);
            if ($total > $reste + 0.01) {
                throw ValidationException::withMessages(['lignes' => sprintf(
                    'Cet avoir porte %s F, et il ne reste que %s F à avoirer sur %s F. Un avoir ne rend pas plus que le bordereau.',
                    self::nombre($total, 0), self::nombre($reste, 0), self::nombre((float) $bapa->montant_ttc, 0)
                )]);
            }

            $entrepriseId = $bapa->pointDeVente->entreprise_id;

            $avoir = AvoirBapa::create([
                'entreprise_id'     => $entrepriseId,
                'achat_id'          => $bapa->id,
                'point_de_vente_id' => $bapa->point_de_vente_id,
                'utilisateur_id'    => $utilisateurId,
                'numero'            => self::numero($entrepriseId),
                'date_avoir'        => now()->toDateString(),
                'motif'             => $motif,
                'montant_ttc'       => round($total, 2),
            ]);

            foreach ($retenues as [$detail, $quantite, $prix, $montant, $retour]) {
                AvoirBapaLigne::create([
                    'avoir_bapa_id'   => $avoir->id,
                    'achat_detail_id' => $detail->id,
                    'produit_id'      => $detail->produit_id,
                    'libelle'         => $detail->produit?->nom ?? $detail->libelle_virtuel ?? 'Article',
                    'quantite'        => $quantite,
                    'prix_unitaire'   => $prix,
                    'montant'         => $montant,
                    'retour_stock'    => $retour,
                ]);

                // La marchandise rendue au producteur sort du stock — si elle
                // était entrée, et si l'on dit qu'elle repart.
                if ($retour && $detail->produit && $detail->produit->estStockable()) {
                    StockService::sortie($detail->produit, (int) $bapa->point_de_vente_id, $quantite,
                        MouvementStock::RETOUR_FOURNISSEUR, [
                            'piece'          => $avoir,
                            'reference'      => $avoir->numero,
                            'fournisseur_id' => $bapa->fournisseur_id,
                        ]);
                }
            }

            ComptabiliteService::genererEcritureAvoirBapa($avoir->load('lignes.produit', 'bapa.fournisseur'));

            return $avoir;
        });
    }

    /** AVB-2026-0001 : la suite de l'entreprise, par année. */
    private static function numero(int $entrepriseId): string
    {
        $annee = now()->year;
        $rang = AvoirBapa::where('entreprise_id', $entrepriseId)->whereYear('date_avoir', $annee)->count() + 1;

        return sprintf('AVB-%d-%04d', $annee, $rang);
    }

    private static function nombre(float $valeur, int $decimales = 3): string
    {
        return rtrim(rtrim(number_format($valeur, $decimales, ',', ' '), '0'), ',');
    }
}
