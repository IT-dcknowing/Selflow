<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Services\FneService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job : Normalisation de bordereau d'achat BAPA via l'API FNE/DGI.
 *
 * Ce Job exécute l'appel HTTP à l'API FNE en arrière-plan pour normaliser
 * un achat BAPA de manière asynchrone (évite les blocages).
 */
class NormaliserAchatBapaJob implements ShouldQueue
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

    public function __construct(
        public readonly Achat $achat
    ) {}

    /**
     * Exécuter le Job.
     */
    public function handle(): void
    {
        try {
            Log::info("NormaliserAchatBapaJob: Début normalisation async - Achat #{$this->achat->id} / Facture: {$this->achat->numero_facture}");

            $fneResult = FneService::normaliserAchatBapa($this->achat);

            if ($fneResult['success']) {
                // Recharger l'achat pour éviter les conflits
                $achat = Achat::find($this->achat->id);

                if ($achat) {
                    // Retours de la plateforme (timbre, montants, alerte de
                    // stickers) : conserves pour les ventes, ils etaient perdus
                    // pour les bordereaux.
                    $achat->update([
                        'normalise'     => true,
                        'numero_fne'    => $fneResult['numero_recu'],
                        'signature_dgi' => $fneResult['signature'] ?? null,
                        'qr_code_data'  => $fneResult['qr_code_data'],
                        'fichier_fne_pdf_url' => $fneResult['pdf_url'] ?? null,
                    ] + FneService::colonnesRetoursFne($fneResult));

                    \App\Modules\Admin\Modeles\FneRejet::resoudre($achat);

                    Log::info("NormaliserAchatBapaJob: Normalisation BAPA réussie - Achat #{$achat->id} → FNE: {$fneResult['numero_recu']}");
                }
            } else {
                Log::warning("NormaliserAchatBapaJob: Réponse non-success pour Achat #{$this->achat->id}", $fneResult);

                // La DGI n'a rien examiné : le transport a manqué, pas le
                // bordereau. On relance plutôt que de consigner un refus qui
                // n'en est pas un — et qui ouvrirait un relevé du portail
                // sans objet. Voir NormaliserFactureFne, même raisonnement.
                if (\App\Modules\Admin\Modeles\FneRejet::classer($fneResult)
                        === \App\Modules\Admin\Modeles\FneRejet::CAUSE_RESEAU
                    && $this->uneAutreTentativeViendra()) {
                    throw new \RuntimeException(
                        "Plateforme FNE injoignable pour Achat #{$this->achat->id} : "
                        . ($fneResult['message'] ?? 'sans message')
                    );
                }

                // Le rejet laissait une ligne de log et rien d'autre. Consigné,
                // il devient rapprochable du relevé du portail.
                \App\Modules\Admin\Modeles\FneRejet::consigner($this->achat, $fneResult);
            }
        } catch (\Exception $e) {
            Log::error("NormaliserAchatBapaJob: Exception pour Achat #{$this->achat->id} - " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Une autre tentative viendra-t-elle ? Même raisonnement, et même défaut
     * corrigé, que dans `NormaliserFactureFne` : en synchrone — le bouton
     * « Normaliser » de l'écran des achats —, `SyncJob::attempts()` rend
     * toujours 1, le job relançait donc pour toujours, et une plateforme
     * injoignable rendait une erreur 500 sans consigner le moindre rejet.
     */
    private function uneAutreTentativeViendra(): bool
    {
        if ($this->job instanceof SyncJob) {
            return false;
        }

        return $this->attempts() < $this->tries;
    }

    /**
     * Callback en cas d'échec définitif.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("NormaliserAchatBapaJob: ECHEC DEFINITIF pour Achat #{$this->achat->id} après {$this->tries} tentatives - " . $exception->getMessage());
        Achat::where('id', $this->achat->id)->update(['normalise' => false]);
    }
}
