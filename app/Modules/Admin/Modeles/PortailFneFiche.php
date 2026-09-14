<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La fiche d'une entreprise telle que le portail FNE l'affiche, à une date.
 *
 * C'est un **constat**, pas un paramétrage : ces valeurs ne pilotent rien dans
 * Selflow. Les comparer à celles de l'entreprise est le rôle de qui les lit.
 */
class PortailFneFiche extends Model
{
    protected $table = 'portail_fne_fiches';

    /**
     * Les champs suivis d'un relevé à l'autre, et leur nom lisible.
     *
     * Le libellé n'est pas une décoration : le constat est lu par quelqu'un qui
     * n'a pas le schéma sous les yeux, et « bapa » ne dit rien là où
     * « Bordereau d'achat de produits agricoles » dit tout.
     */
    public const CHAMPS_SUIVIS = [
        'email'                   => 'Email',
        'telephone'               => 'Téléphone',
        'adresse'                 => 'Adresse',
        'commune'                 => 'Commune',
        'quartier'                => 'Quartier',
        'reference_cadastrale'    => 'Référence cadastrale',
        'idu'                     => 'IDU',
        'proprietaire_local'      => 'Propriétaire du local',
        'ref_bancaire'            => 'Références bancaires',
        'sticker_solde_alerte'    => "Sticker : solde d'alerte",
        'timbre_quittance'        => 'Timbre de quittance',
        'bapa'                    => "Bordereau d'achat de produits agricoles",
        'pied_de_page_facture'    => 'Pied de page des factures',
        'facture_autres_mentions' => 'Factures autres mentions',
    ];

    protected $fillable = [
        'import_id',
        'entreprise_id',
        'login',
        'date_scraping',
        'email',
        'telephone',
        'adresse',
        'commune',
        'quartier',
        'reference_cadastrale',
        'idu',
        'proprietaire_local',
        'ref_bancaire',
        'sticker_solde_alerte',
        'timbre_quittance',
        'bapa',
        'pied_de_page_facture',
        'facture_autres_mentions',
        'champs_inconnus',
    ];

    protected function casts(): array
    {
        return [
            'date_scraping'        => 'date',
            'sticker_solde_alerte' => 'integer',
            'timbre_quittance'     => 'boolean',
            'bapa'                 => 'boolean',
            'champs_inconnus'      => 'array',
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
     * Les champs où le portail et l'entreprise paramétrée dans Selflow ne
     * disent pas la même chose.
     *
     * Rendu sous la forme `champ => ['portail' => …, 'selflow' => …]`. Aucune
     * écriture : le rapprochement se regarde, il ne s'applique pas tout seul —
     * `timbre_quittance` et `bapa` changent ce qui part à la DGI.
     *
     * @return array<string, array{portail: mixed, selflow: mixed}>
     */
    public function ecartsAvecEntreprise(): array
    {
        $entreprise = $this->entreprise;

        if (!$entreprise) {
            return [];
        }

        $champs = [
            'email',
            'telephone',
            'adresse',
            'commune',
            'quartier',
            'reference_cadastrale',
            'idu',
            'proprietaire_local',
            'ref_bancaire',
            'sticker_solde_alerte',
            'timbre_quittance',
            'bapa',
            'pied_de_page_facture',
            'facture_autres_mentions',
        ];

        $ecarts = [];

        foreach ($champs as $champ) {
            $portail = $this->{$champ};

            // Un champ que le portail n'a pas rendu ne prouve rien : il ne
            // constitue pas un écart avec ce que Selflow a enregistré.
            if ($portail === null || $portail === '') {
                continue;
            }

            $selflow = $entreprise->{$champ} ?? null;

            if ((string) $portail !== (string) $selflow) {
                $ecarts[$champ] = ['portail' => $portail, 'selflow' => $selflow];
            }
        }

        return $ecarts;
    }

    /**
     * Le relevé qui précède celui-ci, pour le même login.
     *
     * Par `login` et non par `entreprise_id` : un relevé arrivé avant que
     * l'entreprise n'existe dans Selflow n'est rattaché qu'à son login, et il
     * vaut mieux comparer à un relevé orphelin qu'à rien.
     *
     * `id` départage à égalité de date : deux relevés du même jour existent —
     * un passage de nuit et un passage déclenché par un rejet du matin.
     */
    public function precedente(): ?self
    {
        return self::where('login', $this->login)
            ->where(function ($q) {
                $q->where('date_scraping', '<', $this->date_scraping)
                    ->orWhere(function ($q) {
                        $q->where('date_scraping', $this->date_scraping)
                            ->where('id', '<', $this->id);
                    });
            })
            ->orderByDesc('date_scraping')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Ce que le portail a changé depuis le relevé précédent.
     *
     * `ecartsAvecEntreprise()` répond à « le portail et Selflow disent-ils la
     * même chose ». Celle-ci répond à une autre question, qu'aucune méthode ne
     * traitait : **« quelqu'un a-t-il touché au portail depuis la dernière
     * fois ? »** Sans elle, un timbre de quittance désactivé au portail un
     * mardi soir n'apparaissait nulle part tant qu'une facture n'était pas
     * refusée.
     *
     * Un champ qui passe à vide **compte comme un changement**, contrairement
     * au rapprochement avec l'entreprise. Ici les deux valeurs viennent du même
     * portail, lues par le même scraper : une valeur qui disparaît est soit un
     * changement réel, soit un défaut d'extraction — et les deux méritent d'être
     * vues.
     *
     * @return array<int, array{champ: string, libelle: string, avant: mixed, apres: mixed}>
     */
    public function ecartsAvecPrecedente(): array
    {
        $precedente = $this->precedente();

        // Premier relevé de ce login : il n'y a rien à comparer, et surtout pas
        // de quoi annoncer quatorze changements.
        if (!$precedente) {
            return [];
        }

        $ecarts = [];

        foreach (self::CHAMPS_SUIVIS as $champ => $libelle) {
            $avant = $precedente->{$champ};
            $apres = $this->{$champ};

            // Comparaison sur la chaîne, les colonnes étant typées
            // différemment : `null` et `''` désignent tous deux « le portail
            // n'a rien », et ne doivent pas se signaler l'un l'autre.
            if ((string) ($avant ?? '') === (string) ($apres ?? '')) {
                continue;
            }

            $ecarts[] = [
                'champ'   => $champ,
                'libelle' => $libelle,
                'avant'   => $avant,
                'apres'   => $apres,
            ];
        }

        return $ecarts;
    }
}
