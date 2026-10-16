<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeJournal extends Model
{
    use IdentifiantOpaque;

    protected $table = 'codes_journaux';
    protected $fillable = ['entreprise_id', 'type', 'code', 'numero_original', 'intitule', 'compte', 'source', 'archive_le', 'systeme'];

    /** Les types proposés à l'écran. La liste vivait en dur dans le `<select>`. */
    public const TYPES = ['Vente', 'Achat', 'Caisse', 'Banque', 'Général'];

    /**
     * Ce journal met-il en jeu un compte de trésorerie ?
     *
     * La question décide de l'affichage du champ « Compte comptable », et de
     * son caractère obligatoire.
     *
     * **Deux types, et non un seul.** Le propriétaire a demandé le champ
     * « uniquement lorsque Banque est sélectionné » ; la caisse pose exactement
     * le même besoin — c'est son compte 571 que chaque écriture du journal
     * mouvemente, comme le 521 de la banque. L'exemple affiché sous le champ le
     * disait déjà : « Ex: 571000, 521000 ». Un journal de caisse sans compte
     * laisserait la contrepartie de chaque encaissement indéterminée.
     *
     * Les ventes, les achats et les opérations diverses n'en portent pas : leur
     * contrepartie est le tiers de la pièce, et elle change à chaque écriture.
     */
    public static function porteUnCompteDeTresorerie(?string $type): bool
    {
        return in_array($type, ['Banque', 'Caisse'], true);
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public static function obtenirJournauxPrioritaires($entrepriseId)
    {
        $hasComptaflow = self::where('entreprise_id', $entrepriseId)->where('source', 'comptaflow')->exists();
        if ($hasComptaflow) {
            return self::where('entreprise_id', $entrepriseId)->where('source', 'comptaflow')->orderBy('code')->get();
        }
        return self::where('entreprise_id', $entrepriseId)->orderBy('code')->get();
    }

    /**
     * Ce qui ne sert pas s'archive, il ne se supprime pas : un compte ou un
     * journal efface apres avoir servi laisserait des ecritures orphelines.
     */
    public function scopeActifs($query)
    {
        return $query->whereNull('archive_le');
    }

    public function scopeArchives($query)
    {
        return $query->whereNotNull('archive_le');
    }

    /**
     * Journaux proposés à la saisie : ni archivés, ni pilotés par le système.
     *
     * Le report à nouveau est écrit par la clôture ; le proposer dans une liste
     * de saisie inviterait à passer une écriture à la main dans un journal que
     * l'application recalcule.
     */
    public function scopeSaisissables($query)
    {
        return $query->whereNull('archive_le')->where('systeme', false);
    }

    public function estArchive(): bool
    {
        return $this->archive_le !== null;
    }

    protected function casts(): array
    {
        return ['systeme' => 'boolean', 'archive_le' => 'datetime'];
    }

    // ══════════════ Banques et mobile money (16/10/2026) ══════════════
    //
    // Le mobile money est rangé en journal « Banque » à partir de 521500
    // (voir TrousseauEntrepriseService). Les deux s'affichaient donc mêlés :
    // MTN, Moov, Orange et Wave dans la liste des banques, pendant que le mode
    // « Mobile Money » proposait quatre opérateurs écrits en dur et versait
    // l'encaissement **en caisse (571)**.

    /** Les subdivisions de 521 réservées au mobile money. */
    public const PREFIXES_MOBILE_MONEY = ['5215', '5216', '5217', '5218', '5219'];

    public function estMobileMoney(): bool
    {
        return $this->type === 'Banque' && self::compteMobileMoney((string) $this->compte);
    }

    public static function compteMobileMoney(string $compte): bool
    {
        foreach (self::PREFIXES_MOBILE_MONEY as $prefixe) {
            if (str_starts_with($compte, $prefixe)) {
                return true;
            }
        }

        return false;
    }

    /** Les banques proprement dites, sans le mobile money. */
    public function scopeBanques($requete)
    {
        // Une seule condition groupée : un `orWhere` au premier niveau
        // échapperait au filtre de l'entreprise posé avant le scope.
        return $requete->where('type', 'Banque')->where(function ($q) {
            $q->whereNull('compte')->orWhere(function ($r) {
                foreach (self::PREFIXES_MOBILE_MONEY as $prefixe) {
                    $r->where('compte', 'not like', $prefixe . '%');
                }
            });
        });
    }

    /** Les comptes de mobile money (MTN, Moov, Orange, Wave…). */
    public function scopeMobileMoney($requete)
    {
        return $requete->where('type', 'Banque')->where(function ($q) {
            foreach (self::PREFIXES_MOBILE_MONEY as $prefixe) {
                $q->orWhere('compte', 'like', $prefixe . '%');
            }
        });
    }

    /** Le prochain compte libre de mobile money pour une entreprise. */
    public static function prochainCompteMobileMoney(int $entrepriseId): string
    {
        $pris = self::where('entreprise_id', $entrepriseId)->pluck('compte')->filter()->all();
        foreach (range(521500, 521990, 10) as $numero) {
            if (! in_array((string) $numero, $pris, true)) {
                return (string) $numero;
            }
        }

        return '521999';
    }
}
