<?php

namespace Tests\Unit;

use App\Modules\Admin\Modeles\FneRejet;
use PHPUnit\Framework\TestCase;

/**
 * Ce qui distingue un refus de la DGI d'une coupure réseau.
 *
 * ## Pourquoi ce test existe
 *
 * `FneRejet::consigner()` ouvrait une demande de relevé du portail dès que
 * `FneService` rendait `success: false` — sans regarder pourquoi. Une coupure
 * de trente secondes envoyait donc le scraper relever quatorze champs, et le
 * rapprochement comparait ce qu'aucune DGI n'avait mis en cause. Une file
 * d'alertes sans objet cesse d'être lue, et c'est ainsi qu'on rate le vrai
 * rejet.
 *
 * La classification se fait sur le **message**, parce que `FneService` est gelé
 * et ne porte aucun code de cause. C'est fragile par nature : ce test fige donc
 * les formulations que le service produit réellement, copiées depuis lui. Si
 * l'une change, ce test tombe — au lieu que la classification retombe en
 * silence sur « la DGI a refusé » et rouvre des relevés pour rien.
 *
 * Aucune base de données : `classer()` ne lit que le tableau qu'on lui passe.
 */
class FneRejetCauseTest extends TestCase
{
    /* ------------------ Ce que la DGI n'a jamais vu : le réseau ------------- */

    public function test_une_exception_de_transport_est_un_souci_reseau(): void
    {
        // FneService.php:276 — le catch qui attrape délai dépassé, DNS,
        // connexion refusée, et les rend en message au lieu de les relancer.
        $this->assertSame(FneRejet::CAUSE_RESEAU, FneRejet::classer([
            'success' => false,
            'message' => "Exception lors de l'appel API FNE : cURL error 28: "
                . 'Operation timed out after 10001 milliseconds',
        ]));
    }

    public function test_le_bordereau_dachat_suit_la_meme_regle(): void
    {
        // FneService.php:414 — la jumelle BAPA du catch précédent.
        $this->assertSame(FneRejet::CAUSE_RESEAU, FneRejet::classer([
            'success' => false,
            'message' => "Exception lors de l'appel API FNE BAPA : Connection refused",
        ]));
    }

    public function test_une_panne_de_la_plateforme_est_un_souci_reseau(): void
    {
        // Un 5xx vient de chez eux : la pièce n'a pas été examinée.
        foreach (['500', '502', '503'] as $code) {
            $this->assertSame(
                FneRejet::CAUSE_RESEAU,
                FneRejet::classer([
                    'success' => false,
                    'message' => "La normalisation DGI a échoué (HTTP {$code}) : Bad Gateway",
                ]),
                "HTTP {$code} devrait être classé réseau"
            );
        }
    }

    public function test_une_reponse_illisible_est_un_souci_reseau(): void
    {
        // FneService.php:257 — elle a répondu 200, mais sans verdict.
        $this->assertSame(FneRejet::CAUSE_RESEAU, FneRejet::classer([
            'success' => false,
            'message' => "La normalisation DGI a échoué : la réponse de l'API est incomplète "
                . '(référence ou token manquant).',
        ]));
    }

    /* ------------------ Ce qui n'est jamais parti : le local --------------- */

    public function test_une_cle_api_absente_est_un_defaut_local(): void
    {
        // FneService.php:50 — rien n'a quitté Selflow.
        $this->assertSame(FneRejet::CAUSE_LOCALE, FneRejet::classer([
            'success' => false,
            'message' => "La normalisation DGI a échoué : aucune clé API FNE active "
                . "n'est configurée pour cette entreprise.",
        ]));
    }

    public function test_un_taux_de_tva_hors_bareme_est_un_defaut_local(): void
    {
        // FneService.php:630 — Selflow refuse d'envoyer, et il a raison :
        // transmise telle quelle, la ligne serait taxée à 18 % par la plateforme.
        $this->assertSame(FneRejet::CAUSE_LOCALE, FneRejet::classer([
            'success' => false,
            'message' => "Normalisation refusée : la DGI n'accepte que les taux de TVA "
                . '18 %, 9 % et 0 %. Les lignes suivantes portent un taux '
                . "qu'aucun code FNE ne représente : Ligne 2 (5 %).",
            'errors'  => ['taux_tva' => ['Ligne 2 (5 %)']],
        ]));
    }

    public function test_un_avoir_sans_facture_dorigine_est_un_defaut_local(): void
    {
        // FneService.php:79
        $this->assertSame(FneRejet::CAUSE_LOCALE, FneRejet::classer([
            'success' => false,
            'message' => "Impossible de normaliser un avoir : la facture d'origine "
                . "n'a pas d'identifiant FNE UUID.",
        ]));
    }

    /* ------------------ Ce que la DGI a examiné et refusé ------------------ */

    public function test_un_refus_de_la_dgi_reste_un_refus_de_la_dgi(): void
    {
        // Le seul cas où un relevé du portail sert à quelque chose.
        $this->assertSame(FneRejet::CAUSE_DGI, FneRejet::classer([
            'success' => false,
            'message' => 'La normalisation DGI a échoué (HTTP 400) : '
                . 'Le nom du point de vente doit être déclaré à l\'identique sur votre espace FNE.',
            'errors'  => ['api_error' => '{"errors":{"pointOfSale":"FACTURATION SIEGE"}}'],
        ]));
    }

    public function test_un_4xx_nest_pas_confondu_avec_un_5xx(): void
    {
        foreach (['400', '401', '403', '422'] as $code) {
            $this->assertSame(
                FneRejet::CAUSE_DGI,
                FneRejet::classer([
                    'success' => false,
                    'message' => "La normalisation DGI a échoué (HTTP {$code}) : champ invalide",
                ]),
                "HTTP {$code} devrait rester un refus de la DGI"
            );
        }
    }

    public function test_un_message_inconnu_est_traite_comme_un_refus_de_la_dgi(): void
    {
        // En cas de doute, on ouvre le relevé. Un relevé de trop fait travailler
        // le scraper pour rien ; un relevé manquant laisse une facture refusée
        // sans explication, et c'est le plus cher des deux.
        $this->assertSame(FneRejet::CAUSE_DGI, FneRejet::classer([
            'success' => false,
            'message' => 'Une formulation que personne n\'a prévue.',
        ]));

        $this->assertSame(FneRejet::CAUSE_DGI, FneRejet::classer(['success' => false]));
    }
}
