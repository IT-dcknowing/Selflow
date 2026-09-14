<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un relevé qui ne dit rien de neuf n'ajoute plus de ligne.
 *
 * Jusqu'ici, chaque passage du scraper écrivait une ligne dans
 * `portail_fne_imports`, même quand le portail n'avait pas bougé. L'empreinte
 * du fichier ne pouvait rien y faire : le tableur de la DGI embarque un
 * horodatage de génération, et deux exports identiques diffèrent donc octet
 * pour octet. Trois relevés d'affilée le même jour donnaient trois lignes pour
 * un seul et même contenu.
 *
 * L'empreinte du **contenu** tranche ce que celle du fichier ne pouvait pas.
 * Elle est calculée après conversion, de sorte que les libertés que le portail
 * s'autorise — `"5000"` ou `5000`, `"*"` ou `null`, colonnes réordonnées — ne
 * comptent pas comme des changements.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('portail_fne_imports', function (Blueprint $table) {
            // SHA-256 du contenu ramené à sa forme canonique. Nullable pour
            // les lignes antérieures, que la reprise ci-dessous renseigne.
            $table->char('contenu_empreinte', 64)->nullable()->after('fichier_empreinte');

            // La date du dernier relevé qui a confirmé ce contenu. `date_scraping`
            // reste celle du relevé qui l'a fait connaître : l'une dit depuis
            // quand le portail affiche cela, l'autre qu'on l'a revu hier.
            $table->date('dernier_releve_le')->nullable()->after('importe_at');

            // Combien de relevés distincts ont confirmé ce contenu. Un compteur
            // qui monte est le signe d'un portail stable, pas d'un scraper en
            // panne — et il monte au plus d'une unité par date de relevé.
            $table->unsignedInteger('releves')->default(1)->after('dernier_releve_le');

            $table->index(['login', 'type', 'contenu_empreinte'], 'portail_fne_imports_contenu_index');
        });

        // Les lignes déjà en base n'ont pas d'empreinte de contenu. Sans reprise,
        // le premier relevé qui suit créerait une ligne de plus pour un contenu
        // que la base connaît déjà — précisément ce que cette migration corrige.
        //
        // `donnees_brutes` porte le contenu lu avant interprétation : il est
        // rejouable, et c'est le service qui le canonicalise, pour que la reprise
        // et les relevés à venir parlent la même langue.
        $service = app(\App\Modules\Admin\Services\ImportPortailFneService::class);

        DB::table('portail_fne_imports')
            ->whereNotNull('donnees_brutes')
            ->orderBy('id')
            ->each(function (object $ligne) use ($service) {
                $donnees = json_decode((string) $ligne->donnees_brutes, true);

                if (!is_array($donnees)) {
                    return;
                }

                DB::table('portail_fne_imports')->where('id', $ligne->id)->update([
                    'contenu_empreinte' => $service->empreinteDuContenu($ligne->type, $donnees),
                    'dernier_releve_le' => $ligne->date_scraping,
                ]);
            });

        DB::table('portail_fne_imports')
            ->whereNull('dernier_releve_le')
            ->update(['dernier_releve_le' => DB::raw('date_scraping')]);

        $this->replierLesDoublons();
    }

    /**
     * Replie les lignes qui redisaient déjà ce que la précédente disait.
     *
     * L'ancienne règle écrivait une ligne à chaque passage, même sans
     * changement. Les laisser en place ferait mentir `releves` et rendrait
     * `dernier_releve_le` inutile — deux colonnes ajoutées pour dire ce que ces
     * doublons prétendent dire à leur manière.
     *
     * Le repli se fait de proche en proche, dans l'ordre : deux relevés
     * identiques **séparés par un troisième différent** ne se replient pas. Un
     * portail qui passe de A à B puis revient à A a bel et bien changé deux
     * fois, et l'écraser ferait disparaître le passage par B.
     */
    private function replierLesDoublons(): void
    {
        $groupes = DB::table('portail_fne_imports')
            ->select('login', 'type')
            ->where('statut', '!=', 'erreur')
            ->groupBy('login', 'type')
            ->get();

        foreach ($groupes as $groupe) {
            $lignes = DB::table('portail_fne_imports')
                ->where('login', $groupe->login)
                ->where('type', $groupe->type)
                ->where('statut', '!=', 'erreur')
                ->orderBy('date_scraping')
                ->orderBy('id')
                ->get();

            $garde = null;

            foreach ($lignes as $ligne) {
                if ($garde === null || $ligne->contenu_empreinte !== $garde->contenu_empreinte) {
                    $garde = $ligne;
                    continue;
                }

                // Les points et fiches de la ligne repliée partent avec elle
                // (`cascadeOnDelete`) : ils font double emploi avec ceux de la
                // ligne gardée, qui porte le même contenu.
                DB::table('portail_fne_imports')->where('id', $garde->id)->update([
                    'dernier_releve_le' => $ligne->dernier_releve_le ?: $ligne->date_scraping,
                    'releves'           => DB::raw('releves + 1'),
                    'statut'            => 'importe',
                ]);

                DB::table('portail_fne_imports')->where('id', $ligne->id)->delete();
            }
        }
    }

    public function down(): void
    {
        Schema::table('portail_fne_imports', function (Blueprint $table) {
            $table->dropIndex('portail_fne_imports_contenu_index');
            $table->dropColumn(['contenu_empreinte', 'dernier_releve_le', 'releves']);
        });
    }
};
