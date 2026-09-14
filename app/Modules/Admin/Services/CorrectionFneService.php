<?php

namespace App\Modules\Admin\Services;

use App\Jobs\NormaliserAchatBapaJob;
use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Support\Facades\Log;

/**
 * Applique au paramétrage ce que le portail déclare, et renvoie les pièces.
 *
 * ## Pourquoi ce service existe
 *
 * Le rapprochement dit « vous avez envoyé *FACTURATION SIEGE*, le portail
 * déclare *FACTURATION SIÈGE* ». Jusqu'ici, la suite était un geste humain :
 * un clic pour renommer, un autre pour renvoyer la pièce, écran par écran.
 * **Le propriétaire du projet a demandé, le 29/08/2026, que ce cas se referme
 * tout seul.**
 *
 * ## Ce qu'il corrige, et ce qu'il ne corrigera jamais
 *
 * Un seul champ : le **nom du point de vente**. C'est un libellé descriptif,
 * dont le portail est la source de vérité — la DGI refuse la pièce précisément
 * parce qu'il ne correspond pas à ce qu'elle a enregistré.
 *
 * Les trois champs de la fiche qui commandent le comportement fiscal —
 * `timbre_quittance`, `bapa`, `sticker_solde_alerte` — **restent montrés et
 * jamais appliqués**. Les recopier ferait changer le contenu d'une facture
 * parce qu'un fichier est arrivé dans un dossier. C'est la règle d'or du
 * projet, et l'automatisation demandée ne la lève pas : elle porte sur un nom,
 * pas sur un montant.
 *
 * ## Ce qui empêche la boucle
 *
 * Rien n'est appliqué si le point de vente porte déjà le nom déclaré. Après un
 * renommage, le rapprochement suivant conclut « concordant » et ne propose plus
 * rien : une pièce refusée deux fois pour la même raison s'arrête d'elle-même,
 * sans compteur à tenir.
 */
class CorrectionFneService
{
    /**
     * Le seul champ du payload que Selflow corrige seul.
     *
     * `establishment` ne s'y ajoutera pas : le portail n'en publie que
     * l'identifiant, jamais le nom, et corriger sur un identifiant reviendrait
     * à deviner.
     */
    public const CHAMP_CORRIGEABLE = 'pointOfSale';

    /**
     * La correction automatique est-elle allumée ?
     *
     * Allumée par défaut, comme demandé. L'interrupteur existe pour qu'on
     * puisse l'éteindre sans livrer une version : renommer un point de vente
     * touche toutes les pièces qui suivront, et une entreprise doit pouvoir
     * reprendre la main sans attendre.
     */
    public function estActive(): bool
    {
        return (bool) config('selflow.portail_fne.correction_auto', true);
    }

    /**
     * La valeur du portail à appliquer, s'il y en a une.
     *
     * **Une seule règle, lue à deux endroits** — l'écran des rejets et le
     * passage horaire. La dédoubler, c'est s'assurer qu'une des deux copies
     * dérivera : le journal en garde un précédent, la liste des modules socle
     * qui vivait en double et avait perdu `points_de_vente` des deux côtés.
     */
    public function correctionApplicable(FneRejet $rejet): ?string
    {
        foreach ($rejet->diagnostic['champs'] ?? [] as $champ) {
            if (($champ['champ'] ?? null) !== self::CHAMP_CORRIGEABLE
                || ($champ['verdict'] ?? null) !== 'ecart') {
                continue;
            }

            // Un seul nom déclaré au portail : il n'y a pas d'ambiguïté.
            // Plusieurs : la machine ne choisit pas à la place de qui a établi
            // la pièce, et l'automatisme s'abstient comme le bouton s'abstenait.
            $declares = $champ['portail'] ?? [];

            return count($declares) === 1 ? (string) $declares[0] : null;
        }

        return null;
    }

    /**
     * Les noms que le portail déclare, entre lesquels il faut choisir.
     *
     * L'automatisme s'abstient quand il y en a plusieurs — il ne choisit pas à
     * la place de qui a établi la pièce. Mais **quelqu'un** peut choisir, et
     * pour cela il faut lui montrer la liste. C'est la seule chose que ce
     * service en dit : l'affichage est au contrôleur, la décision à l'humain.
     *
     * Rendue vide quand il n'y a rien à trancher — pas de rapprochement, aucun
     * écart sur le nom, ou un seul nom déclaré, que l'automatisme applique
     * déjà seul.
     *
     * @return array<int, string>
     */
    public function nomsAuChoix(FneRejet $rejet): array
    {
        foreach ($rejet->diagnostic['champs'] ?? [] as $champ) {
            if (($champ['champ'] ?? null) !== self::CHAMP_CORRIGEABLE
                || ($champ['verdict'] ?? null) !== 'ecart') {
                continue;
            }

            $declares = array_values(array_filter(
                array_map(fn ($nom) => trim((string) $nom), $champ['portail'] ?? []),
                fn (string $nom) => $nom !== ''
            ));

            return count($declares) > 1 ? $declares : [];
        }

        return [];
    }

    /**
     * Le nom déclaré que désigne ce choix, ou `null` s'il n'en désigne aucun.
     *
     * Un rang dans la liste, et non le nom lui-même : ce qui revient du
     * navigateur ne sert alors qu'à **désigner** une valeur que le
     * rapprochement a écrite, et ne peut pas en introduire une autre. Un
     * formulaire forgé renomme au pire un point de vente avec un nom que le
     * portail déclare déjà — c'est-à-dire rien de neuf.
     */
    public function nomAuRang(FneRejet $rejet, int $rang): ?string
    {
        return $this->nomsAuChoix($rejet)[$rang] ?? null;
    }

    /**
     * Le nom choisi, s'il figure bien parmi ceux que le portail déclare.
     *
     * La vérification se refait ici et pas seulement au contrôleur : c'est le
     * dernier point avant le renommage, et c'est ce qui garantit qu'un nom ne
     * peut pas entrer par ce chemin sans que le portail l'ait déclaré.
     */
    private function nomRetenu(FneRejet $rejet, string $choisi): ?string
    {
        foreach ($this->nomsAuChoix($rejet) as $declare) {
            if ($declare === trim($choisi)) {
                return $declare;
            }
        }

        return null;
    }

    /**
     * Renomme le point de vente, puis renvoie les pièces que ce nom bloquait.
     *
     * @return array{
     *   ancien: string,
     *   nouveau: string,
     *   point_de_vente_id: int,
     *   renvoyees: int,
     *   suspendues: int
     * }|null  `null` si rien n'était applicable.
     */
    /**
     * @param  bool  $synchrone  Envoyer les pièces à la DGI dans la foulée
     *   (dispatchSync), plutôt que de les mettre en file. Un bouton attend un
     *   résultat immédiat — la certification doit avoir eu lieu quand le
     *   message s'affiche ; l'ordonnanceur nocturne, lui, garde la file.
     * @param  string|null  $choisi  Le nom retenu **par un humain** quand le
     *   portail en déclare plusieurs. La machine continue de s'abstenir dans ce
     *   cas ; ce paramètre n'est jamais rempli par elle. Il doit désigner un nom
     *   que le rapprochement a effectivement relevé au portail — sans quoi rien
     *   n'est appliqué, et le geste n'ouvre pas la porte à un renommage libre.
     */
    public function corriger(FneRejet $rejet, bool $synchrone = false, ?string $choisi = null): ?array
    {
        $correction = $choisi === null
            ? $this->correctionApplicable($rejet)
            : $this->nomRetenu($rejet, $choisi);

        if ($correction === null) {
            return null;
        }

        $piece = $rejet->piece();
        $pdv   = $piece?->pointDeVente;

        if (!$pdv instanceof PointDeVente) {
            return null;
        }

        // Le cloisonnement se vérifie ici aussi, et non dans le seul contrôleur :
        // le passage horaire n'a pas d'utilisateur connecté pour le porter.
        if ($rejet->entreprise_id !== null && $pdv->entreprise_id !== $rejet->entreprise_id) {
            return null;
        }

        // Déjà au nom déclaré : il n'y a rien à faire, et c'est ce qui empêche
        // une pièce refusée pour une autre raison de faire tourner la machine.
        if (trim((string) $pdv->nom) === trim($correction)) {
            return null;
        }

        // Un point de vente porte-t-il DÉJÀ le nom déclaré ?
        $cible = PointDeVente::where('entreprise_id', $pdv->entreprise_id)
            ->whereRaw('TRIM(nom) = ?', [trim($correction)])
            ->first();

        $mode = 'bascule';

        // Si aucun point ne porte ce nom dans Selflow : on CRÉE le nouveau point de vente.
        // Règle d'or : On ne modifie ni ne renomme JAMAIS un point de vente existant.
        if (!$cible instanceof PointDeVente) {
            $cible = PointDeVente::create([
                'entreprise_id' => $pdv->entreprise_id,
                'nom'           => trim($correction),
                'ville'         => $pdv->ville ?? '',
                'commune'       => $pdv->commune ?? '',
                'statut'        => 'Ouvert',
            ]);

            if (method_exists($cible, 'initialiserLesFichesDeStock')) {
                $cible->initialiserLesFichesDeStock();
            }

            $mode = 'cree';

            Log::info('FNE : nouveau point de vente créé automatiquement dans Selflow d\'après le portail', [
                'point_de_vente' => $cible->id,
                'nom'            => $cible->nom,
                'rejet'          => $rejet->id,
                'entreprise'     => $rejet->entreprise_id,
            ]);
        }

        // On bascule les pièces rejetées vers le point de vente cible (existant ou nouvellement créé)
        $resultat = $this->basculerSurExistant($pdv, $cible, $rejet, $synchrone);
        $resultat['mode'] = $mode;

        return $resultat;
    }

    /**
     * Rattache les pièces refusées au point de vente qui porte déjà le bon nom.
     *
     * Plutôt que de renommer — ce qui poserait un second point de vente
     * homonyme à côté de celui déclaré au portail —, on déplace les pièces vers
     * le point existant, puis on les renvoie. Le point mal nommé reste tel quel,
     * mais aucune nouvelle pièce ne s'y ajoute par cette voie.
     *
     * @return array{
     *   mode: string, ancien: string, nouveau: string,
     *   point_de_vente_id: int, renvoyees: int, suspendues: int
     * }
     */
    private function basculerSurExistant(PointDeVente $malNomme, PointDeVente $existant, FneRejet $origine, bool $synchrone = false): array
    {
        Log::warning('FNE : pièces rattachées au point de vente existant plutôt que de dupliquer un nom', [
            'point_mal_nomme' => $malNomme->id,
            'nom_mal_nomme'   => $malNomme->nom,
            'point_existant'  => $existant->id,
            'nom'             => $existant->nom,
            'rejet'           => $origine->id,
            'entreprise'      => $origine->entreprise_id,
        ]);

        $renvoyees  = 0;
        $suspendues = 0;

        $rejets = FneRejet::query()
            ->where('cause', FneRejet::CAUSE_DGI)
            ->whereIn('statut', [FneRejet::STATUT_OUVERT, FneRejet::STATUT_DIAGNOSTIQUE])
            ->when($origine->entreprise_id, fn ($q) => $q->where('entreprise_id', $origine->entreprise_id))
            ->orderBy('id')
            ->get();

        foreach ($rejets as $rejet) {
            if (!in_array(self::CHAMP_CORRIGEABLE, $rejet->nomsDesChamps(), true)) {
                continue;
            }

            $piece = $rejet->piece();

            if ($piece === null
                || (int) $piece->point_de_vente_id !== (int) $malNomme->id
                || (bool) $piece->normalise) {
                continue;
            }

            // Rattacher au point existant. Écriture hors affectation en masse :
            // la valeur vient d'une décision de rapprochement, pas d'un
            // formulaire.
            $piece->point_de_vente_id = $existant->id;
            $piece->save();

            // Le constat portait sur l'ancien rattachement : il n'a plus cours.
            $rejet->update(['diagnostic' => null, 'statut' => FneRejet::STATUT_OUVERT]);

            if (!$this->peutPartirSeule($piece, $existant)) {
                $suspendues++;
                continue;
            }

            $this->renvoyer($piece, $synchrone);

            $renvoyees++;
        }

        return [
            'mode'              => 'bascule',
            'ancien'            => (string) $malNomme->nom,
            'nouveau'           => (string) $existant->nom,
            'point_de_vente_id' => $existant->id,
            'renvoyees'         => $renvoyees,
            'suspendues'        => $suspendues,
        ];
    }

    /**
     * Renvoie toutes les pièces que ce nom-là faisait refuser.
     *
     * **Toutes, et non la seule pièce du rejet traité.** Un nom mal orthographié
     * fait refuser tout ce qui part de ce point de vente : une soirée de saisie
     * laisse dix pièces refusées pour une seule cause. Ne renvoyer que la
     * première obligerait à reprendre les neuf autres à la main — et le
     * rapprochement, lui, les déclarerait désormais « concordantes », puisque la
     * valeur envoyée se relit sur le point de vente, qui vient d'être corrigé.
     * Elles seraient restées refusées sans que rien ne le rappelle.
     *
     * @return array{renvoyees: int, suspendues: int}
     */
    private function renvoyerLesPiecesRefusees(PointDeVente $pdv, FneRejet $origine, bool $synchrone = false): array
    {
        $renvoyees  = 0;
        $suspendues = 0;

        $rejets = FneRejet::query()
            ->where('cause', FneRejet::CAUSE_DGI)
            ->whereIn('statut', [FneRejet::STATUT_OUVERT, FneRejet::STATUT_DIAGNOSTIQUE])
            ->when($origine->entreprise_id, fn ($q) => $q->where('entreprise_id', $origine->entreprise_id))
            ->orderBy('id')
            ->get();

        foreach ($rejets as $rejet) {
            if (!in_array(self::CHAMP_CORRIGEABLE, $rejet->nomsDesChamps(), true)) {
                continue;
            }

            $piece = $rejet->piece();

            if ($piece === null
                || (int) $piece->point_de_vente_id !== (int) $pdv->id
                || (bool) $piece->normalise) {
                continue;
            }

            // Le constat décrivait un écart qui n'existe plus. Le laisser en
            // place afficherait comme actuel un rapprochement fait sur un nom
            // que le point de vente ne porte plus.
            $rejet->update(['diagnostic' => null, 'statut' => FneRejet::STATUT_OUVERT]);

            if (!$this->peutPartirSeule($piece, $pdv)) {
                $suspendues++;
                continue;
            }

            $this->renvoyer($piece, $synchrone);

            $renvoyees++;
        }

        return ['renvoyees' => $renvoyees, 'suspendues' => $suspendues];
    }

    /**
     * Renvoyer une pièce à la DGI — tout de suite, ou en file.
     *
     * `dispatchSync` quand un bouton l'a demandé : la certification a eu lieu au
     * retour, et le message affiché dit vrai. `dispatch` sinon — l'ordonnanceur
     * ne bloque pas sur des dizaines d'appels réseau.
     *
     * @param  \App\Modules\Admin\Modeles\Vente|Achat  $piece
     */
    private function renvoyer($piece, bool $synchrone): void
    {
        if ($piece instanceof Achat) {
            $synchrone
                ? NormaliserAchatBapaJob::dispatchSync($piece)
                : NormaliserAchatBapaJob::dispatch($piece);

            return;
        }

        $synchrone
            ? NormaliserFactureFne::dispatchSync($piece)
            : NormaliserFactureFne::dispatch($piece);
    }

    /**
     * Cette pièce peut-elle repartir sans que personne ne le demande ?
     *
     * Une entreprise qui a décroché la normalisation automatique vérifie ses
     * pièces avant de les certifier — **et une pièce certifiée ne se reprend
     * pas**. Corriger le nom pour elle est un service ; renvoyer à sa place
     * serait passer outre un choix qu'elle a fait exprès. La correction
     * s'applique donc, le renvoi attend qu'elle le déclenche.
     *
     * @param  Vente|Achat  $piece
     */
    private function peutPartirSeule($piece, PointDeVente $pdv): bool
    {
        if (!$piece instanceof Vente) {
            return true;
        }

        return $pdv->entreprise?->normaliseAutomatiquement($piece) ?? true;
    }
}
