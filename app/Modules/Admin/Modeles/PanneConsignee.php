<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Une panne vue en production, telle que le superadministrateur la suit.
 *
 * Une ligne par défaut (classe, fichier, ligne) : chaque nouvelle occurrence
 * l'incrémente. Elle quitte la liste quand elle est corrigée — à la main, ou
 * d'elle-même quand le fichier fautif a changé depuis (un correctif déployé).
 * Si elle revient, elle se rouvre.
 */
class PanneConsignee extends Model
{
    protected $table = 'pannes';

    protected $fillable = [
        'empreinte', 'code', 'type', 'message', 'fichier', 'ligne', 'url', 'methode',
        'utilisateur_id', 'entreprise_id', 'trace', 'empreinte_fichier', 'occurrences',
        'premiere_le', 'derniere_le', 'corrigee_le', 'corrigee_par',
    ];

    protected $casts = [
        'premiere_le' => 'datetime',
        'derniere_le' => 'datetime',
        'corrigee_le' => 'datetime',
    ];

    public function scopeOuvertes(Builder $requete): Builder
    {
        return $requete->whereNull('corrigee_le');
    }

    /**
     * Consigner une panne. Silencieux en cas d'échec : la base peut être
     * précisément ce qui ne répond pas, et la page de maintenance doit
     * s'afficher quand même.
     */
    public static function consigner(\Throwable $e, ?\Illuminate\Http\Request $requete = null): ?self
    {
        try {
            $fichier = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getFile());
            $empreinte = hash('sha256', $e::class . '|' . $fichier . '|' . $e->getLine());
            $contenu = is_file($e->getFile()) ? sha1_file($e->getFile()) : null;
            $utilisateur = $requete?->user();

            $panne = static::firstOrNew(['empreinte' => $empreinte]);
            $panne->fill([
                'code'              => \App\Exceptions\Panne::reference($e),
                'type'              => mb_substr($e::class, 0, 255),
                'message'           => mb_substr((string) $e->getMessage(), 0, 5000),
                'fichier'           => mb_substr($fichier, 0, 500),
                'ligne'             => $e->getLine(),
                'url'               => $requete ? mb_substr($requete->fullUrl(), 0, 2000) : null,
                'methode'           => $requete?->method(),
                'utilisateur_id'    => $utilisateur?->id,
                'entreprise_id'     => $utilisateur?->entreprise_id,
                'trace'             => mb_substr(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getTraceAsString()), 0, 20000),
                'empreinte_fichier' => $contenu,
                'derniere_le'       => now(),
                // Revenue après une correction : elle se rouvre.
                'corrigee_le'       => null,
                'corrigee_par'      => null,
            ]);
            $panne->premiere_le ??= now();
            $panne->occurrences = $panne->exists ? $panne->occurrences + 1 : 1;
            $panne->save();

            return $panne;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Les pannes dont le fichier fautif a changé depuis la dernière
     * occurrence sont tenues pour corrigées.
     */
    public static function fermerCellesCorrigeesParUnDeploiement(): int
    {
        $fermees = 0;

        foreach (static::ouvertes()->whereNotNull('empreinte_fichier')->get() as $panne) {
            $chemin = base_path($panne->fichier);
            $actuel = is_file($chemin) ? sha1_file($chemin) : null;

            if ($actuel !== $panne->empreinte_fichier) {
                $panne->update(['corrigee_le' => now(), 'corrigee_par' => 'Correctif déployé (fichier modifié)']);
                $fermees++;
            }
        }

        return $fermees;
    }

    public function utilisateur()
    {
        return $this->belongsTo(\App\Modules\Authentification\Modeles\Utilisateur::class, 'utilisateur_id');
    }

    public function entreprise()
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }
}
