<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TresorerieJournal extends Model
{
    protected $table = 'tresorerie_journal';
    protected $fillable = [
        'point_de_vente_id', 'utilisateur_id', 'date_operation', 'type_operation',
        'libelle', 'mode_paiement', 'moyen_bancaire', 'reference_paiement',
        'montant_entree', 'montant_sortie', 'solde_resultat', 'reference_document',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Modules\Admin\Scopes\PeriodeScope('date_operation'));

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->utilisateur_id = auth()->id();
            }

            $model->plafonnerALaPiece();
        });
    }

    /**
     * Ce qui entre en caisse pour une pièce de vente ne dépasse pas ce qui
     * reste dû sur elle — retour du 16/10/2026.
     *
     * Le « montant payé » saisi à la caisse est ce que le client tend ; la
     * différence lui est rendue. Il entrait pourtant tel quel en trésorerie :
     * 500 milliards « encaissés » sur une facture de 59 millions, quand la
     * comptabilité, elle, retenait le dû. Les deux ne s'accordaient plus.
     *
     * Le plafond est posé ici, et non dans chacun des chemins qui encaissent
     * (caisse, modification, API mobile, créances, commandes, B2B) : un seul
     * endroit, qu'aucun chemin futur ne peut oublier.
     */
    public function plafonnerALaPiece(): void
    {
        $entree = (float) $this->montant_entree;
        if ($entree <= 0 || blank($this->reference_document) || !$this->point_de_vente_id) {
            return;
        }

        $entrepriseId = PointDeVente::whereKey($this->point_de_vente_id)->value('entreprise_id');
        $piece = Vente::withoutGlobalScopes()
            ->where('numero_facture', $this->reference_document)
            ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entrepriseId))
            ->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))
            ->first();

        if (!$piece) {
            return;
        }

        $du = (float) $piece->montant_ttc + (float) $piece->montant_autres_taxes + (float) $piece->timbre_quittance;
        $dejaEncaisse = (float) static::withoutGlobalScopes()
            ->where('reference_document', $this->reference_document)
            ->where('point_de_vente_id', $this->point_de_vente_id)
            ->sum('montant_entree');

        $plafond = round(max(0, $du - $dejaEncaisse), 2);
        if ($entree <= $plafond) {
            return;
        }

        // Le solde suit : il avait été calculé sur le montant tendu.
        $this->montant_entree = $plafond;
        if ($this->solde_resultat !== null) {
            $this->solde_resultat = (float) $this->solde_resultat - ($entree - $plafond);
        }
    }

    protected function casts(): array
    {
        return [
            'date_operation' => 'date',
            'montant_entree' => 'decimal:2',
            'montant_sortie' => 'decimal:2',
            'solde_resultat' => 'decimal:2',
        ];
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }
}
