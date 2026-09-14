<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use Illuminate\Database\Eloquent\Model;

/**
 * Rapproche une pièce refusée par la DGI du dernier relevé du portail.
 *
 * ## Ce qu'il rend
 *
 * Une phrase que l'utilisateur comprend, à la place d'un code. Au lieu de :
 *
 *     « Le nom du point de vente doit être déclaré à l'identique sur votre
 *       espace FNE. »
 *
 * il rend :
 *
 *     « Vous avez envoyé "FACTURATION SIEGE". Le portail, relevé le
 *       21/08/2026, déclare "FACTURATION SIÈGE" et "FACTURATION ANNEXE". »
 *
 * ## Ce qu'il ne fait pas
 *
 * **Il n'écrit rien et ne corrige rien.** Ni la pièce, ni l'entreprise, ni le
 * paramétrage. Il lit un rejet, lit un relevé, et rend la comparaison. Qui
 * décide de corriger, et quoi, reste un être humain — trois des champs du
 * portail (`timbre_quittance`, `bapa`, `sticker_solde_alerte`) commandent le
 * comportement fiscal, et une facture ne doit pas changer parce qu'un fichier
 * est arrivé dans un dossier.
 *
 * ## Ce qu'il ne peut pas dire
 *
 * Le portail ne porte pas tout. Un rejet sur `clientNcc` met en cause le NCC du
 * *client*, que le portail de l'entreprise n'affiche nulle part. Ces cas sont
 * rendus explicitement comme hors de portée, plutôt que passés sous silence :
 * un diagnostic muet se lit comme un diagnostic favorable.
 */
class DiagnosticFneService
{
    /**
     * Les champs du payload DGI que le portail sait éclairer.
     *
     * `pointOfSale` et `establishment` sont construits par
     * `FneService::normaliserFacture()` à partir du point de vente de la pièce
     * et du nom de l'entreprise (`FneService.php:169` et `:194`). On les
     * re-dérive ici plutôt que de les conserver au moment du rejet : la règle
     * d'or gèle `FneService`, donc ces deux expressions ne bougeront pas sous
     * nos pieds. Si un jour elles bougent, c'est que la règle a été levée, et
     * ce fichier fait partie de ce qu'il faudra relire.
     */
    private const CHAMPS_ECLAIRES = ['pointOfSale', 'establishment'];

    /**
     * Rapproche un rejet du dernier relevé disponible.
     *
     * @return array{
     *   rejet_id: int,
     *   releve: array{date: string|null, import_id: int, fiche_id: int}|null,
     *   champs: array<int, array<string, mixed>>,
     *   ecarts_fiche: array<string, array{portail: mixed, selflow: mixed}>,
     *   conclusion: string
     * }
     */
    public function diagnostiquer(FneRejet $rejet): array
    {
        $fiche  = $this->dernierReleve($rejet);
        $points = $fiche ? $this->pointsDuReleve($fiche) : [];

        $champs = [];

        foreach ($rejet->nomsDesChamps() as $champ) {
            $champs[] = $this->examiner($rejet, $champ, $fiche, $points);
        }

        // Les écarts de la fiche accompagnent le diagnostic sans en faire
        // partie : ils ne sont pas la cause du rejet, mais celui qui répare une
        // facture a intérêt à voir que le timbre de quittance diverge.
        $ecartsFiche = $fiche ? $fiche->ecartsAvecEntreprise() : [];

        return [
            'rejet_id'     => $rejet->id,
            'releve'       => $fiche ? [
                'date'      => $fiche->date_scraping?->format('d/m/Y'),
                'import_id' => $fiche->import_id,
                // L'identité du relevé, et pas seulement sa date : c'est elle
                // qui dit si un diagnostic décrit encore le dernier état connu
                // du portail. Une date formatée se compare mal, et deux relevés
                // du même jour existent.
                'fiche_id'  => $fiche->id,
                // Les points viennent de leur propre fichier, et donc de leur
                // propre date. Sans elle, un diagnostic posé sur les points de
                // la veille se croyait à jour tant que la fiche ne bougeait
                // pas — et la commande horaire le sautait pour toujours.
                'points_le' => $this->dateDesPoints($points),
                // Et leur identité, pour la même raison que `fiche_id` plus
                // haut : **deux relevés du même jour existent**. Le 31/08/2026,
                // un point renommé au portail à 20 h 44 est arrivé dans un
                // second relevé du 31/08 ; la date n'ayant pas bougé, le
                // rapprochement s'est cru à jour et a continué d'affirmer « le
                // portail confirme les valeurs envoyées » sur un constat périmé
                // d'une journée. Une date ne peut pas identifier un relevé.
                'points_id' => $this->identiteDesPoints($points),
            ] : null,
            'champs'       => $champs,
            'ecarts_fiche' => $ecartsFiche,
            'conclusion'   => $this->conclusion($fiche, $champs),
        ];
    }

    /**
     * Le relevé le plus récent pour l'entreprise du rejet.
     *
     * Par `login` autant que par `entreprise_id` : un relevé arrivé avant que
     * l'entreprise ne soit créée dans Selflow reste rattaché à son seul login,
     * et il vaut mieux un relevé orphelin que pas de relevé du tout.
     */
    public function dernierReleve(FneRejet $rejet): ?PortailFneFiche
    {
        $requete = PortailFneFiche::query()
            ->orderByDesc('date_scraping')
            ->orderByDesc('id');

        if ($rejet->entreprise_id) {
            $requete->where(function ($q) use ($rejet) {
                $q->where('entreprise_id', $rejet->entreprise_id);

                if ($rejet->login) {
                    $q->orWhere('login', $rejet->login);
                }
            });
        } elseif ($rejet->login) {
            $requete->where('login', $rejet->login);
        } else {
            return null;
        }

        return $requete->first();
    }

    /**
     * Le diagnostic de ce rejet décrit-il encore le dernier relevé connu ?
     *
     * Un rejet diagnostiqué n'était plus jamais repris : si le relevé du jour
     * était périmé, le constat restait périmé avec lui, et l'écran affichait
     * comme actuel un rapprochement fait sur des données mortes.
     *
     * Un diagnostic sans identité de relevé — ceux écrits avant que le champ
     * n'existe — est tenu pour dépassé : le rejouer une fois ne coûte rien.
     */
    public function diagnosticEstAJour(FneRejet $rejet): bool
    {
        $fiche = $this->dernierReleve($rejet);

        if ($fiche === null) {
            return false;
        }

        $connu = $rejet->diagnostic['releve']['fiche_id'] ?? null;

        if ($connu === null || $connu !== $fiche->id) {
            return false;
        }

        // La fiche n'a pas bougé ; les points, eux, ont pu changer seuls. C'est
        // même le cas qui compte : un point de facturation renommé au portail
        // ne touche pas à la fiche, et le constat devait se refaire.
        //
        // Sur l'identité du jeu, et non sur sa date. La date ne distingue pas
        // deux relevés du même jour, et c'est précisément ce qui est arrivé le
        // 31/08/2026 : le constat est resté figé sur un nom que le portail ne
        // déclarait plus.
        $connuDesPoints = $rejet->diagnostic['releve']['points_id'] ?? null;

        // Un diagnostic écrit avant que cette identité existe ne porte pas la
        // clé. Le refaire une fois vaut mieux que de le croire à jour sans
        // pouvoir le vérifier.
        if ($connuDesPoints === null) {
            return false;
        }

        return $connuDesPoints === $this->identiteDesPoints($this->pointsDuReleve($fiche));
    }

    /**
     * L'identité du jeu de points retenu, ou `null` s'il n'y en a aucun.
     *
     * Le relevé qui l'a écrit : un jeu est déposé d'un bloc — le service les
     * écrit tous ou aucun —, si bien que son import le désigne entièrement, et
     * qu'un jeu nouveau porte forcément un import nouveau.
     *
     * @param  array<int, PortailFnePointFacturation>  $points
     */
    private function identiteDesPoints(array $points): ?int
    {
        return $points === [] ? null : (int) $points[0]->import_id;
    }

    /**
     * La date du jeu de points retenu, ou `null` s'il n'y en a aucun.
     *
     * @param  array<int, PortailFnePointFacturation>  $points
     */
    private function dateDesPoints(array $points): ?string
    {
        return $points === []
            ? null
            : ($points[0]->date_scraping?->format('Y-m-d'));
    }

    /**
     * Les derniers points de facturation connus pour ce login.
     *
     * **Le dernier jeu, et non celui qui porte la date de la fiche.** Le
     * tableur et le JSON sont deux fichiers, donc deux relevés indépendants, et
     * l'import n'écrit que ce qui a changé : une fiche identique à celle de la
     * veille n'est pas réécrite, un tableur identique non plus. Leurs dates
     * divergent donc dès le deuxième passage, et c'est le cas ordinaire.
     *
     * Les apparier par égalité de date produisait deux constats faux, tous deux
     * observés :
     *
     * - la fiche n'a pas bougé, les points si — le rapprochement lisait les
     *   points de la veille et affichait un écart déjà corrigé au portail ;
     * - les points n'ont pas bougé, la fiche si — aucun point ne portait la
     *   date de la fiche, et le rapprochement annonçait « le relevé ne déclare
     *   aucun point de facturation actif » à une entreprise qui en déclare.
     *
     * Un jeu de points est complet à sa date — `rangerPoints()` les écrit tous
     * ou aucun — donc le plus récent décrit ce que le portail déclare.
     *
     * @return array<int, PortailFnePointFacturation>
     */
    private function pointsDuReleve(PortailFneFiche $fiche): array
    {
        // Le dernier jeu, et non ceux du dernier jour : deux relevés du même
        // jour empilaient leurs points, et le rapprochement citait deux fois
        // le même nom au responsable venu comprendre un refus.
        return PortailFnePointFacturation::dernierJeu('login', $fiche->login);
    }

    /**
     * @param  array<int, PortailFnePointFacturation>  $points
     * @return array<string, mixed>
     */
    private function examiner(FneRejet $rejet, string $champ, ?PortailFneFiche $fiche, array $points): array
    {
        $raison = $rejet->champs[$champ] ?? null;
        $raison = is_array($raison) ? implode(', ', array_map('strval', $raison)) : (string) $raison;

        $base = ['champ' => $champ, 'raison_dgi' => $raison];

        if (!in_array($champ, self::CHAMPS_ECLAIRES, true)) {
            return $base + [
                'verdict'     => 'hors_portee',
                'explication' => "Le portail FNE n'affiche pas ce champ : la comparaison "
                    . 'ne peut pas être faite ici.',
            ];
        }

        if ($fiche === null) {
            return $base + [
                'verdict'     => 'sans_releve',
                'explication' => "Aucun relevé du portail pour cette entreprise. "
                    . 'Une demande de relevé a été déposée.',
            ];
        }

        $envoye = $this->valeurEnvoyee($rejet, $champ);

        if ($champ === 'pointOfSale') {
            $declares = array_values(array_filter(array_map(
                fn (PortailFnePointFacturation $p) => $p->nom,
                array_filter($points, fn (PortailFnePointFacturation $p) => $p->estActif())
            )));

            return $base + [
                'envoye'      => $envoye,
                'portail'     => $declares,
                'verdict'     => $this->verdict($envoye, $declares),
                'explication' => $this->expliquerPointDeVente($envoye, $declares, $points, $fiche),
            ];
        }

        // `establishment` : le portail ne rend pas le nom de l'établissement,
        // seulement son identifiant DGI. On dit ce qu'on a, sans le maquiller
        // en réponse.
        $identifiants = array_values(array_unique(array_filter(array_map(
            fn (PortailFnePointFacturation $p) => $p->etablissement_id,
            $points
        ))));

        return $base + [
            'envoye'      => $envoye,
            'portail'     => $identifiants,
            'verdict'     => 'a_verifier',
            'explication' => sprintf(
                'Vous avez envoyé « %s ». Le portail ne publie pas le nom de '
                . "l'établissement, seulement son identifiant (%s) : le nom est à "
                . "relever à l'écran, sur la fiche de l'entreprise.",
                $envoye ?? '(vide)',
                $identifiants ? implode(', ', $identifiants) : 'aucun'
            ),
        ];
    }

    /**
     * La valeur que la pièce a fait partir, re-dérivée depuis la pièce.
     */
    private function valeurEnvoyee(FneRejet $rejet, string $champ): ?string
    {
        $piece = $rejet->piece();

        if (!$piece instanceof Model) {
            return null;
        }

        return match ($champ) {
            'pointOfSale'   => trim($piece->pointDeVente?->nom ?: 'Siège'),
            'establishment' => $piece->pointDeVente?->entreprise?->nom,
            default         => null,
        };
    }

    /**
     * @param  array<int, string>  $declares
     */
    private function verdict(?string $envoye, array $declares): string
    {
        if ($declares === []) {
            return 'sans_releve';
        }

        if ($envoye !== null && in_array($envoye, $declares, true)) {
            return 'concordant';
        }

        return 'ecart';
    }

    /**
     * La date citée est celle des **points**, pas celle de la fiche.
     *
     * Les deux fichiers arrivent séparément et ne sont pas réécrits quand leur
     * contenu ne change pas : dater les points du jour de la fiche revenait à
     * attribuer au portail, à une date donnée, une liste qu'il affichait un
     * autre jour. Celui qui va vérifier au portail doit savoir de quand date ce
     * qu'on lui montre.
     *
     * @param  array<int, string>  $declares
     * @param  array<int, PortailFnePointFacturation>  $points
     */
    private function expliquerPointDeVente(?string $envoye, array $declares, array $points, PortailFneFiche $fiche): string
    {
        $date = ($points !== [] ? $points[0]->date_scraping?->format('d/m/Y') : null)
            ?? $fiche->date_scraping?->format('d/m/Y')
            ?? 'date inconnue';

        if ($declares === []) {
            return "Le relevé du {$date} ne déclare aucun point de facturation actif.";
        }

        $liste = '« ' . implode(' », « ', $declares) . ' »';

        if ($envoye !== null && in_array($envoye, $declares, true)) {
            return sprintf(
                'Le point de vente « %s » est bien déclaré au portail (relevé du %s). '
                . "Le refus vient d'ailleurs.",
                $envoye,
                $date
            );
        }

        $proche = $this->plusProche($envoye, $declares);

        return sprintf(
            'Vous avez envoyé « %s ». Le portail, relevé le %s, déclare %s.%s',
            $envoye ?? '(vide)',
            $date,
            $liste,
            $proche ? " Le plus proche est « {$proche} »." : ''
        );
    }

    /**
     * Le nom déclaré le plus proche de ce qui a été envoyé.
     *
     * Les écarts observés sont des accents et des espaces — « FACTURATION
     * SIEGE » contre « FACTURATION SIÈGE ». Une distance de Levenshtein
     * désigne le bon candidat là où une comparaison stricte ne dit que « non ».
     *
     * @param  array<int, string>  $declares
     */
    private function plusProche(?string $envoye, array $declares): ?string
    {
        if ($envoye === null || $envoye === '' || $declares === []) {
            return null;
        }

        $meilleur = null;
        $distance = PHP_INT_MAX;

        foreach ($declares as $candidat) {
            // `levenshtein` travaille en octets : deux chaînes UTF-8 très
            // proches à l'oeil le sont un peu moins pour lui, un accent pesant
            // deux octets. Suffisant pour désigner un candidat, jamais utilisé
            // pour décider seul.
            $d = levenshtein(mb_strtoupper($envoye), mb_strtoupper($candidat));

            if ($d < $distance) {
                $distance = $d;
                $meilleur = $candidat;
            }
        }

        // Au-delà de la moitié de la longueur, ce n'est plus une faute de
        // frappe mais un autre point de vente : le proposer induirait en erreur.
        return $distance <= max(3, (int) floor(mb_strlen($envoye) / 2)) ? $meilleur : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $champs
     */
    private function conclusion(?PortailFneFiche $fiche, array $champs): string
    {
        if ($fiche === null) {
            return "Aucun relevé du portail n'est disponible pour cette entreprise : "
                . 'le diagnostic se limite au message de la plateforme.';
        }

        $ecarts = array_filter($champs, fn (array $c) => ($c['verdict'] ?? null) === 'ecart');

        if ($ecarts !== []) {
            return 'Le portail et la pièce ne disent pas la même chose. La correction '
                . 'est à appliquer manuellement, après vérification.';
        }

        return 'Le portail confirme les valeurs envoyées : la cause du refus est ailleurs. '
            . 'Le message de la plateforme fait foi.';
    }
}
