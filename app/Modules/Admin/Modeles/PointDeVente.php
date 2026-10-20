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
    }

    /**
     * Un code court tiré du nom — « PDV-Bondoukou » donne BON —, unique dans
     * l'entreprise : « PDV-Bongouanou », après Bondoukou, devient BNG.
     */
    public static function codeLibre(int $entrepriseId, string $nom, ?int $saufId = null): string
    {
        $propre = strtoupper(\Illuminate\Support\Str::ascii($nom));
        $propre = preg_replace('/\b(PDV|POINT|DE|DU|VENTE|SITE|MAGASIN|BOUTIQUE)\b/', ' ', $propre);
        $lettres = preg_replace('/[^A-Z0-9]/', '', (string) $propre);
        $base = substr($lettres !== '' ? $lettres : 'SITE', 0, 3);

        $pris = \Illuminate\Support\Facades\DB::table('points_de_vente')
            ->where('entreprise_id', $entrepriseId)
            ->when($saufId, fn ($q) => $q->where('id', '!=', $saufId))
            ->whereNotNull('code_piece')
            ->pluck('code_piece')->map(fn ($c) => strtoupper($c))->all();

        // D'abord les trois premières lettres (BON pour Bondoukou), puis la
        // première suivie des consonnes (BNG pour Bongouanou), puis un chiffre.
        $consonnes = preg_replace('/[AEIOUY0-9]/', '', substr($lettres, 1));
        $candidats = array_values(array_unique(array_filter([
            $base,
            strlen($consonnes) >= 2 ? substr($lettres, 0, 1) . substr($consonnes, 0, 2) : null,
            strlen($lettres) >= 3 ? substr($lettres, 0, 2) . substr($lettres, -1) : null,
        ])));
        foreach ($candidats as $code) {
            if (!in_array($code, $pris, true)) {
                return $code;
            }
        }

        for ($i = 2; in_array($base . $i, $pris, true); $i++) {
        }

        return $base . $i;
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
