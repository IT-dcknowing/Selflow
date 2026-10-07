<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BonLivraison extends Model
{
    use IdentifiantOpaque;

    protected $table = 'bons_livraison';

    protected $fillable = [
        'numero_bl',
        'vente_id',
        'facture_vente_id',
        'point_de_vente_id',
        'client_id',
        'created_by',
        'date_livraison',
        'statut',
        'livraison_partielle',
        'notes',
        // Le transport (chantier 15.3) : voir TransportLivraisonService.
        'adresse_livraison',
        'livreur_type',
        'livreur_utilisateur_id',
        'livreur_nom',
        'vehicule',
        'heure_depart',
        'heure_arrivee',
        'receptionnaire_nom',
        'receptionnaire_signature',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'date_livraison'     => 'date',
            'livraison_partielle'=> 'boolean',
            'heure_depart'       => 'datetime',
            'heure_arrivee'      => 'datetime',
        ];
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    /** Le Bon de Commande d'origine */
    public function bonDeCommande(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'vente_id');
    }

    /** La Facture générée depuis ce BL (nullable tant que pas facturé) */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'facture_vente_id');
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(BonLivraisonDetail::class, 'bon_livraison_id');
    }

    // ── Accesseurs ─────────────────────────────────────────────────────────────

    /** Libellé lisible du statut */
    public function getStatutLabelAttribute(): string
    {
        return match($this->statut) {
            'en_preparation' => 'En préparation',
            'partiel'        => 'Partiel',
            'livre'          => 'Livré',
            'facture'        => 'Facturé',
            default          => ucfirst($this->statut),
        };
    }

    /** Couleur badge statut */
    public function getStatutColorAttribute(): string
    {
        return match($this->statut) {
            'en_preparation' => '#f3f4f6|#374151',   // gris
            'partiel'        => '#fffbeb|#b45309',   // ambre
            'livre'          => '#e0f2fe|#0369a1',   // bleu
            'facture'        => '#e6fdf5|#047857',   // vert
            default          => '#f3f4f6|#374151',
        };
    }
}
