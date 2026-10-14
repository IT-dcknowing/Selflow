<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une négociation entre deux entreprises inscrites — la conversation et la
 * commande qui en naît (lot 75).
 *
 * Le parcours :
 *
 *   RFQ ──► Negociation_Fournisseur ◄──► Negociation_Client
 *    │                │                        │
 *    └────────────────┴──── Commande ◄─────────┘   (ou commande directe)
 *                              │
 *                          Confirme  (« en attente de livraison »)
 *                              │
 *                            Livre   (facture du fournisseur établie)
 *                              │
 *                           Termine  (réceptionné par le client)
 *
 * `Refuse` peut tomber à toute étape avant la livraison. `Valide` est un
 * statut de l'ancien parcours, lu comme une proposition du fournisseur.
 */
class B2bNegotiation extends Model
{
    use IdentifiantOpaque;

    protected $table = 'b2b_negotiations';

    public const CLIENT = 'client';
    public const FOURNISSEUR = 'fournisseur';

    /** Où l'on peut encore discuter le prix. */
    public const EN_DISCUSSION = ['RFQ', 'Negociation_Fournisseur', 'Negociation_Client', 'Valide'];

    /** Avant la livraison, l'une ou l'autre partie peut renoncer. */
    public const REFUSABLES = ['RFQ', 'Negociation_Fournisseur', 'Negociation_Client', 'Valide', 'Commande', 'Confirme'];

    protected $fillable = [
        'entreprise_client_id',
        'entreprise_fournisseur_id',
        'statut',
        'type_demande',
        'reference_commande',
        'produits_demandes',
        'prix_final',
        'type_facturation',
        'historique_discussions',
        'derniere_activite_le',
        'derniere_activite_par',
        'lu_client_le',
        'lu_fournisseur_le',
    ];

    protected $casts = [
        'produits_demandes'      => 'array',
        'historique_discussions' => 'array',
        'prix_final'             => 'decimal:2',
        'derniere_activite_le'   => 'datetime',
        'lu_client_le'           => 'datetime',
        'lu_fournisseur_le'      => 'datetime',
    ];

    public function entrepriseClient(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_client_id');
    }

    public function entrepriseFournisseur(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_fournisseur_id');
    }

    public function achats(): HasMany
    {
        return $this->hasMany(Achat::class, 'b2b_negotiation_id');
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class, 'b2b_negotiation_id');
    }

    /** Le rôle d'une entreprise dans cette négociation, ou null si elle n'y est pas. */
    public function roleDe(?int $entrepriseId): ?string
    {
        return match ($entrepriseId) {
            $this->entreprise_client_id      => self::CLIENT,
            $this->entreprise_fournisseur_id => self::FOURNISSEUR,
            default                          => null,
        };
    }

    /** Ce que le statut veut dire, vu de chaque côté. */
    public function libelleStatut(string $role): string
    {
        $client = $role === self::CLIENT;

        return match ($this->statut) {
            'RFQ'                     => $client ? 'Demande envoyée — attente du fournisseur' : 'Demande de prix reçue — à répondre',
            'Negociation_Fournisseur', 'Valide' => $client ? 'Prix proposés — à vous de décider' : 'Proposition envoyée — attente du client',
            'Negociation_Client'      => $client ? 'Contre-proposition envoyée' : 'Contre-proposition reçue — à répondre',
            'Commande'                => $client ? 'Commande envoyée — attente de confirmation' : 'Commande reçue — à confirmer',
            'Confirme'                => 'Confirmée — en attente de livraison',
            'Livre'                   => $client ? 'Livrée et facturée — à réceptionner' : 'Livrée et facturée — attente de réception',
            'Termine'                 => 'Terminée — réceptionnée',
            'Refuse'                  => 'Refusée',
            default                   => $this->statut,
        };
    }

    /** La couleur du statut : à faire de mon côté, en attente de l'autre, close. */
    public function tonStatut(string $role): string
    {
        if (in_array($this->statut, ['Termine', 'Refuse'], true)) {
            return $this->statut === 'Termine' ? 'fait' : 'clos';
        }

        $aMoi = [
            self::CLIENT      => ['Negociation_Fournisseur', 'Valide', 'Livre'],
            self::FOURNISSEUR => ['RFQ', 'Negociation_Client', 'Commande', 'Confirme'],
        ];

        return in_array($this->statut, $aMoi[$role], true) ? 'a_faire' : 'attente';
    }

    /**
     * L'autre partie a-t-elle écrit ou agi depuis ma dernière visite ?
     *
     * Chaque action de l'un efface la lecture de l'autre ; ouvrir la page la
     * repose. Comparer deux heures ne suffisait pas : une réponse donnée dans
     * la même seconde passait pour lue.
     */
    public function estNonLuPour(string $role): bool
    {
        $lu = $role === self::CLIENT ? $this->lu_client_le : $this->lu_fournisseur_le;

        return $this->derniere_activite_le !== null && $this->derniere_activite_par !== $role && $lu === null;
    }

    /** Les négociations d'une entreprise où l'autre partie attend d'être lue. */
    public function scopeNonLuesPour(Builder $requete, int $entrepriseId, string $role): Builder
    {
        $colonneEntreprise = $role === self::CLIENT ? 'entreprise_client_id' : 'entreprise_fournisseur_id';
        $colonneLu = $role === self::CLIENT ? 'lu_client_le' : 'lu_fournisseur_le';

        return $requete->where($colonneEntreprise, $entrepriseId)
            ->whereNotNull('derniere_activite_le')
            ->where('derniere_activite_par', '!=', $role)
            ->whereNull($colonneLu);
    }

    /** Le total des lignes, aux prix et quantités en cours de discussion. */
    public function totalHt(): float
    {
        return round(collect($this->produits_demandes ?? [])
            ->sum(fn ($l) => (float) ($l['quantite_confirmee'] ?? $l['quantite'] ?? 0) * (float) ($l['prix_propose'] ?? 0)), 2);
    }
}
