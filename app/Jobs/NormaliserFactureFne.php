<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Services\FneService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job : Normalisation de facture via l'API FNE/DGI (Section 18.5).
 *
 * Ce Job exécute l'appel HTTP à l'API FNE en arrière-plan, évitant de bloquer
 * la réponse HTTP de la vente. En cas d'échec temporaire (timeout, API down),
 * Laravel réessaiera automatiquement (max 3 tentatives, délai exponentiel).
 *
 * Après normalisation réussie, la vente est mise à jour en base avec :
 *   - numero_fne
 *   - signature_dgi
 *   - qr_code_data
 *   - normalise = true
 *   - type_facture
 */
class NormaliserFactureFne implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Nombre maximum de tentatives en cas d'échec.
     */
    public int $tries = 3;

    /**
     * Délai entre les tentatives (en secondes).
     */
    public int $backoff = 30;

    /**
     * Timeout maximum pour ce Job (en secondes).
     */
    public int $timeout = 30;

    /**
     * @param bool $emissionRecuDePassage Conservé pour compatibilité avec les
     *        appels existants. La nature du document (facture ou reçu) est
     *        désormais portée par `ventes.type_piece`, choisie à la saisie, et
     *        n'a rien à voir avec le champ DGI `isRne` (facture rattachée à un
     *        reçu déjà émis), qui provient de `ventes.est_rne`.
     */
    public function __construct(
        public readonly Vente $vente,
        public readonly bool $emissionRecuDePassage = false,
    ) {
    }

    /**
     * Exécuter le Job.
     */
    public function handle(): void
    {
        try {
            Log::info("NormaliserFactureFne: Début normalisation async - Vente #{$this->vente->id} / Facture: {$this->vente->numero_facture}");

            $fneResult = FneService::normaliserFacture($this->vente, $this->emissionRecuDePassage);

            if ($fneResult['success']) {
                // Recharger la vente pour éviter les conflicts de version
                $vente = Vente::find($this->vente->id);

                if ($vente) {
                    $updateData = [
                        'normalise' => true,
                        'numero_fne' => $fneResult['numero_recu'],
                        'signature_dgi' => $fneResult['signature'] ?? null,
                        'qr_code_data' => $fneResult['qr_code_data'],
                        'fichier_fne_pdf_url' => $fneResult['pdf_url'] ?? null,
                    ];

                    // La nature du document (facture ou recu) est portee par
                    // `type_piece`, choisie a la saisie. `type_facture` ne
                    // decrit plus que l'etat de certification.
                    if ($vente->type_facture !== 'avoir') {
                        $updateData['type_facture'] = 'normale';
                    }

                    if (!empty($fneResult['invoice_id'])) {
                        $updateData['fne_invoice_id'] = $fneResult['invoice_id'];
                    }

                    // Montants, timbre et alerte de stock renvoyes par la DGI
                    $updateData += FneService::colonnesRetoursFne($fneResult);

                    $vente->update($updateData);

                    if (!empty($fneResult['fne_item_ids']) && is_array($fneResult['fne_item_ids'])) {
                        foreach ($fneResult['fne_item_ids'] as $detailId => $itemId) {
                            VenteDetail::where('id', $detailId)->update(['fne_invoice_item_id' => $itemId]);
                        }
                    }

                    // La pièce est passée : les refus qu'elle avait essuyés sont
                    // derrière elle. Sans cela, l'écran des rejets afficherait
                    // pour toujours un refus corrigé depuis longtemps, et une
                    // file qui ne se vide jamais cesse d'être lue.
                    FneRejet::resoudre($vente);

                    Log::info("NormaliserFactureFne: Normalisation réussie - Vente #{$vente->id} → FNE: {$fneResult['numero_recu']}");
                }
            } else {
                Log::warning("NormaliserFactureFne: Réponse non-success pour Vente #{$this->vente->id}", $fneResult);


                if (
                    FneRejet::classer($fneResult) === FneRejet::CAUSE_RESEAU
                    && $this->uneAutreTentativeViendra()
                ) {

                    throw new \RuntimeException(
                        "Plateforme FNE injoignable pour Vente #{$this->vente->id} : "
                        . ($fneResult['message'] ?? 'sans message')
                    );
                }


                FneRejet::consigner($this->vente, $fneResult);
            }
        } catch (\Exception $e) {
            Log::error("NormaliserFactureFne: Exception pour Vente #{$this->vente->id} - " . $e->getMessage());
            throw $e; // Relancer pour déclencher la logique de retry de Laravel
        }
    }

    /**
     * Une autre tentative viendra-t-elle chercher cette pièce ?
     *
     * `attempts()` ne suffisait pas à en décider. Lancé en synchrone — ce que
     * fait le bouton « Normaliser » de l'écran, comme la correction déclenchée
     * à la main —, `SyncJob::attempts()` rend **toujours 1** : la condition
     * « il me reste des tentatives » était donc vraie pour toujours. Sur une
     * plateforme injoignable, le job levait son exception, `SyncQueue` la
     * relançait telle quelle, et l'utilisateur recevait une **erreur 500** :
     * aucun rejet consigné, et le message « la plateforme FNE est injoignable »
     * que le contrôleur tient prêt n'était jamais atteint. En synchrone,
     * personne ne rejouera : c'est ici, et maintenant, qu'il faut consigner.
     *
     * Hors file — `handle()` appelé directement —, la règle ne change pas : le
     * job relance et laisse l'appelant décider.
     */
    private function uneAutreTentativeViendra(): bool
    {
        if ($this->job instanceof SyncJob) {
            return false;
        }

        return $this->attempts() < $this->tries;
    }

    /**
     * Callback en cas d'échec définitif (après épuisement des tentatives).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("NormaliserFactureFne: ECHEC DEFINITIF pour Vente #{$this->vente->id} après {$this->tries} tentatives - " . $exception->getMessage());

        // Marquer la vente comme non-normalisée pour permettre une retry manuelle
        Vente::where('id', $this->vente->id)->update(['normalise' => false]);
    }
}
