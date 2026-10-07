<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFiche;

/**
 * Ce que le portail FNE détient de l'entreprise, et ce qu'on ne lui
 * redemande donc plus (chantier 10.4 du plan).
 *
 * ## Le relevé, fait avant de retirer quoi que ce soit
 *
 * Champ par champ, sur les quatre cartes des paramètres :
 *
 * | Carte | Ramené par le scraper | Pas ramené — reste à saisir |
 * |---|---|---|
 * | Informations générales | adresse, téléphone, e-mail | raison sociale, gérant (nom, prénom, fonction), RCCM, forme juridique |
 * | Identité fiscale | références bancaires | NCC (c'est l'identifiant du relevé, pas une donnée relevée), régime, centre des impôts |
 * | DGI & local professionnel | IDU, commune, quartier, référence cadastrale, propriétaire du local, solde d'alerte des stickers | — |
 * | Impression des factures | pied de page, autres mentions | — |
 *
 * ## Ce qui n'est jamais repris d'office
 *
 * `ImportPortailFneService` n'écrit rien dans `entreprises`, et c'est voulu :
 * `timbre_quittance`, `bapa` et `sticker_solde_alerte` changent ce que Selflow
 * fait d'une facture. Ils restent hors d'ici — le solde d'alerte reste donc à
 * l'écran, à saisir. Les onze autres champs sont descriptifs : ils se
 * reprennent, mais **d'un geste**, celui de l'utilisateur, et ce geste est
 * journalisé.
 *
 * Un champ ne disparaît de l'écran que si le portail le porte **et** que
 * Selflow porte la même valeur : masquer un champ dont la valeur n'est pas
 * encore chez nous ferait disparaître l'information sans moyen de la saisir.
 */
class ReleveDuPortailService
{
    /** Les champs descriptifs repris du portail, et leur nom lisible. */
    public const LIBELLES = [
        'email'                   => 'e-mail',
        'telephone'               => 'téléphone',
        'adresse'                 => 'adresse',
        'ref_bancaire'            => 'références bancaires',
        'idu'                     => 'IDU',
        'commune'                 => 'commune',
        'quartier'                => 'quartier',
        'reference_cadastrale'    => 'référence cadastrale',
        'proprietaire_local'      => 'propriétaire du local',
        'pied_de_page_facture'    => 'pied de page des factures',
        'facture_autres_mentions' => 'autres mentions des factures',
    ];

    /**
     * Le dernier relevé, ce qu'il confirme et ce qui en diffère.
     *
     * @return array{fiche: ?PortailFneFiche, identiques: array<int, string>, ecarts: array<string, array{portail: string, selflow: ?string}>}
     */
    public static function pour(Entreprise $entreprise): array
    {
        $fiche = self::dernierReleve($entreprise);
        $identiques = [];
        $ecarts = [];

        if ($fiche) {
            foreach (array_keys(self::LIBELLES) as $champ) {
                $portail = trim((string) ($fiche->{$champ} ?? ''));

                // Un champ que le portail n'a pas rendu ne prouve rien : il
                // reste à saisir chez nous.
                if ($portail === '') {
                    continue;
                }

                $selflow = trim((string) ($entreprise->{$champ} ?? ''));

                if ($portail === $selflow) {
                    $identiques[] = $champ;
                } else {
                    $ecarts[$champ] = ['portail' => $portail, 'selflow' => $selflow !== '' ? $selflow : null];
                }
            }
        }

        return ['fiche' => $fiche, 'identiques' => $identiques, 'ecarts' => $ecarts];
    }

    /**
     * Reprendre dans Selflow les valeurs descriptives du dernier relevé.
     *
     * @return array<string, array{portail: string, selflow: ?string}> ce qui a changé
     */
    public static function reprendre(Entreprise $entreprise): array
    {
        $ecarts = self::pour($entreprise)['ecarts'];

        if ($ecarts !== []) {
            $entreprise->update(array_map(fn (array $e) => $e['portail'], $ecarts));
        }

        return $ecarts;
    }

    private static function dernierReleve(Entreprise $entreprise): ?PortailFneFiche
    {
        return PortailFneFiche::query()
            ->where(function ($q) use ($entreprise) {
                $q->where('entreprise_id', $entreprise->id);

                if (filled($entreprise->ncc)) {
                    $q->orWhere('login', $entreprise->ncc);
                }
            })
            ->orderByDesc('date_scraping')
            ->orderByDesc('id')
            ->first();
    }
}
