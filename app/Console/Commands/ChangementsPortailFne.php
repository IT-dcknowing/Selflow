<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Ce que le portail de la DGI a changé depuis le relevé précédent.
 *
 * ## La question à laquelle rien ne répondait
 *
 * `ecartsAvecEntreprise()` compare le portail au paramétrage de Selflow :
 * « disent-ils la même chose ? ». `fne:diagnostiquer-rejets` compare une pièce
 * refusée à ce que le portail déclare. Aucune des deux ne répondait à
 * **« quelqu'un a-t-il touché au portail depuis hier ? »**
 *
 * Sans elle, un timbre de quittance désactivé au portail un mardi soir
 * n'apparaissait nulle part — jusqu'au jour où une facture était refusée, et
 * l'on cherchait alors ce qui avait bougé, sans savoir quand.
 *
 * ## Ce qu'elle ne fait pas
 *
 * Elle ne corrige rien et ne recopie rien. `timbre_quittance`, `bapa` et
 * `sticker_solde_alerte` commandent le comportement fiscal de l'application :
 * les aligner sur le portail parce qu'un fichier est arrivé dans un dossier
 * ferait changer une facture sans que personne ne l'ait décidé. C'est la règle
 * d'or du projet, et elle vaut ici comme ailleurs — **un constat, pas une
 * décision.**
 *
 * ## Usage
 *
 *   php artisan portail-fne:changements               tous les logins relevés
 *   php artisan portail-fne:changements --login=1864699A
 *   php artisan portail-fne:changements --silencieux  ne dit rien s'il n'y a rien
 *
 * Le `--silencieux` sert au passage planifié : un journal qui répète chaque
 * heure « aucun changement » cesse d'être lu, et c'est le jour où il dit
 * quelque chose qu'on ne le lira pas.
 */
class ChangementsPortailFne extends Command
{
    protected $signature = 'portail-fne:changements
                            {--login= : Se limiter à ce login}
                            {--silencieux : N\'écrire que s\'il y a des changements}';

    protected $description = 'Montre ce que le portail FNE a changé depuis le relevé précédent.';

    public function handle(): int
    {
        $logins = $this->option('login')
            ? [$this->option('login')]
            : PortailFneFiche::query()->distinct()->orderBy('login')->pluck('login')->all();

        if (!$logins) {
            if (!$this->option('silencieux')) {
                $this->info('Aucun relevé du portail en base.');
            }

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($logins as $login) {
            $total += $this->rapporter($login);
        }

        if ($total === 0 && !$this->option('silencieux')) {
            $this->info('Aucun changement au portail depuis le relevé précédent.');
        }

        return self::SUCCESS;
    }

    /**
     * Le dernier changement est-il celui du dernier passage du scraper ?
     *
     * Sans passage connu — des fiches posées à la main, un import antérieur au
     * suivi — on répond oui : mieux vaut dire une nouvelle deux fois que de la
     * taire.
     */
    private function changementDuDernierPassage(string $login, PortailFneFiche $derniere): bool
    {
        $dernierPassage = PortailFneImport::where('login', $login)
            ->where('statut', PortailFneImport::STATUT_IMPORTE)
            ->max('dernier_releve_le');

        if ($dernierPassage === null || $derniere->date_scraping === null) {
            return true;
        }

        return $derniere->date_scraping->format('Y-m-d')
            === CarbonImmutable::parse($dernierPassage)->format('Y-m-d');
    }

    /** @return int Le nombre de changements rapportés pour ce login. */
    private function rapporter(string $login): int
    {
        $derniere = PortailFneFiche::where('login', $login)
            ->orderByDesc('date_scraping')
            ->orderByDesc('id')
            ->first();

        if (!$derniere) {
            return 0;
        }

        // Depuis qu'un relevé identique au précédent n'écrit plus rien, la
        // dernière fiche en base n'est plus celle du dernier passage : elle est
        // celle du dernier *changement*. Sans ce filtre, le passage planifié
        // annoncerait chaque heure une nouvelle vieille de trois semaines, et
        // le `--silencieux` — qui existe pour qu'un journal reste lisible — ne
        // servirait plus à rien.
        //
        // Lancée à la main, sans le drapeau, la commande continue de montrer le
        // dernier changement connu, quelle que soit sa date : c'est justement
        // ce qu'on vient lui demander.
        if ($this->option('silencieux') && !$this->changementDuDernierPassage($login, $derniere)) {
            return 0;
        }

        $champs = $derniere->ecartsAvecPrecedente();
        $points = PortailFnePointFacturation::changementsDepuisLePrecedent($login);

        $nombre = count($champs)
            + count($points['apparus'])
            + count($points['disparus'])
            + count($points['modifies']);

        if ($nombre === 0) {
            return 0;
        }

        $precedente = $derniere->precedente();
        $nom = $derniere->entreprise?->nom ?? $login;

        $this->newLine();
        $this->line("<options=bold>{$nom} ({$login})</>");
        $this->line(sprintf(
            '  relevé du %s, comparé à celui du %s',
            $derniere->date_scraping?->format('d/m/Y') ?? '?',
            $precedente?->date_scraping?->format('d/m/Y') ?? '?'
        ));

        foreach ($champs as $ecart) {
            $this->line(sprintf(
                '  <fg=yellow>~</> %s : « %s » → « %s »',
                $ecart['libelle'],
                $this->lisible($ecart['avant']),
                $this->lisible($ecart['apres'])
            ));
        }

        foreach ($points['apparus'] as $point) {
            $this->line("  <fg=green>+</> point de facturation « {$point->nom} »"
                . ($point->estActif() ? ' (actif)' : ' (inactif)'));
        }

        foreach ($points['disparus'] as $point) {
            $this->line("  <fg=red>-</> point de facturation « {$point->nom} » n'est plus déclaré");
        }

        foreach ($points['modifies'] as $modifie) {
            $this->line("  <fg=yellow>~</> point « {$modifie['avant']->nom} » — "
                . implode(' ; ', $modifie['changements']));
        }

        // Trois champs commandent le comportement fiscal. Qu'ils bougent au
        // portail sans que Selflow bouge est exactement le genre d'écart qui a
        // produit, par le passé, des pièces certifiées différentes de celles
        // établies ici.
        $fiscaux = array_intersect(
            array_column($champs, 'champ'),
            ['timbre_quittance', 'bapa', 'sticker_solde_alerte']
        );

        if ($fiscaux) {
            $this->line('  <fg=red;options=bold>Attention :</> '
                . implode(', ', $fiscaux)
                . ' commande(nt) le comportement fiscal. Rien n\'est recopié automatiquement — '
                . 'à arbitrer depuis l\'écran des rejets.');
        }

        return $nombre;
    }

    private function lisible(mixed $valeur): string
    {
        return match (true) {
            $valeur === null, $valeur === '' => '—',
            $valeur === true  => 'oui',
            $valeur === false => 'non',
            default => (string) $valeur,
        };
    }
}
