<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\NumerotationService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Urgence du 26/10/2026 : pas de facture en double. Ni deux pièces sous le
 * même numéro, ni la même vente enregistrée deux fois par un double envoi.
 */
class FacturesSansDoublonsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Produit $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->entreprise = Entreprise::create([
            'nom' => 'Poissonnerie EDEN', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody',
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers'],
            'normalisation_auto_factures' => false, 'normalisation_auto_recus' => false,
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Comptoir', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client comptoir']);
        $this->service = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-001', 'nom' => 'Écaillage',
            'type' => 'service', 'unite' => 'unité', 'prix_achat' => 0, 'prix_vente' => 1000, 'taux_tva' => 18,
        ]);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@eden.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function vendre(array $extra = [])
    {
        return $this->post(route('admin.ventes.enregistrer'), array_merge([
            'mode_paiement' => 'Caisse', 'montant_paye' => 1180, 'etape' => 'Facture',
            'articles' => [['produit_id' => $this->service->id, 'quantite' => 1, 'unite' => 'unité']],
        ], $extra));
    }

    public function test_le_second_envoi_du_meme_formulaire_n_enregistre_rien(): void
    {
        $this->vendre(['jeton_envoi' => 'jeton-unique-1'])->assertSessionHasNoErrors();
        $this->vendre(['jeton_envoi' => 'jeton-unique-1'])
            ->assertSessionHas('info', fn ($m) => str_contains($m, 'déjà été enregistrée'));

        $this->assertSame(1, Vente::where('etape', 'Facture')->count());

        // Un autre formulaire, un autre jeton : une seconde vente, bien sûr.
        $this->vendre(['jeton_envoi' => 'jeton-unique-2'])->assertSessionHasNoErrors();
        $this->assertSame(2, Vente::where('etape', 'Facture')->count());
    }

    public function test_le_formulaire_porte_son_jeton_et_bloque_le_double_clic(): void
    {
        $this->get(route('admin.ventes.nouvelle'))->assertOk()
            ->assertSee('name="jeton_envoi"', false)
            ->assertSee("form.dataset.envoye === '1'", false);
    }

    public function test_un_numero_n_est_jamais_redonne_apres_une_suppression(): void
    {
        $this->vendre()->assertSessionHasNoErrors();
        $this->vendre()->assertSessionHasNoErrors();
        [$premiere, $seconde] = Vente::where('etape', 'Facture')->orderBy('id')->get()->all();
        $this->assertStringEndsWith('-001', $premiere->numero_facture);
        $this->assertStringEndsWith('-002', $seconde->numero_facture);

        // La première disparaît (archive effacée) : l'ancien comptage
        // redonnait « -002 », déjà porté par la seconde.
        $premiere->details()->delete();
        $premiere->delete();

        $numero = NumerotationService::genererNumeroVente($this->entreprise->id, 'Facture', null, $this->site->id);
        $this->assertStringEndsWith('-003', $numero);
        $this->assertSame(0, Vente::where('numero_facture', $numero)->count());
    }

    public function test_un_numero_deja_pris_est_saute(): void
    {
        $this->vendre()->assertSessionHasNoErrors();
        $vente = Vente::where('etape', 'Facture')->sole();
        // Une pièce saisie à part a déjà pris le numéro suivant.
        $suivant = preg_replace('/-001$/', '-002', $vente->numero_facture);
        $copie = $vente->replicate(['uuid']);
        $copie->numero_facture = $suivant;
        $copie->save();

        $this->assertStringEndsWith('-003', NumerotationService::genererNumeroVente($this->entreprise->id, 'Facture', null, $this->site->id));
    }

    public function test_la_commande_recense_les_doublons_sans_rien_modifier(): void
    {
        $this->vendre()->assertSessionHasNoErrors();
        $this->vendre()->assertSessionHasNoErrors();
        $ventes = Vente::where('etape', 'Facture')->orderBy('id')->get();
        // Même numéro sur deux pièces, comme l'ancienne numérotation pouvait le faire.
        $ventes[1]->update(['numero_facture' => $ventes[0]->numero_facture]);

        $this->artisan('selflow:doublons-factures', ['entreprise' => 'Poissonnerie EDEN'])
            ->expectsOutputToContain('Même numéro sur plusieurs pièces')
            ->expectsOutputToContain('Même pièce enregistrée deux fois')
            ->expectsOutputToContain('Bilan par entreprise')
            ->expectsOutputToContain("Rien n'a été modifié")
            ->assertSuccessful();

        $this->assertSame(2, Vente::where('etape', 'Facture')->count());
    }

    /**
     * Retour du 26/10/2026 sur la première exécution en ligne : deux ventes de
     * montants égaux mais d'articles différents, ou pour deux clients, ou à
     * plusieurs minutes d'écart, ne sont pas des doublons.
     */
    public function test_meme_montant_ne_suffit_pas_a_faire_un_doublon(): void
    {
        $autre = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-002', 'nom' => 'Découpe',
            'type' => 'service', 'unite' => 'unité', 'prix_achat' => 0, 'prix_vente' => 1000, 'taux_tva' => 18,
        ]);
        $this->vendre()->assertSessionHasNoErrors();
        // Même montant, autre article.
        $this->vendre(['articles' => [['produit_id' => $autre->id, 'quantite' => 1, 'unite' => 'unité']]])->assertSessionHasNoErrors();
        // Même article, cinq minutes plus tard.
        $this->travel(5)->minutes();
        $this->vendre()->assertSessionHasNoErrors();

        $this->artisan('selflow:doublons-factures', ['entreprise' => 'Poissonnerie EDEN'])
            ->doesntExpectOutputToContain('Même pièce enregistrée deux fois')
            ->expectsOutputToContain('Aucun doublon à traiter.')
            ->assertSuccessful();
    }

    public function test_les_numeros_manquants_sont_listes(): void
    {
        $this->vendre()->assertSessionHasNoErrors();
        $this->vendre()->assertSessionHasNoErrors();
        $this->vendre()->assertSessionHasNoErrors();
        $seconde = Vente::where('etape', 'Facture')->orderBy('id')->skip(1)->first();
        $seconde->update(['numero_facture' => preg_replace('/-002$/', '-009', $seconde->numero_facture)]);

        $this->artisan('selflow:doublons-factures', ['entreprise' => 'Poissonnerie EDEN', '--secondes' => 0])
            ->expectsOutputToContain('Numéros manquants dans la séquence')
            ->expectsOutputToContain('002, 004, 005, 006, 007, 008')
            ->assertSuccessful();
    }

    /** Chaque point de vente a sa propre séquence, sans saut. */
    public function test_chaque_point_de_vente_numerote_a_part_sans_sauter(): void
    {
        $second = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Marché', 'ville' => 'Abidjan', 'commune' => 'Adjamé']);
        $this->site->update(['code_piece' => '01']);
        $second->update(['code_piece' => '02']);

        $numeros = [];
        foreach ([$this->site, $this->site, $second, $this->site, $second] as $site) {
            $this->withSession(['point_de_vente_actif_id' => $site->id])->vendre()->assertSessionHasNoErrors();
            $numeros[] = Vente::where('etape', 'Facture')->latest('id')->value('numero_facture');
        }

        $jour = now()->format('dmy');
        $this->assertSame([
            "VTE-01-{$jour}-001", "VTE-01-{$jour}-002", "VTE-02-{$jour}-001", "VTE-01-{$jour}-003", "VTE-02-{$jour}-002",
        ], $numeros);
    }

    /** Un doublon déjà annulé est réglé : il ne se mélange plus aux autres (26/10/2026). */
    public function test_un_doublon_annule_ne_compte_plus(): void
    {
        $this->vendre()->assertSessionHasNoErrors();
        $this->vendre()->assertSessionHasNoErrors();
        $ventes = Vente::where('etape', 'Facture')->orderBy('id')->get();
        $ventes[1]->update(['numero_facture' => $ventes[0]->numero_facture]);

        // L'entreprise annule la seconde, avec son avoir interne.
        $this->post(route('admin.ventes.annuler', $ventes[1]), ['motif' => 'Doublon'])->assertSessionHas('succes');

        $this->artisan('selflow:doublons-factures', ['entreprise' => 'Poissonnerie EDEN', '--sans-trous' => true])
            ->doesntExpectOutputToContain('Même numéro sur plusieurs pièces')
            ->doesntExpectOutputToContain('Même pièce enregistrée deux fois')
            ->expectsOutputToContain('Déjà réglés par annulation')
            ->expectsOutputToContain('Aucun doublon à traiter.')
            ->assertSuccessful();
    }

    public function test_sans_doublon_la_commande_le_dit(): void
    {
        $this->vendre()->assertSessionHasNoErrors();
        $this->artisan('selflow:doublons-factures')->expectsOutputToContain('Aucun doublon à traiter.')->assertSuccessful();
    }
}
