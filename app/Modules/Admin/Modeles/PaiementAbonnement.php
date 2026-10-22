<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un paiement d'abonnement déclaré par l'entreprise, en attendant l'API de
 * paiement (16/10/2026) : l'offre, le montant, l'opérateur et la référence
 * de la transaction ; le superadministrateur le vérifie et l'active.
 */
class PaiementAbonnement extends Model
{
    use IdentifiantOpaque;

    protected $table = 'paiements_abonnements';

    public const STATUTS = [
        'recu'     => 'Reçu',
        'en_cours' => 'Vérification en cours',
        'active'   => 'Confirmé — abonnement activé',
        'echec'    => 'Échec',
    ];

    public const A_TRAITER = ['recu', 'en_cours'];

    /** Les statuts d'un paiement `bloquant` qui tiennent l'entreprise bloquée (22/10/2026). */
    public const SUSPENDENT = ['recu', 'en_cours', 'echec'];

    public const COULEURS = [
        'recu'     => ['#eff6ff', '#1d4ed8'],
        'en_cours' => ['#fef9c3', '#a16207'],
        'active'   => ['#dcfce7', '#15803d'],
        'echec'    => ['#fee2e2', '#b91c1c'],
    ];

    protected $fillable = [
        'entreprise_id', 'utilisateur_id', 'offre', 'nature', 'mois', 'montant',
        'operateur_paiement', 'reference_paiement', 'statut', 'motif_echec',
        'en_cours_le', 'active_le', 'echec_le', 'confirmation_vue_le', 'bloquant', 'traite_par',
    ];

    protected function casts(): array
    {
        return [
            'montant'     => 'decimal:2',
            'mois'        => 'integer',
            'en_cours_le' => 'datetime',
            'active_le'   => 'datetime',
            'echec_le'    => 'datetime',
            'confirmation_vue_le' => 'datetime',
            'bloquant'    => 'boolean',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'traite_par');
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** @return array{0: string, 1: string} */
    public function couleurs(): array
    {
        return self::COULEURS[$this->statut] ?? ['#f1f5f9', '#475569'];
    }

    public function estOuvert(): bool
    {
        return in_array($this->statut, self::A_TRAITER, true);
    }

    /**
     * Le superadministrateur peut-il encore confirmer ? Un paiement ouvert,
     * ou un paiement mis en échec : le règlement retrouvé après coup se
     * confirme depuis la même ligne (22/10/2026).
     */
    public function peutEtreConfirme(): bool
    {
        return $this->estOuvert() || $this->statut === 'echec';
    }

    /** @return array<int, array{libelle: string, le: ?\Illuminate\Support\Carbon}> */
    public function etapes(): array
    {
        $etapes = [['libelle' => self::STATUTS['recu'], 'le' => $this->created_at]];
        foreach (['en_cours' => 'en_cours_le', 'active' => 'active_le', 'echec' => 'echec_le'] as $statut => $colonne) {
            if ($this->{$colonne}) {
                $etapes[] = ['libelle' => self::STATUTS[$statut], 'le' => $this->{$colonne}];
            }
        }

        // Dans l'ordre du temps : un échec peut précéder la confirmation.
        usort($etapes, fn ($a, $b) => ($a['le']?->getTimestamp() ?? 0) <=> ($b['le']?->getTimestamp() ?? 0));

        return $etapes;
    }
}
