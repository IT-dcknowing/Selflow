<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Selflow sur mesure (23/11/2026) : l'adresse, l'identité visuelle et les
 * modules propres à une entreprise. Une seule application, une seule base ;
 * l'adresse par laquelle on arrive choisit l'habillage.
 */
class EspaceSurMesure extends Model
{
    protected $table = 'espaces_sur_mesure';

    /** Les modules activables, dans l'ordre de la page du superadministrateur. */
    public const MODULES = [
        'accueil'   => ['Page d\'accueil personnalisée', 'Ce que voit un visiteur à l\'adresse de l\'entreprise.'],
        'connexion' => ['Connexion personnalisée', 'La page de connexion à ses couleurs, réservée à ses utilisateurs.'],
        'couleurs'  => ['Couleurs et logo dans l\'application', 'Le menu, les boutons et le logo de l\'application une fois connecté.'],
    ];

    public const MODELES_ACCUEIL = [
        'horizon'    => 'Horizon : texte à gauche, visuel à droite',
        'immersion'  => 'Immersion : grande image de fond, texte centré',
        'epure'      => 'Épure : sobre, blanc, typographie',
        'sur_mesure' => 'Sur mesure : page dessinée pour l\'entreprise',
    ];

    public const MODELES_CONNEXION = [
        'panneau'    => 'Panneau : visuel à gauche, formulaire à droite',
        'plein_ecran' => 'Plein écran : image de fond, carte centrée',
        'carte'      => 'Carte : fond uni, carte centrée',
    ];

    protected $fillable = [
        'entreprise_id', 'actif', 'sous_domaine', 'domaine', 'domaine_verifie_le', 'modules',
        'couleur_principale', 'couleur_secondaire', 'logo', 'logo_blanc', 'icone', 'image_fond',
        'modele_accueil', 'modele_connexion', 'titre_accueil', 'sous_titre_accueil', 'presentation',
        'atouts', 'message_connexion', 'telephone', 'email', 'adresse',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'modules' => 'array',
            'atouts' => 'array',
            'domaine_verifie_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // La correspondance adresse → espace est gardée en cache : elle est
        // lue à chaque requête, elle ne doit rien coûter. Toute modification
        // l'efface.
        static::saved(fn () => self::oublierLesAdresses());
        static::deleted(fn () => self::oublierLesAdresses());
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    public function moduleActif(string $module): bool
    {
        return $this->actif && in_array($module, (array) ($this->modules ?? []), true);
    }

    /** Le domaine racine sous lequel se créent les sous-domaines. */
    public static function domaineRacine(): string
    {
        return strtolower((string) config('selflow.sur_mesure.domaine_racine', 'selflow.ci'));
    }

    /** Les adresses complètes de l'espace. */
    public function adresses(): array
    {
        return array_values(array_filter([
            $this->sous_domaine ? $this->sous_domaine . '.' . self::domaineRacine() : null,
            $this->domaine ?: null,
        ]));
    }

    public function adressePrincipale(): ?string
    {
        $adresses = $this->adresses();

        return $adresses ? 'https://' . end($adresses) : null;
    }

    /** L'espace de la requête en cours (posé par `ReconnaitreEspaceSurMesure`). */
    public static function courant(): ?self
    {
        return app()->bound('espace.sur_mesure') ? app('espace.sur_mesure') : null;
    }

    /** L'espace qui répond à cette adresse, ou rien. */
    public static function pourHote(?string $hote): ?self
    {
        $hote = strtolower(trim((string) $hote));
        $hote = preg_replace('/^www\./', '', $hote);
        if ($hote === '' || $hote === self::domaineRacine()) {
            return null;
        }

        $version = (int) Cache::get('espaces_sur_mesure_version', 0);
        $id = Cache::rememberForever('espace_hote_' . $version . '_' . md5($hote), function () use ($hote) {
            try {
                $racine = '.' . self::domaineRacine();
                $requete = self::query()->where('actif', true)->where(function ($q) use ($hote, $racine) {
                    $q->where('domaine', $hote);
                    if (str_ends_with($hote, $racine)) {
                        $q->orWhere('sous_domaine', substr($hote, 0, -strlen($racine)));
                    }
                });

                return (int) ($requete->value('id') ?? 0);
            } catch (\Throwable) {
                // Table absente tant que la migration n'est pas jouée : Selflow
                // reste Selflow, rien ne tombe.
                return 0;
            }
        });

        return $id ? self::find($id) : null;
    }

    public static function oublierLesAdresses(): void
    {
        Cache::forever('espaces_sur_mesure_version', (int) Cache::get('espaces_sur_mesure_version', 0) + 1);
    }

    /** L'adresse publique d'une image de l'espace. */
    public function media(?string $champ): ?string
    {
        $fichier = $champ ? $this->{$champ} : null;

        return $fichier ? route('espace.media', ['espace' => $this->id, 'fichier' => basename($fichier)]) : null;
    }

    /** Une couleur éclaircie, pour les fonds. */
    public function teinte(string $couleur, float $part = 0.9): string
    {
        $hex = ltrim($couleur, '#');
        [$r, $v, $b] = array_map('hexdec', str_split(strlen($hex) === 6 ? $hex : '1d4ed8', 2));
        $m = fn ($c) => (int) round($c + (255 - $c) * $part);

        return sprintf('#%02x%02x%02x', $m($r), $m($v), $m($b));
    }

    /** Une couleur assombrie, pour les survols. */
    public function ombre(string $couleur, float $part = 0.2): string
    {
        $hex = ltrim($couleur, '#');
        [$r, $v, $b] = array_map('hexdec', str_split(strlen($hex) === 6 ? $hex : '1d4ed8', 2));
        $m = fn ($c) => (int) round($c * (1 - $part));

        return sprintf('#%02x%02x%02x', $m($r), $m($v), $m($b));
    }
}
