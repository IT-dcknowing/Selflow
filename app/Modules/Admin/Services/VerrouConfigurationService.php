<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'une entreprise ne peut plus défaire de sa configuration.
 *
 * Le parcours de configuration se reprend autant de fois qu'on veut, et il est
 * **additif** presque partout : rechoisir un domaine ou cocher un métier de
 * plus n'enlève rien — la souscription passe sur ce qui existe déjà sans y
 * toucher. Un utilisateur qui ouvre une activité nouvelle la trouve donc
 * ouverte, et c'est bien ce qu'on veut.
 *
 * **Une seule étape défait.** L'étape des modules écrit `modules_actifs` par
 * intersection : décocher un module le ferme. Fermer « Comptabilité » sur une
 * entreprise qui tient ses écritures depuis six mois ne supprime rien, mais
 * fait disparaître de la barre latérale l'écran où ces écritures se lisent —
 * et rien à l'écran ne prévient que le module qu'on décoche porte des données.
 *
 * D'où cette règle : **un module qui porte des données ne se referme plus.**
 * Elle ne s'applique qu'à ce qui est vérifiable — un comptage de lignes dans
 * la table du module. Ce qu'on ne sait pas compter reste libre, plutôt que
 * verrouillé « au cas où » : un verrou sans motif se contourne, et le suivant
 * ne sera plus cru.
 */
class VerrouConfigurationService
{
    /**
     * Où chaque module laisse sa trace.
     *
     * La table, et **par quelle colonne elle se rattache à l'entreprise**.
     * Toutes ne portent pas `entreprise_id` : une vente, un achat et un
     * mouvement de stock appartiennent à un point de vente, qui appartient à
     * l'entreprise. Compter sur `entreprise_id` partout aurait laissé trois
     * modules sur cinq sans verrou, sans rien dire.
     *
     * Les articles et les tiers en sont absents à dessein : la souscription en
     * crée des dizaines d'office, leur simple présence ne prouve aucun usage.
     *
     * @var array<string, array{table: string, par: string}>
     */
    private const TRACES = [
        'ventes'        => ['table' => 'ventes',               'par' => 'point_de_vente_id'],
        'achats'        => ['table' => 'achats',               'par' => 'point_de_vente_id'],
        'stock'         => ['table' => 'mouvements_stock',     'par' => 'point_de_vente_id'],
        'comptabilite'  => ['table' => 'ecritures_comptables', 'par' => 'entreprise_id'],
        'production'    => ['table' => 'ordres_production',    'par' => 'entreprise_id'],
    ];

    /** @var array<string, bool> */
    private static array $tracesPresentes = [];

    /**
     * La table et sa colonne existent-elles ? Demandé une fois par processus :
     * sur un MySQL mutualisé, interroger `information_schema` à chaque
     * affichage coûtait plus que tout le reste de l'écran (audit du 16/10/2026).
     */
    private static function tracePresente(string $table, string $colonne): bool
    {
        return self::$tracesPresentes["{$table}.{$colonne}"] ??=
            Schema::hasTable($table) && Schema::hasColumn($table, $colonne);
    }

    /**
     * Les modules que l'entreprise ne peut plus refermer, et pourquoi.
     *
     * @return array<string, string>  module => phrase à afficher
     */
    public static function modulesVerrouilles(Entreprise $entreprise): array
    {
        $verrous = [];
        $sites   = null;

        foreach (self::TRACES as $module => $trace) {
            // Un module structurel est déjà indéracinable, et une table peut
            // manquer sur une base partiellement migrée : ni l'un ni l'autre
            // ne doit faire tomber l'écran de configuration.
            if (in_array($module, Entreprise::MODULES_STRUCTURELS, true)
                || !self::tracePresente($trace['table'], $trace['par'])) {
                continue;
            }

            if ($trace['par'] === 'point_de_vente_id') {
                $sites ??= DB::table('points_de_vente')
                    ->where('entreprise_id', $entreprise->id)->pluck('id')->all();

                $nombre = $sites === []
                    ? 0
                    : DB::table($trace['table'])->whereIn('point_de_vente_id', $sites)->count();
            } else {
                $nombre = DB::table($trace['table'])->where('entreprise_id', $entreprise->id)->count();
            }

            if ($nombre > 0) {
                $verrous[$module] = self::phrase($module, $nombre);
            }
        }

        return $verrous;
    }

    /**
     * Rouvrir les modules fermés qui portent pourtant des données.
     *
     * Le verrou empêche d'en refermer un ; il ne répare pas ceux qui l'ont été
     * avant qu'il existe. Une entreprise s'est retrouvée avec des mouvements de
     * stock et aucune section « Stock » dans son menu : la marchandise était
     * comptée, valorisée, reprise dans les écritures — et l'écran où elle se
     * lit avait disparu.
     *
     * L'opération est **idempotente et n'ajoute rien d'autre** : elle ne rouvre
     * qu'un module dont on a compté les lignes. Elle est appelée à l'affichage
     * des écrans de configuration, ce qui est inhabituel pour une lecture ;
     * l'alternative était de laisser des données hors d'atteinte jusqu'à ce que
     * quelqu'un pense à rouvrir la bonne case.
     *
     * @return array<int, string>  les modules rouverts, vide si rien à faire
     */
    public static function reconcilier(Entreprise $entreprise): array
    {
        $ouverts = $entreprise->modules_actifs ?? [];
        $manquants = array_values(array_diff(
            array_keys(self::modulesVerrouilles($entreprise)),
            $ouverts
        ));

        if ($manquants === []) {
            return [];
        }

        $entreprise->update([
            'modules_actifs' => array_values(array_unique(array_merge($ouverts, $manquants))),
        ]);

        return $manquants;
    }

    /**
     * Les métiers déjà souscrits.
     *
     * Ils ne se décochent pas — non par verrou, mais parce que rien ne les
     * décoche : la souscription n'enlève jamais un profil. L'écran doit le
     * dire, faute de quoi l'utilisateur croit avoir retiré un métier qui est
     * toujours là, avec ses rayons et ses articles.
     *
     * @return array<int, string>  codes de profil
     */
    public static function profilsAcquis(Entreprise $entreprise): array
    {
        return $entreprise->profils()->pluck('code')->all();
    }

    /**
     * Les domaines d'activité que l'entreprise porte réellement.
     *
     * Ils se lisent des métiers souscrits, et de nulle part ailleurs. La
     * colonne `secteur_activite` se saisissait à la main dans les paramètres,
     * à côté d'un parcours qui posait la même question autrement : une
     * entreprise pouvait cocher « Santé » dans un écran et souscrire au métier
     * « Boulangerie » dans l'autre, sans que rien ne les rapproche.
     *
     * @return array<int, string>
     */
    public static function domainesSouscrits(Entreprise $entreprise): array
    {
        $noms = $entreprise->profils()
            ->with('categorie')
            ->get()
            ->map(fn ($profil) => $profil->categorie?->nom)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $noms;
    }

    /**
     * Aligner `secteur_activite` sur les métiers souscrits.
     *
     * Appelée à chaque passage du parcours. Elle ne vide jamais la colonne :
     * une entreprise qui rouvre son parcours avant d'avoir souscrit quoi que
     * ce soit perdrait son secteur, et avec lui la complétude de son
     * inscription — donc l'accès à ses propres écrans.
     */
    public static function alignerLeSecteur(Entreprise $entreprise): void
    {
        $domaines = self::domainesSouscrits($entreprise);

        if ($domaines === []) {
            return;
        }

        if ($entreprise->secteur_activite !== $domaines) {
            $entreprise->update(['secteur_activite' => $domaines]);
        }
    }

    private static function phrase(string $module, int $nombre): string
    {
        $mots = [
            'ventes'       => ['vente enregistrée', 'ventes enregistrées'],
            'achats'       => ['achat enregistré', 'achats enregistrés'],
            'comptabilite' => ['écriture comptable', 'écritures comptables'],
            'production'   => ['ordre de production', 'ordres de production'],
            'stock'        => ['mouvement de stock', 'mouvements de stock'],
        ];

        [$singulier, $pluriel] = $mots[$module] ?? ['donnée', 'données'];

        return sprintf('%d %s — ce module ne se referme plus.', $nombre, $nombre > 1 ? $pluriel : $singulier);
    }
}
