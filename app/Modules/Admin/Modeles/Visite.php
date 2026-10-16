<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;

/**
 * Une requête reçue par la plateforme (16/10/2026) : de quoi bâtir les KPI,
 * le graphe et l'activité par adresse IP. Horodatée par `cree_le`.
 */
class Visite extends Model
{
    protected $table = 'visites';
    public $timestamps = false;

    protected $fillable = [
        'ip', 'methode', 'chemin', 'statut', 'duree_ms',
        'utilisateur_id', 'entreprise_id', 'agent', 'session_id', 'cree_le',
    ];

    protected $casts = ['cree_le' => 'datetime'];

    public function utilisateur()
    {
        return $this->belongsTo(\App\Modules\Authentification\Modeles\Utilisateur::class, 'utilisateur_id');
    }
}
