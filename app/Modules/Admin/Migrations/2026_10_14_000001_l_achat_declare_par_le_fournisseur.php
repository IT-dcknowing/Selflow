<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'achat dont la facture sera déclarée par le fournisseur — retest de
 * production, octobre 2026.
 *
 * La facture normalisée d'un fournisseur revient par le relevé du portail et
 * passe en écriture (lot 42). Saisie aussi dans Selflow, elle s'écrivait deux
 * fois, ou la facture certifiée était écartée au profit de la saisie. Le
 * propriétaire tranche : à la facturation, on demande si le fournisseur la
 * déclarera ; si oui, la saisie reste informative et c'est la facture reçue
 * de la DGI qui passe en écriture. Nul : la question n'a pas été posée
 * (pièces antérieures, qui gardent leur écriture).
 *
 * Au passage : l'achat créé par la négociation B2B s'écrivait « Bon de
 * Commande », C majuscule, et échappait à toutes les vérifications qui
 * attendent « Bon de commande ».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('achats', 'declaree_par_fournisseur')) {
            Schema::table('achats', function (Blueprint $table) {
                $table->boolean('declaree_par_fournisseur')->nullable()->after('type_facture');
            });
        }

        DB::table('achats')->where('etape', 'Bon de Commande')->update(['etape' => 'Bon de commande']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('achats', 'declaree_par_fournisseur')) {
            Schema::table('achats', function (Blueprint $table) {
                $table->dropColumn('declaree_par_fournisseur');
            });
        }
    }
};
