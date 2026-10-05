<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Une classe employée sans être importée ne se voit qu'au clic.
 *
 * Signalé par le propriétaire le 05/10/2026, console à l'appui :
 *
 *     POST /admin/stock/livraisons/{uuid}/valider
 *     Class "App\Modules\Admin\Controleurs\VenteDetail" not found  — 500
 *
 * `VenteDetail::findOrFail()` était appelé dans `validerLivraison()` sans que
 * la classe soit importée. PHP la cherchait alors dans l'espace de noms du
 * contrôleur — `App\Modules\Admin\Controleurs\VenteDetail` — et ne la trouvait
 * pas.
 *
 * **Ce défaut ne se voit pas à l'affichage.** La page s'ouvre, le formulaire se
 * remplit, le stock s'affiche : c'est le bouton qui casse, et seulement lui.
 * Aucune épreuve d'écran ne l'aurait pris.
 *
 * `AchatDetail` portait le même défaut dans la validation d'une réception, et
 * personne ne l'avait rencontré — la même moitié réparée qui cachait l'autre
 * qu'au lot 20, sur le choix de la pièce d'un avoir.
 *
 * ## Pourquoi le tokeniseur, et non une expression régulière
 *
 * La première version lisait le source brut. Ce dépôt commente beaucoup, et
 * ses commentaires citent des classes — « voir `Produit::CODES_TVA` » — qu'une
 * expression régulière prend pour du code. Elle rendait vingt-huit faux
 * positifs. `token_get_all()` sépare le code des commentaires et des chaînes,
 * et ne se trompe pas.
 */
class ClassesImporteesTest extends TestCase
{
    /** Ce que PHP résout seul, sans import ni espace de noms. */
    private const MOTS_RESERVES = [
        'self', 'static', 'parent', 'int', 'float', 'string', 'bool',
        'array', 'object', 'mixed', 'void', 'callable', 'iterable', 'null',
        'true', 'false', 'never',
    ];

    /** @return array<int, string> */
    private function fichiersPhp(): array
    {
        $fichiers = [];

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterateur as $fichier) {
            if ($fichier->getExtension() === 'php') {
                $fichiers[] = $fichier->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }

    /**
     * Les noms de classe employés sans qualification, par le code seul.
     *
     * @return array{0: string, 1: array<int, string>, 2: array<int, string>}
     *         l'espace de noms, les noms importés, les noms employés
     */
    private function lire(string $source): array
    {
        $jetons = token_get_all($source);
        $n = count($jetons);

        $espace   = '';
        $importes = [];
        $employes = [];

        for ($i = 0; $i < $n; $i++) {
            $jeton = $jetons[$i];

            if (!is_array($jeton)) {
                continue;
            }

            // L'espace de noms du fichier.
            if ($jeton[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $n && $jetons[$j] !== ';' && $jetons[$j] !== '{'; $j++) {
                    if (is_array($jetons[$j]) && $jetons[$j][0] !== T_WHITESPACE) {
                        $espace .= $jetons[$j][1];
                    }
                }
                continue;
            }

            // Les `use` du haut de fichier. Ceux d'un trait, dans le corps de
            // la classe, importent aussi un nom : les deux comptent.
            if ($jeton[0] === T_USE) {
                $declaration = '';
                for ($j = $i + 1; $j < $n && $jetons[$j] !== ';' && $jetons[$j] !== '{' && $jetons[$j] !== '('; $j++) {
                    if (is_array($jetons[$j]) && $jetons[$j][0] !== T_WHITESPACE) {
                        $declaration .= $jetons[$j][1] . '|';
                    }
                    if ($jetons[$j] === ',') {
                        $declaration .= '#';
                    }
                }

                foreach (explode('#', $declaration) as $morceau) {
                    $parties = array_values(array_filter(explode('|', $morceau)));
                    if ($parties === []) {
                        continue;
                    }
                    // `use A\B as C` : c'est `C` qui est le nom disponible.
                    $position = array_search('as', array_map('strtolower', $parties), true);
                    $nom = $position !== false
                        ? ($parties[$position + 1] ?? '')
                        : end($parties);

                    $morceaux = explode('\\', (string) $nom);
                    $court = end($morceaux);
                    if ($court !== '') {
                        $importes[] = $court;
                    }
                }
                continue;
            }

            if ($jeton[0] !== T_STRING) {
                continue;
            }

            // Un nom précédé d'une barre, ou faisant partie d'un nom qualifié,
            // se résout seul : on le laisse.
            $precedent = $this->jetonUtile($jetons, $i, -1);
            if ($precedent === '\\'
                || (is_array($precedent) && in_array($precedent[0], [T_OBJECT_OPERATOR, T_FUNCTION, T_CONST], true))) {
                continue;
            }
            if (is_array($precedent) && defined('T_NAME_QUALIFIED') && $precedent[0] === T_NAME_QUALIFIED) {
                continue;
            }

            $suivant = $this->jetonUtile($jetons, $i, 1);

            $estUsage = (is_array($suivant) && $suivant[0] === T_DOUBLE_COLON)
                || (is_array($precedent) && $precedent[0] === T_NEW);

            if (!$estUsage) {
                continue;
            }

            $nom = $jeton[1];
            if ($nom !== '' && ctype_upper($nom[0]) && !in_array(strtolower($nom), self::MOTS_RESERVES, true)) {
                $employes[] = $nom;
            }
        }

        return [$espace, array_unique($importes), array_unique($employes)];
    }

    /** Le jeton significatif voisin, en sautant les blancs et commentaires. */
    private function jetonUtile(array $jetons, int $depuis, int $sens): array|string|null
    {
        $i = $depuis + $sens;

        while (isset($jetons[$i])) {
            $jeton = $jetons[$i];
            if (is_array($jeton) && in_array($jeton[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i += $sens;
                continue;
            }
            return $jeton;
        }

        return null;
    }

    /** Ce nom existe-t-il dans cet espace de noms ? */
    private function existeDans(string $espace, string $nom): bool
    {
        if ($espace === '' || !str_starts_with($espace, 'App')) {
            return false;
        }

        $chemin = app_path(str_replace('\\', DIRECTORY_SEPARATOR, substr($espace, strlen('App\\')))
            . DIRECTORY_SEPARATOR . $nom . '.php');

        return file_exists($chemin);
    }

    public function test_aucune_classe_n_est_employee_sans_etre_importee(): void
    {
        $fautifs = [];

        foreach ($this->fichiersPhp() as $chemin) {
            [$espace, $importes, $employes] = $this->lire(file_get_contents($chemin));

            foreach (array_diff($employes, $importes) as $nom) {
                // Un nom non importé se résout d'abord dans l'espace du
                // fichier : s'il y vit, tout va bien.
                if ($this->existeDans($espace, $nom)) {
                    continue;
                }

                // Reste à savoir si la classe existe ailleurs chez nous. Sinon
                // c'est une classe du socle ou du vendor, qu'un `use` absent
                // ferait échouer au chargement de la classe, pas ici.
                if (!$this->existeAilleurs($nom)) {
                    continue;
                }

                $fautifs[] = basename($chemin) . ' emploie ' . $nom;
            }
        }

        $this->assertSame([], array_values(array_unique($fautifs)), implode("\n", [
            'Ces classes sont employées sans import, et sans barre de tête.',
            'PHP les cherche dans l\'espace de noms du fichier, ne les trouve pas,',
            'et la page tombe en 500 (Internal Server Error — erreur interne du',
            'serveur) au moment précis où la ligne s\'exécute : souvent sur un',
            'bouton, jamais à l\'affichage.',
            'Ajoutez le `use`, ou écrivez le nom pleinement qualifié.',
        ]));
    }

    /** Ce nom désigne-t-il une classe de l'application, ailleurs ? */
    private function existeAilleurs(string $nom): bool
    {
        static $connues = null;

        if ($connues === null) {
            $connues = [];
            foreach ($this->fichiersPhp() as $chemin) {
                $connues[basename($chemin, '.php')] = true;
            }
        }

        return isset($connues[$nom]);
    }
}
