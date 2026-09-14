<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La surveillance du portail : ce qui a changé depuis le relevé précédent.
 *
 * ## Pourquoi
 *
 * Deux comparaisons existaient déjà — le portail contre le paramétrage de
 * Selflow, et une pièce refusée contre le portail. Aucune ne répondait à
 * « quelqu'un a-t-il touché au portail depuis hier ? ». Un timbre de quittance
 * désactivé un mardi soir n'apparaissait donc nulle part, jusqu'au jour où une
 * facture était refusée.
 *
 * Ce qui se vérifie ici :
 *
 * 1. **Un premier relevé n'annonce rien.** Sinon chaque nouvelle entreprise
 *    afficherait quatorze changements le jour de son arrivée, et le signal
 *    deviendrait du bruit.
 * 2. **La comparaison porte sur le contenu, jamais sur l'empreinte du
 *    fichier.** Le tableur du portail embarque un horodatage de génération :
 *    deux exports identiques peuvent différer octet pour octet.
 * 3. **« rien » et « vide » ne se signalent pas l'un l'autre.**
 * 4. **Les trois champs fiscaux sont nommés à part**, et rien n'est recopié.
 * 5. **Un point renommé reste le même point** — c'est le renommage qu'on veut
 *    voir, pas une disparition suivie d'une apparition.
 */
class ChangementsPortailFneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-01111', 'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'comptabilite'],
        ]);
    }

    public function test_un_premier_releve_nannonce_aucun_changement(): void
    {
        $fiche = $this->unReleve('2026-08-21', ['timbre_quittance' => true, 'commune' => 'COCODY']);

        $this->assertSame([], $fiche->ecartsAvecPrecedente());

        $this->artisan('portail-fne:changements')
            ->expectsOutputToContain('Aucun changement')
            ->assertSuccessful();
    }

    public function test_un_champ_modifie_au_portail_est_signale_avec_avant_et_apres(): void
    {
        $this->unReleve('2026-08-21', ['commune' => 'COCODY']);
        $recente = $this->unReleve('2026-08-22', ['commune' => 'PLATEAU']);

        $ecarts = $recente->ecartsAvecPrecedente();

        $this->assertCount(1, $ecarts);
        $this->assertSame('commune', $ecarts[0]['champ']);
        $this->assertSame('COCODY', $ecarts[0]['avant']);
        $this->assertSame('PLATEAU', $ecarts[0]['apres']);

        // Les deux valeurs tiennent sur une seule ligne, et chaque attente
        // consomme une ligne : les vérifier séparément ferait échouer la
        // seconde sur une sortie pourtant correcte.
        $this->artisan('portail-fne:changements')
            ->expectsOutputToContain('COCODY » → « PLATEAU')
            ->assertSuccessful();
    }

    public function test_un_releve_identique_nannonce_rien_meme_si_le_fichier_differe(): void
    {
        // Chaque relevé porte une empreinte différente — c'est le cas réel : le
        // tableur du portail embarque son horodatage de génération. Seul le
        // contenu doit compter.
        $this->unReleve('2026-08-21', ['commune' => 'COCODY', 'timbre_quittance' => true]);
        $recente = $this->unReleve('2026-08-22', ['commune' => 'COCODY', 'timbre_quittance' => true]);

        $this->assertSame([], $recente->ecartsAvecPrecedente());
    }

    public function test_rien_et_vide_ne_se_signalent_pas_lun_lautre(): void
    {
        $this->unReleve('2026-08-21', ['ref_bancaire' => null]);
        $recente = $this->unReleve('2026-08-22', ['ref_bancaire' => '']);

        $this->assertSame([], $recente->ecartsAvecPrecedente());
    }

    public function test_un_champ_fiscal_qui_bouge_est_nomme_a_part(): void
    {
        $this->unReleve('2026-08-21', ['timbre_quittance' => true]);
        $this->unReleve('2026-08-22', ['timbre_quittance' => false]);

        $this->artisan('portail-fne:changements')
            ->expectsOutputToContain('Timbre de quittance')
            ->expectsOutputToContain('comportement fiscal')
            ->assertSuccessful();
    }

    public function test_rien_nest_recopie_dans_lentreprise(): void
    {
        $avant = $this->entreprise->fresh()->toArray();

        $this->unReleve('2026-08-21', ['timbre_quittance' => true, 'commune' => 'COCODY']);
        $this->unReleve('2026-08-22', ['timbre_quittance' => false, 'commune' => 'PLATEAU']);

        $this->artisan('portail-fne:changements')->assertSuccessful();

        // Le constat ne décide de rien : c'est la règle d'or du projet.
        $this->assertSame($avant, $this->entreprise->fresh()->toArray());
    }

    public function test_un_point_apparu_et_un_point_disparu_sont_signales(): void
    {
        $this->unReleve('2026-08-21', [], [
            ['nom' => 'FACTURATION SIEGE', 'etablissement_id' => 'etab-1'],
            ['nom' => 'CAISSE 2',          'etablissement_id' => 'etab-2'],
        ]);
        $this->unReleve('2026-08-22', [], [
            ['nom' => 'FACTURATION SIEGE', 'etablissement_id' => 'etab-1'],
            ['nom' => 'CAISSE 3',          'etablissement_id' => 'etab-3'],
        ]);

        $changements = PortailFnePointFacturation::changementsDepuisLePrecedent('1864699A');

        $this->assertCount(1, $changements['apparus']);
        $this->assertSame('CAISSE 3', $changements['apparus'][0]->nom);

        $this->assertCount(1, $changements['disparus']);
        $this->assertSame('CAISSE 2', $changements['disparus'][0]->nom);

        $this->assertSame([], $changements['modifies']);
    }

    public function test_un_point_renomme_reste_le_meme_point(): void
    {
        // Le renommage est précisément la cause du rejet le plus fréquent :
        // « Le nom du point de vente doit être déclaré à l'identique. »
        // Le voir comme une disparition suivie d'une apparition le noierait.
        $this->unReleve('2026-08-21', [], [['nom' => 'FACTURATION SIEGE', 'etablissement_id' => 'etab-1']]);
        $this->unReleve('2026-08-22', [], [['nom' => 'FACTURATION SIÈGE', 'etablissement_id' => 'etab-1']]);

        $changements = PortailFnePointFacturation::changementsDepuisLePrecedent('1864699A');

        $this->assertSame([], $changements['apparus']);
        $this->assertSame([], $changements['disparus']);
        $this->assertCount(1, $changements['modifies']);
        $this->assertStringContainsString('FACTURATION SIEGE', $changements['modifies'][0]['changements'][0]);
        $this->assertStringContainsString('FACTURATION SIÈGE', $changements['modifies'][0]['changements'][0]);
    }

    public function test_un_point_desactive_est_signale(): void
    {
        $this->unReleve('2026-08-21', [], [['nom' => 'CAISSE 1', 'etablissement_id' => 'etab-1', 'statut' => '1']]);
        $this->unReleve('2026-08-22', [], [['nom' => 'CAISSE 1', 'etablissement_id' => 'etab-1', 'statut' => '0']]);

        $changements = PortailFnePointFacturation::changementsDepuisLePrecedent('1864699A');

        $this->assertCount(1, $changements['modifies']);
        $this->assertStringContainsString('statut', $changements['modifies'][0]['changements'][0]);
    }

    public function test_deux_releves_du_meme_jour_sont_departages(): void
    {
        // Un passage de nuit et un passage déclenché par un rejet du matin :
        // ils portent la même date, et l'ordre doit rester celui de l'arrivée.
        $matin = $this->unReleve('2026-08-22', ['commune' => 'COCODY']);
        $apres = $this->unReleve('2026-08-22', ['commune' => 'PLATEAU']);

        $this->assertSame($matin->id, $apres->precedente()?->id);
        $this->assertCount(1, $apres->ecartsAvecPrecedente());
    }

    public function test_le_silencieux_ne_dit_rien_quand_il_ny_a_rien(): void
    {
        // Un journal qui répète chaque heure « aucun changement » cesse d'être
        // lu, et c'est le jour où il dit quelque chose qu'on ne le lira pas.
        $this->unReleve('2026-08-21', ['commune' => 'COCODY']);
        $this->unReleve('2026-08-22', ['commune' => 'COCODY']);

        $this->artisan('portail-fne:changements --silencieux')
            ->doesntExpectOutput('Aucun changement au portail depuis le relevé précédent.')
            ->assertSuccessful();
    }

    /* ------------------------------- Fixtures ------------------------------ */

    /**
     * @param  array<string, mixed>              $champs
     * @param  array<int, array<string, mixed>>  $points
     */
    private function unReleve(string $date, array $champs = [], array $points = []): PortailFneFiche
    {
        $import = PortailFneImport::create([
            'entreprise_id'     => $this->entreprise->id,
            'login'             => '1864699A',
            'date_scraping'     => $date,
            'type'              => PortailFneImport::TYPE_FICHE,
            'fichier_nom'       => '1864699A_' . str_replace('-', '', $date) . '.json',
            // Empreinte toujours différente : le tableur du portail embarque un
            // horodatage, deux exports identiques ne sont pas identiques.
            'fichier_empreinte' => hash('sha256', uniqid('', true)),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
        ]);

        $fiche = PortailFneFiche::create(array_merge([
            'import_id'     => $import->id,
            'entreprise_id' => $this->entreprise->id,
            'login'         => '1864699A',
            'date_scraping' => $date,
        ], $champs));

        foreach ($points as $point) {
            PortailFnePointFacturation::create(array_merge([
                'import_id'     => $import->id,
                'entreprise_id' => $this->entreprise->id,
                'login'         => '1864699A',
                'date_scraping' => $date,
                'outil'         => 'Application FNE',
                'statut'        => '1',
            ], $point));
        }

        return $fiche;
    }
}
