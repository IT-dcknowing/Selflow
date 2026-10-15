<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Referentiel\Article;
use App\Modules\Admin\Modeles\Referentiel\Categorie;
use App\Modules\Admin\Modeles\Referentiel\Compte;
use App\Modules\Admin\Modeles\Referentiel\Famille;
use App\Modules\Admin\Modeles\Referentiel\Profil;
use App\Modules\Admin\Modeles\Referentiel\TypeArticle;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Référentiel de préparamétrage.
 *
 * Le classeur est la source, le JSON versionné en est la traduction. Ces tests
 * verrouillent la traduction : les comptes sur six chiffres, l'héritage du type
 * vers la famille puis vers l'article, et le fait que recharger ne duplique pas.
 */
class ReferentielTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);
    }

    public function test_le_classeur_est_charge_en_entier(): void
    {
        $this->assertSame(12,  Categorie::count());
        $this->assertSame(72,  Profil::count()); // 71 du classeur + la poissonnerie (15/10/2026)
        $this->assertSame(203, Famille::count());
        $this->assertSame(638, Article::count());
        $this->assertSame(10,  TypeArticle::count());
        // 1 256 comptes OHADA, dont les 41 communs qui les recouvrent.
        $this->assertSame(1256, Compte::count());
        $this->assertSame(41,   Compte::where('commun', true)->count());
    }

    public function test_les_comptes_sont_tous_sur_six_chiffres(): void
    {
        // Le classeur donne des racines SYSCOHADA de longueur variable — `701`,
        // `7011`, `31`, `60311`. L'application les écrit sur six chiffres,
        // comme le reste de son plan comptable.
        foreach ([TypeArticle::all(), Famille::all(), Article::all()] as $lot) {
            foreach ($lot as $ligne) {
                foreach (['compte_vente', 'compte_achat', 'compte_stock', 'compte_variation'] as $champ) {
                    $valeur = $ligne->$champ ?? null;
                    if ($valeur !== null) {
                        $this->assertMatchesRegularExpression('/^\d{6}$/', $valeur,
                            "{$champ} de « {$ligne->getTable()} » : {$valeur}");
                    }
                }
            }
        }

        $this->assertSame(0, Compte::whereRaw('LENGTH(numero) <> 6')->count());
    }

    public function test_la_marchandise_porte_les_comptes_attendus(): void
    {
        // Le type d'article est le pivot : c'est lui qui décide des comptes,
        // et la famille ne fait que subdiviser la racine qu'il impose.
        $marchandise = TypeArticle::where('code', 'MARCHANDISE')->firstOrFail();

        $this->assertSame('701000', $marchandise->compte_vente);
        $this->assertSame('601000', $marchandise->compte_achat);
        $this->assertSame('310000', $marchandise->compte_stock);
        $this->assertSame('603100', $marchandise->compte_variation);
        $this->assertTrue($marchandise->estStockable());
    }

    public function test_un_service_n_est_pas_stockable(): void
    {
        // C'est cette propriété qui évitera de créer une fiche de stock pour une
        // prestation : un cabinet comptable ne doit jamais lire « rupture » sur
        // une mission d'assistance.
        $service = TypeArticle::where('code', 'SERVICE')->firstOrFail();

        $this->assertFalse($service->estStockable());
        $this->assertNull($service->compte_stock);
        $this->assertSame('706000', $service->compte_vente);
    }

    public function test_les_ventes_et_achats_ne_subdivisent_plus_la_racine(): void
    {
        // L'acte uniforme reserve la quatrieme position de 701, 601 et 706 a la
        // ventilation geographique — « Dans la Region », « Hors Region ». Le
        // classeur y logeait des familles produit, ce qui aurait rendu la
        // liasse fiscale fausse. Le detail par famille reste dans Selflow et
        // dans l'analytique ; le grand livre porte la nature.
        $vivres = Famille::whereHas('profil', fn ($q) => $q->where('code', 'boutique_quartier'))
            ->where('code', 'VIV')
            ->firstOrFail();

        $this->assertSame('MARCHANDISE', $vivres->typeArticle->code);
        $this->assertSame('701000', $vivres->compte_vente);
        $this->assertSame('601000', $vivres->compte_achat);
        $this->assertSame('Ventes de marchandises', Compte::nommer($vivres->compte_vente));
    }

    public function test_les_stocks_gardent_leur_ventilation_par_famille(): void
    {
        // Sur les stocks, l'acte uniforme prescrit justement une ventilation par
        // famille : 311 « Marchandises A », 312 « Marchandises B ». Le classeur
        // est ici dans son droit, et on n'y touche pas.
        $boutique = fn (string $code) => Famille::whereHas('profil', fn ($q) => $q->where('code', 'boutique_quartier'))
            ->where('code', $code)->firstOrFail();

        $this->assertSame('311000', $boutique('VIV')->compte_stock);
        $this->assertSame('312000', $boutique('BOI')->compte_stock);
        $this->assertSame('603110', $boutique('VIV')->compte_variation);
        $this->assertSame('603120', $boutique('BOI')->compte_variation);
    }

    public function test_les_produits_accessoires_suivent_leur_nature(): void
    {
        // Sur 707, l'acte uniforme prescrit des natures, pas de la geographie —
        // et le classeur les avait interverties : la livraison facturee etait
        // rangee en « Commissions et courtages », les commissions en « Ports ».
        $parNom = fn (string $nom) => Famille::where('nom', $nom)->firstOrFail();

        $this->assertSame('707100', $parNom('Livraison facturée')->compte_vente);
        $this->assertStringContainsString('Ports', Compte::nommer('707100'));

        $this->assertSame('707200', $parNom('Commissions dépôt-vente')->compte_vente);
        $this->assertStringContainsString('Commissions', Compte::nommer('707200'));
    }

    public function test_l_article_herite_des_comptes_de_sa_famille(): void
    {
        $riz = Article::where('code', 'BOUQ-VIV-001')->firstOrFail();

        $this->assertSame('Riz sac 25 kg', $riz->designation);
        $this->assertSame('sac', $riz->unite);
        $this->assertSame($riz->famille->compte_vente, $riz->compte_vente);
        $this->assertSame($riz->famille->compte_achat, $riz->compte_achat);
    }

    public function test_les_profils_ouvrent_les_modules_annonces(): void
    {
        $boulangerie = Profil::where('code', 'boulangerie')->firstOrFail();
        $this->assertSame(['stock', 'production'], $boulangerie->modulesOuverts());

        $maconnerie = Profil::where('code', 'maconnerie')->firstOrFail();
        $this->assertSame(['stock', 'chantiers'], $maconnerie->modulesOuverts());

        // Dix profils n'ouvrent aucun module : cabinet comptable, taxi, mobile
        // money. Rien à stocker, rien à produire.
        $cabinet = Profil::where('code', 'cabinet_comptable')->first();
        if ($cabinet) {
            $this->assertSame([], $cabinet->modulesOuverts());
        }
    }

    public function test_les_cinq_combinaisons_de_modules_couvrent_tous_les_profils(): void
    {
        // Cinq combinaisons sur seize possibles : le paramétrage à écrire est
        // bien plus petit que le nombre de profils ne le laisse craindre.
        $combinaisons = Profil::all()
            ->map(fn ($p) => implode('+', $p->modulesOuverts()) ?: 'aucun')
            ->unique()
            ->values();

        $this->assertCount(5, $combinaisons);
    }

    public function test_recharger_le_referentiel_ne_duplique_rien(): void
    {
        // Le chargement doit pouvoir tourner à chaque déploiement, et à chaque
        // nouvelle version du classeur.
        $this->seed(ReferentielSeeder::class);
        $this->seed(ReferentielSeeder::class);

        $this->assertSame(72,  Profil::count()); // 71 du classeur + la poissonnerie (15/10/2026)
        $this->assertSame(203, Famille::count());
        $this->assertSame(638, Article::count());
    }

    public function test_tout_compte_du_referentiel_recoit_un_intitule(): void
    {
        // Le vrai risque : imputer sur un numero que rien ne nomme. Le plan
        // comptable de l'entreprise afficherait des numeros nus, et la balance
        // a venir serait illisible.
        $utilises = collect();
        foreach ([Famille::all(), Article::all(), TypeArticle::all()] as $lot) {
            foreach ($lot as $ligne) {
                foreach (['compte_vente', 'compte_achat', 'compte_stock', 'compte_variation'] as $champ) {
                    if (!empty($ligne->$champ)) {
                        $utilises->push($ligne->$champ);
                    }
                }
            }
        }

        $utilises = $utilises->unique();
        // L'aplatissement des ventes et achats a ramene 41 comptes distincts a 31.
        $this->assertGreaterThan(25, $utilises->count());

        foreach ($utilises as $numero) {
            $this->assertNotNull(
                Compte::nommer($numero),
                "Le compte {$numero} est impute par le referentiel sans avoir d'intitule."
            );
        }
    }

    public function test_une_subdivision_absente_du_plan_herite_de_sa_racine(): void
    {
        // `603110` n'est pas a l'acte uniforme : le classeur le cree en
        // subdivisant `6031`. Il doit malgre tout porter un nom lisible.
        $this->assertNull(Compte::where('numero', '603110')->value('intitule'));

        $this->assertSame(
            'Variations de stocks de marchandises',
            Compte::nommer('603110')
        );
    }

    public function test_le_nom_d_un_compte_de_famille_reste_lisible(): void
    {
        // Le grand livre porte la nature ; la famille la qualifie a l'affichage.
        $vivres = Famille::whereHas('profil', fn ($q) => $q->where('code', 'boutique_quartier'))
            ->where('code', 'VIV')
            ->firstOrFail();

        $this->assertSame(
            'Ventes de marchandises — Vivres et alimentation',
            $vivres->intituleCompte('compte_vente')
        );
        $this->assertSame(
            'Achats de marchandises — Vivres et alimentation',
            $vivres->intituleCompte('compte_achat')
        );
    }

    public function test_les_comptes_communs_gardent_leur_intitule_ivoirien(): void
    {
        // Le classeur nomme en tenant compte du contexte local ; son intitule
        // prime sur celui de l'acte uniforme.
        $tva = Compte::where('numero', '443100')->firstOrFail();

        $this->assertTrue($tva->commun);
        $this->assertStringContainsString('18 %', $tva->intitule);
    }

    public function test_chaque_famille_et_chaque_article_ont_un_type(): void
    {
        $this->assertSame(0, Famille::whereNull('type_article_id')->count());
        $this->assertSame(0, Article::whereNull('type_article_id')->count());
        $this->assertSame(0, Article::whereNull('famille_id')->count());
    }
}
