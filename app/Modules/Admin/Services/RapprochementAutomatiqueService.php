<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rapprocher sans demander.
 *
 * ## Le geste qu'on supprime
 *
 * Le relevé rapportait les factures que les fournisseurs ont certifiées au NCC
 * de l'entreprise, et `rapprochementPropose()` cherchait déjà l'achat qui leur
 * correspond. Mais il **proposait** : il fallait cliquer « Rattacher », facture
 * par facture.
 *
 * Le propriétaire l'a tranché le 25/09/2026 : « en réalité je veux aucune
 * intervention manuelle de l'utilisateur si possible ». Ce service applique la
 * proposition au lieu de l'afficher.
 *
 * ## Les trois règles, et pourquoi elles s'arrêtent là
 *
 * | Ce qu'on trouve | Ce qu'on fait |
 * |---|---|
 * | Un achat, même fournisseur, même date, **même TTC** | rattaché, sans un mot |
 * | Un achat, mais le TTC diffère | rattaché **et l'écart est écrit** : ce n'est pas une décision à prendre, c'est une anomalie à regarder |
 * | Aucun achat en face | on ne touche à rien |
 *
 * **Le troisième cas ne crée pas l'achat**, et c'est voulu. Le propriétaire l'a
 * précisé : créer l'achat depuis la facture reçue ne vaut que « dans le cas où
 * on n'enregistre pas ». Tant qu'une entreprise saisit ses achats, en fabriquer
 * un second depuis le relevé ferait un doublon — exactement ce que le
 * rapprochement cherche à éviter.
 *
 * ## Ce qui rend le geste automatique sans danger
 *
 * Il ne se fait que sur une facture **que personne n'a encore rangée** : ni
 * rattachée, ni écartée. Un écartement est une décision humaine, et elle prime.
 * Et le rattachement reste défaisable — `detacher()` n'a pas bougé.
 */
class RapprochementAutomatiqueService
{
    /**
     * Rapprocher ce qui peut l'être, pour une entreprise.
     *
     * @return array{rapprochees: int, avec_ecart: int, sans_correspondance: int}
     */
    public static function pourEntreprise(int $entrepriseId): array
    {
        $bilan = ['rapprochees' => 0, 'avec_ecart' => 0, 'sans_correspondance' => 0];

        $candidates = PortailFneFactureRecue::where('entreprise_id', $entrepriseId)
            ->whereNull('achat_id')
            ->where('statut_rapprochement', '!=', PortailFneFactureRecue::ECARTEE)
            ->with('lignes')
            ->get();

        foreach ($candidates as $facture) {
            $fait = self::uneFacture($facture);

            if ($fait === null) {
                $bilan['sans_correspondance']++;

                continue;
            }

            $bilan['rapprochees']++;

            if ($fait) {
                $bilan['avec_ecart']++;
            }
        }

        return $bilan;
    }

    /**
     * Rapprocher une facture si un achat lui correspond.
     *
     * @return bool|null `null` si rien ne correspond ; sinon, `true` quand le
     *                   rattachement s'est fait malgré un écart de montant.
     */
    public static function uneFacture(PortailFneFactureRecue $facture): ?bool
    {
        // Une facture déjà rangée — rattachée ou écartée — ne se rerange pas.
        // L'écartement surtout : c'est une décision humaine, et elle prime.
        if ($facture->achat_id || $facture->statut_rapprochement === PortailFneFactureRecue::ECARTEE) {
            return null;
        }

        $propose = $facture->rapprochementPropose();
        $achat   = $propose['achat'];

        if (!$achat instanceof Achat) {
            return null;
        }

        $ecart = $propose['ecart_ttc'];

        DB::transaction(function () use ($facture, $achat, $ecart) {
            $facture->update([
                'achat_id'             => $achat->id,
                'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
                // Le site vient de l'achat : c'est la même pièce, et la ranger
                // ailleurs ferait porter la charge par un établissement qui ne
                // l'a pas supportée.
                'point_de_vente_id'    => $achat->point_de_vente_id,
                'note_rapprochement'   => $ecart
                    ? sprintf(
                        'Rapprochée automatiquement malgré un écart de %s F sur le TTC.',
                        number_format((float) $ecart, 2, ',', ' ')
                    )
                    : 'Rapprochée automatiquement.',
            ]);
        });

        // L'écart n'empêche pas le rattachement — c'est la même pièce — mais il
        // ne doit pas se taire : un montant qui diffère est soit une remise
        // oubliée à la saisie, soit une facture qui n'est pas celle qu'on croit.
        if ($ecart) {
            Log::info('Facture reçue rapprochée avec un écart de montant', [
                'facture'   => $facture->reference,
                'achat'     => $achat->numero_facture,
                'ecart_ttc' => $ecart,
            ]);
        }

        return (bool) $ecart;
    }
}
