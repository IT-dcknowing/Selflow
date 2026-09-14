<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PortailFneDemande;
use App\Modules\Admin\Services\CorrectionFneService;
use App\Modules\Admin\Services\DiagnosticFneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rapproche les pièces refusées par la DGI du dernier relevé du portail.
 *
 * Passe sur les rejets encore ouverts, va chercher le relevé disponible pour
 * l'entreprise, et consigne la comparaison sur le rejet. C'est le moment où
 * « Le nom du point de vente doit être déclaré à l'identique » devient
 * « vous avez envoyé X, le portail déclare Y ».
 *
 * **Elle corrige un seul champ, et renvoie ce qu'il bloquait** : le nom du
 * point de vente, quand le portail n'en déclare qu'un et qu'il diffère de ce
 * qui est parti. Demandé par le propriétaire du projet le 29/08/2026 — voir
 * `CorrectionFneService`, et `selflow.portail_fne.correction_auto` pour
 * l'éteindre.
 *
 * **Tout le reste n'est que montré.** Les trois champs de la fiche qui
 * commandent le comportement fiscal — timbre de quittance, bordereau d'achat,
 * solde d'alerte des stickers — sont rapprochés et jamais appliqués : une
 * facture ne change pas de contenu parce qu'un fichier est arrivé dans un
 * dossier.
 *
 * Usage :
 *   php artisan fne:diagnostiquer-rejets
 *   php artisan fne:diagnostiquer-rejets --rejet=12
 *   php artisan fne:diagnostiquer-rejets --tous   (rejouer les déjà diagnostiqués)
 */
class DiagnostiquerRejetsFne extends Command
{
    protected $signature = 'fne:diagnostiquer-rejets
                            {--rejet= : Un seul rejet, par son identifiant}
                            {--tous : Reprendre aussi les rejets déjà diagnostiqués}';

    protected $description = 'Rapproche les rejets FNE du dernier relevé du portail.';

    public function handle(DiagnosticFneService $service, CorrectionFneService $correcteur): int
    {
        $requete = FneRejet::query()->orderBy('id');

        if ($id = $this->option('rejet')) {
            $requete->whereKey($id);
        } else {
            // Les rejets déjà diagnostiqués sont repris eux aussi : c'est ici
            // que le constat se rafraîchit quand un relevé plus récent arrive.
            // `diagnosticEstAJour()` écarte ensuite ceux qui décrivent déjà le
            // dernier état connu, pour ne pas réécrire la même chose chaque heure.
            $requete->whereIn('statut', [FneRejet::STATUT_OUVERT, FneRejet::STATUT_DIAGNOSTIQUE]);
        }

        $rejets = $requete->get();

        if ($rejets->isEmpty()) {
            $this->info('Aucun rejet à rapprocher.');

            // Le signalement passe aussi ici : une demande peut traîner seule,
            // le rejet qui l'avait ouverte ayant été classé entre-temps. C'est
            // même le cas où personne ne regarde.
            $this->signalerLesDemandesQuiTrainent();

            return self::SUCCESS;
        }

        $rapproches = 0;
        $inchanges  = 0;
        $corriges   = 0;

        foreach ($rejets as $rejet) {
            // `--tous` force la réécriture, y compris d'un constat déjà à jour.
            // La liste a été prise au début du passage. Depuis, la correction
            // d'un premier rejet a pu renvoyer les pièces des suivants et les
            // faire passer : leur statut a changé sous nos pieds.
            $rejet->refresh();

            if (!$this->option('tous')
                && $rejet->statut === FneRejet::STATUT_DIAGNOSTIQUE
                && $service->diagnosticEstAJour($rejet)) {
                $inchanges++;
                continue;
            }

            $diagnostic = $service->diagnostiquer($rejet);

            // Sans relevé, le rejet reste ouvert : le marquer « diagnostiqué »
            // sur la foi d'une comparaison qui n'a pas eu lieu le ferait sortir
            // de la file sans que rien n'ait été vérifié.
            if ($diagnostic['releve'] === null) {
                $this->line("Rejet #{$rejet->id} ({$rejet->numero_piece}) : aucun relevé disponible.");
                continue;
            }

            // Un rejet résolu reste résolu. La commande le rétrogradait en
            // « diagnostiqué » — sans conséquence tant que rien ne résolvait un
            // rejet pendant le passage ; depuis que la correction renvoie les
            // pièces, l'écran affichait en souffrance des pièces certifiées une
            // seconde plus tôt. C'est la règle que l'écran suit déjà.
            $rejet->update([
                'diagnostic' => $diagnostic,
                'statut'     => $rejet->statut === FneRejet::STATUT_RESOLU
                    ? FneRejet::STATUT_RESOLU
                    : FneRejet::STATUT_DIAGNOSTIQUE,
            ]);

            $rapproches++;

            $this->line("Rejet #{$rejet->id} ({$rejet->numero_piece}) — {$diagnostic['conclusion']}");

            foreach ($diagnostic['champs'] as $champ) {
                $this->line("    {$champ['champ']} : {$champ['explication']}");
            }

            // La correction se tente ici, juste après le constat : le
            // diagnostic qu'elle lit vient d'être écrit, il décrit donc le
            // dernier relevé connu et non un état d'il y a une semaine.
            if ($correcteur->estActive() && ($fait = $correcteur->corriger($rejet))) {
                $corriges++;

                $this->line(sprintf(
                    '    corrigé : point de vente « %s » renommé « %s » — %d pièce(s) renvoyée(s)%s',
                    $fait['ancien'],
                    $fait['nouveau'],
                    $fait['renvoyees'],
                    $fait['suspendues'] > 0
                        ? sprintf(", %d en attente d’un renvoi manuel", $fait['suspendues'])
                        : ''
                ));
            }

            // Les écarts de fiche sortent aussi au journal : le timbre de
            // quittance et le bordereau d'achat y figurent, et ils changent ce
            // qui part à la DGI. Personne n'est devant l'écran quand la tâche
            // planifiée passe.
            if ($diagnostic['ecarts_fiche'] !== []) {
                Log::warning('Rejet FNE : écarts entre le portail et le paramétrage', [
                    'rejet'  => $rejet->id,
                    'ecarts' => array_keys($diagnostic['ecarts_fiche']),
                ]);
            }
        }

        $this->info("{$rapproches} rejet(s) rapproché(s) sur {$rejets->count()}"
            . ($inchanges > 0 ? ", {$inchanges} déjà à jour" : '')
            . ($corriges > 0 ? ", {$corriges} corrigé(s)." : '.'));

        $this->signalerLesDemandesQuiTrainent();

        return self::SUCCESS;
    }

    /**
     * Dit tout haut les demandes de relevé qui n'attendent plus : elles traînent.
     *
     * Une demande ouverte est un signal voulu — c'est ainsi qu'on voit qu'un
     * scraper ne répond plus. Encore faut-il que quelqu'un le voie. Sans ce
     * signalement, la seule façon de s'en apercevoir était d'ouvrir l'écran des
     * rejets et de remarquer un chiffre qui ne bouge pas, ce qui suppose de
     * l'avoir remarqué la veille.
     *
     * Trois causes possibles, et aucune ne se corrige toute seule : le scraper
     * ne tourne pas, il dépose ailleurs, ou le login est faux.
     */
    private function signalerLesDemandesQuiTrainent(): void
    {
        $enRetard = PortailFneDemande::enRetard()->orderBy('created_at')->get();

        if ($enRetard->isEmpty()) {
            return;
        }

        $heures = config('selflow.portail_fne.delai_alerte_heures', 24);

        $this->warn("{$enRetard->count()} demande(s) de relevé sans réponse depuis plus de {$heures} h :");

        foreach ($enRetard as $demande) {
            $this->line("    {$demande->login} — depuis {$demande->attenteLisible()} — {$demande->motif}");
        }

        Log::warning('Portail FNE : des demandes de relevé restent sans réponse', [
            'nombre' => $enRetard->count(),
            'logins' => $enRetard->pluck('login')->unique()->values()->all(),
            'seuil_heures' => $heures,
        ]);
    }
}
