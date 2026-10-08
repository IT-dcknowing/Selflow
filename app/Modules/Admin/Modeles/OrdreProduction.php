<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdreProduction extends Model
{
    use IdentifiantOpaque;

    public const BROUILLON = 'Brouillon';
    public const TERMINE   = 'Terminé';
    public const ANNULE    = 'Annulé';

    /** Les statuts qu'un ordre peut réellement prendre, dans l'ordre du filtre. */
    public const STATUTS = [self::BROUILLON, self::TERMINE, self::ANNULE];

    protected $table = 'ordres_production';

    protected $fillable = [
        'entreprise_id',
        'point_de_vente_id',
        'produit_fini_id',
        'code_ordre',
        'quantite_cible',
        'statut',
        'date_production',
        'cout_total',
        'cout_unitaire',
    ];

    protected $casts = [
        'quantite_cible'  => 'decimal:4',
        'date_production' => 'date',
        'cout_total'      => 'decimal:2',
        'cout_unitaire'   => 'decimal:4',
    ];

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    public function produitFini(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_fini_id');
    }

    /**
     * Les matières figées à la création de l'ordre. Vide pour un ordre établi
     * avant le 08/10/2026, qui se valide alors sur la recette en place.
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(OrdreProductionLigne::class, 'ordre_production_id');
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementStock::class, 'piece_id')
            ->where('piece_type', $this->getMorphClass());
    }

    public function estBrouillon(): bool
    {
        return $this->statut === self::BROUILLON;
    }

    public function estTermine(): bool
    {
        return $this->statut === self::TERMINE;
    }

    /**
     * Ce que la fabrication a coûté — la valeur des matières consommées.
     *
     * Posé à la validation ; pour un ordre terminé avant que la colonne
     * existe, relu sur les consommations du journal de stock.
     */
    public function coutDeRevient(): ?float
    {
        if (!$this->estTermine()) {
            return null;
        }

        if ($this->cout_total !== null) {
            return (float) $this->cout_total;
        }

        return round((float) MouvementStock::where('piece_type', $this->getMorphClass())
            ->where('piece_id', $this->getKey())
            ->where('sous_type', MouvementStock::PRODUCTION_CONSOMMATION)
            ->get()
            ->sum(fn ($m) => (float) $m->quantite * (float) ($m->cout_unitaire ?? 0)), 2);
    }

    /** Le coût d'une unité de produit fini. */
    public function coutUnitaireDeRevient(): ?float
    {
        if ($this->cout_unitaire !== null) {
            return (float) $this->cout_unitaire;
        }

        $total = $this->coutDeRevient();
        $quantite = (float) $this->quantite_cible;

        return $total === null || $quantite <= 0 ? null : round($total / $quantite, Stock::DECIMALES_COUT);
    }

    /**
     * Une quantité lisible : jusqu'à trois décimales — la précision du
     * stock —, sans zéros inutiles. `number_format(2.5, 0)` affichait « 3 ».
     */
    public static function quantiteLisible(float|string|null $quantite, int $decimales = Stock::DECIMALES): string
    {
        $texte = number_format((float) $quantite, $decimales, ',', ' ');

        return $decimales > 0 ? rtrim(rtrim($texte, '0'), ',') : $texte;
    }

    /**
     * Générer un code unique pour un nouvel ordre de production.
     */
    public static function genererCode(int $entrepriseId): string
    {
        $annee = now()->year;
        $compteur = self::where('entreprise_id', $entrepriseId)
            ->whereYear('created_at', $annee)
            ->count() + 1;

        return 'OP-' . $annee . '-' . str_pad($compteur, 4, '0', STR_PAD_LEFT);
    }
}
