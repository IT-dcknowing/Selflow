@extends('emails.compte.gabarit')

@section('titre', $negociation->libelleStatut($role))

@section('corps')
    @php
        $autre = $role === \App\Modules\Admin\Modeles\B2bNegotiation::CLIENT
            ? $negociation->entrepriseFournisseur?->nom : $negociation->entrepriseClient?->nom;
    @endphp
    <p style="margin:0 0 14px;font-size:14px;color:#475569;line-height:1.7;">
        <strong>{{ $autre }}</strong> a agi sur votre négociation B2B
        @if($negociation->reference_commande)<strong>{{ $negociation->reference_commande }}</strong>@endif :
    </p>
    <div style="margin:0 0 20px;padding:14px 16px;background:#f8fafc;border-left:4px solid #002B5C;border-radius:6px;font-size:13.5px;color:#1e293b;line-height:1.7;white-space:pre-line;">{{ $texte }}</div>
    @include('emails.compte.bouton', ['adresse' => $lien, 'libelle' => 'Ouvrir la négociation'])
@endsection
