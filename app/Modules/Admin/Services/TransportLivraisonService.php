<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le transport d'une livraison, en deux temps (chantier 15.3 du plan).
 *
 * Le bon ne portait que des quantités. Tranché par le propriétaire le
 * 07/10/2026 : destination, livreur, véhicule, heures de départ et
 * d'arrivée, réceptionnaire — **tout est obligatoire**, et le bon imprimé le
 * porte. L'heure d'arrivée et le réceptionnaire n'existent qu'une fois la
 * marchandise remise : ils se demandent à « marquer livré », le reste au
 * départ.
 *
 * Les deux chemins qui expédient — le bon de livraison des ventes et la file
 * « Livraisons » du stock — passent par ici : une règle écrite deux fois
 * finit par dire deux choses.
 */
class TransportLivraisonService
{
    /** Une signature tracée à l'écran tient largement sous cette taille. */
    private const SIGNATURE_MAX = 300000;

    /** @return array<string, mixed> */
    public static function reglesDuDepart(Entreprise $entreprise): array
    {
        return [
            'adresse_livraison'      => ['required', 'string', 'max:255'],
            'livreur_type'           => ['required', 'in:personnel,prestataire'],
            // Un livreur du personnel est désigné dans l'entreprise, et non
            // saisi : un identifiant d'une autre entreprise posté à la main
            // ne passe pas.
            'livreur_utilisateur_id' => ['required_if:livreur_type,personnel', 'nullable', 'integer',
                Rule::exists('utilisateurs', 'id')->where('entreprise_id', $entreprise->id)],
            'livreur_nom'            => ['required_if:livreur_type,prestataire', 'nullable', 'string', 'max:150'],
            'vehicule'               => ['required', 'string', 'max:60'],
            'heure_depart'           => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'adresse_livraison.required'          => 'Indiquez où la marchandise est livrée.',
            'livreur_type.required'               => 'Indiquez qui livre : un membre du personnel ou un prestataire.',
            'livreur_utilisateur_id.required_if'  => 'Choisissez le livreur parmi le personnel.',
            'livreur_nom.required_if'             => 'Indiquez le nom du prestataire qui livre.',
            'vehicule.required'                   => 'Indiquez le véhicule ou son immatriculation.',
            'heure_depart.required'               => 'Indiquez l\'heure de départ.',
            'heure_arrivee.required'              => 'Indiquez l\'heure d\'arrivée chez le client.',
            'heure_arrivee.after_or_equal'        => 'L\'arrivée ne peut pas précéder le départ.',
            'receptionnaire_nom.required'         => 'Indiquez qui a reçu la marchandise.',
            'receptionnaire_signature.required'   => 'Le réceptionnaire doit signer.',
            'receptionnaire_signature.regex'      => 'La signature n\'a pas été reconnue : faites-la retracer.',
            'observations.required'               => 'Notez les observations — « RAS » s\'il n\'y a rien à signaler.',
        ];
    }

    /**
     * Les colonnes du départ, prêtes pour le bon.
     *
     * @return array<string, mixed>
     */
    public static function depart(Request $request): array
    {
        $parLePersonnel = $request->input('livreur_type') === 'personnel';
        $livreur = $parLePersonnel ? Utilisateur::find($request->input('livreur_utilisateur_id')) : null;

        return [
            'adresse_livraison'      => trim((string) $request->input('adresse_livraison')),
            'livreur_type'           => $request->input('livreur_type'),
            'livreur_utilisateur_id' => $livreur?->id,
            // Le nom est figé sur le bon : un collaborateur renommé ou parti
            // ne doit pas changer ce qu'un document déjà remis porte.
            'livreur_nom'            => $livreur
                ? trim(($livreur->prenom ?? '') . ' ' . ($livreur->nom ?? ''))
                : trim((string) $request->input('livreur_nom')),
            'vehicule'               => trim((string) $request->input('vehicule')),
            'heure_depart'           => $request->input('heure_depart'),
        ];
    }

    /** @return array<string, mixed> */
    public static function reglesDeLArrivee(BonLivraison $bl): array
    {
        return [
            'heure_arrivee'            => array_filter(['required', 'date',
                $bl->heure_depart ? 'after_or_equal:' . $bl->heure_depart->format('Y-m-d H:i:s') : null]),
            'receptionnaire_nom'       => ['required', 'string', 'max:150'],
            'receptionnaire_signature' => ['required', 'string', 'max:' . self::SIGNATURE_MAX,
                'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/'],
            'observations'             => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function arrivee(Request $request): array
    {
        return [
            'heure_arrivee'            => $request->input('heure_arrivee'),
            'receptionnaire_nom'       => trim((string) $request->input('receptionnaire_nom')),
            'receptionnaire_signature' => $request->input('receptionnaire_signature'),
            'observations'             => trim((string) $request->input('observations')),
        ];
    }
}
