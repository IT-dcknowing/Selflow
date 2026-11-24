<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une catégorie se nomme dans son secteur, pas dans toute l'entreprise
 * (24/11/2026, audit du parcours de paramétrage).
 *
 * L'unicité (entreprise, nom) de 2026_06_29 faisait tomber en erreur 500 le
 * parcours dès qu'un même rayon (« Divers »…) servait à deux métiers : le
 * service crée volontairement une catégorie par (domaine, métier). Le code
 * garde l'unicité du triplet ; la base garde un index simple pour la recherche.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        $index = collect(Schema::getIndexes('categories'))->pluck('name')->all();

        Schema::table('categories', function (Blueprint $table) use ($index) {
            if (in_array('categories_entreprise_id_nom_unique', $index, true)) {
                // La clé étrangère sur entreprise_id s'appuie sur un index : on
                // pose le nouveau avant de retirer l'ancien (MySQL l'exige).
                if (! in_array('categories_entreprise_nom_index', $index, true)) {
                    $table->index(['entreprise_id', 'nom'], 'categories_entreprise_nom_index');
                }
            }
        });

        if (in_array('categories_entreprise_id_nom_unique', $index, true)) {
            Schema::table('categories', fn (Blueprint $table) => $table->dropUnique('categories_entreprise_id_nom_unique'));
        }
    }

    public function down(): void
    {
        // Rien : rétablir l'unicité échouerait sur les rayons partagés entre métiers.
    }
};
