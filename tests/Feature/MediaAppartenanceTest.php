<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\FichierPublic;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Les images servies par l'application appartiennent à quelqu'un.
 *
 * La route `admin.media` a été posée le 25/09/2026 pour servir les fichiers là
 * où `public/storage` n'est pas utilisable — un hébergement mutualisé répondant
 * **403 (Forbidden — accès interdit)** sur le dossier.
 *
 * Elle servait **tout fichier de ses quatre dossiers à tout utilisateur
 * connecté**, sans jamais regarder à qui il appartient. Contrairement à
 * `admin.produits.photo.voir`, qui vérifie l'entreprise.
 *
 * Le nom du fichier est tiré au hasard, ce qui le rend difficile à deviner.
 * Mais **un nom difficile à deviner n'est pas un contrôle d'accès** : il suffit
 * qu'une adresse ait été partagée, recopiée dans un journal de serveur, ou lue
 * dans l'historique d'un navigateur partagé.
 *
 * Signalé au propriétaire le 02/10/2026, corrigé sur sa demande le même jour.
 */
class MediaAppartenanceTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $chezNous;
    private Entreprise $chezLAutre;
    private Utilisateur $notre;
    private Utilisateur $leur;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->chezNous   = $this->uneEntreprise('DC-KNOWING CGA', '1864699A');
        $this->chezLAutre = $this->uneEntreprise('LA CONCURRENCE', '1999999Z');

        $this->notre = $this->unAdmin($this->chezNous, 'nous');
        $this->leur  = $this->unAdmin($this->chezLAutre, 'eux');
    }

    private function uneEntreprise(string $nom, string $ncc): Entreprise
    {
        return Entreprise::create([
            'nom' => $nom, 'regime_imposition' => 'TEE',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => $ncc, 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'produits', 'points_de_vente'],
        ]);
    }

    private function unAdmin(Entreprise $entreprise, string $marque): Utilisateur
    {
        $site = PointDeVente::create([
            'entreprise_id' => $entreprise->id,
            'nom' => 'PDV-' . $marque, 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        return Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis',
            'email' => "lewis-media-{$marque}@exemple.ci",
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $entreprise->id,
            'point_de_vente_id' => $site->id,
        ]);
    }

    /** Une photo d'article réellement déposée, et la ligne qui la réclame. */
    private function unePhoto(Entreprise $entreprise, string $nomFichier): string
    {
        $chemin = 'produits/' . $nomFichier;
        Storage::disk('public')->put($chemin, 'des octets');

        Produit::create([
            'entreprise_id' => $entreprise->id,
            'nom' => 'Ciment 50 kg', 'reference' => 'REF-' . uniqid(),
            'prix_vente' => 7000, 'prix_achat' => 5500,
            'type' => 'marchandise', 'photo' => $chemin,
        ]);

        return $chemin;
    }

    private function demander(Utilisateur $utilisateur, string $chemin): \Illuminate\Testing\TestResponse
    {
        [$dossier, $fichier] = FichierPublic::decouper($chemin);

        return $this->actingAs($utilisateur)
            ->get(route('admin.media', ['dossier' => $dossier, 'fichier' => $fichier]));
    }

    // ══════════════ Ce qui était ouvert ══════════════

    public function test_la_photo_d_une_autre_entreprise_ne_se_sert_pas(): void
    {
        $leurPhoto = $this->unePhoto($this->chezLAutre, 'leur-ciment.jpg');

        // C'est le défaut : connecté chez nous, on obtenait leur fichier.
        $this->demander($this->notre, $leurPhoto)->assertNotFound();
    }

    public function test_le_refus_est_un_404_et_non_un_403(): void
    {
        $leurPhoto = $this->unePhoto($this->chezLAutre, 'leur-fer.jpg');

        /*
         * Répondre « interdit » confirmerait que le fichier existe, et
         * rendrait les noms énumérables un par un — exactement l'oracle de
         * volume fermé au lot 8.
         */
        $this->demander($this->notre, $leurPhoto)->assertStatus(404);
    }

    public function test_un_logo_d_une_autre_entreprise_ne_se_sert_pas(): void
    {
        $chemin = 'logos/leur-logo.png';
        Storage::disk('public')->put($chemin, 'des octets');
        $this->chezLAutre->logo_path = $chemin;
        $this->chezLAutre->save();

        $this->demander($this->notre, $chemin)->assertNotFound();
    }

    public function test_l_avatar_d_un_salarie_d_une_autre_entreprise_ne_se_sert_pas(): void
    {
        $chemin = 'avatars/leur-portrait.png';
        Storage::disk('public')->put($chemin, 'des octets');
        $this->leur->avatar_path = $chemin;
        $this->leur->save();

        $this->demander($this->notre, $chemin)->assertNotFound();
    }

    public function test_un_fichier_que_plus_aucune_ligne_ne_reclame_ne_se_sert_pas(): void
    {
        // Le fichier traîne sur le disque — dépôt abandonné, ligne supprimée.
        // Rien ne le rattache à une entreprise : il n'est à personne.
        Storage::disk('public')->put('produits/orphelin.jpg', 'des octets');

        $this->demander($this->notre, 'produits/orphelin.jpg')->assertNotFound();
    }

    // ══════════════ Ce qui doit continuer de marcher ══════════════

    public function test_notre_propre_photo_d_article_se_sert(): void
    {
        $laNotre = $this->unePhoto($this->chezNous, 'notre-ciment.jpg');

        $this->demander($this->notre, $laNotre)->assertOk();
    }

    public function test_notre_propre_logo_se_sert(): void
    {
        $chemin = 'logos/notre-logo.png';
        Storage::disk('public')->put($chemin, 'des octets');
        $this->chezNous->logo_path = $chemin;
        $this->chezNous->save();

        $this->demander($this->notre, $chemin)->assertOk();
    }

    public function test_le_logo_fne_se_sert_comme_l_autre(): void
    {
        // Le second logo vit dans une colonne à part : l'oublier aurait fait
        // disparaître le visuel de certification des documents imprimés.
        $chemin = 'logos/notre-logo-fne.png';
        Storage::disk('public')->put($chemin, 'des octets');
        $this->chezNous->logo_fne_path = $chemin;
        $this->chezNous->save();

        $this->demander($this->notre, $chemin)->assertOk();
    }

    public function test_l_avatar_d_un_collegue_se_sert(): void
    {
        // La limite est l'entreprise, pas la personne : les portraits des
        // collègues s'affichent sur l'écran du personnel.
        $collegue = $this->unAdmin($this->chezNous, 'collegue');
        $chemin = 'avatars/collegue.png';
        Storage::disk('public')->put($chemin, 'des octets');
        $collegue->avatar_path = $chemin;
        $collegue->save();

        $this->demander($this->notre, $chemin)->assertOk();
    }

    // ══════════════ La vitrine, qui n'est à personne ══════════════

    public function test_les_images_de_la_vitrine_se_servent_sans_compte(): void
    {
        $chemin = 'vitrine/banniere.jpg';
        Storage::disk('public')->put($chemin, 'des octets');

        /*
         * Elles passaient par `admin.media`, derrière `auth` et `role:admin`.
         * Sur l'hébergement mutualisé — le seul où cette route sert — la page
         * de présentation s'affichait **sans aucune de ses images** pour un
         * visiteur anonyme. C'est-à-dire pour le public auquel elle s'adresse.
         */
        $this->get(route('vitrine.media', ['fichier' => 'banniere.jpg']))->assertOk();
    }

    public function test_la_porte_publique_de_la_vitrine_ne_sert_que_la_vitrine(): void
    {
        $leurPhoto = $this->unePhoto($this->chezLAutre, 'leur-sable.jpg');

        /*
         * Le dossier est imposé par le contrôleur, et non lu dans l'adresse.
         * Une route publique qui accepterait un nom de dossier laisserait
         * demander `logos/…` ou `avatars/…` sans même être connecté.
         */
        $this->get('/presentation/media/../produits/leur-sable.jpg')->assertNotFound();

        $this->assertStringContainsString('produits/', $leurPhoto);
    }

    public function test_l_adresse_d_une_image_de_vitrine_mene_a_la_porte_publique(): void
    {
        Produit::oublierLeLienDeStockage();

        // Sans le lien de stockage, `url()` doit aiguiller la vitrine vers sa
        // porte publique, et non vers celle de l'espace d'administration.
        $adresse = (string) FichierPublic::url('vitrine/banniere.jpg');

        if (FichierPublic::lienPose()) {
            $this->assertStringContainsString('/storage/vitrine/banniere.jpg', $adresse);
            return;
        }

        $this->assertStringContainsString('presentation/media', $adresse);
        $this->assertStringNotContainsString('admin/media', $adresse);
    }

    // ══════════════ Le superadministrateur ══════════════

    public function test_le_superadministrateur_voit_les_logos_de_tous(): void
    {
        $chemin = 'logos/leur-logo-2.png';
        Storage::disk('public')->put($chemin, 'des octets');
        $this->chezLAutre->logo_path = $chemin;
        $this->chezLAutre->save();

        // Les écrans de supervision montrent les entreprises, logo compris.
        $super = Utilisateur::create([
            'nom' => 'Agnimel', 'prenom' => 'Abraham',
            'email' => 'super-media@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
        ]);

        $this->assertTrue(FichierPublic::lisiblePar($chemin, $super));
    }

    public function test_sans_personne_connectee_rien_de_prive_ne_se_lit(): void
    {
        $laNotre = $this->unePhoto($this->chezNous, 'anonyme.jpg');

        $this->assertFalse(FichierPublic::lisiblePar($laNotre, null));
        $this->assertTrue(FichierPublic::lisiblePar('vitrine/banniere.jpg', null));
    }
}
