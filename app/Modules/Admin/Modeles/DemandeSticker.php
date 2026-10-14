<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une demande de stickers que DC-Knowing achète à la place de l'entreprise.
 *
 * Selflow ne vend pas de vignettes : la plateforme FNE les vend, sur l'espace
 * de l'entreprise. Ce que Selflow porte, c'est la demande — combien, pour
 * quel site, avec quelle urgence — et son suivi jusqu'à la livraison.
 */
class DemandeSticker extends Model
{
    use IdentifiantOpaque;

    protected $table = 'demandes_stickers';

    public const STATUTS = [
        'recue'    => 'Reçue',
        'en_cours' => 'Achat en cours',
        'achetee'  => 'Achetée auprès de la DGI',
        'livree'   => 'Livrée sur l\'espace FNE',
        'annulee'  => 'Annulée',
    ];

    public const URGENCES = [
        'normale' => 'Normale',
        'urgente' => 'Urgente — le solde est presque épuisé',
    ];

    /** Bornes d'une demande : un lot de vignettes, pas une saisie au hasard. */
    public const QUANTITE_MIN = 50;
    public const QUANTITE_MAX = 1000000;

    /** Quantités proposées d'un clic ; toute autre se saisit. */
    public const QUANTITES_PROPOSEES = [100, 500, 1000, 5000];

    protected $fillable = [
        'entreprise_id', 'point_de_vente_id', 'utilisateur_id', 'quantite', 'prix_unitaire',
        'montant_estime', 'urgence', 'contact_telephone', 'commentaire', 'statut',
        'note_dcknowing', 'traitee_par', 'traitee_le', 'courriel_envoye',
    ];

    protected function casts(): array
    {
        return [
            'quantite'        => 'integer',
            'prix_unitaire'   => 'decimal:2',
            'montant_estime'  => 'decimal:2',
            'traitee_le'      => 'datetime',
            'courriel_envoye' => 'boolean',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function traiteePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'traitee_par');
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function estOuverte(): bool
    {
        return in_array($this->statut, ['recue', 'en_cours', 'achetee'], true);
    }

    /** Où DC-Knowing reçoit les demandes. */
    public static function adressesDcKnowing(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('selflow.adresse_demandes_stickers')))));
    }
}
