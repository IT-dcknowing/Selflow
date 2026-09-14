<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'écran des factures reçues du portail.
 *
 * Trois choses s'y vérifient, et aucune n'est décorative :
 *
 * 1. **Rien ne crée d'achat.** Rattacher relie une pièce du portail à un achat
 *    qui existe déjà. En fabriquer un produirait des écritures comptables parce
 *    qu'un fichier est arrivé dans un dossier.
 * 2. **Rien n'écrit dans les colonnes gelées d'`achats`.** Le numéro FNE d'un
 *    fournisseur dans `achats.numero_fne` ferait dire à Selflow qu'il a certifié
 *    une pièce qu'il n'a jamais émise.
 * 3. **Une entreprise ne voit pas les factures d'une autre.** Une pièce fiscale
 *    lue par le mauvais client ne se répare pas.
 */
class EcranFacturesRecuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_ecran_montre_les_factures_relevees_au_portail(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise, ['emetteur_nom' => 'FOURNISSEUR SARL']);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues'))
            ->assertOk()
            ->assertSee('B0000001X26000000042')
            ->assertSee('FOURNISSEUR SARL')
            // Le message qui dit ce que l'écran ne fait pas, montré une fois en
            // haut plutôt que répété sur chaque ligne.
            ->assertSee('un constat de la DGI', false);
    }

    public function test_une_facture_sans_fournisseur_connu_le_dit_au_lieu_de_proposer_un_rapprochement(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise, [
            'statut_rapprochement' => PortailFneFactureRecue::ORPHELINE,
            'emetteur_ncc'         => null,
        ]);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues'))
            ->assertOk()
            ->assertSee('Aucun fournisseur ne porte ce NCC');
    }

    public function test_rattacher_relie_a_un_achat_existant_sans_en_creer(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $fournisseur = Fournisseur::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'FOURNISSEUR SARL',
            'ncc'           => '0000001 X',
        ]);

        $achat = $this->unAchat($pdv, $fournisseur, 11800);
        $facture = $this->uneFactureRecue($entreprise);

        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.rattacher', $facture))
            ->assertRedirect();

        $facture->refresh();

        $this->assertSame($achat->id, $facture->achat_id);
        $this->assertSame(PortailFneFactureRecue::RAPPROCHEE, $facture->statut_rapprochement);

        // Aucun achat de plus, et surtout aucune colonne gelée touchée.
        $this->assertSame(1, Achat::count());
        $this->assertNull($achat->refresh()->numero_fne);
    }

    public function test_un_ecart_de_montant_est_conserve_et_non_tu(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $fournisseur = Fournisseur::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'FOURNISSEUR SARL',
            'ncc'           => '0000001X',
        ]);

        // L'achat saisi à la main dit 11 000, la DGI détient 11 800. C'est
        // exactement ce qui vaut de l'argent, et personne ne le voyait.
        $this->unAchat($pdv, $fournisseur, 11000);
        $facture = $this->uneFactureRecue($entreprise);

        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.rattacher', $facture))
            ->assertRedirect();

        $this->assertStringContainsString('écart', (string) $facture->refresh()->note_rapprochement);
    }

    public function test_rattacher_sans_achat_en_face_refuse_plutot_que_de_le_creer(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        Fournisseur::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'FOURNISSEUR SARL',
            'ncc'           => '0000001X',
        ]);

        $facture = $this->uneFactureRecue($entreprise);

        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.rattacher', $facture))
            ->assertRedirect()
            ->assertSessionHas('erreur');

        $this->assertSame(0, Achat::count());
        $this->assertNull($facture->refresh()->achat_id);
    }

    public function test_ecarter_ne_supprime_pas_la_facture(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();
        $facture = $this->uneFactureRecue($entreprise);

        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.ecarter', $facture), ['motif' => 'Doublon fournisseur'])
            ->assertRedirect();

        // Le portail la redéposera au prochain relevé : la supprimer ferait
        // revenir la même pièce dans « à rapprocher » chaque jour.
        $this->assertSame(1, PortailFneFactureRecue::count());
        $this->assertSame(PortailFneFactureRecue::ECARTEE, $facture->refresh()->statut_rapprochement);
        $this->assertSame('Doublon fournisseur', $facture->note_rapprochement);
    }

    public function test_une_entreprise_ne_touche_pas_la_facture_d_une_autre(): void
    {
        [$utilisateur] = $this->uneEntrepriseAvecUtilisateur();
        [, $autre] = $this->uneEntrepriseAvecUtilisateur('AUTRE SARL', '9999999Z');

        $facture = $this->uneFactureRecue($autre);

        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.ecarter', $facture))
            ->assertNotFound();

        $this->assertSame(
            PortailFneFactureRecue::A_RAPPROCHER,
            $facture->refresh()->statut_rapprochement
        );
    }

    public function test_l_ecran_est_joignable_sans_le_module_comptabilite(): void
    {
        // C'est la raison d'etre du deplacement sous `achats` : une entreprise
        // qui achete sans tenir sa comptabilite dans Selflow doit voir les
        // factures que ses fournisseurs lui ont certifiees. Sous le groupe FNE,
        // garde par `modules:comptabilite`, elle recevait un 403 sur l'ecran de
        // ses propres factures d'achat.
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $entreprise->update(['modules_actifs' => ['principal', 'achats']]);

        $this->uneFactureRecue($entreprise);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues'))
            ->assertOk()
            ->assertSee('B0000001X26000000042')
            // Et les onglets FNE ne s'affichent pas : ils pointent vers des
            // ecrans qui, eux, lui repondraient 403.
            ->assertDontSee('Stickers');
    }

    /* -------------------------------------------------------------------- */

    /** @return array{0: Utilisateur, 1: Entreprise, 2: PointDeVente} */
    private function uneEntrepriseAvecUtilisateur(string $nom = 'DC-KNOWING CGA', string $ncc = '1864699A'): array
    {
        $entreprise = Entreprise::create([
            'nom'               => $nom,
            'ncc'               => $ncc,
            'regime_imposition' => 'RNI',
            'adresse'           => 'RIVIERA II AFRICAINE',
            'rccm'              => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'comptabilite'],
        ]);

        $pdv = PointDeVente::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'FACTURATION SIEGE',
            'ville'         => 'Abidjan',
            'commune'       => 'Cocody',
        ]);

        $utilisateur = Utilisateur::create([
            'nom'               => 'Yao',
            'prenom'            => 'Adjoua',
            'email'             => 'test' . random_int(1000, 999999) . '@exemple.ci',
            'password'          => bcrypt('secret-de-test'),
            'role'              => 'admin',
            'entreprise_id'     => $entreprise->id,
            'point_de_vente_id' => $pdv->id,
        ]);

        return [$utilisateur, $entreprise, $pdv];
    }

    private function unAchat(PointDeVente $pdv, Fournisseur $fournisseur, float $ttc): Achat
    {
        return Achat::create([
            'point_de_vente_id' => $pdv->id,
            'fournisseur_id'    => $fournisseur->id,
            'numero_facture'    => 'ACH-0001',
            'date_achat'        => '2026-08-27',
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => round($ttc / 1.18, 2),
            'montant_tva'       => round($ttc - $ttc / 1.18, 2),
            'montant_ttc'       => $ttc,
        ]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function uneFactureRecue(Entreprise $entreprise, array $attributs = []): PortailFneFactureRecue
    {
        $import = PortailFneImport::create([
            'entreprise_id'     => $entreprise->id,
            'login'             => $entreprise->ncc,
            'date_scraping'     => '2026-08-27',
            'type'              => 'achats',
            'fichier_nom'       => $entreprise->ncc . '_20260827.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
            'dernier_releve_le' => '2026-08-27',
        ]);

        return PortailFneFactureRecue::create(array_merge([
            'import_id'            => $import->id,
            'entreprise_id'        => $entreprise->id,
            'login'                => $entreprise->ncc,
            'date_scraping'        => '2026-08-27',
            'reference'            => 'B0000001X26000000042',
            'token'                => '01a04306-e47e-7000-8275-49aa4b9318e3',
            'type'                 => 'invoice',
            'subtype'              => 'normal',
            'date_facture'         => '2026-08-27 11:42:00',
            'emetteur_ncc'         => '0000001X',
            'emetteur_nom'         => 'FOURNISSEUR SARL',
            'montant_ht'           => 10000,
            'montant_tva'          => 1800,
            'montant_ttc'          => 11800,
            'net_a_payer'          => 11800,
            'statut_rapprochement' => PortailFneFactureRecue::A_RAPPROCHER,
        ], $attributs));
    }
}
