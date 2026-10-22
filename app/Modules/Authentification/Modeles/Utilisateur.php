<?php

namespace App\Modules\Authentification\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Utilisateur extends Authenticatable
{
    use IdentifiantOpaque;

    use Notifiable;

    protected $table = 'utilisateurs';

    protected $fillable = [
        'entreprise_id',
        'point_de_vente_id',
        'nom',
        'prenom',
        'email',
        'avatar_path',
        'password',
        'role',
        'fonction',
        'date_debut_contrat',
        'date_fin_contrat',
        'statut',
        'visite_guidee_terminee_le',
        'notes',
        'habilitations',
        'jeton_api',
        'doit_changer_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Jamais sérialisé : un `response()->json($utilisateur)` le livrerait.
        'jeton_api',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'habilitations'         => 'array',
            'doit_changer_password' => 'boolean',
            'visite_guidee_terminee_le' => 'datetime',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Admin\Modeles\Entreprise::class, 'entreprise_id');
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Admin\Modeles\PointDeVente::class, 'point_de_vente_id');
    }

    /**
     * Le point de vente sur lequel cet utilisateur travaillait en dernier.
     *
     * Distinct de `point_de_vente_id`, qui est l'**affectation** d'un caissier
     * — décidée par son responsable, et qu'un changement d'écran ne doit pas
     * toucher. Celui-ci est une préférence, et il survit à la déconnexion : le
     * choix ne vivait qu'en session, que `session()->invalidate()` efface. Un
     * responsable de trois magasins repartait donc chaque matin sur le premier
     * venu, sans que rien ne le dise.
     */
    public function pointDeVenteActif(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Admin\Modeles\PointDeVente::class, 'point_de_vente_actif_id');
    }

    /**
     * Retenir le point de vente choisi, pour la prochaine fois.
     *
     * Écrit hors affectation en masse : la valeur ne vient jamais d'un
     * formulaire, seulement d'un choix explicite fait à l'écran.
     */
    public function retenirLePointDeVente(int $pointDeVenteId): void
    {
        $this->point_de_vente_actif_id = $pointDeVenteId;
        $this->save();
    }

    /**
     * Sur quel point de vente cet utilisateur doit-il ouvrir sa journée ?
     *
     * Un caissier n'a pas le choix : son affectation prime, sans quoi il
     * encaisserait au nom d'un magasin où il n'est pas.
     */
    public function pointDeVenteDOuverture(): ?int
    {
        if ($this->estAffecteAUnSite()) {
            return $this->point_de_vente_id;
        }

        return $this->point_de_vente_actif_id ?? $this->point_de_vente_id;
    }

    /**
     * Ce membre est-il lié à son point de vente (19/10/2026) ?
     *
     * Le caissier et le responsable de point de vente travaillent sur le site
     * où on les a affectés, et sur lui seul : leurs entrées en stock, leurs
     * encaissements, leurs ventes concernent ce site. L'administrateur
     * principal et l'administrateur secondaire, eux, choisissent le site
     * qu'ils activent.
     */
    public function estAffecteAUnSite(): bool
    {
        return $this->point_de_vente_id !== null
            && in_array($this->role, ['caissier', 'responsable_pdv'], true);
    }

    public function estSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function estAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function estAdminSecondaire(): bool
    {
        return $this->role === 'admin_secondaire';
    }

    public function estResponsablePdv(): bool
    {
        return $this->role === 'responsable_pdv';
    }

    public function estCaissier(): bool
    {
        return $this->role === 'caissier';
    }

    /**
     * Les accès délégués : le même espace, mais pas les mêmes droits.
     *
     * Un administrateur qui veut confier son travail ne crée pas un second
     * propriétaire — il ouvre un accès à **son** espace, et choisit ce que la
     * personne y voit. Ces rôles franchissent donc `role:admin`, et ce sont
     * les habilitations qui tranchent ensuite, écran par écran.
     */
    public const ROLES_DELEGUES = ['admin_secondaire', 'responsable_pdv'];

    /** Les rôles, tels qu'ils s'écrivent à l'écran. */
    public const LIBELLES_ROLES = [
        'superadmin'       => 'Superadministrateur',
        'admin'            => 'Administrateur',
        'admin_secondaire' => 'Admin secondaire',
        'responsable_pdv'  => 'Responsable PDV',
        'caissier'         => 'Caissier',
    ];

    /**
     * Le poste de la personne : la fonction saisie au personnel quand elle
     * existe (« Gérante », « Comptable »…), sinon son rôle dans Selflow.
     */
    public function poste(): string
    {
        $role = self::LIBELLES_ROLES[$this->role] ?? ucfirst((string) $this->role);

        return filled($this->fonction) ? $this->fonction . ' (' . $role . ')' : $role;
    }

    /**
     * Cet utilisateur travaille-t-il dans l'espace d'administration ?
     *
     * Le propriétaire et ses délégués, oui. La différence entre eux ne se joue
     * pas ici mais dans `aHabilitation()`.
     */
    public function partageLEspaceAdmin(): bool
    {
        return $this->estAdmin() || in_array($this->role, self::ROLES_DELEGUES, true);
    }

    /**
     * Vérifier si l'utilisateur a l'habilitation pour une page spécifique.
     *
     * **`admin_secondaire` recevait `true` pour tout**, au même titre que le
     * propriétaire. Déléguer revenait donc à céder l'entreprise entière : le
     * compte créé « pour aider aux ventes » atteignait la comptabilité, les
     * paramètres fiscaux, et l'écran qui distribue les droits — d'où il
     * pouvait s'en accorder d'autres, ou en retirer au propriétaire.
     *
     * Seuls le superadministrateur et le propriétaire ont tout. Un délégué a
     * ce qu'on lui a donné, et rien de plus. Un compte sans habilitation ne
     * voit donc rien tant que son administrateur n'a pas coché : c'est le sens
     * d'un contrôle qui ferme par défaut.
     */
    public function aHabilitation(string $page): bool
    {
        if ($this->estSuperAdmin() || $this->estAdmin()) {
            return true;
        }

        return is_array($this->habilitations) && in_array($page, $this->habilitations);
    }

    /**
     * L'image du profil, ou les initiales quand il n'y en a pas d'affichable.
     *
     * Le retest de production montrait une image cassée à qui n'avait chargé
     * aucune photo. Trois causes : la connexion Google enregistre l'adresse
     * de la photo Google, que la politique de sécurité (`img-src 'self'`)
     * refuse ; un fichier effacé du disque laissait une adresse en 404 ; et
     * une initiale accentuée, coupée par `substr` au milieu de son octet,
     * rendait le dessin illisible.
     */
    public function getAvatarUrlAttribute(): string
    {
        $chemin = trim((string) $this->avatar_path);

        if ($chemin !== '' && ! preg_match('#^https?://#i', $chemin)) {
            try {
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($chemin)) {
                    $url = \App\Modules\Admin\Services\FichierPublic::url($chemin);
                    if ($url) {
                        return $url;
                    }
                }
            } catch (\Throwable) {
                // Disque injoignable : les initiales valent mieux qu'une page en erreur.
            }
        }

        return $this->avatar_repli;
    }

    /** Les initiales dessinées, servies en ligne : elles ne peuvent pas manquer. */
    public function getAvatarRepliAttribute(): string
    {
        return 'data:image/svg+xml;utf8,' . rawurlencode($this->genererAvatarSvg());
    }

    public function genererAvatarSvg(): string
    {
        $initiale = fn ($texte) => mb_strtoupper(mb_substr(trim((string) $texte), 0, 1));
        $initials = $initiale($this->prenom) . $initiale($this->nom);
        if ($initials === '') {
            $initials = 'U';
        }
        $initials = htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128"><rect width="128" height="128" fill="#002B5C"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-family="system-ui, -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif" font-size="48" font-weight="800" fill="#FFFFFF">' . $initials . '</text></svg>';
    }

    /**
     * A-t-il déjà fait la visite guidée ?
     *
     * Elle se retient par utilisateur : un vendeur qui rejoint une entreprise
     * déjà configurée découvre l'application pour la première fois, lui aussi.
     */
    public function aFaitLaVisite(): bool
    {
        return $this->visite_guidee_terminee_le !== null;
    }
}
