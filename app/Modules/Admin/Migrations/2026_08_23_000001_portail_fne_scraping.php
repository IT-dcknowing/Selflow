<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration {
    public function up(): void
    {
        Schema::create('portail_fne_imports', function (Blueprint $table) {
            $table->id();

            // Nulle tant que le login ne correspond à aucune entreprise connue.
            // Le fichier est conservé quand même : perdre un relevé parce que
            // l'entreprise n'est pas encore créée obligerait à le reprendre au
            // portail, où il n'est pas forcément encore disponible.
            $table->foreignId('entreprise_id')->nullable()
                ->constrained('entreprises')->nullOnDelete();

            // Le login tel qu'il figure dans le nom du fichier — en pratique le
            // NCC. Conservé brut : c'est la seule trace de ce qui a servi à
            // rattacher (ou non) le relevé à une entreprise.
            $table->string('login', 40)->index();
            $table->date('date_scraping');

            // 'fiche' pour le JSON, 'points' pour le tableur.
            $table->string('type', 20);

            $table->string('fichier_nom');

            // Empreinte SHA-256 du contenu. Unique : redéposer le même fichier
            // ne crée pas un second relevé. C'est ce qui rend la commande
            // rejouable sans précaution — un dossier peut être relu en entier.
            $table->char('fichier_empreinte', 64)->unique();

            // Le contenu lu, avant toute interprétation.
            $table->json('donnees_brutes')->nullable();

            // 'importe' | 'erreur'
            $table->string('statut', 20)->default('importe');
            $table->text('message')->nullable();
            $table->unsignedInteger('lignes_importees')->default(0);

            $table->timestamp('importe_at')->nullable();
            $table->timestamps();

            $table->index(['login', 'date_scraping']);
        });

        Schema::create('portail_fne_fiches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')
                ->constrained('portail_fne_imports')->cascadeOnDelete();
            $table->foreignId('entreprise_id')->nullable()
                ->constrained('entreprises')->nullOnDelete();
            $table->string('login', 40)->index();
            $table->date('date_scraping');

            $table->string('email')->nullable();
            $table->string('telephone', 40)->nullable();
            $table->string('adresse')->nullable();
            $table->string('commune', 120)->nullable();
            $table->string('quartier', 120)->nullable();
            $table->string('reference_cadastrale', 120)->nullable();
            $table->string('idu', 120)->nullable();
            $table->string('proprietaire_local', 190)->nullable();
            $table->text('ref_bancaire')->nullable();

            // Le portail rend « 5000 » entre guillemets ; la colonne est un
            // entier parce que c'est un nombre de stickers, pas un libellé.
            $table->unsignedInteger('sticker_solde_alerte')->nullable();

            // Nullables à trois états volontairement : « le portail dit non » et
            // « le portail n'a rien dit » ne se valent pas quand on compare un
            // relevé au paramétrage de l'entreprise.
            $table->boolean('timbre_quittance')->nullable();
            $table->boolean('bapa')->nullable();

            $table->text('pied_de_page_facture')->nullable();
            $table->text('facture_autres_mentions')->nullable();

            // Les clés du JSON qu'aucune colonne ne reçoit. Le portail peut
            // ajouter un champ du jour au lendemain ; sans ce fourre-tout, il
            // serait lu, jeté, et personne ne le saurait.
            $table->json('champs_inconnus')->nullable();

            $table->timestamps();
        });

        Schema::create('portail_fne_points_facturation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')
                ->constrained('portail_fne_imports')->cascadeOnDelete();
            $table->foreignId('entreprise_id')->nullable()
                ->constrained('entreprises')->nullOnDelete();
            $table->string('login', 40)->index();
            $table->date('date_scraping');

            $table->string('nom')->nullable();
            $table->string('outil', 120)->nullable();
            $table->string('terminal_id', 120)->nullable();

            // Le portail rend « 1 ». Chaîne, et non booléen : rien ne dit que
            // le jeu de valeurs se limite à 0 et 1, et un statut inconnu doit
            // pouvoir être conservé tel quel plutôt que ramené à faux.
            $table->string('statut', 40)->nullable();
            $table->string('raison_statut')->nullable();

            // L'identifiant DGI de l'établissement, un UUID.
            $table->string('etablissement_id', 64)->nullable()->index();

            // Les dates portées par le portail, à ne pas confondre avec les
            // `timestamps` ci-dessous, qui datent notre propre enregistrement.
            $table->timestamp('cree_a')->nullable();
            $table->timestamp('mis_a_jour_a')->nullable();

            $table->timestamps();

            $table->index(['entreprise_id', 'date_scraping']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portail_fne_points_facturation');
        Schema::dropIfExists('portail_fne_fiches');
        Schema::dropIfExists('portail_fne_imports');
    }
};
