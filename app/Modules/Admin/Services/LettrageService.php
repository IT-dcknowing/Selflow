<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Lettrage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Rapprocher une facture du règlement qui la solde.
 *
 * Sans lettrage, le compte d'un client accumule indéfiniment ses factures et
 * ses encaissements sans que rien ne dise lesquels se répondent. Le solde est
 * juste — il l'a toujours été — mais **on ne sait pas ce qui reste dû** : une
 * facture de mars payée en avril reste visible à côté d'une facture d'août
 * impayée, et relancer le bon client demande de refaire le rapprochement à la
 * main, sur un tableur.
 *
 * Deux règles, et elles ne se négocient pas :
 *
 * - **Un lettrage équilibre.** On ne lettre ensemble que des écritures dont les
 *   débits égalent les crédits. Lettrer une facture de 100 000 avec un acompte
 *   de 40 000 dirait que la créance est éteinte alors qu'il reste 60 000 dus.
 *   Un règlement partiel se lettre quand le solde est atteint, pas avant.
 * - **Un lettrage ne se réécrit pas.** On le défait — `delettrer()` — et on
 *   recommence. Modifier la composition d'un lettrage existant laisserait des
 *   écritures marquées comme soldées sans qu'elles le soient.
 *
 * Le code suit la convention comptable : `A`, `B`, … `Z`, puis `AA`, `AB`. Un
 * comptable qui ouvre un grand livre s'attend à lire cela, et non un
 * identifiant numérique.
 */
class LettrageService
{
    /** Tolérance d'arrondi : le centime que peut laisser une TVA ventilée. */
    private const TOLERANCE = 0.01;

    /**
     * Lettrer un ensemble d'écritures sur un compte.
     *
     * **Le compte est indispensable, et ce n'est pas un détail de signature.**
     * Dans Selflow, une écriture porte les deux comptes sur la même ligne, avec
     * un débit et un crédit **égaux** : sommer les deux colonnes donnerait donc
     * toujours zéro, et n'importe quel ensemble passerait pour équilibré. Une
     * facture de 100 000 se lettrerait avec un acompte de 40 000.
     *
     * L'équilibre se juge donc **du point de vue du compte lettré** : la facture
     * le débite de 100 000, le règlement le crédite de 40 000, et l'écart de
     * 60 000 saute aux yeux — c'est ce qui reste dû.
     *
     * @param  array<int, int>  $ecritureIds
     * @throws \InvalidArgumentException si l'ensemble ne s'équilibre pas sur ce compte
     */
    public static function lettrer(int $entrepriseId, string $compte, array $ecritureIds, ?string $date = null): Lettrage
    {
        return DB::transaction(function () use ($entrepriseId, $compte, $ecritureIds, $date) {
            // Les identifiants viennent d'un formulaire : ils sont confrontés à
            // l'entreprise avant tout. Sans cela, il suffirait de poster
            // l'identifiant d'une écriture du voisin pour la marquer soldée.
            $ecritures = EcritureComptable::whereIn('id', array_unique($ecritureIds))
                ->where('entreprise_id', $entrepriseId)
                ->lockForUpdate()
                ->get();

            if ($ecritures->count() < 2) {
                throw new \InvalidArgumentException(
                    'Un lettrage rapproche au moins deux écritures : une pièce et son règlement.'
                );
            }

            $dejaLettrees = $ecritures->whereNotNull('lettrage_id');

            if ($dejaLettrees->isNotEmpty()) {
                throw new \InvalidArgumentException(
                    'Certaines écritures sont déjà lettrées (n° '
                    . $dejaLettrees->pluck('id')->implode(', ')
                    . '). Défaites le lettrage existant avant d\'en poser un autre.'
                );
            }

            // Chaque écriture ne compte que par le côté où figure le compte
            // lettré : c'est ce qui distingue une facture d'un règlement.
            $etrangeres = $ecritures->filter(
                fn ($e) => $e->compte_debit !== $compte && $e->compte_credit !== $compte
            );

            if ($etrangeres->isNotEmpty()) {
                throw new \InvalidArgumentException(
                    'Certaines écritures ne touchent pas le compte ' . $compte
                    . ' (n° ' . $etrangeres->pluck('id')->implode(', ') . ').'
                );
            }

            $ecart = round(self::mouvementSurLeCompte($ecritures, $compte), 2);

            if (abs($ecart) > self::TOLERANCE) {
                throw new \InvalidArgumentException(sprintf(
                    'Un lettrage équilibre : il reste %s F d\'écart. '
                    . 'Un règlement partiel se lettre quand le solde est atteint, pas avant.',
                    number_format(abs($ecart), 2, ',', ' ')
                ));
            }

            $lettrage = Lettrage::create([
                'entreprise_id'  => $entrepriseId,
                'code'           => self::prochainCode($entrepriseId),
                'date_lettrage'  => $date ?? now()->toDateString(),
                'utilisateur_id' => Auth::id(),
            ]);

            EcritureComptable::whereIn('id', $ecritures->pluck('id'))
                ->update(['lettrage_id' => $lettrage->id]);

            return $lettrage;
        });
    }

    /**
     * Lettrer d'office les écritures d'une pièce, si elles se soldent.
     *
     * Appelé après chaque règlement : quand l'encaissement éteint la créance,
     * il n'y a aucune raison de demander à l'utilisateur de refaire le
     * rapprochement à la main. Un lettrage qui demande une seconde manipulation
     * ne se fait pas, et le compte client redevient illisible en trois mois.
     *
     * **Ne fait rien plutôt que de mal faire** : si les écritures de la pièce
     * ne se soldent pas — règlement partiel, acompte —, on n'écrit rien et on
     * laisse l'écran de lettrage à l'utilisateur. C'est exactement le cas que
     * `lettrer()` refuse, et il le refuse pour de bonnes raisons.
     *
     * @return Lettrage|null le lettrage posé, ou `null` s'il n'y avait pas lieu
     */
    public static function lettrerLaPiece(int $entrepriseId, string $compte, string $reference): ?Lettrage
    {
        $ecritures = EcritureComptable::where('entreprise_id', $entrepriseId)
            ->where('reference_document', $reference)
            ->whereNull('lettrage_id')
            ->where(fn ($q) => $q->where('compte_debit', $compte)->orWhere('compte_credit', $compte))
            ->get();

        if ($ecritures->count() < 2) {
            return null;
        }

        if (abs(round(self::mouvementSurLeCompte($ecritures, $compte), 2)) > self::TOLERANCE) {
            return null;
        }

        try {
            return self::lettrer($entrepriseId, $compte, $ecritures->pluck('id')->all());
        } catch (\InvalidArgumentException|\Illuminate\Database\QueryException) {
            // Une condition a changé entre la lecture et l'écriture — une autre
            // session a lettré entre-temps. Le lettrage manuel reste possible ;
            // faire échouer l'encaissement pour autant serait disproportionné.
            return null;
        }
    }

    /**
     * Défaire un lettrage.
     *
     * Les écritures redeviennent ouvertes ; le code n'est pas réattribué. Un
     * code recyclé désignerait deux rapprochements différents dans l'histoire
     * du compte, et le grand livre d'un exercice clos deviendrait faux.
     */
    public static function delettrer(Lettrage $lettrage): int
    {
        return DB::transaction(function () use ($lettrage) {
            $nombre = EcritureComptable::where('lettrage_id', $lettrage->id)
                ->update(['lettrage_id' => null]);

            $lettrage->delete();

            return $nombre;
        });
    }

    /**
     * Les écritures d'un compte qui restent à lettrer.
     *
     * C'est la liste que l'écran propose, et c'est la réponse à « que me
     * doit-on encore ? ». Une écriture lettrée est soldée : elle n'a plus rien
     * à faire dans une relance.
     */
    public static function ouvertes(int $entrepriseId, string $compte)
    {
        return EcritureComptable::where('entreprise_id', $entrepriseId)
            ->whereNull('lettrage_id')
            ->where(fn ($q) => $q->where('compte_debit', $compte)->orWhere('compte_credit', $compte))
            ->orderBy('date_ecriture')
            ->orderBy('id')
            ->get();
    }

    /**
     * Ce qui reste dû sur un compte : la somme des écritures non lettrées.
     *
     * Positif = le tiers nous doit ; négatif = nous lui devons.
     */
    public static function resteDu(int $entrepriseId, string $compte): float
    {
        return round(
            self::mouvementSurLeCompte(self::ouvertes($entrepriseId, $compte), $compte),
            2
        );
    }

    /**
     * Ce qu'un ensemble d'écritures fait bouger sur un compte donné.
     *
     * Une écriture porte les deux comptes : selon le côté où figure celui qu'on
     * examine, elle joue au débit ou au crédit. Une écriture qui le porterait
     * des deux côtés — un virement interne au même compte — s'annule d'elle-même,
     * ce qui est le bon résultat.
     */
    private static function mouvementSurLeCompte($ecritures, string $compte): float
    {
        $solde = 0.0;

        foreach ($ecritures as $ecriture) {
            if ($ecriture->compte_debit === $compte) {
                $solde += (float) $ecriture->debit;
            }

            if ($ecriture->compte_credit === $compte) {
                $solde -= (float) $ecriture->credit;
            }
        }

        return $solde;
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Le code suivant, dans la convention comptable : A, B, … Z, AA, AB…
     *
     * Le compte des lettrages existants suffit à le déterminer, puisqu'un code
     * n'est jamais réattribué. `Str::excel()` de Laravel fait exactement cette
     * suite — c'est la numérotation des colonnes d'un tableur, qui est la même.
     */
    private static function prochainCode(int $entrepriseId): string
    {
        // Le rang suit le plus grand code déjà posé, et non le nombre de
        // lettrages : un lettrage défait faisait baisser le compte, et le code
        // suivant retombait sur un code existant — erreur 1062 en pleine vente
        // (« poissonnerie entente », 16/10/2026).
        $rang = Lettrage::withoutGlobalScopes()->where('entreprise_id', $entrepriseId)
            ->pluck('code')
            ->map(fn ($code) => self::rangDuCode((string) $code))
            ->max() ?? 0;

        do {
            $code = self::codeDuRang(++$rang);
        } while (Lettrage::withoutGlobalScopes()->where('entreprise_id', $entrepriseId)->where('code', $code)->exists());

        return $code;
    }

    /** A → 1, Z → 26, AA → 27 ; tout autre code compte pour 0. */
    private static function rangDuCode(string $code): int
    {
        if (!preg_match('/^[A-Z]+$/', $code)) {
            return 0;
        }

        $rang = 0;
        foreach (str_split($code) as $lettre) {
            $rang = $rang * 26 + (ord($lettre) - 64);
        }

        return $rang;
    }

    private static function codeDuRang(int $rang): string
    {
        $code = '';

        while ($rang > 0) {
            $reste = ($rang - 1) % 26;
            $code = chr(65 + $reste) . $code;
            $rang = intdiv($rang - 1 - $reste, 26);
        }

        return $code;
    }
}
