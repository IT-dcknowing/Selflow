<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le document que la DGI sert pour une facture reçue.
 *
 * ## Pourquoi une colonne
 *
 * Demandé par le propriétaire du projet le 08/09/2026 : *« comment faire pour
 * que cette facture s'affiche aussi directement sans passer par le bouton
 * exporter »*. Le bouton en question est celui du portail : pour lire une
 * facture reçue, il fallait s'y connecter et l'exporter à la main.
 *
 * Selflow en montrait déjà deux approximations — la page de vérification de la
 * DGI, qui authentifie sans donner à lire, et sa propre reconstruction depuis le
 * relevé. Aucune des deux n'est **le** document : celui que le fournisseur a
 * établi et que la plateforme a certifié.
 *
 * ## Un chemin, et non le fichier
 *
 * La colonne porte le nom du fichier déposé par `achats.js`, relatif au dossier
 * d'import. Le PDF lui-même reste sur le disque, hors de la base :
 *
 * - une base qui grossit de 130 Ko par facture devient une base qu'on ne
 *   sauvegarde plus ;
 * - le dossier d'import est déjà hors de `public/`, donc déjà à l'abri d'une
 *   URL devinée — ces pièces portent des données fiscales nominatives ;
 * - et un fichier absent se voit : la colonne pointe, l'écran retombe sur la
 *   reconstruction plutôt que de servir une page blanche.
 *
 * Nulle veut dire « le portail n'a rien donné pour cette pièce », ce qui est le
 * cas de tous les relevés antérieurs au 08/09/2026.
 *
 * ## Ce qu'elle ne touche pas
 *
 * Rien ne part à la DGI. `FneService` est gelé et ne lit pas cette table : une
 * facture reçue n'est pas une pièce que Selflow émet, et le PDF conservé ici est
 * celui d'un tiers — il n'atteste rien de ce que l'application a établi.
 */
return new class extends Migration {
    public function up(): void
    {
        // Ce qu'elle pose, elle vérifie d'abord que ce ne l'est pas : une
        // migration décrit un état, pas un geste.
        if (Schema::hasColumn('portail_fne_factures_recues', 'fichier_pdf')) {
            return;
        }

        Schema::table('portail_fne_factures_recues', function (Blueprint $table) {
            $table->string('fichier_pdf')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('portail_fne_factures_recues', 'fichier_pdf')) {
            return;
        }

        Schema::table('portail_fne_factures_recues', function (Blueprint $table) {
            $table->dropColumn('fichier_pdf');
        });
    }
};
