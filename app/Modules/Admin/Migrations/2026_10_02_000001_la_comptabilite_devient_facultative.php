<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La comptabilité cesse d'être offerte à tout le monde.
 *
 * Décision du propriétaire, le 02/10/2026. Selflow est **vente, achat,
 * facturation, stock**. La comptabilité intervient pour ceux qui la veulent —
 * et ceux-là ont Comptaflow, qui tient les livres. Garder toute la
 * comptabilité dans Selflow ôtait l'envie d'aller dans Comptaflow.
 *
 * Deux colonnes, et elles ne disent pas la même chose :
 *
 * | Colonne | Qui la pose | Ce qu'elle veut dire |
 * |---|---|---|
 * | `comptabilite_activee` | L'entreprise, depuis ses paramètres | « Je veux voir mes écrans comptables » |
 * | `attributions` | Le superadministrateur, depuis l'écran Attributions | « Cette entreprise y a droit, quel que soit son statut » |
 *
 * Les confondre en une seule aurait fait qu'une entreprise décochant son
 * réglage annulerait ce que le superadministrateur lui a accordé — ou
 * l'inverse, qu'elle s'accorderait elle-même ce qui ne lui revient pas.
 *
 * ## Le défaut est FAUX, y compris pour les entreprises existantes
 *
 * C'est voulu, et c'est le point à connaître avant de déployer. Une entreprise
 * qui consultait hier son grand livre ne le verra plus. **Rien n'est supprimé**
 * — les écritures continuent d'être produites et rangées, et l'écran revient
 * entier d'une case à cocher, par l'entreprise elle-même ou par le
 * superadministrateur.
 *
 * L'inverse — ouvrir par défaut ce qui existe — aurait laissé le masquage sans
 * effet sur la seule population qui compte aujourd'hui, celle déjà en service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (!Schema::hasColumn('entreprises', 'comptabilite_activee')) {
                $table->boolean('comptabilite_activee')
                    ->default(false)
                    ->after('modules_autorises');
            }

            if (!Schema::hasColumn('entreprises', 'attributions')) {
                $table->json('attributions')
                    ->nullable()
                    ->after('comptabilite_activee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            foreach (['attributions', 'comptabilite_activee'] as $colonne) {
                if (Schema::hasColumn('entreprises', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
