<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointDeVente extends Model
{
    use IdentifiantOpaque;

    protected $table = 'points_de_vente';

    protected static function booted(): void
    {
        // Le compte mémorisé par `Entreprise::nombreDePointsDeVente()`.
        $oublier = fn () => Entreprise::oublierLesPointsDeVente();
        static::created($oublier);
        static::deleted($oublier);

        // Le code des numéros de pièce (20/10/2026).
        static::creating(function (PointDeVente $site) {
            if (blank($site->code_piece) && $site->entreprise_id) {
                $site->code_piece = self::codeLibre((int) $site->entreprise_id, (string) $site->nom);
            }
        });

        // La clé d'identité suit le nom (20/10/2026) : l'index unique refuse
        // deux sites de même clé dans une entreprise.
        static::saving(function (PointDeVente $site) {
            if ($site->isDirty('nom') || blank($site->cle_nom)) {
                $site->cle_nom = self::empreinteDuNom((string) $site->nom);
            }
        });
    }

    /**
     * Le nom ramené à l'essentiel : majuscules, lettres et chiffres seuls,
     * sans les mots qui ne distinguent rien (« PDV », « point de vente »).
     * « PDV-Guédé  Aya » → GUÉDÉAYA.
     *
     * **Les accents restent** : pour la DGI, « FACTURATION SIEGE » et
     * « FACTURATION SIÈGE » sont deux noms, et la correction d'un rejet crée
     * à dessein le point au nom exact du portail.
     */
    public static function cleDuNom(string $nom): string
    {
        $propre = mb_strtoupper($nom);
        $propre = preg_replace('/\b(PDV|POINT\s+DE\s+VENTE|POINT\s+DE\s+FACTURATION)\b/u', ' ', $propre);
        $cle = preg_replace('/[^\p{L}\p{N}]/u', '', (string) $propre);

        return $cle !== '' ? $cle : 'SITE';
    }

    /**
     * L'empreinte de la clé, stockée dans `cle_nom` sous index unique. Une
     * empreinte, et non la clé : la collation de MySQL ignore accents et
     * casse, et y confondrait « SIEGE » et « SIÈGE ».
     */
    public static function empreinteDuNom(string $nom): string
    {
        return sha1(self::cleDuNom($nom));
    }

    /** La clé sans accents, pour repérer les noms seulement proches. */
    private static function cleSansAccents(string $nom): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(\Illuminate\Support\Str::ascii(self::cleDuNom($nom))));
    }

    /**
     * Un site de l'entreprise au nom identique ou très proche — une lettre
     * inversée, une lettre de moins : « GEUDE AYA JAQUELINE » et
     * « GUEDE AYA JACQUELINE ». `null` si aucun.
     */
    public static function procheDe(int $entrepriseId, string $nom, ?int $saufId = null): ?self
    {
        $exacte = self::cleDuNom($nom);
        $cle = self::cleSansAccents($nom);
        $tolerance = max(1, (int) floor(strlen($cle) / 5));

        return self::where('entreprise_id', $entrepriseId)
            ->when($saufId, fn ($q) => $q->where('id', '!=', $saufId))
            ->get()
            ->first(function (self $site) use ($exacte, $cle, $tolerance) {
                if (self::cleDuNom((string) $site->nom) === $exacte) {
                    return true;
                }
                $autre = self::cleSansAccents((string) $site->nom);
                if ($autre === $cle) {
                    return true;
                }
                // « Boutique 1 » et « Boutique 2 » sont deux sites : des
                // numéros différents ne font pas une faute de frappe.
                if (preg_replace('/\D/', '', $autre) !== preg_replace('/\D/', '', $cle)) {
                    return false;
                }

                return levenshtein($autre, $cle) <= $tolerance;
            });
    }

    /**
     * Le code du site dans les numéros de pièce : un numéro d'ordre dans
     * l'entreprise — 01, 02, 03… (décision du propriétaire, 20/10/2026).
     * Les lettres tirées du nom (BON, BNG) se devinaient mal dès que deux
     * sites commençaient pareil. Un code donné ne se réattribue pas : le
     * suivant prend le plus grand plus un, même après une suppression.
     */
    public static function codeLibre(int $entrepriseId, string $nom = '', ?int $saufId = null): string
    {
        $pris = \Illuminate\Support\Facades\DB::table('points_de_vente')
            ->where('entreprise_id', $entrepriseId)
            ->when($saufId, fn ($q) => $q->where('id', '!=', $saufId))
            ->whereNotNull('code_piece')
            ->pluck('code_piece')
            ->filter(fn ($c) => ctype_digit((string) $c))
            ->map(fn ($c) => (int) $c);

        return str_pad((string) (($pris->max() ?? 0) + 1), 2, '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'entreprise_id',
        'nom',
        'ville',
        'commune',
        'responsable',
        'telephone',
        'statut',

        // D'où vient ce point de vente, quand il vient d'un relevé du portail
        // FNE. Ni l'un ni l'autre ne part à la DGI : ils servent à reconnaître
        // le même point au relevé suivant, même renommé.
        //
        // L'établissement seul ne suffit pas — le portail le donne identique à
        // tous les points d'un même établissement (relevé réel du 31/08/2026).
        // C'est la paire qui identifie.
        'etablissement_fne_id',
        'point_fne_cree_a',
    ];

    protected function casts(): array
    {
        return [
            // Lue comme une date, elle se compare à celle du relevé sans passer
            // par le format qu'un tableur a bien voulu écrire.
            'point_fne_cree_a' => 'datetime',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class, 'point_de_vente_id');
    }

    public function achats(): HasMany
    {
        return $this->hasMany(Achat::class, 'point_de_vente_id');
    }

    public function mouvementsStock(): HasMany
    {
        return $this->hasMany(MouvementStock::class, 'point_de_vente_id');
    }

    public function tresorerieJournal(): HasMany
    {
        return $this->hasMany(TresorerieJournal::class, 'point_de_vente_id');
    }

    public function utilisateurs(): HasMany
    {
        return $this->hasMany(\App\Modules\Authentification\Modeles\Utilisateur::class, 'point_de_vente_id');
    }

    /**
     * Ouvre une fiche de stock vide, sur ce site, pour chaque article qui se
     * compte.
     *
     * Vivait dans `PointDeVenteControleur::creer()`. Un second endroit crée
     * désormais des points de vente — l'import des points déclarés au portail
     * FNE —, et un site né sans fiches se serait comporté autrement qu'un site
     * créé à la main, sans que rien ne le dise. Le journal garde le précédent
     * de la liste des modules socle qui vivait en double et avait perdu
     * `points_de_vente` des deux côtés.
     *
     * Un service n'a ni quantité disponible, ni seuil, ni rupture ; un article
     * archivé ne se vendra jamais sur un site qui vient d'ouvrir.
     */
    public function initialiserLesFichesDeStock(): void
    {
        // Deux requêtes par article — chercher la fiche, puis la créer — et le
        // bouton « Créer » restait figé sans rien dire : 1 000 requêtes pour un
        // catalogue de 500 articles, 20 000 pour 10 000 (chantier 9.3, lot 43).
        // Une lecture des fiches déjà posées, puis des insertions par paquets.
        $existantes = Stock::where('point_de_vente_id', $this->id)
            ->pluck('produit_id')
            ->flip();

        $maintenant = now();

        Produit::where('entreprise_id', $this->entreprise_id)
            ->selectionnables()
            ->stockables()
            ->pluck('id')
            ->reject(fn ($id) => $existantes->has($id))
            ->chunk(500)
            ->each(fn ($paquet) => Stock::insert($paquet->map(fn ($id) => [
                'produit_id'          => $id,
                'point_de_vente_id'   => $this->id,
                'quantite_disponible' => 0,
                'stock_minimum'       => 5,
                'stock_maximum'       => 100,
                'created_at'          => $maintenant,
                'updated_at'          => $maintenant,
            ])->values()->all()));
    }
}
