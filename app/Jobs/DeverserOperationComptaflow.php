<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\Periode;
use App\Modules\Admin\Services\LiaisonComptaflowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * L'opération part d'un bloc, ou elle ne part pas.
 *
 * ## Ce qui partait avant, et ce que ça produisait
 *
 * `EcritureComptable::created` lançait `DeverserEcritureComptaflow` **ligne par
 * ligne** : un travail, un appel HTTP, une ligne. Une facture de vente en
 * produit quatre ou cinq — le client au débit, la vente au crédit, la TVA
 * collectée, parfois une taxe et un timbre — et chacune partait de son côté.
 *
 * Trois conséquences, et la première est celle qu'on a vue :
 *
 * 1. **L'opération pouvait arriver à moitié.** Si la ligne du client passait
 *    et que celle de la vente était refusée — journal inconnu, exercice en
 *    désaccord (409, Conflict), coupure réseau —, Comptaflow gardait un débit
 *    sans son crédit. Sa balance ne balançait plus, et rien ne recollait les
 *    morceaux : chaque ligne ignorait l'existence des autres.
 * 2. **Le départ était trop tôt.** `created` se déclenche avant que
 *    `Operation::cloturerEquilibre()` ait vérifié l'équilibre, et avant la fin
 *    de la transaction : une transaction annulée ensuite laissait chez
 *    Comptaflow une écriture que Selflow n'avait pas.
 * 3. Cinq appels HTTP là où un seul suffit.
 *
 * ## Ce qui part maintenant
 *
 * L'opération entière, une fois close et **vérifiée équilibrée**, en un seul
 * appel — l'API de Comptaflow accepte déjà un tableau `ecritures`. Une
 * opération déséquilibrée ne part pas du tout : la déverser reviendrait à
 * exporter l'erreur.
 *
 * L'idempotence ne change pas : chaque ligne garde sa `cle_selflow`, et
 * Comptaflow ignore celles qu'il détient déjà. Rejouer est donc sans danger,
 * y compris après un envoi partiellement accepté.
 */
class DeverserOperationComptaflow implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 30;

    public function __construct(public readonly int $operationId)
    {
    }

    public function handle(): void
    {
        $operation = Operation::with(['ecritures.pointDeVente'])->find($this->operationId);

        if (!$operation) {
            return;
        }

        $entreprise = Entreprise::find($operation->entreprise_id);

        if (!$entreprise
            || $entreprise->comptaflow_sync_status !== 'active'
            || !$entreprise->comptaflow_sync_key) {
            return;
        }

        // Une opération déséquilibrée ne se déverse pas. `cloturerEquilibre()`
        // l'a déjà consignée en erreur ; l'envoyer chez Comptaflow ne ferait
        // qu'y porter le déséquilibre.
        if (!$operation->est_equilibree) {
            Log::warning('Opération déséquilibrée non déversée', [
                'operation_id' => $operation->id,
                'solde'        => $operation->solde_equilibre,
            ]);

            return;
        }

        $lignes = $operation->ecritures
            ->where('comptaflow_sync_status', '!=', 'synced')
            ->values();

        if ($lignes->isEmpty()) {
            return;
        }

        $exercice = Periode::where('entreprise_id', $entreprise->id)
            ->where('est_active', true)
            ->first();

        $charge = $lignes->map(fn (EcritureComptable $e) => self::ligne($e, $entreprise))->all();

        try {
            $reponse = Http::timeout(15)
                ->withHeaders(LiaisonComptaflowService::enTete($entreprise))
                ->post(
                    rtrim(config('selflow.comptaflow_api_url', 'http://127.0.0.1:8000'), '/')
                        . '/api/external/ecritures/deverser',
                    [
                        'secret'             => config('selflow.comptaflow_api_secret'),
                        'selflow_company_id' => $entreprise->id,
                        // L'opération entière, et le dire : Comptaflow peut
                        // ainsi refuser le tout plutôt que d'en garder la
                        // moitié.
                        'operation'          => $operation->numero_saisie,
                        'atomique'           => true,
                        'exercice_debut'     => $exercice?->date_debut?->toDateString(),
                        'exercice_fin'       => $exercice?->date_fin?->toDateString(),
                        'ecritures'          => $charge,
                    ]
                );

            $ids = $lignes->pluck('id')->all();

            if ($reponse->successful() && ($reponse->json('success') ?? false)) {
                // Un refus partiel reste un refus : la moitié d'une opération
                // chez Comptaflow est pire qu'aucune. On laisse les lignes en
                // `failed`, et la reprise des cinq minutes les repassera.
                $refus = $reponse->json('refus') ?? [];

                EcritureComptable::whereIn('id', $ids)->update([
                    'comptaflow_sync_status' => empty($refus) ? 'synced' : 'failed',
                ]);

                if (!empty($refus)) {
                    Log::warning('Opération partiellement refusée par Comptaflow', [
                        'operation_id' => $operation->id,
                        'refus'        => $refus,
                    ]);
                }

                return;
            }

            EcritureComptable::whereIn('id', $ids)->update(['comptaflow_sync_status' => 'failed']);

            Log::warning('Opération refusée par Comptaflow', [
                'operation_id' => $operation->id,
                'statut'       => $reponse->status(),
                'corps'        => mb_substr($reponse->body(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            EcritureComptable::whereIn('id', $lignes->pluck('id')->all())
                ->update(['comptaflow_sync_status' => 'failed']);

            Log::error('Déversement de l\'opération impossible', [
                'operation_id' => $operation->id,
                'erreur'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Une ligne, telle que Comptaflow l'attend.
     *
     * Le format ne change pas d'un pouce : c'est celui que
     * `DeverserEcritureComptaflow` envoyait, et que l'API sait lire. Seul le
     * regroupement change.
     *
     * @return array<string, mixed>
     */
    public static function ligne(EcritureComptable $ecriture, Entreprise $entreprise): array
    {
        $date = $ecriture->date_ecriture instanceof \Carbon\Carbon
            ? $ecriture->date_ecriture->toDateString()
            : \Carbon\Carbon::parse($ecriture->date_ecriture)->toDateString();

        return [
            'date_ecriture'      => $date,
            'libelle'            => $ecriture->libelle,
            'reference_document' => $ecriture->reference_document,
            'code_journal'       => $ecriture->code_journal,
            'compte_debit'       => $ecriture->compte_debit,
            'compte_credit'      => $ecriture->compte_credit,
            'debit'              => (float) $ecriture->debit,
            'credit'             => (float) $ecriture->credit,
            // Le compte de tiers. Sans lui, Comptaflow rattache l'écriture au
            // seul compte collectif et le relevé d'un client particulier
            // devient impossible.
            'compte_tiers'       => $ecriture->compte_tiers,
            // Clé d'idempotence : rejouer ne duplique rien.
            'cle_selflow'        => 'SELFLOW-' . $entreprise->id . '-' . $ecriture->id,
            'point_de_vente'     => $ecriture->pointDeVente?->nom,
        ];
    }
}
