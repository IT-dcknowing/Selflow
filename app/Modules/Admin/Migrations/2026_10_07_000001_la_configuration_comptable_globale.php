<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saisir les comptes une fois, et non par produit (section 6 du plan).
 *
 * Chaque fiche produit portait deux menus obligatoires — compte de vente,
 * compte d'achat. Un catalogue de trois cents articles demandait six cents
 * choix, et la plupart retombaient sur 701000 / 601000 faute de mieux.
 *
 * ## Deux choses
 *
 * - `imputations_globales` : la configuration générale (`cle = general`) et la
 *   configuration par type d'article (`cle = type:service`, …). La
 *   configuration par catégorie vit sur `categories.compte_*`, où le
 *   préparamétrage du métier la pose déjà.
 * - `produits.comptes_personnalises` : la case qui fait d'un article une
 *   exception. Sans elle, ses colonnes de compte ne sont plus lues.
 *
 * ## La reprise de l'existant (chantier 6.5)
 *
 * Les produits portent déjà des comptes. Un compte **différent du défaut et
 * différent de celui de sa catégorie** est réputé voulu : l'article devient
 * une exception cochée, et garde ses comptes. Les autres passent en héritage.
 *
 * Le défaut se lit au pluriel : `701000`/`601000` (configuration actuelle) et
 * `701100`/`601100`, valeurs par défaut de la colonne jusqu'au 08/08/2026. Un
 * article créé à cette époque porte 701100 sans que personne l'ait choisi ;
 * le prendre pour une exception l'aurait figé hors de toute configuration.
 *
 * Les colonnes de compte des articles en héritage **ne sont pas vidées** :
 * l'information reste en base, et la case seule décide si elle est lue.
 */
return new class extends Migration
{
    private const DEFAUTS_VENTE = ['701000', '701100'];
    private const DEFAUTS_ACHAT = ['601000', '601100'];

    public function up(): void
    {
        if (!Schema::hasTable('imputations_globales')) {
            Schema::create('imputations_globales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $table->string('cle', 40);
                $table->string('compte_vente', 20)->nullable();
                $table->string('compte_achat', 20)->nullable();
                $table->timestamps();

                $table->unique(['entreprise_id', 'cle']);
            });
        }

        if (!Schema::hasColumn('produits', 'comptes_personnalises')) {
            Schema::table('produits', function (Blueprint $table) {
                $table->boolean('comptes_personnalises')->default(false)->after('compte_achat');
            });
        }

        DB::table('produits')
            ->leftJoin('categories', 'categories.id', '=', 'produits.categorie_id')
            ->select('produits.id', 'produits.compte_vente', 'produits.compte_achat',
                     'categories.compte_vente as cat_vente', 'categories.compte_achat as cat_achat')
            ->orderBy('produits.id')
            ->chunk(500, function ($produits) {
                $voulus = [];

                foreach ($produits as $p) {
                    if ($this->voulu($p->compte_vente, self::DEFAUTS_VENTE, $p->cat_vente)
                        || $this->voulu($p->compte_achat, self::DEFAUTS_ACHAT, $p->cat_achat)) {
                        $voulus[] = $p->id;
                    }
                }

                if ($voulus !== []) {
                    DB::table('produits')->whereIn('id', $voulus)->update(['comptes_personnalises' => true]);
                }
            });
    }

    private function voulu(?string $compte, array $defauts, ?string $compteCategorie): bool
    {
        $compte = trim((string) $compte);

        return $compte !== ''
            && !in_array($compte, $defauts, true)
            && $compte !== trim((string) $compteCategorie);
    }

    public function down(): void
    {
        if (Schema::hasColumn('produits', 'comptes_personnalises')) {
            Schema::table('produits', function (Blueprint $table) {
                $table->dropColumn('comptes_personnalises');
            });
        }

        Schema::dropIfExists('imputations_globales');
    }
};
