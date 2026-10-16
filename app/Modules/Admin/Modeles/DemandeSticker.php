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

    // « Livrée sur l'espace FNE » est retirée (16/10/2026) : la demande est
    // terminée une fois achetée. « Échec » s'ajoute, avec son motif.
    public const STATUTS = [
        'recue'    => 'Reçue',
        'en_cours' => 'Achat en cours',
        'achetee'  => 'Achetée auprès de la DGI',
        'echec'    => 'Échec',
        'annulee'  => 'Annulée',
    ];

    /** Ce que le superadministrateur compte encore à traiter. */
    public const A_TRAITER = ['recue', 'en_cours'];

    /** Fond et texte du badge : vert terminé, rouge annulé ou échoué, jaune en cours. */
    public const COULEURS = [
        'recue'    => ['#eff6ff', '#1d4ed8'],
        'en_cours' => ['#fef9c3', '#a16207'],
        'achetee'  => ['#dcfce7', '#15803d'],
        'echec'    => ['#fee2e2', '#b91c1c'],
        'annulee'  => ['#fee2e2', '#b91c1c'],
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
        'operateur_paiement', 'reference_paiement', 'motif_echec', 'en_cours_le', 'achetee_le',
        'echec_le', 'annulee_le', 'stock_communique', 'solde_communique', 'notification_vue_le',
    ];

    protected function casts(): array
    {
        return [
            'quantite'        => 'integer',
            'prix_unitaire'   => 'decimal:2',
            'montant_estime'  => 'decimal:2',
            'traitee_le'      => 'datetime',
            'en_cours_le'     => 'datetime',
            'achetee_le'      => 'datetime',
            'echec_le'        => 'datetime',
            'annulee_le'      => 'datetime',
            'notification_vue_le' => 'datetime',
            'solde_communique' => 'decimal:2',
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
        return in_array($this->statut, self::A_TRAITER, true);
    }

    /** @return array{0: string, 1: string} fond, texte */
    public function couleurs(): array
    {
        return self::COULEURS[$this->statut] ?? ['#f1f5f9', '#475569'];
    }

    /**
     * Les étapes franchies, de la première à la dernière, avec leur heure :
     * la traçabilité de la demande.
     *
     * @return array<int, array{statut: string, libelle: string, le: ?\Illuminate\Support\Carbon}>
     */
    public function etapes(): array
    {
        $etapes = [['statut' => 'recue', 'libelle' => self::STATUTS['recue'], 'le' => $this->created_at]];
        foreach (['en_cours' => 'en_cours_le', 'achetee' => 'achetee_le', 'echec' => 'echec_le', 'annulee' => 'annulee_le'] as $statut => $colonne) {
            if ($this->{$colonne}) {
                $etapes[] = ['statut' => $statut, 'libelle' => self::STATUTS[$statut], 'le' => $this->{$colonne}];
            }
        }

        return $etapes;
    }

    /** Où DC-Knowing reçoit les demandes. */
    public static function adressesDcKnowing(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('selflow.adresse_demandes_stickers')))));
    }
}
