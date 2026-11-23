<?php

namespace Tests\Feature;

use App\Mail\FactureAuClient;
use App\Modules\Admin\Controleurs\EspaceSurMesureControleur;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\EspaceSurMesure;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Selflow sur mesure pour un salon de coiffure et de beauté (FOR MEN,
 * 23/11/2026) : couleurs zone par zone, fiche client enrichie, facture
 * envoyée au client par e-mail.
 */
class SurMesureMetierBeauteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $salon;
    private PointDeVente $cocody;
    private Utilisateur $gerant;
    private EspaceSurMesure $espace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salon = Entreprise::create(['nom' => 'FOR MEN', 'modules_actifs' => ['principal', 'ventes', 'tiers', 'produits']]);
        $this->cocody = PointDeVente::create(['entreprise_id' => $this->salon->id, 'nom' => 'Salon Cocody', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->gerant = Utilisateur::create(['nom' => 'Bamba', 'prenom' => 'Ali', 'email' => 'ali@formen.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->salon->id, 'point_de_vente_id' => $this->cocody->id]);
        $this->espace = EspaceSurMesure::create(['entreprise_id' => $this->salon->id, 'actif' => true, 'sous_domaine' => 'formen',
            'modules' => ['couleurs', 'fiche_client', 'facture_email'], 'couleur_principale' => '#b8860b', 'couleur_secondaire' => '#111111']);
    }

    private function facture(Client $client, array $champs = []): Vente
    {
        return Vente::create(array_merge([
            'point_de_vente_id' => $this->cocody->id, 'client_id' => $client->id, 'numero_facture' => 'FAC-' . uniqid(),
            'date_vente' => now()->toDateString(), 'mode_paiement' => 'Espèces', 'montant_ht' => 10000, 'montant_tva' => 0,
            'montant_ttc' => 10000, 'etape' => 'Facture', 'statut' => 'Payé',
        ], $champs));
    }

    public function test_les_adresses_se_creent_sous_dc_knowing_et_selflow_est_reserve(): void
    {
        $this->assertSame('dc-knowing.com', EspaceSurMesure::domaineRacine());
        $this->assertSame($this->salon->id, EspaceSurMesure::pourHote('formen.dc-knowing.com')?->entreprise_id);
        $this->assertNull(EspaceSurMesure::pourHote('selflow.dc-knowing.com'), 'Selflow lui-même reste Selflow.');

        Auth::login($this->gerant);
        $this->expectException(ValidationException::class);
        app(EspaceSurMesureControleur::class)->enregistrer(Request::create('/', 'POST', [
            'sous_domaine' => 'selflow', 'couleur_principale' => '#111111', 'couleur_secondaire' => '#111111',
            'modele_accueil' => 'horizon', 'modele_connexion' => 'panneau',
        ]), Entreprise::create(['nom' => 'Autre']));
    }

    public function test_le_menu_et_son_element_actif_se_reglent_a_part_ou_suivent_la_couleur_principale(): void
    {
        Auth::login($this->gerant);
        $base = ['couleur_principale' => '#B8860B', 'couleur_secondaire' => '#111111', 'modele_accueil' => 'horizon',
            'modele_connexion' => 'panneau', 'actif' => '1', 'sous_domaine' => 'formen', 'modules' => ['couleurs']];
        $controleur = app(EspaceSurMesureControleur::class);

        $controleur->enregistrer(Request::create('/', 'POST', $base + ['couleur_menu' => '#000000', 'menu_auto' => '0', 'accent_auto' => '1']), $this->salon);
        $espace = $this->espace->fresh();
        $this->assertSame('#000000', $espace->couleurMenu());
        $this->assertNull($espace->couleur_menu_accent);
        $this->assertSame($espace->teinte('#b8860b', .55), $espace->couleurMenuAccent(), 'Automatique : tirée de la couleur principale.');

        view()->share('errors', new \Illuminate\Support\ViewErrorBag());
        $html = $controleur->procedure()->render();
        $this->assertStringContainsString('--sidebar-bg: #000000', $html);

        $fiche = $controleur->modifier($this->salon)->render();
        foreach (['Fond du menu latéral', 'Élément actif du menu', 'Boutons et liens', 'Titres des pages', 'Logo blanc', 'Icône d\'onglet'] as $zone) {
            $this->assertStringContainsString(e($zone), $fiche);
        }
    }

    public function test_la_fiche_client_enrichie_n_existe_qu_avec_son_module(): void
    {
        $this->actingAs($this->gerant)->post(route('admin.clients.creer'), [
            'nom' => 'Kouamé Yao', 'email' => 'yao@mail.ci',
            'preferences' => ['style' => 'Dégradé américain', 'prestataire' => 'Moussa', 'inconnu' => 'x'],
        ])->assertSessionHasNoErrors();

        $client = Client::where('nom', 'Kouamé Yao')->first();
        $this->assertSame(['style' => 'Dégradé américain', 'prestataire' => 'Moussa'], $client->preferences);

        $this->get(route('admin.clients.index'))->assertOk()->assertSee('Préférences du client')->assertSee('Dégradé américain');

        $this->espace->update(['modules' => ['couleurs']]);
        $this->post(route('admin.clients.modifier', $client), ['nom' => 'Kouamé Yao', 'preferences' => ['style' => 'Afro']]);
        $this->assertSame('Dégradé américain', $client->fresh()->preferences['style'], 'Sans le module, rien ne change.');
        $this->get(route('admin.clients.index'))->assertDontSee('Préférences du client');
    }

    public function test_la_facture_part_au_client_a_l_emission(): void
    {
        Mail::fake();
        $client = Client::create(['entreprise_id' => $this->salon->id, 'nom' => 'Aya Koné', 'email' => 'aya@mail.ci']);
        $sansEmail = Client::create(['entreprise_id' => $this->salon->id, 'nom' => 'Sans e-mail']);

        $vente = $this->facture($client);
        $this->facture($sansEmail);
        $this->facture($client, ['normalise' => true, 'numero_fne' => 'X1']); // reprise du portail FNE
        app()->terminate();

        Mail::assertSent(FactureAuClient::class, 1);
        Mail::assertSent(FactureAuClient::class, fn ($m) => $m->hasTo('aya@mail.ci') && $m->vente->is($vente));
        $this->assertNotNull($vente->fresh()->envoyee_au_client_le);
    }

    public function test_apres_certification_la_facture_part_quand_elle_est_normalisee(): void
    {
        Mail::fake();
        $this->espace->update(['facture_email_moment' => 'certification']);
        $client = Client::create(['entreprise_id' => $this->salon->id, 'nom' => 'Aya Koné', 'email' => 'aya@mail.ci']);

        $vente = $this->facture($client);
        app()->terminate();
        Mail::assertNothingSent();

        $vente->update(['normalise' => true, 'numero_fne' => '9999999A26000000001']);
        app()->terminate();
        Mail::assertSent(FactureAuClient::class, 1);
    }

    public function test_le_bouton_e_mail_envoie_a_la_main_meme_sans_le_module(): void
    {
        Mail::fake();
        $this->espace->update(['modules' => []]);
        $client = Client::create(['entreprise_id' => $this->salon->id, 'nom' => 'Aya Koné', 'email' => 'aya@mail.ci']);
        $vente = $this->facture($client);
        app()->terminate();
        Mail::assertNothingSent();

        $this->actingAs($this->gerant)->post(route('admin.ventes.envoyer_email', $vente))->assertSessionHas('succes');
        Mail::assertSent(FactureAuClient::class, fn ($m) => $m->hasTo('aya@mail.ci'));
    }
}
