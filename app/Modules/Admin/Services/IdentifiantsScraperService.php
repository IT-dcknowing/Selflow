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
        if (!is_dir(dirname($fichier))) {
            return false;
        }

        // Le verrou porte sur un fichier séparé : le fichier JSON est remplacé
        // atomiquement, donc verrouiller son inode ne protégerait pas le renommage.
        $verrou = fopen($fichier . '.lock', 'c');
        if (!$verrou) {
            return false;
        }
        @chmod($fichier . '.lock', 0600);
        $verrouAcquis = false;

        try {
            $verrouAcquis = flock($verrou, LOCK_EX);
            if (!$verrouAcquis) {
                return false;
            }

            $contenu = [];
            if (is_file($fichier)) {
                $lu = json_decode((string) file_get_contents($fichier), true);
                if (!is_array($lu)) {
                    Log::error('[FNE] identifiants.json illisible : accès non inscrit', ['login' => $login]);
                    return false;
                }
                $contenu = $lu;
            }

            $contenu[$login] = array_filter([
                'motDePasse' => $motDePasse,
                'libelle' => $libelle,
            ], fn ($valeur) => $valeur !== null && $valeur !== '');

            $json = json_encode($contenu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return false;
            }

            $temporaire = $fichier . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($temporaire, $json, LOCK_EX) === false) {
                @unlink($temporaire);
                return false;
            }
            @chmod($temporaire, 0600);
            if (!rename($temporaire, $fichier)) {
                @unlink($temporaire);
                return false;
            }

            @chmod($fichier, 0600);
            Log::info('[FNE] Accès du portail remis au scraper', ['login' => $login]);
            return true;
        } catch (\Throwable $e) {
            Log::error('[FNE] Accès du portail non remis au scraper : ' . $e->getMessage(), ['login' => $login]);
            return false;
        } finally {
            if ($verrouAcquis) {
                flock($verrou, LOCK_UN);
            }
            fclose($verrou);
        }
    }

    /**
     * Retirer un login du magasin local lorsque le superadmin suspend le scraping.
     * L'écriture atomique évite de tronquer les identifiants des autres entreprises.
     */
    public static function retirer(string $login): bool
    {
        $login = strtoupper(trim($login));
        if ($login === '') return false;

        $fichier = self::fichier();
        if (!is_file($fichier)) return true;

        try {
            $contenu = json_decode((string) file_get_contents($fichier), true);
            if (!is_array($contenu)) {
                Log::error('[FNE] identifiants.json illisible : impossible de retirer un accès', ['login' => $login]);
                return false;
            }

            unset($contenu[$login]);
            $temporaire = $fichier . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($temporaire, json_encode($contenu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            @chmod($temporaire, 0600);
            if (!rename($temporaire, $fichier)) {
                @unlink($temporaire);
                return false;
            }

            Log::notice('[FNE] Accès retiré du magasin du scraper', ['login' => $login]);
            return true;
        } catch (\Throwable $e) {
            Log::error('[FNE] Retrait d’accès du scraper impossible : ' . $e->getMessage(), ['login' => $login]);
            return false;
        }
    }

    /**
     * Les accès reçus : on les remet au scraper, on demande un relevé et on le
     * lance — ses champs se reprennent ensuite d'eux-mêmes à l'import
     * (SynchronisationPortailFneService).
     */
    public static function apresAccesFourni(Entreprise $entreprise, string $login, string $motDePasse): bool
    {
        if (!self::inscrire($login, $motDePasse, $entreprise->nom)) {
            return false;
        }

        \App\Modules\Admin\Modeles\PortailFneDemande::pour(
            strtoupper(trim($login)), 'Accès FNE fournis : relever le paramétrage', $entreprise->id
        );

        ScraperPortailFneService::lancerPourLogin(strtoupper(trim($login)));

        return true;
    }
}
