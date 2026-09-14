<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un point de facturation déclaré au portail FNE.
 *
 * Le tableur en rend un par ligne : un nom (« FACTURATION SIEGE »), l'outil qui
 * l'utilise (« Application FNE »), l'établissement auquel il appartient, et son
 * statut. L'identifiant de terminal reste vide pour un point servi par une
 * application — il ne se remplit que pour un matériel de caisse.
 */
class PortailFnePointFacturation extends Model
{
    protected $table = 'portail_fne_points_facturation';

    protected $fillable = [
        'import_id',
        'entreprise_id',
        'login',
        'date_scraping',
        'nom',
        'outil',
        'terminal_id',
        'statut',
        'raison_statut',
        'etablissement_id',
        'cree_a',
        'mis_a_jour_a',
    ];

    protected function casts(): array
    {
        return [
            'date_scraping' => 'date',
            'cree_a'        => 'datetime',
            'mis_a_jour_a'  => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PortailFneImport::class, 'import_id');
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    /**
     * Le portail rend « 1 » pour un point ouvert. Toute autre valeur est
     * rendue telle quelle par l'import, et n'est pas tenue pour active.
     */
    public function estActif(): bool
    {
        return (string) $this->statut === '1';
    }

    /**
     * Le dernier jeu de points relevé, pour un login ou une entreprise.
     *
     * **Le dernier jeu, et non ceux du dernier jour.** Un jeu est complet à son
     * import — `rangerPoints()` les écrit tous ou aucun —, et deux relevés
     * peuvent tomber le même jour : le passage nocturne, puis un clic sur
     * « Relever le portail maintenant ». Retenus par leur date, les deux jeux
     * s'empilaient, et l'écran montrait chaque point deux fois — constaté le
     * 31/08/2026, après deux relevés à une heure d'intervalle.
     *
     * L'import identifie le dépôt : c'est lui qui dit ce que le portail
     * déclarait la dernière fois qu'on l'a lu.
     *
     * @return array<int, self>
     */
    public static function dernierJeu(string $colonne, mixed $valeur): array
    {
        $dernier = self::where($colonne, $valeur)
            ->orderByDesc('date_scraping')
            ->orderByDesc('import_id')
            ->first();

        if ($dernier === null) {
            return [];
        }

        return self::where($colonne, $valeur)
            ->where('import_id', $dernier->import_id)
            ->orderBy('nom')
            ->get()
            ->all();
    }

    /**
     * L'identité d'un point de facturation, d'un relevé au suivant.
     *
     * **Ce n'est pas `etablissement_id` seul**, contrairement à ce qui était
     * écrit ici jusqu'au 31/08/2026 : le portail donne le même identifiant
     * d'établissement à **tous** les points d'un même établissement. Le relevé
     * réel de ce jour-là le montre — « FACTURATION TEST 2 » et
     * « FACTURATION SIEGE » portent tous deux `42200613-f402-40a8-bd4d-…`.
     * Les indexer là-dessus les faisait s'écraser l'un l'autre : un point
     * créé au portail n'entrait jamais en base, et le relevé qui l'apportait
     * se déclarait « inchangé ». C'est ainsi qu'un point de facturation créé
     * à 12 h 27 est resté invisible de Selflow.
     *
     * Ce qui distingue deux points d'un même établissement est leur **date de
     * création**, propre à chacun et stable quand l'intitulé change — un point
     * renommé reste donc le même point, ce qui était et reste l'intention. Le
     * nom ne sert qu'à défaut, quand le portail ne donne ni l'un ni l'autre.
     */
    public static function identite(?string $etablissement, ?string $creeA, ?string $nom): string
    {
        $etablissement = trim((string) $etablissement);
        $creeA         = trim((string) $creeA);

        if ($etablissement !== '' || $creeA !== '') {
            return $etablissement . '|' . $creeA;
        }

        return 'nom:' . trim((string) $nom);
    }

    /**
     * Ce que le portail a changé dans les points de facturation d'un login,
     * entre son dernier relevé et le précédent.
     *
     * La comparaison porte sur le **contenu lu**, jamais sur l'empreinte du
     * fichier : le tableur du portail embarque un horodatage de génération
     * (`dcterms:created`), et deux exports identiques peuvent donc différer
     * octet pour octet. Se fier aux octets annoncerait un changement chaque
     * nuit ; se fier au contenu ne dit quelque chose que quand il y en a un.
     *
     * L'identité d'un point est donnée par `identite()` — la paire
     * établissement et date de création, et non l'établissement seul, qui est
     * commun à tous les points d'un même établissement.
     *
     * @return array{
     *     apparus: array<int, self>,
     *     disparus: array<int, self>,
     *     modifies: array<int, array{point: self, avant: self, changements: array<int, string>}>
     * }
     */
    public static function changementsDepuisLePrecedent(string $login): array
    {
        $vide = ['apparus' => [], 'disparus' => [], 'modifies' => []];

        // Les deux derniers **jeux**, et non les deux derniers jours : deux
        // relevés du même jour — le passage nocturne, puis un clic sur
        // « Relever maintenant » — se mélangeaient en un seul jeu, et la
        // comparaison portait alors sur des points qui n'ont jamais coexisté.
        $jeux = self::where('login', $login)
            ->select('import_id')
            ->distinct()
            ->orderByDesc('import_id')
            ->limit(2)
            ->pluck('import_id');

        // Un seul relevé : rien à comparer, et surtout pas de quoi annoncer
        // que tous les points viennent d'apparaître.
        if ($jeux->count() < 2) {
            return $vide;
        }

        $indexer = fn ($points) => $points->keyBy(
            fn (self $p) => self::identite(
                $p->etablissement_id,
                $p->cree_a?->format('Y-m-d H:i:s'),
                $p->nom
            )
        );

        $maintenant = $indexer(self::where('login', $login)->where('import_id', $jeux[0])->get());
        $avant      = $indexer(self::where('login', $login)->where('import_id', $jeux[1])->get());

        $resultat = $vide;

        foreach ($maintenant as $cle => $point) {
            $ancien = $avant->get($cle);

            if (!$ancien) {
                $resultat['apparus'][] = $point;
                continue;
            }

            $changements = [];
            foreach (['nom' => 'nom', 'outil' => 'outil', 'terminal_id' => 'terminal',
                      'statut' => 'statut', 'raison_statut' => 'raison'] as $colonne => $libelle) {
                if ((string) ($ancien->{$colonne} ?? '') !== (string) ($point->{$colonne} ?? '')) {
                    $changements[] = sprintf(
                        '%s : « %s » → « %s »',
                        $libelle,
                        $ancien->{$colonne} ?? '—',
                        $point->{$colonne} ?? '—'
                    );
                }
            }

            if ($changements) {
                $resultat['modifies'][] = [
                    'point' => $point, 'avant' => $ancien, 'changements' => $changements,
                ];
            }
        }

        foreach ($avant as $cle => $point) {
            if (!$maintenant->has($cle)) {
                $resultat['disparus'][] = $point;
            }
        }

        return $resultat;
    }
}
