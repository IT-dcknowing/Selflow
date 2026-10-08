<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Authentification\Modeles\Utilisateur;

class FneCredential extends Model
{
    protected $table = 'fne_credentials';

    protected $fillable = [
        'entreprise_id',
        'cle_test',
        'cle_test_ajoutee_at',
        'cle_test_ajoutee_par',
        'cle_reelle',
        'cle_reelle_ajoutee_at',
        'cle_reelle_ajoutee_par',
        'statut',
        'derniere_verification_resultat',
        'derniere_verification_at',
        'ncc_associe',
        'notes_superadmin',
        // L'accès à l'espace FNE de l'entreprise, fourni par elle une fois, à
        // la création de son compte. Chiffré, jamais rendu à l'écran.
        'acces_mot_de_passe',
        'acces_fourni_at',
    ];

    protected function casts(): array
    {
        return [
            // Chiffrement automatique au repos (AES-256-CBC, clé = APP_KEY).
            // Ecriture : Eloquent chiffre avant l'INSERT/UPDATE.
            // Lecture  : Eloquent déchiffre automatiquement à l'accès à la propriété.
            // Sans APP_KEY (jamais commité, uniquement dans .env serveur), les
            // valeurs stockées en base sont totalement inexploitables.
            'cle_test'               => 'encrypted',
            'cle_reelle'             => 'encrypted',
            // Meme traitement que les cles : c'est un acces a un service de
            // l'Etat, pas un reglage.
            'acces_mot_de_passe'     => 'encrypted',
            'acces_fourni_at'        => 'datetime',
            'cle_test_ajoutee_at'    => 'datetime',
            'cle_reelle_ajoutee_at'  => 'datetime',
            'derniere_verification_at' => 'datetime',
        ];
    }

    /**
     * Empêche toute sérialisation accidentelle des clés en clair (ex: si le
     * modèle est un jour renvoyé dans une réponse JSON par erreur).
     */
    protected $hidden = ['cle_test', 'cle_reelle', 'acces_mot_de_passe'];

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function ajouteeParTest(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cle_test_ajoutee_par');
    }

    public function ajouteeParReelle(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cle_reelle_ajoutee_par');
    }

    /**
     * La clé actuellement active pour les appels DGI : la clé réelle si le
     * statut est 'validee', sinon la clé test.
     */
    public function cleActive(): ?string
    {
        if ($this->statut === 'validee' && !empty($this->cle_reelle)) {
            return $this->cle_reelle;
        }
        return $this->cle_test;
    }

    public function estConfiguree(): bool
    {
        return !empty($this->cle_test) || !empty($this->cle_reelle);
    }

    /**
     * Libellé lisible du statut, utilisé côté Admin (jamais la clé elle-même).
     */
    public function statutLabel(): string
    {
        return match ($this->statut) {
            'validee'  => 'Clé réelle (production) connectée',
            'test'     => 'Clé de test connectée',
            default    => 'Non connecté',
        };
    }

    /**
     * Masque une clé pour affichage : garde les 4 premiers et 4 derniers
     * caractères, remplace le reste par des points de suspension.
     * ex: "fne_test_8f2a...9c31"
     */
    public static function masquer(?string $cle): string
    {
        if (empty($cle)) return '—';
        $len = strlen($cle);
        if ($len <= 10) {
            return substr($cle, 0, 2) . '...' . substr($cle, -2);
        }
        return substr($cle, 0, 6) . '...' . substr($cle, -4);
    }
}
