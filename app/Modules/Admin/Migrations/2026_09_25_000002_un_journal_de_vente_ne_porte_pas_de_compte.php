<?php

use App\Modules\Admin\Modeles\CodeJournal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le compte de contrepartie ne concerne que la trésorerie.
 *
 * C'est le 521 de la banque ou le 571 de la caisse : le compte que **toute**
 * écriture du journal met en jeu. Un journal de ventes n'en a pas — sa
 * contrepartie est le tiers de la pièce, qui change à chaque écriture ; un
 * journal d'achats non plus, ni les opérations diverses.
 *
 * La création l'a compris depuis le lot 20, et le référentiel des journaux par
 * défaut porte `"compte": null` sur `VTE`, `ACH`, `OD` et `RAN`. Mais les
 * entreprises créées **avant** gardent le compte qu'on leur avait posé : leur
 * écran des codes journaux affiche un 701… ou un 601… en face du journal des
 * ventes, comme si chaque vente le mouvementait.
 *
 * Ce passage l'efface. Il ne touche qu'aux types qui n'en portent pas, et il
 * est sans effet sur une base déjà propre.
 *
 * **Rien n'est perdu** : aucune écriture ne lit ce champ pour un journal de
 * vente ou d'achat — `ComptabiliteService` impute sur les comptes de la pièce,
 * pas sur celui du journal. Le champ était décoratif, et il décorait faux.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('codes_journaux')) {
            return;
        }

        $typesDeTresorerie = array_values(array_filter(
            CodeJournal::TYPES,
            fn (string $type) => CodeJournal::porteUnCompteDeTresorerie($type)
        ));

        DB::table('codes_journaux')
            ->whereNotIn('type', $typesDeTresorerie)
            ->whereNotNull('compte')
            ->update(['compte' => null]);
    }

    public function down(): void
    {
        // Un compte effacé ne se devine pas, et il ne manquait à personne :
        // rien ne le lisait. Le remettre demanderait de l'inventer.
    }
};
