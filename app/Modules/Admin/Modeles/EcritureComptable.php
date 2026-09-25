<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcritureComptable extends Model
{
    protected $table = 'ecritures_comptables';

    protected static function booted()
    {
        static::addGlobalScope(new \App\Modules\Admin\Scopes\PeriodeScope('date_ecriture'));

        // Verrouillage des écritures sur exercice clôturé (création/modification)
        static::saving(function ($ecriture) {
            $cloture = \App\Modules\Admin\Modeles\Periode::where('entreprise_id', $ecriture->entreprise_id)
                ->where('est_cloture', true)
                ->whereDate('date_debut', '<=', $ecriture->date_ecriture)
                ->whereDate('date_fin', '>=', $ecriture->date_ecriture)
                ->exists();
            if ($cloture) {
                abort(403, "Action impossible : l'exercice comptable pour cette date d'écriture est clôturé.");
            }
        });

        // Verrouillage des écritures sur exercice clôturé (suppression)
        static::deleting(function ($ecriture) {
            $cloture = \App\Modules\Admin\Modeles\Periode::where('entreprise_id', $ecriture->entreprise_id)
                ->where('est_cloture', true)
                ->whereDate('date_debut', '<=', $ecriture->date_ecriture)
                ->whereDate('date_fin', '>=', $ecriture->date_ecriture)
                ->exists();
            if ($cloture) {
                abort(403, "Action impossible : l'exercice comptable pour cette date d'écriture est clôturé.");
            }
        });

        /*
         * Le déversement vers Comptaflow ne part plus d'ici.
         *
         * Il partait **ligne par ligne**, sur `created` : une facture de vente
         * en produit quatre ou cinq, et chacune faisait son propre appel. Deux
         * défauts en découlaient, et le second est celui qu'on a constaté :
         *
         * - `created` se déclenche **avant** `Operation::cloturerEquilibre()`
         *   et avant la fin de la transaction : une transaction annulée
         *   ensuite laissait chez Comptaflow une écriture que Selflow n'avait
         *   pas ;
         * - une ligne refusée pendant que les autres passaient laissait chez
         *   Comptaflow une **opération à moitié** — un débit sans son crédit —,
         *   et rien ne recollait les morceaux.
         *
         * L'opération part désormais d'un bloc, une fois close et vérifiée
         * équilibrée : voir `Operation::cloturerEquilibre()` et
         * `App\Jobs\DeverserOperationComptaflow`.
         */
    }

    protected $fillable = [
        'operation_id',
        'entreprise_id',
        'point_de_vente_id',
        'date_ecriture',
        'libelle',
        'reference_document',
        'code_journal',
        'compte_debit',
        'compte_credit',
        'compte_tiers',
        'debit',
        'credit',
        'lettrage_id',
        'description',
        'comptaflow_sync_status',
    ];

    protected function casts(): array
    {
        return [
            'date_ecriture' => 'date',
            'debit'         => 'decimal:2',
            'credit'        => 'decimal:2',
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

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    /**
     * Le lettrage qui rapproche cette écriture d'une autre.
     *
     * Nul tant que la pièce n'est pas soldée : c'est précisément ce qui permet
     * de répondre à « que me doit-on encore ? ».
     */
    public function lettrage()
    {
        return $this->belongsTo(Lettrage::class, 'lettrage_id');
    }
}
