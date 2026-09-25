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
 * Les factures relevées au portail, sur les écrans où l'on regarde ses achats.
 *
 * ## Ce que ces épreuves gardent
 *
 * Le relevé rangeait les factures qu'un fournisseur a certifiées au NCC de
 * l'entreprise, et **seul un écran séparé les montrait**. Les deux écrans que
 * l'on ouvre tous les jours — `/admin/achats/factures` et l'onglet Achats de
 * `/admin/fne/factures` — ne lisaient que la table `achats` : ce que Selflow a
 * saisi, jamais ce que la DGI détient. Une charge connue du fisc pouvait rester
 * des semaines hors de la comptabilité sans que rien ne le signale.
 *
 * **Depuis le 25/09/2026, elles ont leur propre section** —
 * `?etape=Facture&section=dgi` — au lieu d'être mêlées aux pièces saisies. Le
 * mélange obligeait chaque ligne à expliquer ce qu'elle était, et la colonne
 * « Normalisé (DGI) » à mentir pour les autres. L'écran séparé a disparu du
 * même coup : la section porte les mêmes pièces et les mêmes gestes. Ce que
 * ces épreuves gardent n'a pas changé — seulement l'adresse où le vérifier.
 *
 * ## Les deux pièges, et pourquoi ils ne sont pas symétriques
 *
 * 1. **Le doublon.** Une facture du portail rattachée à un achat est le même
 *    document. L'afficher deux fois gonflerait le total TTC de l'écran FNE.
 * 2. **Le point de vente.** Le portail ne dit pas à quel site de l'entreprise
 *    une facture reçue se rattache — `clientEstablishment` et `clientPointOfSale`
 *    décrivent l'émetteur, comme `FneService` envoie les nôtres quand c'est nous
 *    qui émettons. D'où deux règles différentes, et voulues :
 *    l'écran FNE **totalise** des montants, donc il écarte ces pièces dès qu'un
 *    site précis est demandé ; l'écran des achats ne totalise rien, donc il les
 *    montre toujours — les masquer les rendrait invisibles partout.
 */
class FacturesDuPortailAuxEcransAchatsTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------- /admin/achats/factures --------------------- */

    public function test_l_ecran_des_achats_montre_une_facture_relevee_au_portail(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise, ['emetteur_nom' => 'CENTRE IVOIRIEN ARCHIVAGE']);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertSee('B0000001X26000000042')
            ->assertSee('CENTRE IVOIRIEN ARCHIVAGE')
            ->assertSee('Portail DGI')
            // Le site manque : l'écran le demande plutôt que de le deviner.
            ->assertSee('à affecter');
    }

    /* ------------------------ L'adresse de vérification -------------------- */

    public function test_l_adresse_de_verification_se_reconstruit_a_partir_du_token(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        config(['selflow.fne_api_url_production' => '', 'selflow.fne_api_url_sandbox' => 'http://54.247.95.108/ws']);

        $facture = $this->uneFactureRecue($entreprise, ['token' => '01a06bf8-8e1a-7000-8650-7ae311524dfc']);

        // Le même motif que celui que la DGI produit elle-même pour nos pièces
        // certifiées, où `qr_code_data` vaut
        // « …/fr/verification/01a05e78-6da1-7000-8346-e188ca934174 ». Le relevé
        // des factures reçues rend la clé seule : c'est le chemin qui manque.
        $this->assertSame(
            'http://54.247.95.108/fr/verification/01a06bf8-8e1a-7000-8650-7ae311524dfc',
            $facture->urlDeVerification()
        );

        // Et les deux boutons apparaissent, là où la colonne ne portait qu'un
        // texte « vérifiable » sur lequel rien ne se cliquait.
        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertSee('http://54.247.95.108/fr/verification/01a06bf8-8e1a-7000-8650-7ae311524dfc', false);
    }

    public function test_un_token_deja_complet_n_est_pas_prefixe_deux_fois(): void
    {
        [, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        // Nos propres pièces reçoivent l'URL entière dans `token`. Le jour où
        // `/ws/invoices` fera de même, la préfixer produirait un lien mort.
        $facture = $this->uneFactureRecue($entreprise, [
            'token' => 'http://54.247.95.108/fr/verification/01a06bf8-8e1a-7000-8650-7ae311524dfc',
        ]);

        $this->assertSame(
            'http://54.247.95.108/fr/verification/01a06bf8-8e1a-7000-8650-7ae311524dfc',
            $facture->urlDeVerification()
        );
    }

    public function test_sans_token_aucun_lien_n_est_invente(): void
    {
        [, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise, ['token' => null]);

        // Un bouton qui mène à une page de vérification vide ferait douter
        // d'une pièce parfaitement valide.
        $this->assertNull($facture->urlDeVerification());
    }

    /* ---------------------------- Le document ----------------------------- */

    public function test_la_facture_recue_se_lit_comme_un_document(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise, ['point_de_vente_id' => $pdv->id]);
        $facture->lignes()->create([
            'designation'   => 'Tilapia 500/800 20KG',
            'quantite'      => 4,
            'unite'         => 'carton',
            'prix_unitaire' => 11000,
        ]);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues.imprimer', $facture))
            ->assertOk()
            ->assertSee('B0000001X26000000042')
            ->assertSee('FOURNISSEUR SARL')
            ->assertSee('Tilapia 500/800 20KG')
            ->assertSee('FACTURATION SIEGE')
            // Les deux parties sont nommées : qui a émis, qui a reçu.
            ->assertSee('Émetteur')
            ->assertSee('Destinataire');
    }

    public function test_le_document_ne_se_donne_pas_pour_la_piece_originale(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise);

        // C'est le fournisseur qui a établi la pièce et la DGI qui l'a
        // certifiée. Un document qui s'en distinguerait mal serait un
        // fac-similé de pièce fiscale — d'où le bandeau, et d'où la vue rangée
        // hors de `Vues/factures/`, gelé, qui porte ce que Selflow émet.
        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues.imprimer', $facture))
            ->assertOk()
            // `false` : le bandeau est du HTML statique, qui garde l'apostrophe
            // littérale là où `assertSee` l'échapperait.
            ->assertSee("Copie d'après le relevé du portail FNE", false)
            ->assertSee('originale certifiée', false)
            // Le code QR mène à la DGI et n'atteste rien de ce document-ci.
            ->assertSee("Il n'atteste rien de ce document-ci", false);
    }

    public function test_le_document_d_une_autre_entreprise_est_refuse(): void
    {
        [$utilisateur] = $this->uneEntrepriseAvecUtilisateur();

        $voisine = Entreprise::create([
            'nom'               => 'AUTRE SARL',
            'ncc'               => '9999999Z',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Yopougon',
            'rccm'              => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'achats'],
        ]);

        $facture = $this->uneFactureRecue($voisine);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues.imprimer', $facture))
            ->assertNotFound();
    }

    /* --------------------------- Le point de vente ------------------------ */

    public function test_une_facture_affectee_affiche_son_site(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise, ['point_de_vente_id' => $pdv->id]);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertSee('FACTURATION SIEGE')
            ->assertDontSee('à affecter');
    }

    public function test_affecter_range_la_facture_sous_le_site_choisi(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise);

        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.affecter', $facture), [
                'point_de_vente_id' => $pdv->id,
            ])
            ->assertRedirect();

        $this->assertSame($pdv->id, $facture->refresh()->point_de_vente_id);
    }

    public function test_affecter_refuse_le_site_d_une_autre_entreprise(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $voisine = Entreprise::create([
            'nom'               => 'AUTRE SARL',
            'ncc'               => '9999999Z',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Yopougon',
            'rccm'              => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'achats'],
        ]);

        $siteVoisin = PointDeVente::create([
            'entreprise_id' => $voisine->id,
            'nom'           => 'SITE VOISIN',
            'ville'         => 'Abidjan',
            'commune'       => 'Yopougon',
        ]);

        $facture = $this->uneFactureRecue($entreprise);

        // Un identifiant forgé rangerait la charge d'une entreprise sous
        // l'établissement d'une autre.
        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.affecter', $facture), [
                'point_de_vente_id' => $siteVoisin->id,
            ])
            ->assertRedirect();

        $this->assertNull($facture->refresh()->point_de_vente_id);
    }

    public function test_rattacher_fait_hériter_le_site_de_l_achat(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $autreSite = PointDeVente::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'PDV-MARCORY',
            'ville'         => 'Abidjan',
            'commune'       => 'Marcory',
        ]);

        $fournisseur = $this->unFournisseur($entreprise);
        $achat       = $this->unAchat($autreSite, $fournisseur, 11800);

        // Affectée à tort au siège avant qu'on sache de quel achat il s'agissait.
        $facture = $this->uneFactureRecue($entreprise, ['point_de_vente_id' => $pdv->id]);

        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.rattacher', $facture))
            ->assertRedirect();

        // Le rattachement sait mieux : c'est la même pièce que l'achat.
        $this->assertSame($achat->point_de_vente_id, $facture->refresh()->point_de_vente_id);
    }

    public function test_un_site_precis_montre_ce_qui_lui_est_affecte(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise, ['point_de_vente_id' => $pdv->id]);

        // Affectée, donc elle compte dans le total de ce site.
        $this->actingAs($utilisateur)
            ->getJson(route('admin.fne.factures.donnees', [
                'flux'      => 'achats',
                'categorie' => 'recu',
                'pdv_id'    => $pdv->id,
            ]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('documents.0.pdv', 'FACTURATION SIEGE')
            ->assertJsonPath('totaux.ttc', 11800);
    }

    public function test_une_facture_deja_rattachee_n_apparait_pas_une_seconde_fois(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $fournisseur = $this->unFournisseur($entreprise);
        $achat       = $this->unAchat($pdv, $fournisseur, 11800);

        $this->uneFactureRecue($entreprise, [
            'achat_id'             => $achat->id,
            'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
        ]);

        // La section « Factures achat DGI » ne porte que les pièces du portail :
        // l'achat auquel celle-ci est rattachée vit dans une autre section. Le
        // doublon que cette épreuve surveillait — la même facture comptée deux
        // fois dans une liste mêlée — n'est plus possible par construction, et
        // ce qu'il faut vérifier est qu'elle apparaît une fois, pas zéro.
        $dgi = $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk();

        // Une ligne, et une seule. La référence elle-même paraît plusieurs fois
        // dans une ligne — le libellé, l'infobulle, la confirmation d'écartement
        // — : c'est le marqueur de ligne qu'il faut compter.
        $dgi->assertSee('B0000001X26000000042');
        $this->assertSame(1, substr_count($dgi->getContent(), 'Portail DGI'));

        // L'achat n'y a pas de ligne à lui. Son numéro y paraît — la
        // proposition de rapprochement le nomme, et c'est justement ce qu'on
        // lui demande — mais son document n'y est pas.
        $dgi->assertDontSee(route('admin.achats.imprimer', $achat));

        // Et l'achat, lui, est sous « Factures enregistrées », une seule fois.
        $enregistrees = $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'enregistrees']))
            ->assertOk();

        $enregistrees->assertSee('ACH-0001');
        $this->assertSame(0, substr_count($enregistrees->getContent(), 'B0000001X26000000042'));
    }

    public function test_une_facture_ecartee_se_retrouve_derriere_son_filtre(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        // Écarter n'est pas supprimer : la pièce reste en base et le portail la
        // redéposera. Ce qu'on a mis de côté ne doit pas revenir chaque jour.
        $this->uneFactureRecue($entreprise, [
            'statut_rapprochement' => PortailFneFactureRecue::ECARTEE,
        ]);

        // Elle ne revient pas d'elle-même...
        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertDontSee('B0000001X26000000042');

        // ...mais elle se retrouve quand on la demande. L'écran séparé portait
        // seul ce filtre ; le retirer sans le reprendre aurait fait de
        // l'écartement une suppression déguisée.
        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi', 'statut' => 'ecartees']))
            ->assertOk()
            ->assertSee('B0000001X26000000042')
            ->assertSee('Remettre');
    }

    public function test_une_facture_ecartee_peut_revenir(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise, [
            'statut_rapprochement' => PortailFneFactureRecue::ECARTEE,
            'note_rapprochement'   => 'Écartée par erreur.',
        ]);

        // Écarter n'avait aucune porte de retour : « Détacher » ne s'affiche que
        // pour une pièce rattachée, et la facture quittait tous les écrans sans
        // qu'on puisse la rappeler. Une charge que la DGI détient ne doit pas
        // pouvoir disparaître d'un clic sans recours.
        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.reintegrer', $facture))
            ->assertRedirect();

        $facture->refresh();
        $this->assertSame(PortailFneFactureRecue::A_RAPPROCHER, $facture->statut_rapprochement);
        $this->assertNull($facture->note_rapprochement);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertSee('B0000001X26000000042');
    }

    public function test_une_facture_sans_ncc_revient_orpheline_et_non_rapprochable(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise, [
            'statut_rapprochement' => PortailFneFactureRecue::ECARTEE,
            'emetteur_ncc'         => null,
        ]);

        $this->actingAs($utilisateur)
            ->post(route('admin.achats.factures_recues.reintegrer', $facture))
            ->assertRedirect();

        // Sans NCC, aucun fournisseur ne peut être retrouvé : la présenter
        // comme rapprochable serait mentir sur ce qui est possible.
        $this->assertSame(PortailFneFactureRecue::ORPHELINE, $facture->refresh()->statut_rapprochement);
    }

    public function test_une_facture_sans_site_reste_visible_sous_tous_les_sites(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $autreSite = PointDeVente::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'PDV-MARCORY',
            'ville'         => 'Abidjan',
            'commune'       => 'Marcory',
        ]);

        $this->uneFactureRecue($entreprise);

        // Tant que personne ne l'a rangée, elle n'appartient à aucun site. La
        // cacher derrière le site actif la rendrait invisible sous tous, et
        // personne ne l'affecterait jamais.
        $this->actingAs($utilisateur)
            ->withSession(['point_de_vente_actif_id' => $autreSite->id])
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertSee('B0000001X26000000042');
    }

    public function test_une_facture_affectee_ailleurs_n_encombre_pas_le_site_actif(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $marcory = PointDeVente::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'PDV-MARCORY',
            'ville'         => 'Abidjan',
            'commune'       => 'Marcory',
        ]);

        $this->uneFactureRecue($entreprise, ['point_de_vente_id' => $marcory->id]);

        // Rangée à Marcory : le Siège n'a pas à la porter. C'est la contrepartie
        // de l'affectation — sans elle, chaque site verrait les charges de tous.
        $this->actingAs($utilisateur)
            ->withSession(['point_de_vente_actif_id' => $pdv->id])
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertDontSee('B0000001X26000000042');

        // Et elle est bien là où on l'a rangée.
        $this->actingAs($utilisateur)
            ->withSession(['point_de_vente_actif_id' => $marcory->id])
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertSee('B0000001X26000000042');
    }

    public function test_l_ecran_s_ouvre_meme_quand_aucun_achat_n_est_saisi(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise);

        // Sans ce garde-fou, une entreprise dont la DGI détient des factures
        // mais qui n'a encore rien saisi lisait « aucun élément » — l'exact
        // contraire de ce qu'il fallait comprendre.
        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertSee('B0000001X26000000042')
            ->assertDontSee('Aucun élément disponible');
    }

    /* --------------------- /admin/fne/factures (onglet Achats) ------------ */

    public function test_l_onglet_achats_du_registre_fne_montre_les_factures_du_portail(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise);

        $reponse = $this->actingAs($utilisateur)
            ->getJson(route('admin.fne.factures.donnees', [
                'flux'      => 'achats',
                'categorie' => 'recu',
                'pdv_id'    => 'tous',
            ]))
            ->assertOk();

        $reponse->assertJsonPath('documents.0.num_fne', 'B0000001X26000000042');
        $reponse->assertJsonPath('documents.0.origine', 'portail');
        // Certifiée — mais par le fournisseur. L'afficher « non normalisée »
        // laisserait croire qu'un envoi à la DGI nous incombe.
        $reponse->assertJsonPath('documents.0.normalise', true);
        // Rien à imprimer ni à normaliser : la pièce n'est pas la nôtre.
        $reponse->assertJsonPath('documents.0.normaliser_url', null);
        $reponse->assertJsonPath('documents.0.pdv', null);
    }

    public function test_un_site_precis_ecarte_les_pieces_sans_site_pour_ne_pas_fausser_le_total(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise);

        // On ne sait pas que cette facture revient à ce site. L'y verser
        // gonflerait le total TTC affiché sous le tableau d'un montant dont
        // rien ne dit qu'il lui appartient.
        $this->actingAs($utilisateur)
            ->getJson(route('admin.fne.factures.donnees', [
                'flux'      => 'achats',
                'categorie' => 'recu',
                'pdv_id'    => $pdv->id,
            ]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('totaux.ttc', 0);
    }

    public function test_les_pieces_du_portail_ne_polluent_ni_les_bapa_ni_les_avoirs(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $this->uneFactureRecue($entreprise);

        // Un BAPA est émis par nous, un avoir fournisseur aussi. Une facture
        // qu'un tiers nous a adressée n'a rien à faire dans ces deux onglets.
        foreach (['emis', 'avoir_fournisseur'] as $categorie) {
            $this->actingAs($utilisateur)
                ->getJson(route('admin.fne.factures.donnees', [
                    'flux'      => 'achats',
                    'categorie' => $categorie,
                    'pdv_id'    => 'tous',
                ]))
                ->assertOk()
                ->assertJsonPath('pagination.total', 0);
        }
    }

    public function test_un_achat_retrouve_au_portail_est_certifie_et_compte_une_seule_fois(): void
    {
        [$utilisateur, $entreprise, $pdv] = $this->uneEntrepriseAvecUtilisateur();

        $fournisseur = $this->unFournisseur($entreprise);
        $achat       = $this->unAchat($pdv, $fournisseur, 11800);

        $this->uneFactureRecue($entreprise, [
            'achat_id'             => $achat->id,
            'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
        ]);

        $reponse = $this->actingAs($utilisateur)
            ->getJson(route('admin.fne.factures.donnees', [
                'flux'      => 'achats',
                'categorie' => 'recu',
                'pdv_id'    => 'tous',
            ]))
            ->assertOk();

        // Un seul document, et son TTC compté une fois.
        $reponse->assertJsonPath('pagination.total', 1);
        $reponse->assertJsonPath('totaux.ttc', 11800);

        // L'achat n'est pas « non normalisé » : la DGI détient la pièce, et
        // c'est son numéro FNE qui l'identifie devant un contrôle.
        $reponse->assertJsonPath('documents.0.origine', 'selflow_dgi');
        $reponse->assertJsonPath('documents.0.normalise', true);
        $reponse->assertJsonPath('documents.0.num_fne', 'B0000001X26000000042');

        // Et rien n'a été écrit dans les colonnes gelées d'`achats` : elles
        // veulent dire « Selflow a émis cette pièce », ce qui serait faux.
        $this->assertNull($achat->refresh()->numero_fne);
        $this->assertFalse((bool) $achat->normalise);
    }

    public function test_une_entreprise_ne_voit_pas_les_factures_du_portail_d_une_autre(): void
    {
        [$utilisateur] = $this->uneEntrepriseAvecUtilisateur();

        $voisine = Entreprise::create([
            'nom'               => 'AUTRE SARL',
            'ncc'               => '9999999Z',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Yopougon',
            'rccm'              => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'achats'],
        ]);

        $this->uneFactureRecue($voisine);

        // Une pièce fiscale lue par le mauvais client ne se répare pas.
        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']))
            ->assertOk()
            ->assertDontSee('B0000001X26000000042');

        $this->actingAs($utilisateur)
            ->getJson(route('admin.fne.factures.donnees', [
                'flux'      => 'achats',
                'categorie' => 'recu',
                'pdv_id'    => 'tous',
            ]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    /* -------------------------------------------------------------------- */

    /** @return array{0: Utilisateur, 1: Entreprise, 2: PointDeVente} */
    private function uneEntrepriseAvecUtilisateur(): array
    {
        $entreprise = Entreprise::create([
            'nom'               => 'DC-KNOWING CGA',
            'ncc'               => '1864699A',
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

    private function unFournisseur(Entreprise $entreprise): Fournisseur
    {
        return Fournisseur::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'FOURNISSEUR SARL',
            'ncc'           => '0000001X',
        ]);
    }

    private function unAchat(PointDeVente $pdv, Fournisseur $fournisseur, float $ttc): Achat
    {
        return Achat::create([
            'point_de_vente_id' => $pdv->id,
            'fournisseur_id'    => $fournisseur->id,
            'numero_facture'    => 'ACH-0001',
            'date_achat'        => '2026-08-27',
            'etape'             => 'Facture',
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
