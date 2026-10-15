<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La vente pas à pas — retest d'octobre 2026.
 *
 * Une seconde présentation de l'écran de vente, en quatre étapes, choisie par
 * un bouton comme la bascule du catalogue. Elle découpe le même formulaire :
 * l'envoi, et donc ce qui part à la DGI, ne change pas. Le comportement (un
 * refus visible à chaque étape, l'envoi seulement à la dernière) a été
 * vérifié dans un navigateur ; ici, on garde la forme de la page.
 */
class VentePasAPasTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        $entreprise = Entreprise::create([
            'nom' => 'Axenova', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody', 'rccm' => 'CI-ABJ-2026-B-1',
            'ncc' => '2601234A', 'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@axenova.ci', 'password' => bcrypt('secret-de-test'),
            'role' => 'admin', 'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $site->id,
        ]);

        \App\Modules\Admin\Modeles\Produit::create([
            'entreprise_id' => $entreprise->id, 'reference' => 'SRV-1', 'nom' => 'Lavage complet',
            'type' => 'service', 'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 20000, 'taux_tva' => 18,
        ]);

        return $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $site->id])
            ->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();
    }

    public function test_un_bouton_bascule_entre_la_caisse_et_la_vente_pas_a_pas(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('id="btn-vue-caisse"', $html);
        $this->assertStringContainsString('id="btn-vue-etapes"', $html);
        $this->assertStringContainsString("localStorage.setItem('selflow_vente_vue'", $html);
    }

    public function test_les_quatre_etapes_vont_jusqu_a_la_facturation(): void
    {
        $html = $this->page();

        foreach (['Articles', 'Client et document', 'Règlement', 'Valider et facturer'] as $i => $etape) {
            $this->assertMatchesRegularExpression('/data-fil="' . ($i + 1) . '"><span>' . ($i + 1) . '<\/span> ' . preg_quote($etape, '/') . '/u', $html);
        }
    }

    public function test_chaque_bloc_appartient_a_son_etape_et_l_envoi_a_la_derniere(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('/<div class="form-group" data-pas="2">\s*<label class="form-label">Client/', $html);
        $this->assertStringContainsString('<div data-pas="3"><div id="blocPaiementVente">', $html);
        $this->assertMatchesRegularExpression('/<div data-pas="4"[^>]*>\s*<button type="submit" class="btn btn-success" id="btnValider"/', $html);
        $this->assertStringContainsString('id="recapVente"', $html);

        // Le formulaire est le même : même adresse d'envoi.
        $this->assertStringContainsString('action="' . route('admin.ventes.enregistrer') . '" id="formVente"', $html);
    }

    public function test_l_etape_1_est_une_liste_avec_compteurs_et_une_barre_en_bas(): void
    {
        $html = $this->page();

        // Le panier latéral s'efface à l'étape 1 : la barre du bas le résume.
        $this->assertStringContainsString('<div class="colonne-panier">', $html);
        $this->assertStringContainsString('id="barrePas"', $html);
        $this->assertStringContainsString('id="barrePasNet"', $html);
        $this->assertStringContainsString('.vente-pas-a-pas[data-pas-courant="1"] .colonne-panier { display: none; }', $html);
        $this->assertStringContainsString('renderPanier = function () { renderPanierCaisse(); majSelectionPasAPas(); };', $html);
    }

    public function test_chaque_ligne_choisie_s_ouvre_en_boite_reglable(): void
    {
        $html = $this->page();

        // Avant d'ajouter : la TVA de la fiche se lit sur la ligne.
        $this->assertStringContainsString('class="produit-fisc"', $html);
        $this->assertMatchesRegularExpression('/TVA 18 %/', $html);
        // Une fois ajoutée : quantité, unité, remise, code TVA et total, par
        // les mêmes fonctions que le panier de la caisse.
        $this->assertStringContainsString('class="boite-ligne"', $html);
        $this->assertStringContainsString('function remplirBoite(boite, id, item)', $html);
        foreach (['saisirQte(${cle}', 'saisirUnite(${cle}', 'saisirRemiseLigne(${cle}', 'selecteurCodeTva(id, item)', 'supprimerItem(${cle})'] as $appel) {
            $this->assertStringContainsString($appel, $html);
        }
        // Les lignes libres ne disparaissent plus à l'étape 1.
        $this->assertStringContainsString('id="lignesLibresPas"', $html);
    }

    public function test_revenir_en_arriere_dans_le_parcours_garde_le_clic_sur_le_domaine(): void
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);
        $this->page();

        $html = $this->get(route('admin.souscription.index', ['etape' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString("window.addEventListener('pageshow'", $html);
        $this->assertStringContainsString('delete form.dataset.envoye', $html);
    }
}
