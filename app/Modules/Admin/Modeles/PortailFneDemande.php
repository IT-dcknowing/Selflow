<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une demande de relevé adressée au scraper.
 *
 * ## Le contrat, et pourquoi il est aussi maigre
 *
 * Selflow ne va pas sur le portail : il dépose ici « il me faudrait un relevé
 * frais pour le login X », et le scraper — quel qu'il soit, script lancé à la
 * main ou tâche planifiée — vient lire cette file. Le contrat tient en deux
 * champs, `login` et `motif`, parce que le scraper ne connaît ni les
 * entreprises de Selflow, ni ses pièces, ni ses rejets.
 *
 * ## Ce qui ferme une demande
 *
 * **L'arrivée d'un relevé, jamais la parole du scraper.** Une demande passe à
 * `servie` quand `ImportPortailFneService` range un fichier portant ce login à
 * une date au moins aussi récente que la demande. Un scraper qui échoue en
 * silence laisse donc sa demande ouverte, ce qui est exactement ce qu'on veut
 * voir.
 */
class PortailFneDemande extends Model
{
    protected $table = 'portail_fne_demandes';

    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_SERVIE     = 'servie';
    public const STATUT_ABANDONNEE = 'abandonnee';

    protected $fillable = [
        'entreprise_id',
        'login',
        'motif',
        'rejet_id',
        'statut',
        'import_id',
        'servie_at',
    ];

    protected function casts(): array
    {
        return ['servie_at' => 'datetime'];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function rejet(): BelongsTo
    {
        return $this->belongsTo(FneRejet::class, 'rejet_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PortailFneImport::class, 'import_id');
    }

    /**
     * Demande un relevé pour un login, sans en empiler deux.
     *
     * Dix factures rejetées d'affilée pour le même point de vente mal orthographié
     * décrivent un seul problème et appellent un seul relevé. Sans ce
     * dédoublonnage, une soirée de saisie remplirait la file de demandes
     * identiques que le scraper servirait une à une, pour rien.
     */
    public static function pour(
        string $login,
        ?string $motif = null,
        ?int $entrepriseId = null,
        ?int $rejetId = null
    ): ?self {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        $enCours = self::where('login', $login)
            ->where('statut', self::STATUT_EN_ATTENTE)
            ->first();

        if ($enCours) {
            return $enCours;
        }

        return self::create([
            'entreprise_id' => $entrepriseId,
            'login'         => $login,
            'motif'         => $motif,
            'rejet_id'      => $rejetId,
            'statut'        => self::STATUT_EN_ATTENTE,
        ]);
    }

    public function servir(PortailFneImport $import): void
    {
        $this->update([
            'statut'    => self::STATUT_SERVIE,
            'import_id' => $import->id,
            'servie_at' => now(),
        ]);
    }

    /**
     * Abandonne les demandes que plus rien ne justifie pour ce login.
     *
     * Une demande est ouverte par un rejet. Quand tous les rejets de ce login
     * sont refermés — les pièces sont passées — le relevé n'a plus d'objet, et
     * la laisser ouverte ferait passer pour une panne du scraper ce qui n'est
     * qu'une demande devenue sans cause.
     *
     * L'abandon est conditionnel : dix rejets d'affilée ne partagent qu'une
     * demande, et en refermer un seul ne l'éteint pas.
     */
    public static function abandonnerSiPlusDeCause(string $login): int
    {
        $resteDesRejets = FneRejet::where('login', $login)
            ->whereIn('statut', [FneRejet::STATUT_OUVERT, FneRejet::STATUT_DIAGNOSTIQUE])
            ->exists();

        if ($resteDesRejets) {
            return 0;
        }

        return self::where('login', $login)
            ->where('statut', self::STATUT_EN_ATTENTE)
            ->update(['statut' => self::STATUT_ABANDONNEE]);
    }

    /**
     * Les demandes qui n'attendent plus : elles traînent.
     *
     * Une demande ouverte est un signal voulu — c'est ainsi qu'on voit qu'un
     * scraper ne répond plus. Encore faut-il que quelqu'un le voie : sans
     * cette limite, une demande de mars ressemble à une demande de ce matin.
     */
    public function scopeEnRetard($requete)
    {
        return $requete->where('statut', self::STATUT_EN_ATTENTE)
            ->where('created_at', '<', now()->subHours(self::delaiAlerte()));
    }

    public function estEnRetard(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE
            && $this->created_at !== null
            && $this->created_at->lt(now()->subHours(self::delaiAlerte()));
    }

    /**
     * Depuis combien de temps cette demande attend, en clair.
     */
    public function attenteLisible(): string
    {
        if ($this->created_at === null) {
            return 'date inconnue';
        }

        $heures = (int) $this->created_at->diffInHours(now());

        if ($heures < 1) {
            return "moins d'une heure";
        }

        return $heures < 48
            ? $heures . ' h'
            : (int) floor($heures / 24) . ' jours';
    }

    private static function delaiAlerte(): int
    {
        return max(1, (int) config('selflow.portail_fne.delai_alerte_heures', 24));
    }
}
