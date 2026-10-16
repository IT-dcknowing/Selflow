<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Admin\Services\AlerteSuperadminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Les alertes de la plateforme vers DC-Knowing (16/10/2026). */
class AlerteSuperadminTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_configuration_les_courriels_du_cabinet_sont_retenus(): void
    {
        $this->assertContains('it.dcknowing@gmail.com', AlerteSuperadminService::courriels());
    }

    public function test_les_destinataires_configures_priment(): void
    {
        ParametrePlateforme::ecrire(ParametrePlateforme::ALERTES_EMAILS, "a@dc.com\nb@dc.com\npas-un-email");
        $this->assertSame(['a@dc.com', 'b@dc.com'], AlerteSuperadminService::courriels());
    }

    public function test_alerter_envoie_un_courriel_aux_destinataires(): void
    {
        ParametrePlateforme::ecrire(ParametrePlateforme::ALERTES_EMAILS, 'alerte@dc.com');

        $recu = [];
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($corps, $callback) use (&$recu) {
            $message = new class($recu) {
                public function __construct(public &$recu) {}
                public function to($dest) { $this->recu['to'] = $dest; return $this; }
                public function subject($s) { $this->recu['subject'] = $s; return $this; }
            };
            $callback($message);
        });

        AlerteSuperadminService::alerter(AlerteSuperadminService::PAIEMENT, 'Paiement', 'corps');

        $this->assertSame(['alerte@dc.com'], $recu['to']);
        $this->assertStringContainsString('Paiement', $recu['subject']);
    }

    public function test_whatsapp_part_au_relais_quand_il_est_configure(): void
    {
        Mail::fake();
        Http::fake(['relais.test/*' => Http::response(['ok' => true])]);
        ParametrePlateforme::ecrire(ParametrePlateforme::ALERTES_WHATSAPP, '+225 07 00 00 00 00');
        ParametrePlateforme::ecrire(ParametrePlateforme::ALERTES_WHATSAPP_WEBHOOK, 'https://relais.test/envoyer');

        AlerteSuperadminService::alerter(AlerteSuperadminService::ATTAQUE, 'Attaque', 'corps');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'relais.test')
            && $r['message'] !== null
            && in_array('+225 07 00 00 00 00', $r['numeros'], true));
    }

    public function test_une_alerte_qui_echoue_ne_leve_jamais(): void
    {
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('smtp down'));
        $this->expectNotToPerformAssertions();
        AlerteSuperadminService::alerter(AlerteSuperadminService::PANNE, 'x', 'y');
    }
}
