<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle pour la table journal_audit.
 * Cette table est en INSERTION UNIQUEMENT - jamais modifiable ni supprimable par un utilisateur.
 */
class JournalAudit extends Model
{
    protected $table = 'journal_audit';

    // Pas de updated_at (insert only)
    public const UPDATED_AT = null;

    protected $fillable = [
        'utilisateur_id',
        'entreprise_id',
        'action',
        'entite',
        'entite_id',
        'ancienne_valeur',
        'nouvelle_valeur',
        'adresse_ip',
        'point_de_vente_id',
    ];

    protected $casts = [
        'ancienne_valeur' => 'array',
        'nouvelle_valeur' => 'array',
        'created_at'      => 'datetime',
    ];

    public function utilisateur()
    {
        return $this->belongsTo(\App\Modules\Authentification\Modeles\Utilisateur::class, 'utilisateur_id');
    }

    public function entreprise()
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    /** Un libellé lisible pour l'action (« creation_vente » → « Création — Vente »). */
    public function libelleAction(): string
    {
        $cle = (string) $this->action;
        $verbes = [
            'creation' => 'Création', 'modification' => 'Modification', 'suppression' => 'Suppression',
            'annulation' => 'Annulation', 'connexion' => 'Connexion', 'deconnexion' => 'Déconnexion',
            'normalisation' => 'Normalisation', 'mise' => 'Mise en corbeille', 'restauration' => 'Restauration',
            'effacement' => 'Effacement', 'blocage' => 'Blocage', 'deblocage' => 'Déblocage',
            'vidage' => 'Vidage', 'facturation' => 'Facturation', 'deversement' => 'Déversement',
        ];
        $premier = explode('_', $cle)[0];
        $verbe = $verbes[$premier] ?? ucfirst(str_replace('_', ' ', $cle));

        return $this->entite ? $verbe . ' — ' . $this->entite : $verbe;
    }
}
