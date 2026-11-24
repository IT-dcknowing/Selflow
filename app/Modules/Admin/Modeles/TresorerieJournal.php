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
     * Les lignes d'une seule entreprise (24/11/2026).
     *
     * La table n'a pas de colonne `entreprise_id` : elle se rattache par le
     * point de vente. Les sommes « déjà payé » se faisaient sur le seul numéro
     * de pièce, et deux entreprises numérotent leurs factures de la même façon :
     * une facture de 3 000 F affichait 551 704 F réglés, encaissés sous le même
     * numéro par une autre entreprise.
     */
    public function scopeDeLEntreprise($query, ?int $entrepriseId)
    {
        return $query->whereIn(
            'point_de_vente_id',
            PointDeVente::withoutGlobalScopes()->where('entreprise_id', (int) $entrepriseId)->select('id')
        );
    }

    /** L'entreprise d'une pièce (vente ou achat), par son point de vente. */
    public static function entrepriseDeLaPiece(Model $piece): ?int
    {
        $id = $piece->entreprise_id
            ?? PointDeVente::withoutGlobalScopes()->whereKey($piece->point_de_vente_id)->value('entreprise_id');

        return $id ? (int) $id : (auth()->user()?->entreprise_id ? (int) auth()->user()->entreprise_id : null);
    }

    /** Ce qui a été encaissé sur une pièce de vente, dans son entreprise. */
    public static function encaissePour(Model $vente): float
    {
        return (float) static::deLEntreprise(static::entrepriseDeLaPiece($vente))
            ->where('reference_document', $vente->numero_facture)->sum('montant_entree');
    }

    /** Ce qui a été décaissé sur une pièce d'achat, dans son entreprise. */
    public static function decaissePour(Model $achat): float
    {
        return (float) static::deLEntreprise(static::entrepriseDeLaPiece($achat))
            ->where('reference_document', $achat->numero_facture)->sum('montant_sortie');
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
