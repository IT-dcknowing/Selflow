<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;

/**
 * L'accès à l'espace FNE que l'entreprise fournit une seule fois.
 *
 * Une entreprise déjà inscrite auprès de la DGI n'a pas à ressaisir sa
 * situation fiscale : tout est dans son espace. Elle donne son NCC et le mot
 * de passe de cet espace à la création de son compte ; le superadministrateur
 * s'en sert pour relever le paramétrage et poser la clé d'API.
 *
 * **L'entreprise ne configure rien.** Elle fournit une information, comme le
 * propriétaire du projet l'a tranché : les clés et la configuration de la
 * plateforme appartiennent au superadministrateur seul.
 *
 * ## Trois précautions
 *
 * - **Chiffré au repos.** La colonne porte le cast `encrypted` de la table
 *   `fne_credentials`, celle des clés : la valeur stockée est inexploitable
 *   sans `APP_KEY`, qui n'est jamais versionnée.
 * - **Rendu au seul superadministrateur, sur demande.** Décision du
 *   propriétaire, 08/10/2026 : « Voir les accès FNE » sur la page des
 *   entreprises, derrière son mot de passe, comme les clés
 *   (`SuperadminFneControleur::voirAcces`). Aucun autre écran ne l'affiche, et
 *   le modèle le cache à toute sérialisation.
 * - **Effaçable.** Une fois le paramétrage relevé, il ne sert plus.
 *   `oublier()` le retire : ce qui ne sert plus ne se garde pas.
 */
class AccesFneService
{
    /**
     * Retenir l'accès qu'une entreprise vient de fournir.
     *
     * Un mot de passe vide n'efface pas celui qui existe : un formulaire
     * renvoyé sans ce champ — c'est le cas de tout écran de modification, un
     * champ mot de passe ne se pré-remplissant jamais — ne doit pas faire
     * perdre l'accès déjà donné.
     */
    public static function enregistrer(Entreprise $entreprise, ?string $ncc, ?string $motDePasse): void
    {
        $ncc = trim((string) $ncc);
        $motDePasse = (string) $motDePasse;

        if ($ncc === '' && $motDePasse === '') {
            return;
        }

        $identifiants = FneCredential::firstOrNew(['entreprise_id' => $entreprise->id]);

        if ($ncc !== '') {
            $identifiants->ncc_associe = strtoupper(preg_replace('/\s+/', '', $ncc));
        }

        if ($motDePasse !== '') {
            $identifiants->acces_mot_de_passe = $motDePasse;
            $identifiants->acces_fourni_at    = now();
        }

        $identifiants->save();

        // Propriétaire, 08/10/2026 : les accès reçus partent au scraper, et le
        // relevé est demandé tout de suite — c'est lui qui remplit les champs
        // grisés des paramètres.
        if ($motDePasse !== '' && filled($identifiants->ncc_associe)) {
            IdentifiantsScraperService::apresAccesFourni($entreprise, $identifiants->ncc_associe, $motDePasse);
        }
    }

    /**
     * Oublier l'accès, le paramétrage étant fait.
     *
     * Les clés, elles, restent : ce sont elles qui font fonctionner la
     * certification. C'est le mot de passe du compte de l'entreprise qui n'a
     * plus de raison d'être conservé.
     */
    public static function oublier(Entreprise $entreprise): void
    {
        FneCredential::where('entreprise_id', $entreprise->id)->update([
            'acces_mot_de_passe' => null,
            'acces_fourni_at'    => null,
        ]);
    }

    /** Un accès a-t-il été fourni, et quand ? */
    public static function fourniLe(Entreprise $entreprise): ?\Illuminate\Support\Carbon
    {
        return $entreprise->fneCredential?->acces_fourni_at;
    }
}
