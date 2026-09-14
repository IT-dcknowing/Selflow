<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'identifiant qu'un point de facturation porte au portail FNE.
 *
 * ## Pourquoi une colonne, et pas le nom
 *
 * Les points de vente créés depuis un relevé du portail doivent se reconnaître
 * au relevé suivant. Par le nom, cette reconnaissance casse au premier
 * renommage — et le renommage est précisément ce que la chaîne FNE passe son
 * temps à faire : le lot 18 aligne le nom de Selflow sur celui du portail, le
 * lot 20 a dû fusionner à la main trois « FACTURATION SIEGE » nés d'un
 * appariement par nom.
 *
 * `etablissement_id` est stable, publié par le portail à chaque relevé, et ne
 * change pas quand l'intitulé change.
 *
 * **Correction du même jour :** il ne suffit pas. Le relevé réel du 31/08/2026
 * a montré que le portail donne le même identifiant d'établissement à tous les
 * points d'un même établissement. C'est la paire avec la date de création qui
 * identifie un point — voir la migration `2026_08_31_000002`, qui pose la
 * colonne manquante plutôt que de retoucher celle-ci, déjà appliquée.
 *
 * ## Ce qu'elle ne fait pas
 *
 * Elle **ne part pas à la DGI**. Le champ `establishment` du payload FNE est
 * construit par `FneService`, qui est gelé, et cette migration ne le touche
 * pas. La colonne ne sert qu'à savoir, côté Selflow, quel point de vente
 * correspond à quel point de facturation du portail.
 */
return new class extends Migration {
    public function up(): void
    {
        // Ce qu'elle pose, elle vérifie d'abord que ce ne l'est pas : une
        // migration décrit un état, pas un geste.
        if (Schema::hasColumn('points_de_vente', 'etablissement_fne_id')) {
            return;
        }

        Schema::table('points_de_vente', function (Blueprint $table) {
            $table->string('etablissement_fne_id', 64)->nullable()->after('statut')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('points_de_vente', 'etablissement_fne_id')) {
            return;
        }

        Schema::table('points_de_vente', function (Blueprint $table) {
            $table->dropIndex(['etablissement_fne_id']);
            $table->dropColumn('etablissement_fne_id');
        });
    }
};
