<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Operation extends Model
{
    protected $table = 'operations';

    protected $fillable = [
        'entreprise_id',
        'point_de_vente_id',
        'date_operation',
        'type_operation',
        'code_journal',
        'numero_saisie',
        'reference_document',
        'libelle_general',
        'solde_equilibre',
        'est_equilibree',
    ];

    protected function casts(): array
    {
        return [
            'date_operation'   => 'date',
            'solde_equilibre'  => 'decimal:2',
            'est_equilibree'   => 'boolean',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    public function ecritures(): HasMany
    {
        return $this->hasMany(EcritureComptable::class, 'operation_id');
    }

    /**
     * Génère le prochain numéro de saisie séquentiel PAR ENTREPRISE.
     * Reste un simple compteur (1, 2, 3...) — ce n'est PAS ici que la
     * convention datée (VTE-jjmmaa-xxx, etc.) s'applique : celle-ci
     * concerne les RÉFÉRENCES DE FACTURES (numero_facture), gérées par
     * NumerotationService. Le N° de saisie, lui, reste volontairement une
     * simple numérotation d'écriture, lisible et continue.
     *
     * Utilise un verrou de ligne pour éviter les collisions en cas de
     * créations concurrentes.
     */
    public static function prochainNumeroSaisie(int $entrepriseId): string
    {
        return DB::transaction(function () use ($entrepriseId) {
            // Verrouiller la ligne stable de l'entreprise, pas la dernière
            // opération : sur une entreprise sans opération, InnoDB verrouillait
            // une plage d'index (gap lock). Deux créations simultanées pouvaient
            // alors se bloquer mutuellement au moment de l'INSERT.
            Entreprise::query()
                ->whereKey($entrepriseId)
                ->lockForUpdate()
                ->firstOrFail();

            // Le plus grand identifiant technique n'est pas forcément
            // l'opération portant le plus grand numéro de saisie : imports,
            // reprises et écritures historiques peuvent rompre cet ordre.
            // On calcule donc le maximum numérique réellement utilisé.
            // Lecture verrouillée (current read InnoDB), pas une lecture
            // cohérente issue du snapshot de la transaction appelante.
            // Une vente peut créer plusieurs mouvements dans la même transaction ;
            // une transaction concurrente peut aussi avoir commencé avant que le
            // verrou entreprise soit libéré. Sans current read, elle relit un MAX
            // périmé après l'attente et réutilise le numéro déjà inséré.
            $dernierNumero = self::where('entreprise_id', $entrepriseId)
                ->orderByRaw('CAST(numero_saisie AS UNSIGNED) DESC')
                ->lockForUpdate()
                ->value('numero_saisie');

            $prochainNum = ((int) ($dernierNumero ?? 0)) + 1;

            return (string) $prochainNum;
        }, 5);
    }

    /**
     * Crée une opération et retourne son id, prête à recevoir des écritures liées.
     */
    public static function creer(
        int $entrepriseId,
        ?int $pdvId,
        string $date,
        string $typeOperation,
        string $codeJournal,
        ?string $referenceDocument = null,
        ?string $libelleGeneral = null
    ): self {
        // Garder le verrou de l'entreprise jusqu'à l'INSERT. Sans cette
        // transaction englobante, le verrou pris par prochainNumeroSaisie()
        // serait libéré avant la création et deux appels pourraient réutiliser
        // le même numéro.
        return DB::transaction(function () use (
            $entrepriseId, $pdvId, $date, $typeOperation, $codeJournal,
            $referenceDocument, $libelleGeneral
        ) {
            $numeroSaisie = self::prochainNumeroSaisie($entrepriseId);

            return self::create([
                'entreprise_id'       => $entrepriseId,
                'point_de_vente_id'   => $pdvId,
                'date_operation'      => $date,
                'type_operation'      => $typeOperation,
                'code_journal'        => $codeJournal,
                'numero_saisie'       => $numeroSaisie,
                'reference_document'  => $referenceDocument,
                'libelle_general'     => $libelleGeneral,
                'solde_equilibre'     => 0,
                'est_equilibree'      => false,
            ]);
        }, 5);
    }

    /**
     * Recalcule et enregistre l'équilibre débit/crédit de l'opération
     * à partir de ses lignes d'écriture actuelles. À appeler après avoir
     * créé toutes les lignes de l'opération.
     */
    public function cloturerEquilibre(): void
    {
        $totaux = $this->ecritures()
            ->selectRaw('COALESCE(SUM(debit),0) as total_debit, COALESCE(SUM(credit),0) as total_credit')
            ->first();

        $solde = round((float) $totaux->total_debit - (float) $totaux->total_credit, 2);

        $this->update([
            'solde_equilibre' => $solde,
            'est_equilibree'  => abs($solde) < 0.01,
        ]);

        if (abs($solde) >= 0.01) {
            \Illuminate\Support\Facades\Log::error(
                "Opération #{$this->numero_saisie} déséquilibrée : solde = {$solde}"
            );

            // Une opération déséquilibrée ne se déverse pas : l'envoyer
            // porterait le déséquilibre chez Comptaflow.
            return;
        }

        /*
         * C'est ici que l'opération part vers Comptaflow — et non à la
         * création de chaque ligne.
         *
         * Le point est le bon pour trois raisons : toutes les lignes existent,
         * l'équilibre vient d'être vérifié, et la transaction qui les a écrites
         * est sur le point de se refermer. `afterCommit()` fait le reste : rien
         * ne part tant qu'elle n'est pas validée, si bien qu'une transaction
         * annulée ne laisse plus rien chez Comptaflow.
         */
        $entreprise = \App\Modules\Admin\Modeles\Entreprise::find($this->entreprise_id);

        if ($entreprise
            && $entreprise->comptaflow_sync_status === 'active'
            && $entreprise->comptaflow_sync_key) {
            \App\Jobs\DeverserOperationComptaflow::dispatch($this->id)->afterCommit();
        }
    }
}
