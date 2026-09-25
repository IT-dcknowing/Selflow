<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que le client a tendu, et ce qu'on lui a rendu.
 *
 * `montant_paye` était un accesseur : la somme des encaissements de la
 * trésorerie. Il répond à « combien a-t-on encaissé », jamais à « combien le
 * client a-t-il tendu ». Une caisse qui reçoit 10 000 F pour une pièce de
 * 6 500 F enregistrait 10 000 F d'encaissement et ne rendait la monnaie nulle
 * part : la trésorerie était gonflée de 3 500 F, et le ticket ne disait pas au
 * client ce qu'on lui devait.
 *
 * Deux montants distincts, donc :
 *
 * - `montant_recu` — ce que le client a tendu. Il ne sert qu'à établir la
 *   monnaie rendue et à l'imprimer ;
 * - l'encaissement, qui reste plafonné au net à payer.
 *
 * La monnaie rendue elle-même n'est **pas** une colonne : elle se calcule, et
 * une valeur calculée qu'on enregistre finit par contredire ses termes. Voir
 * `Vente::monnaieRendue()` et `Achat::monnaieRendue()`.
 *
 * Rien ici ne touche à la FNE : aucun champ du payload ne porte la somme
 * tendue, et la plateforme reçoit le même net à payer qu'avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ventes', 'achats'] as $table) {
            Schema::table($table, function (Blueprint $colonnes) use ($table) {
                if (!Schema::hasColumn($table, 'montant_recu')) {
                    $colonnes->decimal('montant_recu', 15, 2)
                        ->nullable()
                        ->after('montant_ttc')
                        ->comment('Somme tendue par le client ; la monnaie rendue s\'en déduit.');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['ventes', 'achats'] as $table) {
            Schema::table($table, function (Blueprint $colonnes) use ($table) {
                if (Schema::hasColumn($table, 'montant_recu')) {
                    $colonnes->dropColumn('montant_recu');
                }
            });
        }
    }
};
