<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Les habilitations, et ce qui les faisait dériver.
 *
 * Le dictionnaire vivait dans le middleware, et la vérification s'écrivait :
 *
 *     if (isset($correspondances[$route])) { … }
 *
 * **Une route absente du dictionnaire passait donc sans contrôle.** Ce n'était
 * pas une décision, c'était un oubli qui s'aggravait à chaque lot : au moment de
 * l'audit, **quatre-vingt-huit routes** n'y figuraient pas — la balance, le
 * grand livre, le lettrage, l'inventaire, les immobilisations, les
 * consignations, et **l'import**, par lequel on crée des comptes utilisateurs.
 *
 * Le sens est inversé : ce qui n'est pas classé est refusé. Et le premier test
 * de ce fichier est celui qui compte : **il échoue tant qu'une route nouvelle
 * n'a pas été rangée**, ce qui rend la dérive impossible plutôt que de la
 * corriger une fois de plus.
 *
 * Une adresse écrite en dur — `superadmin@gmail.com` — donnait par ailleurs
 * tous les droits. Elle n'était pas exploitable, `role:superadmin` s'exécutant
 * avant, mais elle publiait dans le dépôt l'identité du compte le plus puissant
 * de la plateforme.
 */
class HabilitationsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom'               => 'Quincaillerie du Bandama',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Cocody, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00777',
            'ncc'               => '2601234A',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'stock', 'produits',
                                    'tiers', 'comptabilite', 'production', 'tresorerie'],
            // Cette entreprise tient ses livres dans Selflow : depuis le lot
            // 39, cela se demande. L'épreuve qui vérifie que le propriétaire
            // « garde tout » visite la balance, et sans la case elle répondrait
            // 404 (Not Found — introuvable) pour une raison qui n'a rien à voir
            // avec les habilitations.
            'comptabilite_activee' => true,
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
    }

    /**
     * @param  array<int, string>  $habilitations
     */
    private function caissier(array $habilitations = []): Utilisateur
    {
        return Utilisateur::create([
            'nom' => 'Koffi', 'prenom' => 'Amos', 'email' => 'amos' . uniqid() . '@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'caissier',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
            'habilitations' => $habilitations,
        ]);
    }

    /**
     * Une pièce de vente à une étape donnée, pour éprouver les conversions.
     */
    private function uneVente(string $etape): \App\Modules\Admin\Modeles\Vente
    {
        return \App\Modules\Admin\Modeles\Vente::create([
            'point_de_vente_id' => $this->magasin->id,
            'numero_facture'    => 'PIECE-' . uniqid(),
            'date_vente'        => now()->toDateString(),
            'date_validite'     => now()->addDays(30)->toDateString(),
            'mode_paiement'     => 'Crédit',
            'montant_ht'        => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
            'statut'            => 'Brouillon',
            'etape'             => $etape,
        ]);
    }

    // ══════════════ Le test qui empêche la dérive ══════════════

    public function test_toute_route_des_espaces_admin_et_caissier_est_classee(): void
    {
        // **C'est le test qui compte.** Il échoue tant qu'une route nouvelle
        // n'a pas été rangée : la dérive devient impossible, au lieu d'être
        // corrigée une fois de plus dans six mois.
        $nonClassees = [];

        foreach (Route::getRoutes() as $route) {
            $nom = $route->getName();

            if (!$nom || (!str_starts_with($nom, 'admin.') && !str_starts_with($nom, 'caissier.'))) {
                continue;
            }

            if (!Habilitations::estClassee($nom)) {
                $nonClassees[] = $nom;
            }
        }

        $this->assertSame([], $nonClassees,
            "Ces routes n'exigent aucune habilitation et ne sont pas déclarées ouvertes. "
            . "Rangez-les dans App\\Modules\\Authentification\\Regles\\Habilitations : "
            . implode(', ', $nonClassees));
    }

    public function test_chaque_route_ouverte_porte_sa_raison(): void
    {
        // Sans raison écrite, une route ouverte est un oubli déguisé en
        // décision.
        foreach (Habilitations::OUVERTES as $route => $raison) {
            $this->assertNotEmpty(trim($raison), "La route {$route} est ouverte sans raison écrite.");
            $this->assertGreaterThan(20, mb_strlen($raison),
                "La raison donnée pour {$route} n'explique rien.");
        }
    }

    public function test_les_deux_classements_ne_se_recoupent_pas(): void
    {
        // Une route à la fois exigeante et ouverte serait ambiguë, et le
        // premier classement lu l'emporterait sans qu'on l'ait décidé.
        $this->assertSame([],
            array_intersect(array_keys(Habilitations::PAR_ROUTE), array_keys(Habilitations::OUVERTES)));
    }

    // ══════════════ Ce qui passait sans contrôle ══════════════
    //
    // L'espace du caissier compte vingt-six routes. Neuf d'entre elles ne
    // figuraient pas au dictionnaire, et **un caissier n'ayant que
    // `nouvelle_vente` pouvait donc les emprunter** : transformer une commande
    // en facture, enregistrer l'acceptation d'un client, et surtout créer puis
    // valider des bons de livraison — qui **font sortir la marchandise du
    // stock**. Le reste des quatre-vingt-huit vit sous `admin.`, derrière
    // `role:admin`, et n'était donc pas atteignable ; ce qui les rendait
    // dangereuses n'était pas ce qu'elles ouvraient hier, mais qu'elles
    // s'ouvriraient sans bruit le jour où l'espace du caissier s'élargirait.

    public function test_un_caissier_sans_droit_sur_les_factures_ne_convertit_pas_une_commande(): void
    {
        // Il n'a que la caisse : transformer un bon de commande en facture est
        // le geste d'un autre.
        $this->actingAs($this->caissier(['nouvelle_vente']));

        $vente = $this->uneVente('Bon de commande');

        $this->post(route('caissier.ventes.convertir.facture', $vente))->assertForbidden();

        $this->assertSame('Bon de commande', $vente->fresh()->etape);
    }

    public function test_un_caissier_sans_droit_sur_le_stock_ne_cree_pas_de_bon_de_livraison(): void
    {
        // **Le plus grave des neuf** : un bon de livraison fait sortir la
        // marchandise du stock.
        $this->actingAs($this->caissier(['nouvelle_vente']));

        $vente = $this->uneVente('Bon de commande');

        $this->get(route('caissier.ventes.livraison.creer', $vente))->assertForbidden();
        $this->post(route('caissier.ventes.livraison.enregistrer', $vente))->assertForbidden();
    }

    public function test_un_caissier_sans_droit_sur_les_factures_n_enregistre_pas_une_acceptation(): void
    {
        // L'acceptation fige le devis et engage l'entreprise sur ses prix.
        $this->actingAs($this->caissier(['nouvelle_vente']));

        $devis = $this->uneVente('Devis');

        $this->post(route('caissier.ventes.accepter', $devis))->assertForbidden();

        $this->assertNull($devis->fresh()->date_acceptation);
    }

    public function test_un_caissier_sans_droit_sur_les_factures_ne_prolonge_pas_une_offre(): void
    {
        // Prolonger, c'est refaire l'offre.
        $this->actingAs($this->caissier(['nouvelle_vente']));

        $devis = $this->uneVente('Devis');

        $this->post(route('caissier.ventes.prolonger', $devis), [
            'date_validite' => now()->addYear()->toDateString(),
        ])->assertForbidden();
    }

    public function test_un_caissier_sans_droit_sur_la_tresorerie_ne_cree_pas_de_banque(): void
    {
        $this->actingAs($this->caissier(['factures_vente']));

        $this->post(route('caissier.banques.creer'))->assertForbidden();
    }

    // ══════════════ Ce qui doit continuer de passer ══════════════

    public function test_le_caissier_habilite_accede_a_sa_caisse(): void
    {
        // Le verrouillage ne doit pas gagner tout le reste : un caissier
        // habilité fait son travail.
        $this->actingAs($this->caissier(['nouvelle_vente']));

        $this->get(route('caissier.ventes.nouvelle'))->assertOk();
    }

    public function test_le_caissier_habilite_aux_factures_convertit_bien(): void
    {
        $this->actingAs($this->caissier(['nouvelle_vente', 'factures_vente']));

        $vente = $this->uneVente('Bon de commande');

        $this->post(route('caissier.ventes.convertir.facture', $vente))
            ->assertRedirect();
    }

    public function test_le_caissier_habilite_au_stock_voit_ses_articles(): void
    {
        $this->actingAs($this->caissier(['stock_articles']));

        $this->get(route('caissier.stock.index'))->assertOk();
    }

    public function test_un_administrateur_d_entreprise_garde_tout(): void
    {
        // C'est lui qui distribue les habilitations : les lui opposer
        // l'enfermerait dehors chez lui.
        $admin = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Adjoua', 'email' => 'adjoua@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->magasin->id]);

        $this->get(route('admin.comptabilite.balance'))->assertOk();
        $this->get(route('admin.immobilisations.index'))->assertOk();
        $this->get(route('admin.consignations.index'))->assertOk();
    }

    // ══════════════ L'adresse écrite en dur ══════════════

    public function test_l_adresse_du_superadministrateur_n_est_plus_ecrite_dans_le_depot(): void
    {
        // Elle n'etait pas exploitable — `role:superadmin` s'execute avant —
        // mais elle publiait l'identite du compte le plus puissant de la
        // plateforme, ce qui suffit a en faire la cible de toute tentative.
        // On lit le code, non les commentaires : le docbloc du middleware cite
        // la ligne supprimee pour expliquer pourquoi elle l'a ete, et une
        // recherche naive dans le fichier entier retomberait dessus.
        $code = '';

        foreach (token_get_all(file_get_contents(
            app_path('Modules/Authentification/Middleware/VerifierHabilitationRoute.php')
        )) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        $this->assertStringNotContainsString('superadmin@gmail.com', $code,
            'Le privilège ne doit pas tenir à une adresse écrite en dur.');
    }

    public function test_un_superadministrateur_sans_habilitation_n_accede_a_rien(): void
    {
        // **Le privilège est une donnée, non une chaîne dans le dépôt.** Un
        // compte d'administration créé sans droits n'en exerce aucun.
        $nu = Utilisateur::create([
            'nom' => 'Sans', 'prenom' => 'Droits', 'email' => 'nu@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => [],
        ]);

        $this->actingAs($nu);

        $this->get(route('superadmin.entreprises'))->assertForbidden();
    }

    public function test_un_superadministrateur_habilite_accede_a_son_ecran(): void
    {
        $habilite = Utilisateur::create([
            'nom' => 'Avec', 'prenom' => 'Droits', 'email' => 'avec@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => Habilitations::PLATEFORME,
        ]);

        $this->actingAs($habilite);

        $this->get(route('superadmin.entreprises'))->assertOk();
    }

    public function test_une_route_d_administration_inconnue_est_refusee(): void
    {
        // Ce sont les ecrans les plus puissants de l'application : y laisser
        // passer l'inconnu serait le pire endroit.
        $partiel = Utilisateur::create([
            'nom' => 'Partiel', 'prenom' => 'Droit', 'email' => 'partiel@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => ['gestion_fne'],
        ]);

        $this->actingAs($partiel);

        $this->get(route('superadmin.fne.index'))->assertOk();
        $this->get(route('superadmin.entreprises'))->assertForbidden();
    }

    public function test_toute_route_d_administration_est_classee(): void
    {
        $middleware = new \App\Modules\Authentification\Middleware\VerifierHabilitationRoute();
        $reflet = new \ReflectionClass($middleware);
        $connues = $reflet->getConstant('SUPERADMIN');

        $nonClassees = [];

        foreach (Route::getRoutes() as $route) {
            $nom = $route->getName();

            if ($nom && str_starts_with($nom, 'superadmin.') && !array_key_exists($nom, $connues)) {
                $nonClassees[] = $nom;
            }
        }

        $this->assertSame([], $nonClassees,
            'Ces routes d\'administration n\'exigent aucune habilitation : '
            . implode(', ', $nonClassees));
    }
}
