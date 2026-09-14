<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use Illuminate\Support\Facades\Log;

/**
 * Les points de facturation déclarés au portail FNE, repris dans Selflow.
 *
 * ## Le sens, et un seul
 *
 * Du portail vers Selflow. Jamais l'inverse : déclarer un point de facturation
 * à la DGI est un acte du contribuable, pas une écriture technique, et le
 * scraper ne fait que lire. Ce qui est repris ici, c'est **le nom que le
 * portail écrit** — celui-là même que `pointOfSale` doit porter pour qu'une
 * facture soit certifiée. Une entreprise qui reprend ses points au lieu de les
 * ressaisir ne se trompe ni d'accent, ni de casse.
 *
 * ## Pourquoi un geste, et non le passage nocturne
 *
 * Un point de vente porte des utilisateurs, du stock, des ventes, un site
 * comptable. Il ne doit pas naître à deux heures du matin parce qu'un fichier
 * est arrivé dans un dossier. Le relevé range ce qu'il a vu ; la reprise, elle,
 * se demande.
 *
 * ## L'appariement se fait sur l'identifiant, pas sur le nom
 *
 * Le lot 18 renomme les points de vente d'après le portail, et le lot 20 a dû
 * fusionner à la main trois « FACTURATION SIEGE » nés d'un appariement par nom.
 * Un point repris porte donc `etablissement_fne_id`, que le portail publie et
 * qui ne bouge pas quand l'intitulé change. Le nom ne sert qu'au premier
 * appariement, pour adopter un point déjà saisi à la main plutôt que d'en
 * créer un second.
 */
class PointsDeVentePortailService
{
    /**
     * Ce que le portail déclare, en face de ce que Selflow détient.
     *
     * @return array{
     *   releve_le: string|null,
     *   points: array<int, array{nom: string, etablissement: string|null, actif: bool, point_de_vente: PointDeVente|null}>,
     *   a_creer: int,
     *   inconnus_du_portail: array<int, PointDeVente>
     * }
     */
    public function comparer(Entreprise $entreprise): array
    {
        $declares = $this->derniersPointsDeclares($entreprise);
        $siens    = PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();

        $points     = [];
        $apparies   = [];

        foreach ($declares as $declare) {
            $correspondant = $this->correspondant($declare, $siens);

            if ($correspondant) {
                $apparies[] = $correspondant->id;
            }

            $points[] = [
                'nom'            => trim((string) $declare->nom),
                'etablissement'  => $declare->etablissement_id,
                'actif'          => $declare->estActif(),
                'point_de_vente' => $correspondant,
            ];
        }

        return [
            'releve_le' => $declares[0] ?? null
                ? optional($declares[0]->date_scraping)->format('d/m/Y')
                : null,
            'points'    => $points,
            'a_creer'   => count(array_filter($points, fn ($p) => $p['point_de_vente'] === null)),
            // Ce que Selflow porte et que le portail ne déclare pas : c'est de
            // là que viennent les refus sur `pointOfSale`.
            'inconnus_du_portail' => $siens
                ->reject(fn (PointDeVente $pdv) => in_array($pdv->id, $apparies, true))
                ->values()
                ->all(),
        ];
    }

    /**
     * Crée dans Selflow les points déclarés au portail qui n'y sont pas encore.
     *
     * @return array{
     *   releve: bool, crees: array<int, string>, adoptes: array<int, string>,
     *   deja_presents: int, quota_atteint: bool
     * }
     */
    public function importer(Entreprise $entreprise): array
    {
        $declares = $this->derniersPointsDeclares($entreprise);

        if ($declares === []) {
            return ['releve' => false, 'crees' => [], 'adoptes' => [], 'deja_presents' => 0, 'quota_atteint' => false];
        }

        $siens   = PointDeVente::where('entreprise_id', $entreprise->id)->get();
        $quota   = (int) $entreprise->quota_points_de_vente;
        $rapport = ['releve' => true, 'crees' => [], 'adoptes' => [], 'deja_presents' => 0, 'quota_atteint' => false];

        foreach ($declares as $declare) {
            $nom = trim((string) $declare->nom);

            if ($nom === '') {
                continue;
            }

            $correspondant = $this->correspondant($declare, $siens);

            if ($correspondant instanceof PointDeVente) {
                // Déjà là. S'il a été saisi à la main, il ne porte pas encore
                // l'identifiant du portail : on l'adopte, pour que le relevé
                // suivant le reconnaisse même si l'un des deux le renomme.
                if (!$correspondant->etablissement_fne_id && $this->identite($declare) !== null) {
                    $correspondant->update([
                        'etablissement_fne_id' => $declare->etablissement_id,
                        'point_fne_cree_a'     => $declare->cree_a,
                    ]);
                    $rapport['adoptes'][] = $correspondant->nom;
                } else {
                    $rapport['deja_presents']++;
                }

                continue;
            }

            // Le quota d'abonnement borne la reprise comme il borne la création
            // à la main : un relevé ne l'ouvre pas.
            if ($quota > 0 && $siens->count() >= $quota) {
                $rapport['quota_atteint'] = true;
                break;
            }

            $pdv = PointDeVente::create([
                'entreprise_id'        => $entreprise->id,
                'nom'                  => $nom,
                // Le portail ne publie ni ville ni commune : les inventer
                // écrirait dans Selflow une adresse que personne n'a déclarée.
                // Elles restent à compléter, comme à l'import CSV.
                'ville'                => '',
                'commune'              => '',
                'statut'               => $declare->estActif() ? 'Ouvert' : 'Fermé',
                'etablissement_fne_id' => $declare->etablissement_id,
                'point_fne_cree_a'     => $declare->cree_a,
            ]);

            $pdv->initialiserLesFichesDeStock();

            $siens->push($pdv);
            $rapport['crees'][] = $pdv->nom;
        }

        if ($rapport['crees'] !== [] || $rapport['adoptes'] !== []) {
            CacheService::invaliderPointsDeVente($entreprise->id);

            Log::info('FNE : points de facturation du portail repris dans Selflow', [
                'entreprise' => $entreprise->id,
                'crees'      => $rapport['crees'],
                'adoptes'    => $rapport['adoptes'],
            ]);
        }

        return $rapport;
    }

    /**
     * Le dernier jeu de points connu pour cette entreprise.
     *
     * Le dernier, quelle que soit sa date, et non ceux d'une date convenue :
     * un jeu est complet à son import — le service les écrit tous ou aucun —,
     * donc le plus récent décrit ce que le portail déclare. C'est la règle que
     * suit déjà `DiagnosticFneService`, pour l'avoir apprise à ses dépens au
     * lot 17.
     *
     * @return array<int, PortailFnePointFacturation>
     */
    private function derniersPointsDeclares(Entreprise $entreprise): array
    {
        return PortailFnePointFacturation::dernierJeu('entreprise_id', $entreprise->id);
    }

    /**
     * Le point de vente de Selflow qui correspond à ce point du portail.
     *
     * Par l'identité d'abord — établissement **et** date de création, qui
     * survit à un renommage des deux côtés —, par le nom ensuite, comparé sans
     * casse ni espaces de bord : c'est ainsi qu'un point déjà saisi à la main
     * se laisse adopter au lieu d'être dupliqué.
     *
     * L'établissement seul ne peut pas servir : le portail le donne identique
     * à tous les points d'un même établissement, et le premier point de vente
     * connu aurait répondu pour tous les autres.
     *
     * @param  \Illuminate\Support\Collection<int, PointDeVente>  $siens
     */
    private function correspondant(PortailFnePointFacturation $declare, $siens): ?PointDeVente
    {
        $nom = $this->nomComparable($declare->nom);

        if ($nom === '') {
            return null;
        }

        // On apparie par le nom : si un point de vente porte ce nom dans Selflow, il correspond.
        // Sinon, le point du portail est considéré comme manquant et sera créé sans renommer l'existant.
        return $siens->first(fn (PointDeVente $pdv) => $this->nomComparable($pdv->nom) === $nom);
    }

    /** L'identité du point déclaré, ou `null` quand le portail n'en donne aucune. */
    private function identite(PortailFnePointFacturation $declare): ?string
    {
        if (!$declare->etablissement_id && !$declare->cree_a) {
            return null;
        }

        return PortailFnePointFacturation::identite(
            $declare->etablissement_id,
            $declare->cree_a?->format('Y-m-d H:i:s'),
            $declare->nom
        );
    }

    private function nomComparable(?string $nom): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $nom)));
    }

}
