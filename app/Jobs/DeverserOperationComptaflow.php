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

    /** Le statut d'une ligne retenue par le contrôle avant envoi. */
    public const ANOMALIE = 'anomalie';

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

        if ($operation->ecritures->every(fn (EcritureComptable $e) => $e->comptaflow_sync_status === 'synced')) {
            return;
        }

        // ── Le contrôle avant envoi (chantier 7.3) ──
        //
        // `est_equilibree` est posé à la clôture de l'opération. Il ne dit
        // rien d'une ligne modifiée depuis, ni d'une ligne qui porterait deux
        // comptes, ou son montant du mauvais côté — Comptaflow ne lit qu'un
        // compte par ligne, et rangerait l'autre nulle part. Le contrôle est
        // refait ici, sur ce qui part réellement. Ce qui ne tombe pas n'est
        // pas envoyé : le chercher chez Comptaflow, où l'on n'a pas la pièce
        // d'origine, serait bien plus long que le voir ici.
        if ($anomalie = self::anomalie($operation->ecritures)) {
            EcritureComptable::whereIn('id', self::aReprendre($operation))
                ->update(['comptaflow_sync_status' => self::ANOMALIE]);

            Log::warning('Opération non déversée : contrôle avant envoi', [
                'operation_id' => $operation->id,
                'anomalie'     => $anomalie,
            ]);

            return;
        }

        // L'opération part **entière**, lignes déjà reçues comprises : c'est
        // à cette condition que Comptaflow peut les ranger sous un même
        // numéro de saisie. Il ignore par `cle_selflow` celles qu'il détient.
        $lignes = $operation->ecritures->values();

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

                EcritureComptable::whereIn('id', empty($refus) ? $ids : self::aReprendre($operation))->update([
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

            EcritureComptable::whereIn('id', self::aReprendre($operation))->update(['comptaflow_sync_status' => 'failed']);

            Log::warning('Opération refusée par Comptaflow', [
                'operation_id' => $operation->id,
                'statut'       => $reponse->status(),
                'corps'        => mb_substr($reponse->body(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            EcritureComptable::whereIn('id', self::aReprendre($operation))
                ->update(['comptaflow_sync_status' => 'failed']);

            Log::error('Déversement de l\'opération impossible', [
                'operation_id' => $operation->id,
                'erreur'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Les lignes que Comptaflow n'a pas encore confirmées. Un échec ne
     * dégrade pas celles qu'il a déjà reçues.
     *
     * @return array<int, int>
     */
    private static function aReprendre(Operation $operation): array
    {
        return $operation->ecritures
            ->where('comptaflow_sync_status', '!=', 'synced')
            ->pluck('id')
            ->all();
    }

    /**
     * Ce qui empêche l'opération de partir, ou null.
     *
     * Trois règles, celles que Comptaflow suppose sans les vérifier :
     * chaque ligne porte **un** compte ; son montant est du côté de ce
     * compte ; la somme des débits égale celle des crédits.
     *
     * @param  \Illuminate\Support\Collection<int, EcritureComptable>  $lignes
     */
    public static function anomalie(\Illuminate\Support\Collection $lignes): ?string
    {
        if ($lignes->isEmpty()) {
            return 'aucune ligne';
        }

        $debit = 0.0;
        $credit = 0.0;

        foreach ($lignes as $e) {
            $aDebit  = trim((string) $e->compte_debit) !== '';
            $aCredit = trim((string) $e->compte_credit) !== '';

            if ($aDebit === $aCredit) {
                return "ligne {$e->id} : " . ($aDebit ? 'deux comptes' : 'aucun compte');
            }

            if (($aDebit && (float) $e->credit != 0.0) || ($aCredit && (float) $e->debit != 0.0)) {
                return "ligne {$e->id} : montant du mauvais côté";
            }

            $debit  += (float) $e->debit;
            $credit += (float) $e->credit;
        }

        if (abs(round($debit - $credit, 2)) >= 0.01) {
            return sprintf('débit %.2f, crédit %.2f', $debit, $credit);
        }

        return null;
    }

    /**
     * Une ligne, telle que Comptaflow l'attend.
     *
     * Le contrat de `ExternalSyncController::deverserEcritures()`, relu
     * champ par champ au lot 43 : un compte par ligne — `compte_debit` OU
     * `compte_credit`, Comptaflow prend le premier non vide —, le montant du
     * même côté, le journal par son code, le tiers par son numéro, et
     * `cle_selflow` pour l'idempotence. Le regroupement des lignes en une
     * saisie se fait sur le champ `operation` de l'envoi, et non ligne à
     * ligne : c'est Comptaflow qui attribue le numéro de saisie.
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
