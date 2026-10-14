@extends('emails.compte.gabarit')

@section('titre', 'Réinitialisation de votre mot de passe')

@section('corps')
    <p style="margin:0 0 16px;font-size:15px;color:#1e293b;line-height:1.6;">Bonjour{{ $prenom ? ' ' . $prenom : '' }},</p>
    <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.7;">
        Une demande de réinitialisation du mot de passe a été faite pour le compte
        <strong>{{ $identifiant }}</strong>. Le lien ci-dessous est valable <strong>{{ $minutes }} minutes</strong>
        et ne sert qu'une fois.
    </p>
    @include('emails.compte.bouton', ['adresse' => $lien, 'libelle' => 'Choisir un nouveau mot de passe'])
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
        style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;margin:8px 0 0;">
        <tr>
            <td style="padding:14px 18px;font-size:12.5px;color:#92400e;line-height:1.7;">
                <strong>Vous n'avez rien demandé&nbsp;?</strong> Ignorez ce message : votre mot de passe
                actuel reste valable, et personne ne peut le changer sans ce lien.
            </td>
        </tr>
    </table>
@endsection
