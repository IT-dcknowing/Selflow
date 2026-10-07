<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Referentiel\Categorie;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\VerrouConfigurationService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le paramétrage ne se saisit plus deux fois, et ne se défait plus tout seul.
 *
 * Deux écrans posaient la même question sans se parler : les paramètres de
 * l'entreprise proposaient de cocher un « secteur d'activité » dans une liste,
 * pendant que le parcours de configuration demandait un domaine à sa première
 * étape puis des métiers à la deuxième. On pouvait déclarer « Santé » d'un
 * côté et souscrire au métier « Boulangerie » de l'autre ; les deux réponses
 * cohabitaient, et rien ne le signalait.
 *
 * Le domaine se choisit désormais **au parcours**, et la colonne
 * `secteur_activite` s'en déduit.
 *
 * Reste la question du retour en arrière. Le parcours est additif presque
 * partout : rechoisir un domaine ou cocher un métier de plus n'enlève rien —
 * `souscrire()` passe sur ce qui existe déjà sans y toucher. **Une seule étape
 * défait** : celle des modules, qui écrit `modules_actifs` par intersection.
 * Refermer « Comptabilité » sur six mois d'écritures ne supprime rien, mais
 * fait disparaître de la barre latérale l'écran où ces écritures se lisent.
 *
 * Ces épreuves gardent les deux points.
 */
class ConfigurationVerrouilleeTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);

        $this->entreprise = Entreprise::create([
            'nom'               => 'Boutique du carrefour',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Adjamé, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00042',
            'ncc'               => '2601234A',
            'gerant_fonction'   => 'Gérant',
            'modules_actifs'    => Entreprise::MODULES_SOCLE,
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Adjamé',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);
    }

    // ── Le secteur se déduit du parcours ─────────────────────────────

    public function test_le_domaine_choisi_devient_le_secteur_de_l_entreprise(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);

        $this->assertSame(['Commerce'], $this->entreprise->fresh()->secteur_activite);
    }

    /**
     * L'étape 4 réaligne la colonne sur les métiers **réellement** souscrits :
     * c'est là que la souscription s'effectue, et non au choix du domaine.
     */
    public function test_le_secteur_se_realigne_sur_les_metiers_souscrits(): void
    {
        $this->parcoursComplet();

        $this->assertSame(['Commerce'], $this->entreprise->fresh()->secteur_activite);
        $this->assertSame(['Commerce'], VerrouConfigurationService::domainesSouscrits($this->entreprise->fresh()));
    }

    /**
     * Une entreprise qui rouvre son parcours sans rien souscrire ne doit pas
     * perdre son secteur : sans lui, elle retombe en « inscription incomplète »
     * et perd l'accès à ses propres écrans.
     */
    public function test_un_parcours_sans_souscription_ne_vide_pas_le_secteur(): void
    {
        $this->entreprise->update(['secteur_activite' => ['Santé']]);

        VerrouConfigurationService::alignerLeSecteur($this->entreprise);

        $this->assertSame(['Santé'], $this->entreprise->fresh()->secteur_activite);
    }

    // ── Les métiers acquis ───────────────────────────────────────────

    public function test_un_metier_souscrit_revient_coche_et_desactive(): void
    {
        $this->parcoursComplet();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.souscription.index', ['etape' => 2]))->assertOk()->getContent();

        $this->assertStringContainsString('déjà en place', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    /**
     * Décocher un métier déjà souscrit n'a jamais rien retiré — `souscrire()`
     * ne détache aucun profil. L'utilisateur croyait pourtant avoir fermé un
     * métier qui restait ouvert, avec ses rayons et ses articles. Le choix
     * retenu le remet, pour que l'écran et la base racontent la même chose.
     */
    public function test_decocher_un_metier_souscrit_ne_le_retire_pas(): void
    {
        $this->parcoursComplet();

        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 2), ['profils' => []])
            ->assertRedirect(route('admin.souscription.index', ['etape' => 3]));

        $this->assertContains(
            'boutique_quartier',
            VerrouConfigurationService::profilsAcquis($this->entreprise->fresh())
        );
    }

    // ── Les modules qui portent des données ──────────────────────────

    /**
     * Révisé au lot 41 (chantier 3.5 du plan). L'épreuve fixait qu'un module
     * sans données se refermait en le décochant à l'étape 3 — l'étape
     * présentait alors les modules déjà ouverts comme un choix à refaire, et
     * annonçait leur activation. Un module en place est désormais acquis : il
     * se referme depuis les paramètres, pas en repassant le parcours.
     */
    public function test_le_parcours_ne_referme_pas_un_module_deja_ouvert(): void
    {
        $this->parcoursComplet();

        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 3), ['modules' => ['ventes']]);
        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 4), ['familles' => []]);

        $this->assertContains('comptabilite', $this->entreprise->fresh()->modules_actifs);
    }

    /**
     * Simulation d'attaque, et surtout simulation de maladresse : la case est
     * désactivée à l'écran, mais un formulaire forgé — ou un navigateur qui
     * n'exécute pas le script — enverrait la liste sans elle.
     */
    public function test_un_module_qui_porte_des_ventes_ne_se_referme_pas(): void
    {
        $this->parcoursComplet();
        $this->uneVente();

        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal']]);
        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 4), ['familles' => []]);

        $this->assertContains('ventes', $this->entreprise->fresh()->modules_actifs);
    }

    /**
     * Une vente n'appartient pas à une entreprise mais à un point de vente. Un
     * verrou posé sur `entreprise_id` aurait laissé trois modules sur cinq sans
     * protection, sans rien dire.
     */
    public function test_le_verrou_suit_les_points_de_vente(): void
    {
        $this->uneVente();

        $this->assertArrayHasKey('ventes', VerrouConfigurationService::modulesVerrouilles($this->entreprise));
    }

    public function test_les_ventes_de_la_voisine_ne_verrouillent_rien(): void
    {
        $voisine = Entreprise::create(['nom' => 'Voisine SARL']);
        $siteVoisin = PointDeVente::create([
            'entreprise_id' => $voisine->id,
            'nom' => 'Dépôt', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);

        $this->uneVente($siteVoisin);

        $this->assertSame([], VerrouConfigurationService::modulesVerrouilles($this->entreprise));
        $this->assertArrayHasKey('ventes', VerrouConfigurationService::modulesVerrouilles($voisine));
    }

    public function test_l_ecran_annonce_le_module_verrouille(): void
    {
        $this->parcoursComplet();
        $this->uneVente();

        $this->actingAs($this->admin)
            ->get(route('admin.souscription.index', ['etape' => 3]))
            ->assertOk()
            ->assertSee('ce module ne se referme plus', false)
            ->assertSee('en service');
    }

    // ── L'écran des paramètres ───────────────────────────────────────

    // ── Les noms des modules ─────────────────────────────────────────

    /**
     * L'écran fabriquait le sien à partir du code — « Comptabilite » sans
     * accent, « Fne » pour la section que le menu appelle « Fiscalité & DGI ».
     * L'utilisateur devait deviner quelle case commandait quelle section.
     */
    public function test_les_modules_portent_le_nom_de_la_barre_laterale(): void
    {
        $this->entreprise->update([
            'secteur_activite' => ['Commerce'],
            'modules_actifs'   => ['principal', 'comptabilite', 'fne', 'points_de_vente'],
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        $this->assertStringContainsString('Comptabilité', $html);
        $this->assertStringContainsString('Fiscalité &amp; DGI', $html);
        $this->assertStringNotContainsString('>Comptabilite<', $html);
        $this->assertStringNotContainsString('>Fne<', $html);
    }

    /**
     * Chaque module a un nom, et la liste des noms ne connaît pas de module
     * qui n'existe pas. Une entrée oubliée retomberait en silence sur le code.
     */
    public function test_chaque_module_a_son_nom(): void
    {
        $this->assertSame(
            Entreprise::TOUS_LES_MODULES,
            array_keys(Entreprise::LIBELLES_MODULES)
        );
    }

    // ── La réconciliation ────────────────────────────────────────────

    /**
     * Le cas constaté chez le propriétaire : des mouvements de stock, et
     * aucune section « Stock » dans le menu. La marchandise était comptée,
     * valorisée, reprise dans les écritures — et l'écran où elle se lit avait
     * disparu. Le verrou empêche d'en refermer un ; il ne répare pas ceux qui
     * l'ont été avant qu'il existe.
     */
    public function test_un_module_ferme_qui_porte_des_donnees_est_rouvert(): void
    {
        $this->entreprise->update([
            'secteur_activite' => ['Commerce'],
            'modules_actifs'   => ['principal', 'achats'],
        ]);
        $this->uneVente();

        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertOk();

        $this->assertContains('ventes', $this->entreprise->fresh()->modules_actifs);
    }

    public function test_la_reconciliation_n_ouvre_rien_d_autre(): void
    {
        $this->entreprise->update(['modules_actifs' => ['principal']]);
        $this->uneVente();

        VerrouConfigurationService::reconcilier($this->entreprise);

        $this->assertSame(['principal', 'ventes'], $this->entreprise->fresh()->modules_actifs);
    }

    public function test_la_reconciliation_ne_fait_rien_quand_tout_est_en_ordre(): void
    {
        $this->entreprise->update(['modules_actifs' => ['principal', 'ventes']]);
        $this->uneVente();

        $this->assertSame([], VerrouConfigurationService::reconcilier($this->entreprise));
        $this->assertSame(['principal', 'ventes'], $this->entreprise->fresh()->modules_actifs);
    }

    // ── Le bouton du parcours ────────────────────────────────────────

    /**
     * Sans `etape => 1`, le parcours s'ouvre à la dernière étape atteinte — la
     * cinquième pour une entreprise déjà configurée, c'est-à-dire l'écran des
     * prix. Le bouton servait donc à tout sauf à ce qu'on lui demandait.
     */
    public function test_le_bouton_renvoie_a_la_premiere_etape(): void
    {
        $this->entreprise->update(['secteur_activite' => ['Commerce']]);

        $this->actingAs($this->admin)
            ->get(route('admin.entreprise.parametres'))
            ->assertOk()
            ->assertSee(route('admin.souscription.index', ['etape' => 1]), false);
    }

    public function test_la_premiere_etape_dit_qu_elle_ajoute(): void
    {
        $this->parcoursComplet();

        $this->actingAs($this->admin)
            ->get(route('admin.souscription.index', ['etape' => 1]))
            ->assertOk()
            ->assertSee('Vous travaillez déjà en', false)
            ->assertSee("n'enlève rien", false);
    }

    /**
     * Le parcours doit rester traversable une fois terminé : c'est par lui
     * qu'on ajoute un domaine. Une entreprise arrivée à la cinquième étape ne
     * doit pas se voir refuser la première.
     */
    public function test_la_premiere_etape_reste_ouverte_apres_la_configuration(): void
    {
        $this->parcoursComplet();

        $autre = Categorie::where('nom', 'Santé')->first() ?? Categorie::where('nom', '!=', 'Commerce')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $autre->id])
            ->assertRedirect(route('admin.souscription.index', ['etape' => 2]));
    }

    // ── L'écran des paramètres ───────────────────────────────────────

    public function test_les_parametres_ne_proposent_plus_de_cocher_un_secteur(): void
    {
        $this->entreprise->update(['secteur_activite' => ['Commerce']]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        $this->assertStringNotContainsString('secteurs_activite[]', $html);
        $this->assertStringContainsString('Votre configuration', $html);
        $this->assertStringContainsString('Ajouter une configuration', $html);
    }

    /**
     * Le défaut qu'aurait produit un simple retrait du champ : la ligne qui
     * l'enregistrait valait `$request->secteurs_activite ?? []`. Un formulaire
     * d'où le champ a disparu aurait donc **vidé** la colonne à chaque
     * enregistrement — et une entreprise sans secteur retombe en « inscription
     * incomplète », bannière comprise.
     */
    public function test_enregistrer_les_parametres_ne_vide_pas_le_secteur(): void
    {
        $this->entreprise->update(['secteur_activite' => ['Commerce']]);

        $this->actingAs($this->admin)
            ->put(route('admin.entreprise.parametres.enregistrer'), [
                'nom' => 'Boutique du carrefour', 'gerant_nom' => 'Kouadio',
                'gerant_prenom' => 'Lewis', 'gerant_fonction' => 'Gérant',
            ])->assertRedirect();

        $this->assertSame(['Commerce'], $this->entreprise->fresh()->secteur_activite);
    }

    /**
     * Simulation d'attaque : le champ n'est plus à l'écran, mais rien
     * n'empêche de le poster. Il ne doit avoir aucun effet — sans quoi le
     * retrait n'aurait déplacé le problème qu'à la couche visible.
     */
    public function test_un_secteur_poste_a_la_main_est_ignore(): void
    {
        $this->entreprise->update(['secteur_activite' => ['Commerce']]);

        $this->actingAs($this->admin)
            ->put(route('admin.entreprise.parametres.enregistrer'), [
                'nom' => 'Boutique du carrefour', 'gerant_nom' => 'Kouadio',
                'gerant_prenom' => 'Lewis', 'gerant_fonction' => 'Gérant',
                'secteurs_activite' => ['Santé', 'BTP-Travaux'],
            ])->assertRedirect();

        $this->assertSame(['Commerce'], $this->entreprise->fresh()->secteur_activite);
    }

    /**
     * Sans cette phrase, une entreprise qui n'a pas fait son parcours resterait
     * « incomplète » en remplissant pourtant tous les champs visibles, sans
     * qu'aucun écran ne dise où aller — l'ancien bloc de cases ayant disparu.
     */
    public function test_la_banniere_renvoie_au_parcours_quand_le_secteur_manque(): void
    {
        $this->entreprise->update(['secteur_activite' => []]);

        $this->actingAs($this->admin)
            ->get(route('admin.entreprise.parametres'))
            ->assertOk()
            ->assertSee("Votre domaine d'activité n'est pas encore choisi", false)
            ->assertSee(route('admin.souscription.index'), false);
    }

    // ─────────────────────────────────────────────────────────────────

    private function commerce(): Categorie
    {
        return Categorie::where('nom', 'Commerce')->firstOrFail();
    }

    private function parcoursComplet(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => Entreprise::MODULES_SOCLE]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);
    }

    private function uneVente(?PointDeVente $site = null): Vente
    {
        return Vente::create([
            'point_de_vente_id' => ($site ?? $this->site)->id,
            'numero_facture'    => 'FV-' . uniqid(),
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 1000, 'montant_tva' => 180, 'montant_ttc' => 1180,
            'etape'             => 'Facture', 'statut' => 'Payée',
        ]);
    }
}
