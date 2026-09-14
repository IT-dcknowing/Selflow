<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pourquoi la pièce n'est pas passée.
 *
 * `fne_rejets` gardait le message et les champs mis en cause, mais pas la
 * nature de l'échec. Or `FneService` rend `success: false` aussi bien quand la
 * DGI refuse une pièce que quand la connexion a sauté ou que Selflow a refusé
 * d'envoyer. Faute de distinction, `FneRejet::consigner()` ouvrait une demande
 * de relevé du portail dans les trois cas — et le scraper partait relever
 * quatorze champs parce qu'un réseau avait bougé.
 *
 * Trois valeurs :
 *   'dgi'    — la plateforme a examiné la pièce et l'a refusée. Un relevé sert.
 *   'reseau' — elle n'a rien examiné : délai dépassé, DNS, panne de son côté.
 *   'locale' — rien n'est parti : clé API absente, avoir sans facture d'origine,
 *              taux de TVA hors barème. Le défaut est chez nous.
 *
 * Les lignes déjà en base restent à NULL. Elles ont été consignées avant que la
 * distinction existe ; leur prêter une cause après coup serait inventer un
 * constat, et un constat inventé se lit comme un constat.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('fne_rejets', function (Blueprint $table) {
            $table->string('cause', 20)->nullable()->after('champs')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fne_rejets', function (Blueprint $table) {
            $table->dropIndex(['cause']);
            $table->dropColumn('cause');
        });
    }
};
