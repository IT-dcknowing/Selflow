<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les factures que le portail FNE dit avoir reçues pour le compte de l'entreprise.
 *
 * ## Pourquoi une table, et pas les colonnes d'`achats`
 *
 * `achats.numero_fne`, `achats.normalise`, `achats.fne_*` veulent dire une chose
 * précise : **Selflow a émis cette pièce et la DGI l'a certifiée** — c'est le cas
 * du bordereau d'achat agricole. Une facture reçue a été certifiée par le
 * fournisseur, pas par nous. Y écrire ferait mentir Selflow sur ce qu'il a émis,
 * devant un contrôle. D'où une table à part, et un lien par `achat_id`.
 *
 * ## Pourquoi ce n'est pas un historique, contrairement aux fiches
 *
 * Une fiche d'entreprise est un **état** qu'on photographie à chaque relevé, et
 * l'on veut voir ce qui a bougé entre deux photos. Une facture est un **fait** :
 * elle est émise, certifiée, et ne change plus. La stocker deux fois parce
 * qu'elle apparaît dans deux relevés serait une faute.
 *
 * D'où l'unicité sur `(login, reference)` : le numéro FNE de la pièce, dans le
 * portail où on l'a relevée. Un second relevé met à jour, il ne duplique pas.
 *
 * ## Ce qui n'est pas décidé ici
 *
 * Aucune ligne d'`achats` n'est créée, aucune écriture comptable n'est produite.
 * Le relevé apporte un constat ; transformer une facture reçue en achat est un
 * geste d'utilisateur. C'est la règle d'or du projet.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('portail_fne_factures_recues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_id')
                ->constrained('portail_fne_imports')->cascadeOnDelete();

            // Nul tant que le login ne correspond à aucune entreprise connue :
            // perdre un relevé parce que l'entreprise n'est pas encore créée
            // obligerait à le reprendre au portail.
            $table->foreignId('entreprise_id')->nullable()
                ->constrained('entreprises')->nullOnDelete();

            $table->string('login', 40)->index();

            // Le relevé qui a vu cette facture pour la dernière fois.
            $table->date('date_scraping');

            /* ------------------------- L'identité de la pièce ------------- */

            // Le numéro FNE — « B1864699A26000000016 ». C'est la clé de tout :
            // c'est par lui qu'un second relevé reconnaît une facture déjà vue,
            // et par lui qu'un doublon de saisie manuelle se détecte.
            $table->string('reference', 64);

            // L'identifiant interne de la plateforme, et le code de vérification
            // qui devient le QR. Le second permet de revérifier la pièce auprès
            // de la DGI sans dépendre de ce qu'on en a recopié.
            $table->string('fne_id', 64)->nullable();
            $table->string('token', 128)->nullable();

            // `invoice` | `receipt`, puis `normal` | `purchase_slip` | `refund`
            // | `proforma`. La nuance n'est pas cosmétique : une facture porte
            // une TVA déductible, un reçu n'en porte pas.
            $table->string('type', 32)->nullable();
            $table->string('subtype', 32)->nullable();

            // Le reçu normalisé, que Selflow connaît déjà sous ces deux noms.
            $table->boolean('est_rne')->default(false);
            $table->string('numero_rne', 64)->nullable();

            $table->timestamp('date_facture')->nullable();

            /* ---------------------------- L'émetteur ---------------------- */

            // Le NCC de l'émetteur est la clé de rapprochement avec
            // `fournisseurs.ncc`. Sans lui, le rapprochement se ferait sur un
            // nom approximatif — inacceptable pour une pièce fiscale.
            $table->string('emetteur_ncc', 40)->nullable()->index();
            $table->string('emetteur_nom')->nullable();
            $table->string('emetteur_id', 64)->nullable();
            $table->string('emetteur_rccm', 64)->nullable();

            /* ----------------------------- Les montants ------------------- */

            // L'API rend des nombres, pas des libellés formatés : on les range
            // tels quels. `decimal` et non `float` — une facture ne s'arrondit
            // pas au gré de la représentation binaire.
            $table->decimal('montant_ht', 15, 2)->default(0);
            $table->decimal('remise', 15, 2)->default(0);
            $table->decimal('montant_tva', 15, 2)->default(0);
            $table->decimal('timbre_fiscal', 15, 2)->default(0);
            $table->decimal('autres_taxes', 15, 2)->default(0);
            $table->decimal('montant_ttc', 15, 2)->default(0);
            $table->decimal('net_a_payer', 15, 2)->default(0);

            $table->string('devise', 8)->nullable();
            $table->decimal('taux_change', 15, 6)->nullable();

            $table->string('statut_portail', 32)->nullable();
            $table->string('moyen_paiement', 32)->nullable();

            /* ------------------------- Le lien avec Selflow --------------- */

            // Posé par un humain, jamais par l'import. Créer l'achat tout seul
            // produirait des écritures comptables parce qu'un fichier est arrivé
            // dans un dossier.
            $table->foreignId('achat_id')->nullable()
                ->constrained('achats')->nullOnDelete();

            // 'a_rapprocher' | 'rapprochee' | 'orpheline' | 'ecartee'
            $table->string('statut_rapprochement', 24)->default('a_rapprocher')->index();
            $table->text('note_rapprochement')->nullable();

            // Tout ce que le portail a rendu, avant interprétation. Le portail
            // peut ajouter un champ du jour au lendemain ; sans ce fourre-tout
            // il serait lu, jeté, et personne ne le saurait.
            $table->json('contenu_brut')->nullable();

            $table->timestamps();

            // Une facture, une ligne. Un second relevé met à jour.
            $table->unique(['login', 'reference'], 'portail_fne_facture_recue_unique');
            $table->index(['entreprise_id', 'date_facture']);
        });

        Schema::create('portail_fne_facture_recue_lignes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('facture_recue_id')
                ->constrained('portail_fne_factures_recues')->cascadeOnDelete();

            $table->string('fne_item_id', 64)->nullable();

            $table->string('reference_article', 64)->nullable();
            $table->text('designation')->nullable();

            // Trois décimales, comme partout ailleurs dans Selflow depuis le
            // passage des quantités en `decimal(15,3)`.
            $table->decimal('quantite', 15, 3)->default(0);
            $table->string('unite', 32)->nullable();

            $table->decimal('prix_unitaire', 15, 2)->default(0);
            $table->decimal('remise', 15, 2)->default(0);

            // Le portail rend les taxes d'une ligne dans un tableau : on garde
            // le montant total et le détail brut, sans chercher à deviner un
            // code TVA que le référentiel de Selflow n'aurait pas.
            $table->decimal('montant_tva', 15, 2)->default(0);
            $table->json('taxes')->nullable();

            $table->json('contenu_brut')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portail_fne_facture_recue_lignes');
        Schema::dropIfExists('portail_fne_factures_recues');
    }
};
