<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Support\Facades\Log;

/**
 * Remettre au scraper les accès FNE qu'une entreprise vient de fournir.
 *
 * Le scraper lit ses mots de passe dans `identifiants.json`, à côté de son
 * script, fichier ignoré par git et propre au serveur. Une entreprise qui
 * donnait son NCC et son mot de passe dans Selflow ne s'y retrouvait donc
 * jamais : le relevé ne partait pas, et les champs grisés « remplis
 * automatiquement » restaient vides. Propriétaire, 08/10/2026 : « assure
 * qu'il les remplit au niveau des paramètres si le bouton J'ai déjà un compte
 * est cliqué ».
 *
 * L'écriture est atomique (fichier temporaire puis renommage) et le fichier
 * reste lisible par le seul propriétaire (0600). Une erreur d'écriture ne
 * fait jamais échouer l'enregistrement de l'entreprise : elle est journalisée.
 */
class IdentifiantsScraperService
{
    public static function fichier(): string
    {
        return dirname((string) config('selflow.portail_fne.scraper.script')) . '/identifiants.json';
    }

    public static function inscrire(string $login, string $motDePasse, ?string $libelle = null): bool
    {
        $login = strtoupper(trim($login));

        if ($login === '' || $motDePasse === '') {
            return false;
        }

        $fichier = self::fichier();

        try {
            $contenu = [];
            if (is_file($fichier)) {
                $lu = json_decode((string) file_get_contents($fichier), true);
                if (!is_array($lu)) {
                    // Un magasin illisible ne s'écrase pas : on perdrait les
                    // accès des autres entreprises.
                    Log::error('[FNE] identifiants.json illisible : accès non inscrit', ['login' => $login]);

                    return false;
                }
                $contenu = $lu;
            }

            $contenu[$login] = array_filter([
                'motDePasse' => $motDePasse,
                'libelle'    => $libelle,
            ], fn ($v) => $v !== null && $v !== '');

            if (!is_dir(dirname($fichier))) {
                return false;
            }

            $temporaire = $fichier . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($temporaire, json_encode($contenu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            @chmod($temporaire, 0600);
            rename($temporaire, $fichier);

            Log::info('[FNE] Accès du portail remis au scraper', ['login' => $login]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[FNE] Accès du portail non remis au scraper : ' . $e->getMessage(), ['login' => $login]);

            return false;
        }
    }

    /**
     * Les accès reçus : on les remet au scraper, on demande un relevé et on le
     * lance — ses champs se reprennent ensuite d'eux-mêmes à l'import
     * (SynchronisationPortailFneService).
     */
    public static function apresAccesFourni(Entreprise $entreprise, string $login, string $motDePasse): void
    {
        self::inscrire($login, $motDePasse, $entreprise->nom);

        \App\Modules\Admin\Modeles\PortailFneDemande::pour(
            strtoupper(trim($login)), 'Accès FNE fournis : relever le paramétrage', $entreprise->id
        );

        ScraperPortailFneService::lancerPourLogin(strtoupper(trim($login)));
    }
}
