<?php

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * L'adresse d'un espace sur mesure créée depuis le superadmin (23/11/2026).
 *
 * Par l'API de cPanel, avec un jeton (cPanel, « Gérer les jetons d'API ») :
 * sous-domaine (UAPI SubDomain::addsubdomain), domaine propre (API 2
 * AddonDomain::addaddondomain) et certificat (UAPI SSL::start_autossl_check).
 * Sans configuration, rien n'est appelé : la procédure manuelle reste.
 */
class CpanelService
{
    public static function configure(): bool
    {
        return filled(config('selflow.cpanel.hote')) && filled(config('selflow.cpanel.utilisateur'))
            && filled(config('selflow.cpanel.jeton')) && filled(config('selflow.cpanel.dossier_selflow'));
    }

    /** @return array{0:bool,1:string} */
    public function creerSousDomaine(string $sousDomaine, string $racine): array
    {
        return $this->uapi('SubDomain', 'addsubdomain', [
            'domain' => $sousDomaine, 'rootdomain' => $racine,
            'dir' => config('selflow.cpanel.dossier_selflow'), 'disallowdot' => 1,
        ], "Le sous-domaine {$sousDomaine}.{$racine} est créé sur le serveur.");
    }

    /** @return array{0:bool,1:string} */
    public function ajouterDomaine(string $domaine): array
    {
        $etiquette = substr(preg_replace('/[^a-z0-9]/', '', strtolower($domaine)), 0, 30) ?: 'espace';
        try {
            $reponse = $this->client()->get($this->base() . '/json-api/cpanel', [
                'cpanel_jsonapi_user' => config('selflow.cpanel.utilisateur'),
                'cpanel_jsonapi_apiversion' => 2, 'cpanel_jsonapi_module' => 'AddonDomain', 'cpanel_jsonapi_func' => 'addaddondomain',
                'newdomain' => $domaine, 'subdomain' => $etiquette, 'dir' => config('selflow.cpanel.dossier_selflow'),
            ]);
            $donnees = $reponse->json('cpanelresult.data.0') ?? [];
            if ($reponse->successful() && (int) ($donnees['result'] ?? 0) === 1) {
                return [true, "Le domaine {$domaine} est ajouté sur le serveur."];
            }

            return [false, 'cPanel a refusé : ' . trim(strip_tags((string) ($donnees['reason'] ?? $reponse->json('cpanelresult.error') ?? ('HTTP ' . $reponse->status()))))];
        } catch (\Throwable $e) {
            Log::warning('cPanel injoignable', ['erreur' => $e->getMessage()]);

            return [false, 'cPanel est injoignable : ' . $e->getMessage()];
        }
    }

    /** @return array{0:bool,1:string} */
    public function lancerAutoSsl(): array
    {
        return $this->uapi('SSL', 'start_autossl_check', [], 'Le certificat HTTPS est demandé (AutoSSL) : il arrive en quelques minutes.');
    }

    /** @return array{0:bool,1:string} */
    private function uapi(string $module, string $fonction, array $parametres, string $succes): array
    {
        try {
            $reponse = $this->client()->get($this->base() . "/execute/{$module}/{$fonction}", $parametres);
            if ($reponse->successful() && (int) $reponse->json('status') === 1) {
                return [true, $succes];
            }
            $erreurs = implode(' ; ', (array) ($reponse->json('errors') ?? [])) ?: ('HTTP ' . $reponse->status());

            return [false, 'cPanel a refusé : ' . $erreurs];
        } catch (\Throwable $e) {
            Log::warning('cPanel injoignable', ['erreur' => $e->getMessage()]);

            return [false, 'cPanel est injoignable : ' . $e->getMessage()];
        }
    }

    private function base(): string
    {
        $hote = rtrim((string) config('selflow.cpanel.hote'), '/');

        return str_starts_with($hote, 'http') ? $hote : 'https://' . $hote . ':2083';
    }

    private function client()
    {
        return Http::timeout(60)->acceptJson()
            ->withHeaders(['Authorization' => 'cpanel ' . config('selflow.cpanel.utilisateur') . ':' . config('selflow.cpanel.jeton')]);
    }
}
