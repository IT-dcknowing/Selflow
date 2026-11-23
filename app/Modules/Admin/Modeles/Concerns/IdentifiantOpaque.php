<?php

namespace App\Modules\Admin\Modeles\Concerns;

use Illuminate\Support\Str;

/**
 * Un identifiant tiré au hasard, dans les adresses, à la place du numéro de
 * ligne.
 *
 * ## Ce que le numéro séquentiel disait
 *
 * `/admin/ventes/facture/4213` porte une information que personne n'a voulu
 * publier : **le nombre de factures de la plateforme**. Le lot 8.3 a fermé
 * l'oracle en répondant 404 (page introuvable) sur la pièce d'autrui comme sur
 * une pièce inexistante — mais l'adresse elle-même parle encore, et elle sort de
 * l'application : dans un courriel transféré, une capture d'écran, un billet
 * d'assistance, l'historique d'un navigateur partagé.
 *
 * ## Une identité, une seule
 *
 * La clé primaire reste l'entier : elle sert les jointures, les index et les
 * clés étrangères, où elle est plus rapide et plus compacte. L'`uuid` ne sert
 * qu'à **désigner la ressource de l'extérieur** — dans les adresses, et dans ce
 * que l'API rend.
 *
 * Le point important : `getRouteKeyName()` vaut pour **toutes** les routes du
 * modèle, web et API confondues. C'est ce qui donne une identité unique plutôt
 * que deux — le numéro sur le mobile, l'`uuid` sur le web — qui auraient rendu
 * impossible de rapprocher un journal du serveur, un billet d'assistance et une
 * capture d'écran.
 *
 * ## Ce que le modèle doit fournir
 *
 * Une colonne `uuid`, unique et indexée, et `uuid` dans `$fillable` n'est pas
 * nécessaire : le trait la remplit lui-même à la création, avant tout
 * enregistrement, et **jamais depuis une requête**.
 */
trait IdentifiantOpaque
{
    public static function bootIdentifiantOpaque(): void
    {
        static::creating(function ($modele) {
            // La valeur ne vient jamais de l'extérieur : la poser ici, et non
            // par `$fillable`, empêche qu'une requête choisisse l'identifiant
            // d'une ressource — et donc qu'elle le devine à l'avance.
            if (empty($modele->uuid)) {
                $modele->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Ce que les adresses portent.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * L'`uuid`, même quand la requête ne l'a pas chargé (23/11/2026).
     *
     * Une liste chargée en colonnes choisies (`with('entreprises:id,nom')`,
     * `get(['id', 'nom'])`) donnait des modèles sans `uuid` : le premier
     * `route(…, $modele)` levait « Missing required parameter » et **toute la
     * page tombait en panne** (SF-261123-EED9CF : le menu « Changer
     * d'entreprise » d'un administrateur membre d'un groupe). Le modèle va
     * chercher son identifiant lui-même, une fois, plutôt que de compter sur
     * chaque appelant.
     */
    public function getRouteKey()
    {
        $cle = $this->getAttribute($this->getRouteKeyName());

        if (($cle === null || $cle === '') && $this->exists && $this->getKey() !== null
            && ! array_key_exists($this->getRouteKeyName(), $this->getAttributes())) {
            $cle = static::query()->withoutGlobalScopes()->whereKey($this->getKey())->value($this->getRouteKeyName());
            // Posé comme valeur d'origine : le modèle ne devient pas « modifié ».
            $this->attributes[$this->getRouteKeyName()] = $cle;
            $this->original[$this->getRouteKeyName()] = $cle;
        }

        return $cle;
    }

    /**
     * Une copie n'hérite pas de l'identifiant de l'original.
     *
     * `replicate()` recopie tous les attributs non exclus, et l'`uuid` en fait
     * partie : la copie naissait donc avec l'identifiant de la pièce dont elle
     * est issue. Deux conséquences, toutes deux constatées quand le devis
     * opposable a été converti pour la première fois :
     *
     * - **la contrainte d'unicité refusait l'enregistrement** — un bon de
     *   commande ne pouvait plus naître d'un devis ;
     * - et si elle ne l'avait pas refusé, **l'adresse du devis aurait désigné
     *   sa commande**, ce qui est exactement ce que l'identifiant sert à
     *   empêcher.
     *
     * L'exclusion est ici, dans le trait, et non dans chaque appelant : un
     * `replicate()` écrit demain ailleurs hériterait sinon du même défaut.
     */
    public function replicate(?array $except = null)
    {
        $copie = parent::replicate(array_unique(array_merge($except ?? [], ['uuid'])));

        $copie->uuid = null;

        return $copie;
    }
}
