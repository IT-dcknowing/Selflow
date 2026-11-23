<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Services\AdressePortailFne;
use Tests\TestCase;

/**
 * Le portail FNE visé suit l'accès de l'entreprise : test ou production.
 *
 * Le 23/11/2026, tout visait le test (54.247.95.108) alors que DC-KNOWING CGA
 * était validée en production.
 */
class AdressePortailFneTest extends TestCase
{
    private function acces(string $statut): FneCredential
    {
        return new FneCredential(['statut' => $statut]);
    }

    public function test_un_acces_valide_vise_la_production(): void
    {
        config(['selflow.fne_api_url_production' => 'https://fne.exemple.ci/ws', 'selflow.portail_fne.url_production' => '']);

        $this->assertSame('https://fne.exemple.ci', AdressePortailFne::hote($this->acces('validee')));
        $this->assertSame('https://fne.exemple.ci/fr/login', AdressePortailFne::connexion($this->acces('validee')));
    }

    public function test_un_acces_de_test_vise_le_test(): void
    {
        config(['selflow.fne_api_url_production' => 'https://fne.exemple.ci/ws', 'selflow.fne_api_url_sandbox' => 'http://54.247.95.108/ws']);

        $this->assertSame('http://54.247.95.108', AdressePortailFne::hote($this->acces('test')));
        $this->assertSame('http://54.247.95.108', AdressePortailFne::hote(null));
    }

    public function test_sans_adresse_de_production_on_reste_sur_le_test_comme_la_certification(): void
    {
        config(['selflow.fne_api_url_production' => '', 'selflow.portail_fne.url_production' => '', 'selflow.fne_api_url_sandbox' => 'http://54.247.95.108/ws']);

        $this->assertSame('http://54.247.95.108', AdressePortailFne::hote($this->acces('validee')));
    }

    public function test_un_hote_de_portail_distinct_l_emporte(): void
    {
        config(['selflow.fne_api_url_production' => 'https://api.fne.exemple.ci/ws', 'selflow.portail_fne.url_production' => 'https://portail.fne.exemple.ci/fr/login']);

        $this->assertSame('https://portail.fne.exemple.ci', AdressePortailFne::hote($this->acces('validee')));
    }
}
