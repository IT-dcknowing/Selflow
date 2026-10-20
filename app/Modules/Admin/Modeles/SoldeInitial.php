<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'un tiers devait, ou ce qu'on lui devait, le jour où l'entreprise est
 * arrivée sur Selflow (19/10/2026).
 *
 * Le solde se règle comme une facture : chaque règlement passe en trésorerie
 * sous la `reference` du solde, et le reste se lit par différence.
 */
class SoldeInitial extends Model
{
    protected $table = 'soldes_initiaux';

    public const CLIENT = 'client';
    public const FOURNISSEUR = 'fournisseur';

    protected $fillable = [
        'entreprise_id', 'type', 'tiers_id', 'montant', 'date_solde', 'reference', 'libelle', 'cree_par',
        'point_de_vente_id',
    ];

    protected function casts(): array
    {
        return [
            'montant'    => 'decimal:2',
            'date_solde' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'tiers_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'tiers_id');
    }

    public function nomDuTiers(): string
    {
        $tiers = $this->type === self::CLIENT ? $this->client : $this->fournisseur;

        return $tiers?->nom ?? '—';
    }

    /** La référence qui rattache les règlements : SD-C-000012, SD-F-000007. */
    public static function referencePour(string $type, int $tiersId, ?int $pointDeVenteId = null): string
    {
        return sprintf('SD-%s-%06d', $type === self::CLIENT ? 'C' : 'F', $tiersId)
            . ($pointDeVenteId ? '-P' . $pointDeVenteId : '');
    }

    /** Ce qui a déjà été réglé sur ce solde, d'après la trésorerie de l'entreprise. */
    public function regle(): float
    {
        $colonne = $this->type === self::CLIENT ? 'montant_entree' : 'montant_sortie';

        return (float) TresorerieJournal::where('reference_document', $this->reference)
            ->whereIn('point_de_vente_id', PointDeVente::where('entreprise_id', $this->entreprise_id)->select('id'))
            ->when($this->point_de_vente_id, fn ($q) => $q->where('point_de_vente_id', $this->point_de_vente_id))
            ->sum($colonne);
    }

    public function reste(): float
    {
        return round(max(0, (float) $this->montant - $this->regle()), 2);
    }
}
