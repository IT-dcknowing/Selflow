<?php

namespace Tests\Feature;

use App\Modules\Admin\Controleurs\GroupeEntreprisesControleur;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\GroupeEntreprises;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Une même personne passe d'une entreprise à l'autre, une fois le groupe lié
 * et activé par le superadministrateur, et si elle y est autorisée (23/11/2026).
 */
class GroupesEntreprisesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $a;
    private Entreprise $b;
    private Utilisateur $dirigeant;
    private GroupeEntreprises $groupe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Entreprise::create(['nom' => 'DC-KNOWING CGA', 'modules_actifs' => ['principal', 'ventes']]);
        $this->b = Entreprise::create(['nom' => 'DC-KNOWING CONSEIL', 'modules_actifs' => ['principal', 'ventes']]);
        PointDeVente::create(['entreprise_id' => $this->b->id, 'nom' => 'Siège Conseil', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->dirigeant = Utilisateur::create(['nom' => 'Keyman', 'prenom' => 'Constant', 'email' => 'ck@x.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->a->id]);
        $this->groupe = GroupeEntreprises::create(['nom' => 'Groupe DC-Knowing', 'actif' => true]);
        $this->groupe->entreprises()->sync([$this->a->id, $this->b->id]);
        $this->groupe->utilisateurs()->sync([$this->dirigeant->id]);
    }

    public function test_l_administrateur_autorise_change_d_entreprise(): void
    {
        Auth::login($this->dirigeant);

        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], GroupeEntreprises::entreprisesAccessibles($this->dirigeant)->pluck('id')->all());

        $requete = request();
        $requete->setLaravelSession(app('session.store'));
        app(GroupeEntreprisesControleur::class)->changer($requete, $this->b);

        $this->assertSame($this->b->id, $this->dirigeant->fresh()->entreprise_id);
        $this->assertNotNull($this->dirigeant->fresh()->point_de_vente_id, 'Un seul site : il devient le site de travail.');
        $this->assertCount(2, GroupeEntreprises::entreprisesAccessibles($this->dirigeant->fresh()), 'Il peut revenir.');
    }

    public function test_un_groupe_desactive_ne_s_ouvre_pas(): void
    {
        $this->groupe->update(['actif' => false]);
        Auth::login($this->dirigeant);

        $this->assertTrue(GroupeEntreprises::entreprisesAccessibles($this->dirigeant)->isEmpty());
        $this->expectException(HttpException::class);
        app(GroupeEntreprisesControleur::class)->changer(request(), $this->b);
    }

    public function test_un_administrateur_non_autorise_ne_change_pas(): void
    {
        $autre = Utilisateur::create(['nom' => 'X', 'prenom' => 'Y', 'email' => 'xy@x.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->a->id]);
        Auth::login($autre);

        $this->expectException(HttpException::class);
        app(GroupeEntreprisesControleur::class)->changer(request(), $this->b);
    }

    public function test_un_caissier_n_a_pas_le_changement_d_entreprise(): void
    {
        $caissier = Utilisateur::create(['nom' => 'C', 'prenom' => 'A', 'email' => 'ca@x.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'statut' => 'actif', 'entreprise_id' => $this->a->id]);
        $this->groupe->utilisateurs()->attach($caissier->id);

        $this->assertTrue(GroupeEntreprises::entreprisesAccessibles($caissier)->isEmpty());
    }

    public function test_les_pages_d_un_administrateur_membre_d_un_groupe_s_affichent(): void
    {
        // SF-261123-EED9CF : le menu « Changer d'entreprise » construisait ses
        // liens avec des entreprises chargées sans leur uuid, et toute page
        // de l'administrateur tombait en panne.
        $pdv = PointDeVente::create(['entreprise_id' => $this->a->id, 'nom' => 'Siège CGA', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->dirigeant->forceFill(['point_de_vente_id' => $pdv->id])->save();

        $this->actingAs($this->dirigeant)->get('/admin')->assertOk()
            ->assertSee(route('admin.entreprise.changer', $this->b), false);

        $partielle = Entreprise::query()->whereKey($this->b->id)->first(['id', 'nom']);
        $this->assertSame($this->b->uuid, $partielle->getRouteKey());
        $this->assertFalse($partielle->isDirty(), 'Aller chercher l\'uuid ne modifie pas le modèle.');
    }
}
