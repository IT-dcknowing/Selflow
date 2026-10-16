<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Supprimer une entreprise — erreur 500 en production le 15/10/2026 : une
 * vente pointait vers une autre (`ventes.parent_id`, l'avoir vers sa facture),
 * la base refusait, et l'entreprise restait sans ses utilisateurs.
 */
class SuppressionEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    private function entrepriseAvecUneFactureEtSonAvoir(string $nom, string $email): Entreprise
    {
        $entreprise = Entreprise::create([
            'nom' => $nom, 'regime_imposition' => 'RNI', 'adresse' => 'Cocody',
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        foreach ([['VTE', 'Ventes', 'Vente'], ['CAI', 'Caisse', 'Trésorerie'], ['OD', 'Opérations diverses', 'OD']] as [$code, $intitule, $type]) {
            CodeJournal::create(['entreprise_id' => $entreprise->id, 'code' => $code, 'intitule' => $intitule, 'type' => $type]);
        }
        $admin = Utilisateur::create([
            'nom' => 'Admin', 'prenom' => $nom, 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin',
            'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        $client = Client::create(['entreprise_id' => $entreprise->id, 'nom' => 'Konan Yao']);
        $famille = Categorie::create([
            'entreprise_id' => $entreprise->id, 'nom' => 'Vivres', 'prefixe' => 'VIV',
            'compte_vente' => '701000', 'compte_achat' => '601000', 'compte_stock' => '311000', 'compte_variation' => '603100',
        ]);
        $riz = Produit::create([
            'entreprise_id' => $entreprise->id, 'reference' => 'VIV-001', 'nom' => 'Riz', 'type' => 'marchandise',
            'unite' => 'sac', 'prix_achat' => 12000, 'prix_vente' => 20000, 'taux_tva' => 18, 'categorie_id' => $famille->id,
        ]);
        StockService::entree($riz, $site->id, 10, MouvementStock::RECEPTION, ['cout_unitaire' => 12000]);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $site->id]);
        $this->post(route('admin.ventes.enregistrer'), [
            'client_id' => $client->id, 'mode_paiement' => 'Espèces', 'etape' => 'Facture', 'montant_paye' => 50000,
            'articles' => [['produit_id' => $riz->id, 'quantite' => 2, 'prix_unitaire' => 20000, 'unite' => 'sac']],
        ])->assertSessionHasNoErrors();
        $facture = Vente::where('point_de_vente_id', $site->id)->latest('id')->firstOrFail();
        $this->post(route('admin.ventes.avoir', $facture), ['raison' => 'Erreur'])->assertSessionHasNoErrors();
        $this->assertTrue(Vente::where('parent_id', $facture->id)->exists(), 'L\'avoir pointe vers sa facture.');

        auth()->logout();

        return $entreprise;
    }

    public function test_le_superadmin_supprime_une_entreprise_qui_a_des_avoirs(): void
    {
        $aSupprimer = $this->entrepriseAvecUneFactureEtSonAvoir('Eve disagn', 'eve@exemple.ci');
        $voisine = $this->entrepriseAvecUneFactureEtSonAvoir('Voisine', 'voisine@exemple.ci');
        $ventesVoisine = Vente::count() - Vente::whereIn('point_de_vente_id', $aSupprimer->pointsDeVente()->pluck('id'))->count();

        $superadmin = Utilisateur::create([
            'nom' => 'Super', 'prenom' => 'Admin', 'email' => 'super@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);

        // Supprimer place en corbeille (instantané) ; l'effacement réel suit en
        // arrière-plan — on le joue ici pour vérifier la cascade (16/10/2026).
        $this->actingAs($superadmin)->delete(route('superadmin.entreprises.supprimer', $aSupprimer))
            ->assertRedirect(route('superadmin.entreprises'))
            ->assertSessionHas('succes');

        $this->assertTrue($aSupprimer->fresh()->estEnCorbeille());

        (new \App\Jobs\EffacerEntreprise($aSupprimer->id))->handle();

        $this->assertDatabaseMissing('entreprises', ['id' => $aSupprimer->id]);
        $this->assertDatabaseMissing('utilisateurs', ['email' => 'eve@exemple.ci']);
        $this->assertSame(0, PointDeVente::where('entreprise_id', $aSupprimer->id)->count());
        $this->assertSame(0, DB::table('ecritures_comptables')->where('entreprise_id', $aSupprimer->id)->count());

        // La voisine n'a rien perdu.
        $this->assertDatabaseHas('entreprises', ['id' => $voisine->id]);
        $this->assertDatabaseHas('utilisateurs', ['email' => 'voisine@exemple.ci']);
        $this->assertSame($ventesVoisine, Vente::count());

        // Aucune ligne restante ne pointe vers ce qui a disparu.
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_un_echec_n_efface_rien(): void
    {
        $entreprise = $this->entrepriseAvecUneFactureEtSonAvoir('Eve disagn', 'eve@exemple.ci');
        $avant = Utilisateur::count();

        DB::statement('CREATE TRIGGER refuser BEFORE DELETE ON entreprises BEGIN SELECT RAISE(ABORT, "refus"); END');

        $superadmin = Utilisateur::create([
            'nom' => 'Super', 'prenom' => 'Admin', 'email' => 'super@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
        // La mise en corbeille réussit toujours (c'est une mise à jour).
        $this->actingAs($superadmin)->delete(route('superadmin.entreprises.supprimer', $entreprise))
            ->assertSessionHas('succes');

        // L'effacement réel, lui, est atomique : la base refuse la suppression,
        // la transaction est annulée, et rien n'est effacé. Le job remonte
        // l'erreur (la file le rejouera) sans laisser une entreprise à moitié
        // effacée.
        try {
            (new \App\Jobs\EffacerEntreprise($entreprise->id))->handle();
            $this->fail('La suppression refusée par la base aurait dû lever une exception.');
        } catch (\Throwable $e) {
            // attendu
        }

        $this->assertSame($avant + 1, Utilisateur::count(), 'Les utilisateurs sont toujours là.');
        $this->assertDatabaseHas('entreprises', ['id' => $entreprise->id]);
    }
}
