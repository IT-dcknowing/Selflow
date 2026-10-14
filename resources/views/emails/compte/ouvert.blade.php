@extends('emails.compte.gabarit')

@section('titre', 'Votre accès à Selflow est ouvert')

@section('corps')
    <p style="margin:0 0 16px;font-size:15px;color:#1e293b;line-height:1.6;">Bonjour{{ $prenom ? ' ' . $prenom : '' }},</p>
    <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.7;">
        @if($nomEntreprise)
            Un compte Selflow vient d'être ouvert à votre nom pour <strong>{{ $nomEntreprise }}</strong>.
        @else
            Un compte Selflow vient d'être ouvert à votre nom.
        @endif
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
        style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;margin:0 0 22px;">
        <tr>
            <td style="padding:16px 18px;font-size:14px;color:#166534;line-height:1.8;">
                Identifiant&nbsp;: <strong>{{ $identifiant }}</strong><br>
                @if($creeParUnTiers)
                    Mot de passe&nbsp;: celui que vous a remis la personne qui a créé votre compte.
                    Il vous sera demandé de le changer à la première connexion.
                @else
                    Mot de passe&nbsp;: celui que vous avez choisi à l'inscription.
                @endif
            </td>
        </tr>
    </table>
    @include('emails.compte.bouton', ['adresse' => $adresseConnexion, 'libelle' => 'Me connecter'])
    <p style="margin:0 0 6px;font-size:12.5px;color:#64748b;line-height:1.6;">
        Mot de passe inconnu ou égaré&nbsp;? Utilisez «&nbsp;Mot de passe oublié&nbsp;» sur la page de connexion :
        un lien vous sera envoyé à cette adresse.
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
        style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;margin:18px 0 0;">
        <tr>
            <td style="padding:14px 18px;font-size:12.5px;color:#92400e;line-height:1.7;">
                <strong>Vous n'attendiez pas ce message&nbsp;?</strong> Répondez-y : nous fermerons ce compte.
            </td>
        </tr>
    </table>
@endsection
