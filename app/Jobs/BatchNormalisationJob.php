<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Services\FneService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BatchNormalisationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Nombre maximum de tentatives. En cas d'échec FNE, on ne réessaie pas automatiquement
     * pour éviter d'autres appels si le problème est structurel (ex: NCC invalide).
     */
    public int $tries = 1;

    /**
     * Timeout maximum (en secondes).
     */
    public int $timeout = 600;

    public function __construct(
        public readonly int $entrepriseId,
        public readonly string $flux,
        public readonly string $dateDebut,
        public readonly string $dateFin,
        public readonly int $batchSize
    ) {}

    public function handle(): void
    {
        Log::info("BatchNormalisationJob: Début du traitement - Entreprise #{$this->entrepriseId}, Flux: {$this->flux}, Période: {$this->dateDebut} au {$this->dateFin}, Taille: {$this->batchSize}");

        $statusPath = storage_path("app/fne_batch_{$this->entrepriseId}.json");

        // 1. Récupérer les documents non-normalisés
        //    `withoutGlobalScopes()` est indispensable : le PeriodeScope filtre
        //    sur la période active en SESSION. Dans un job de file d'attente il
        //    n'y a pas de session, et en exécution synchrone il restreindrait
        //    silencieusement la plage de dates demandée par l'utilisateur — le
        //    lot ne trouvait alors aucun document a traiter.
        if ($this->flux === 'ventes') {
            $invoices = Vente::withoutGlobalScopes()
                ->where('normalise', false)
                ->where('etape', 'Facture')
                ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $this->entrepriseId))
                ->whereBetween('date_vente', [$this->dateDebut . ' 00:00:00', $this->dateFin . ' 23:59:59'])
                ->orderBy('date_vente', 'asc')
                ->orderBy('id', 'asc')
                ->limit($this->batchSize)
                ->get();
        } else {
            $invoices = Achat::withoutGlobalScopes()
                ->where('normalise', false)
                ->where('type_facture', 'bapa')
                ->where('etape', 'Facture')
                ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $this->entrepriseId))
                ->whereBetween('date_achat', [$this->dateDebut . ' 00:00:00', $this->dateFin . ' 23:59:59'])
                ->orderBy('date_achat', 'asc')
                ->orderBy('id', 'asc')
                ->limit($this->batchSize)
                ->get();
        }

        $totalToProcess = count($invoices);
        Log::info("BatchNormalisationJob: {$totalToProcess} document(s) trouvé(s) à normaliser.");

        // 2. Initialiser le fichier de statut
        $statusData = [
            'status' => 'running',
            'flux' => $this->flux,
            'total_to_process' => $totalToProcess,
            'processed_count' => 0,
            'current_invoice' => null,
            'error' => null,
            'last_updated' => now()->toDateTimeString(),
        ];
        file_put_contents($statusPath, json_encode($statusData));

        if ($totalToProcess === 0) {
            $statusData['status'] = 'completed';
            $statusData['last_updated'] = now()->toDateTimeString();
            file_put_contents($statusPath, json_encode($statusData));
            return;
        }

        // 3. Boucler sur les documents
        foreach ($invoices as $invoice) {
            // Vérifier s'il y a une demande d'annulation
            if (file_exists($statusPath)) {
                $currentData = json_decode(file_get_contents($statusPath), true);
                if (isset($currentData['status']) && $currentData['status'] === 'cancelled') {
                    Log::info("BatchNormalisationJob: Annulé par l'utilisateur.");
                    return;
                }
            }

            // Mettre à jour le statut en cours
            $statusData['current_invoice'] = $invoice->numero_facture;
            $statusData['last_updated'] = now()->toDateTimeString();
            file_put_contents($statusPath, json_encode($statusData));

            try {
                if ($this->flux === 'ventes') {
                    $fneResult = FneService::normaliserFacture($invoice);

                    if ($fneResult['success']) {
                        $updateData = [
                            'normalise'     => true,
                            'numero_fne'    => $fneResult['numero_recu'],
                            'signature_dgi' => $fneResult['signature'] ?? null,
                            'qr_code_data'  => $fneResult['qr_code_data'],
                            'fichier_fne_pdf_url' => $fneResult['pdf_url'] ?? null,
                        ];

                        if ($invoice->type_facture !== 'avoir') {
                            $updateData['type_facture'] = 'normale';
                        }

                        if (!empty($fneResult['invoice_id'])) {
                            $updateData['fne_invoice_id'] = $fneResult['invoice_id'];
                        }

                        $updateData += FneService::colonnesRetoursFne($fneResult);

                        $invoice->update($updateData);

                        if (!empty($fneResult['fne_item_ids']) && is_array($fneResult['fne_item_ids'])) {
                            foreach ($fneResult['fne_item_ids'] as $detailId => $itemId) {
                                VenteDetail::where('id', $detailId)->update(['fne_invoice_item_id' => $itemId]);
                            }
                        }

                        FneRejet::resoudre($invoice);
                    } else {
                        FneRejet::consigner($invoice, $fneResult);

                        // Échec de la normalisation : on arrête immédiatement la boucle pour éviter des trous
                        $statusData['status'] = 'failed';
                        $statusData['error'] = "Échec sur {$invoice->numero_facture} : " . ($fneResult['message'] ?? 'Erreur inconnue');
                        $statusData['last_updated'] = now()->toDateTimeString();
                        file_put_contents($statusPath, json_encode($statusData));
                        Log::warning("BatchNormalisationJob arrêté à {$invoice->numero_facture} : " . $statusData['error']);
                        return;
                    }
                } else {
                    // Achats (BAPA)
                    $fneResult = FneService::normaliserAchatBapa($invoice);

                    if ($fneResult['success']) {
                        // Retours de la plateforme (timbre, montants, alerte de
                        // stickers) : conserves pour les ventes, ils etaient perdus
                        // pour les bordereaux.
                        $invoice->update([
                            'normalise'     => true,
                            'numero_fne'    => $fneResult['numero_recu'],
                            'signature_dgi' => $fneResult['signature'] ?? null,
                            'qr_code_data'  => $fneResult['qr_code_data'],
                            'fichier_fne_pdf_url' => $fneResult['pdf_url'] ?? null,
                        ] + FneService::colonnesRetoursFne($fneResult));

                        FneRejet::resoudre($invoice);
                    } else {
                        FneRejet::consigner($invoice, $fneResult);

                        // Échec de la normalisation : on arrête
                        $statusData['status'] = 'failed';
                        $statusData['error'] = "Échec sur {$invoice->numero_facture} : " . ($fneResult['message'] ?? 'Erreur inconnue');
                        $statusData['last_updated'] = now()->toDateTimeString();
                        file_put_contents($statusPath, json_encode($statusData));
                        Log::warning("BatchNormalisationJob arrêté à {$invoice->numero_facture} : " . $statusData['error']);
                        return;
                    }
                }
            } catch (\Exception $e) {
                $statusData['status'] = 'failed';
                $statusData['error'] = "Exception sur {$invoice->numero_facture} : " . $e->getMessage();
                $statusData['last_updated'] = now()->toDateTimeString();
                file_put_contents($statusPath, json_encode($statusData));
                Log::error("BatchNormalisationJob exception sur {$invoice->numero_facture} : " . $e->getMessage());
                return;
            }

            // Incrémenter le nombre traité
            $statusData['processed_count']++;
            $statusData['last_updated'] = now()->toDateTimeString();
            file_put_contents($statusPath, json_encode($statusData));
        }

        // 4. Finalisation avec succès
        $statusData['status'] = 'completed';
        $statusData['current_invoice'] = null;
        $statusData['last_updated'] = now()->toDateTimeString();
        file_put_contents($statusPath, json_encode($statusData));
        Log::info("BatchNormalisationJob terminé avec succès.");
    }

    /**
     * Echec definitif du job (exception non rattrapee, timeout, worker tue).
     *
     * Sans ce rappel, le fichier de suivi resterait indefiniment en « running »
     * et la barre de progression tournerait dans le vide.
     */
    public function failed(\Throwable $exception): void
    {
        $statusPath = storage_path("app/fne_batch_{$this->entrepriseId}.json");

        $statusData = file_exists($statusPath)
            ? (json_decode(file_get_contents($statusPath), true) ?: [])
            : [];

        $statusData['status'] = 'failed';
        $statusData['error'] = 'Le traitement s\'est interrompu : ' . $exception->getMessage();
        $statusData['last_updated'] = now()->toDateTimeString();

        file_put_contents($statusPath, json_encode($statusData));

        Log::error('BatchNormalisationJob: echec definitif - ' . $exception->getMessage());
    }
}
