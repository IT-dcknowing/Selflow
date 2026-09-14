<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Services\ScraperPortailFneService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Une pièce que la plateforme FNE a refusée.
 *
 * ## Pourquoi cette table existe
 *
 * `FneService::messageRejet()` assemble déjà un message précis — le champ
 * fautif, la valeur envoyée, la raison de la DGI. Ce message était rendu à
 * l'appelant, journalisé, puis perdu : le job de normalisation se contentait
 * d'un `Log::warning`. Un rejet survenu la nuit ne laissait donc, au matin,
 * qu'une ligne dans un fichier de log que personne ne lit.
 *
 * ## Ce que cette table ne fait pas
 *
 * Elle **ne rejoue rien** et **ne corrige rien**. Consigner un rejet n'a aucun
 * effet sur la pièce, sur l'entreprise, ni sur ce qui sera envoyé la prochaine
 * fois. C'est un constat daté, au même titre qu'un relevé du portail.
 */
class FneRejet extends Model
{
    protected $table = 'fne_rejets';

    public const STATUT_OUVERT       = 'ouvert';
    public const STATUT_DIAGNOSTIQUE = 'diagnostique';
    public const STATUT_RESOLU       = 'resolu';

    /**
     * Pourquoi la pièce n'est pas passée. Trois causes, et une seule appelle un
     * relevé du portail.
     *
     * `FneService` rend `success: false` pour des raisons qui n'ont rien de
     * commun : la DGI a refusé la pièce, la DGI n'a jamais reçu la pièce, ou
     * Selflow a refusé de l'envoyer. Les confondre revenait à envoyer le
     * scraper sur le portail parce qu'une connexion avait sauté, et à comparer
     * quatorze champs qu'aucune DGI n'avait mis en cause.
     */
    public const CAUSE_DGI    = 'dgi';     // examinée et refusée : un relevé sert
    public const CAUSE_RESEAU = 'reseau';  // jamais examinée : réseau, délai, plateforme HS
    public const CAUSE_LOCALE = 'locale';  // jamais envoyée : Selflow a refusé avant l'appel

    /**
     * Les refus que Selflow prononce lui-même, avant tout appel — clé API
     * absente, avoir sans facture d'origine, taux de TVA hors barème DGI.
     *
     * Reconnus par des fragments sans apostrophe ni accent variable : ces
     * messages sont assemblés dans `FneService`, qui est gelé, et une
     * comparaison trop serrée casserait au premier ajustement de formulation.
     */
    private const REFUS_LOCAUX = [
        'aucune clé API FNE active',
        'Normalisation refusée',
        'Impossible de normaliser',
    ];

    protected $fillable = [
        'entreprise_id',
        'piece_type',
        'piece_id',
        'numero_piece',
        'login',
        'message',
        'champs',
        'cause',
        'statut',
        'diagnostic',
    ];

    protected function casts(): array
    {
        return [
            'champs'     => 'array',
            'diagnostic' => 'array',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(PortailFneDemande::class, 'rejet_id');
    }

    /**
     * Consigne un rejet à partir du résultat rendu par `FneService`.
     *
     * Appelée là où l'on constatait `success === false` sans rien en faire.
     * Elle n'échoue jamais bruyamment : un défaut de journalisation ne doit pas
     * transformer un rejet — déjà mauvais — en exception qui masque le rejet
     * lui-même.
     *
     * **Elle ouvre aussi la demande de relevé**, plutôt que de laisser chaque
     * appelant y penser. Un rejet est le seul moment où un relevé du portail
     * sert vraiment à quelque chose ; confier cette ouverture aux trois — demain
     * quatre — endroits qui normalisent une pièce, c'est s'assurer qu'un
     * d'entre eux l'oubliera.
     *
     * @param  Vente|Achat            $piece
     * @param  array<string, mixed>   $resultat  Le retour de `normaliserFacture()`
     *                                           ou `normaliserAchatBapa()`.
     */
    /**
     * Pourquoi la pièce n'est pas passée, à partir de ce que `FneService` rend.
     *
     * La lecture se fait sur le message, faute de mieux : le service est gelé
     * et ne porte aucun code de cause. Ce n'est pas idéal, c'est vérifiable —
     * `FneRejetCauseTest` fige les six formulations que `FneService` produit
     * réellement, et un message qui changerait ferait tomber le test au lieu
     * de retomber en silence sur « la DGI a refusé ».
     *
     * En cas de doute, la réponse est `CAUSE_DGI` : ouvrir un relevé de trop
     * fait travailler le scraper pour rien ; en manquer un laisse une facture
     * refusée sans explication.
     */
    public static function classer(array $resultat): string
    {
        $message = (string) ($resultat['message'] ?? '');

        // 1. Rien n'est parti. Le défaut est ici, pas sur le portail.
        foreach (self::REFUS_LOCAUX as $marqueur) {
            if (str_contains($message, $marqueur)) {
                return self::CAUSE_LOCALE;
            }
        }

        // 2. L'appel n'a pas abouti : délai dépassé, DNS, connexion refusée.
        //    FneService attrape l'exception de transport et la rend en message
        //    (`FneService.php:276`, et sa jumelle BAPA `:414`).
        if (str_contains($message, "Exception lors de l'appel API FNE")) {
            return self::CAUSE_RESEAU;
        }

        // 3. La plateforme a répondu, mais pas un verdict : une panne de son
        //    côté (5xx) ou un corps qu'on n'a pas su lire. Dans les deux cas
        //    elle n'a pas examiné la pièce, et le portail n'a rien à en dire.
        if (preg_match('/\(HTTP (\d{3})\)/', $message, $trouve) && (int) $trouve[1] >= 500) {
            return self::CAUSE_RESEAU;
        }

        if (str_contains($message, "la réponse de l'API est incomplète")) {
            return self::CAUSE_RESEAU;
        }

        // 4. Reste le cas qui compte : la DGI a lu la pièce et l'a refusée.
        return self::CAUSE_DGI;
    }

    /** La DGI n'a jamais examiné la pièce : il n'y a rien à relever, il y a à réessayer. */
    public function estReseau(): bool
    {
        return $this->cause === self::CAUSE_RESEAU;
    }

    public static function consigner($piece, array $resultat): ?self
    {
        if (($resultat['success'] ?? false) === true) {
            return null;
        }

        try {
            // Ni `ventes` ni `achats` ne portent `entreprise_id` : l'entreprise
            // se joint par le point de vente, comme le fait FneService pour
            // bâtir son payload (`FneService.php:28`).
            $entreprise = $piece->pointDeVente?->entreprise;
            $cause = self::classer($resultat);

            $rejet = self::create([
                'entreprise_id' => $entreprise?->id,
                'piece_type'    => $piece instanceof Achat ? 'achat' : 'vente',
                'piece_id'      => $piece->id,
                'numero_piece'  => $piece->numero_facture ?? $piece->numero ?? null,
                'login'         => $entreprise?->ncc,
                'message'       => $resultat['message'] ?? null,
                'champs'        => self::champsRejetes($resultat),
                'cause'         => $cause,
                'statut'        => self::STATUT_OUVERT,
            ]);

            // Le relevé n'est ouvert que si la DGI a réellement examiné la
            // pièce. Une coupure réseau ouvrait jusqu'ici une demande : le
            // scraper partait sur le portail, relevait quatorze champs, et le
            // rapprochement comparait ce qu'aucune DGI n'avait mis en cause.
            // Une file qui se remplit d'alertes sans objet cesse d'être lue.
            if ($cause === self::CAUSE_DGI && $rejet->login) {
                PortailFneDemande::pour(
                    $rejet->login,
                    trim(sprintf(
                        'Rejet %s%s',
                        $rejet->numero_piece ?: "pièce #{$rejet->piece_id}",
                        $rejet->nomsDesChamps() ? ' sur ' . implode(', ', $rejet->nomsDesChamps()) : ''
                    )),
                    $rejet->entreprise_id,
                    $rejet->id
                );

                // Et le relevé part tout de suite, sans attendre le passage de
                // :40. C'est ici, et non dans un écran, parce que tous les
                // refus passent par là — une facture normalisée à la main, un
                // bordereau d'achat, une normalisation par lot, le tableau de
                // bord. Poser le geste dans un contrôleur, c'était l'oublier
                // dans les cinq autres.
                //
                // Verrouillé par login : vingt refus d'un même lot ont la même
                // cause et n'appellent qu'un seul relevé.
                ScraperPortailFneService::relancerApresRejet($rejet->login);
            }

            return $rejet;
        } catch (Throwable $e) {
            Log::error('FneRejet : consignation impossible', [
                'piece'  => $piece->id ?? null,
                'erreur' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Referme les rejets d'une pièce que la plateforme vient d'accepter.
     *
     * Appelée là où la normalisation réussit. Sans elle, un rejet reste ouvert
     * pour toujours : la facture repart, passe, et l'écran continue d'afficher
     * un refus corrigé depuis longtemps. Une file qui ne se vide jamais cesse
     * d'être lue, et c'est ainsi qu'on rate le rejet suivant.
     *
     * @param  Vente|Achat  $piece
     * @return int  Le nombre de rejets refermés.
     */
    public static function resoudre($piece): int
    {
        $logins = self::where('piece_type', $piece instanceof Achat ? 'achat' : 'vente')
            ->where('piece_id', $piece->id)
            ->whereIn('statut', [self::STATUT_OUVERT, self::STATUT_DIAGNOSTIQUE])
            ->pluck('login')
            ->filter()
            ->unique();

        $refermes = self::where('piece_type', $piece instanceof Achat ? 'achat' : 'vente')
            ->where('piece_id', $piece->id)
            ->whereIn('statut', [self::STATUT_OUVERT, self::STATUT_DIAGNOSTIQUE])
            ->update(['statut' => self::STATUT_RESOLU]);

        // Une demande de relevé sans rejet à éclairer n'a plus d'objet. La
        // laisser ouverte ferait passer pour une panne du scraper ce qui n'est
        // qu'une demande devenue sans cause.
        foreach ($logins as $login) {
            PortailFneDemande::abandonnerSiPlusDeCause((string) $login);
        }

        return $refermes;
    }

    /**
     * Les champs que la DGI a nommés dans son refus.
     *
     * La plateforme les rend dans la clé `errors` de sa réponse, que
     * `FneService` conserve brute sous `errors.api_error`. On la relit ici
     * plutôt que d'analyser le message en français assemblé par
     * `messageRejet()` : un message est fait pour être lu, pas découpé.
     *
     * @param  array<string, mixed>  $resultat
     * @return array<string, mixed>|null
     */
    private static function champsRejetes(array $resultat): ?array
    {
        $erreurs = $resultat['errors'] ?? null;

        if (!is_array($erreurs)) {
            return null;
        }

        // Cas courant : un rejet HTTP, dont le corps brut porte le détail.
        if (isset($erreurs['api_error']) && is_string($erreurs['api_error'])) {
            $corps = json_decode($erreurs['api_error'], true);

            if (is_array($corps) && is_array($corps['errors'] ?? null)) {
                return $corps['errors'];
            }
        }

        // Cas des refus prononcés par Selflow avant tout appel — clé API
        // absente, avoir sans facture d'origine. La clé suffit à les nommer.
        return $erreurs ?: null;
    }

    /**
     * La pièce refusée, quand elle existe encore.
     */
    public function piece(): ?Model
    {
        return $this->piece_type === 'achat'
            ? Achat::find($this->piece_id)
            : Vente::find($this->piece_id);
    }

    /**
     * Les noms de champs que la DGI a mis en cause.
     *
     * @return array<int, string>
     */
    public function nomsDesChamps(): array
    {
        return array_keys($this->champs ?? []);
    }

    public function estOuvert(): bool
    {
        return $this->statut === self::STATUT_OUVERT;
    }
}
